<?php
/**
 * Diagnostics — read-only health checks. Especially: which sudo privileges
 * actually work, so "sudo errors" are self-explanatory.
 */
require_once APP_ROOT . '/lib/mod_apps.php';

/** Did sudo REJECT us (auth/tty), regardless of the command's own exit code? */
function diag_sudo_denied(string $cmd): bool
{
    [$code, $out] = run_cmd('sudo -n ' . $cmd . ' 2>&1', 15);
    return stripos($out, 'a password is required') !== false
        || stripos($out, 'may not run sudo') !== false
        || stripos($out, 'a terminal is required') !== false
        || stripos($out, 'no tty present') !== false
        || stripos($out, 'not allowed to execute') !== false;
}

$whoami = trim(shell_exec('whoami 2>/dev/null') ?: '?');
$helper = helper_available();

// Build check rows: [label, status(ok|bad|na|info), detail]
$rows = [];
$rows[] = ['Web/PHP user', 'info', $whoami . '  ·  PHP ' . PHP_VERSION];
$rows[] = ['data/ writable', is_writable(DATA_DIR) ? 'ok' : 'bad',
    is_writable(DATA_DIR) ? DATA_DIR : DATA_DIR . ' is NOT writable — chown -R ' . $whoami . ' ' . DATA_DIR];

$rows[] = ['Privileged helper', $helper ? 'ok' : 'bad',
    $helper ? NEBULA_HELPER : 'Not installed — re-run install.sh (needed for Websites/SSL/PHP/phpMyAdmin)'];

if ($helper) {
    [$hc] = helper_cmd('php-versions', 20);
    $rows[] = ['Helper sudo (websites/SSL/PHP)', $hc === 0 ? 'ok' : 'bad',
        $hc === 0 ? 'sudo -n nebula-helper works' : 'sudo rejected the helper — check /etc/sudoers.d/nebula-panel'];
}

// systemctl: probe lifecycle and boot-state rules with a non-existent unit.
$systemctlDenied = diag_sudo_denied('systemctl restart nebula-diagnostic-nonexistent.service')
    || diag_sudo_denied('systemctl enable nebula-diagnostic-nonexistent.service');
$rows[] = ['systemctl sudo (Services)', $systemctlDenied ? 'bad' : 'ok',
    'Controls start/stop/restart and enable/disable at boot'];

// Wildcard-rule binaries — only meaningful if the tool is installed.
// NB: `tar` (Backups) is intentionally NOT here — the panel does not hold a
// `sudo tar` rule (it would be arbitrary root code execution), so backups run
// as the web user over web-readable files. Reporting a missing tar sudo rule
// was a false positive; it's covered by the "Backups (tar)" check below.
$sudoBins = [
    'apt-get'    => 'Updates / Install Apps',
    'ufw'        => 'Firewall',
    'docker'     => 'Docker',
    'mysql'      => 'Databases',
    'journalctl' => 'Logs',
];
foreach ($sudoBins as $bin => $use) {
    if (!has_cmd($bin)) {
        $rows[] = [$bin . ' sudo (' . $use . ')', 'na', 'not installed'];
        continue;
    }
    $ok = !diag_sudo_denied($bin . ' --version');
    $rows[] = [$bin . ' sudo (' . $use . ')', $ok ? 'ok' : 'bad',
        $ok ? 'sudo rule present' : 'sudo rejected — re-run install.sh to add the rule'];
}

// Backups archive web-readable files as the web user (no sudo) — check tar is
// present and actually runnable, not that a (deliberately absent) sudo rule.
if (!has_cmd('tar')) {
    $rows[] = ['Backups (tar)', 'bad', 'tar is not installed — apt-get install tar'];
} else {
    [$tarCode] = run_cmd('tar --version', 10);
    $rows[] = ['Backups (tar)', $tarCode === 0 ? 'ok' : 'bad',
        $tarCode === 0 ? 'runs as ' . $whoami . ' over web-readable files (no sudo needed)' : 'tar failed to run'];
}

// Tool presence.
$tools = ['nginx', 'php', 'certbot', 'crontab', 'rsync', 'curl'];
$present = array_filter($tools, 'has_cmd');
$rows[] = ['Core tools', count($present) === count($tools) ? 'ok' : 'info',
    'present: ' . (implode(', ', $present) ?: 'none')];

$phpv = php_installed_versions();
$rows[] = ['PHP-FPM versions', $phpv ? 'ok' : 'info', $phpv ? implode(', ', $phpv) : 'none detected'];

$badge = [
    'ok'   => ['badge-emerald', 'OK'],
    'bad'  => ['badge-red', 'Action needed'],
    'na'   => ['badge-slate', 'N/A'],
    'info' => ['badge-blue', 'Info'],
];
$problems = count(array_filter($rows, fn($r) => $r[1] === 'bad'));
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Diagnostics</h1>
    <p class="page-subtitle">Environment &amp; privilege checks — resolve anything marked “Action needed”</p>
  </div>
  <div class="page-actions">
    <span class="badge <?= $problems ? 'badge-red' : 'badge-emerald' ?>"><span class="bdot"></span><?= $problems ? ($problems . ' issue' . ($problems === 1 ? '' : 's')) : 'All good' ?></span>
  </div>
</div>

<?php if ($problems): ?>
<div class="card" style="margin-bottom:16px;border-color:rgba(245,158,11,.25)">
  <div class="card-pad flex items-center gap-3" style="color:var(--orange-400)">
    <i data-lucide="info"></i>
    <div style="font-size:13px;color:var(--text-secondary)">
      Most privilege issues are fixed by re-running the installer, which writes <span class="mono">/etc/sudoers.d/nebula-panel</span>
      and the helper: <span class="mono">curl -fsSL https://raw.githubusercontent.com/lmb53/nebulapanel/main/install.sh | sudo bash</span>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><h3>Checks</h3><span class="muted"><?= count($rows) ?> checks</span></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th style="width:280px">Check</th><th style="width:130px">Status</th><th>Detail</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): [$cls, $label] = $badge[$r[1]]; ?>
          <tr>
            <td style="font-weight:600"><?= e($r[0]) ?></td>
            <td><span class="badge <?= e($cls) ?>"><span class="bdot"></span><?= e($label) ?></span></td>
            <td class="mono text-tertiary" style="font-size:12px;word-break:break-word"><?= e($r[2]) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
