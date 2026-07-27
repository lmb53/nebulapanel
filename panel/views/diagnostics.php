<?php
/**
 * Read-only checks for the dedicated panel runtime and its single validating
 * privilege boundary.
 */
require_once APP_ROOT . '/lib/mod_apps.php';

$whoami = get_current_user();
if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
    $pw = posix_getpwuid(posix_geteuid());
    if (is_array($pw) && isset($pw['name'])) {
        $whoami = (string) $pw['name'];
    }
}
$helper = helper_available();

// Build check rows: [label, status(ok|bad|na|info), detail]
$rows = [];
$rows[] = ['Panel PHP identity', $whoami === 'nebula-panel' ? 'ok' : 'info',
    $whoami . ' / PHP ' . PHP_VERSION . ($whoami === 'www-data' ? ' — re-run install.sh to enable the dedicated pool' : '')];
$rows[] = ['data/ writable', is_writable(DATA_DIR) ? 'ok' : 'bad',
    is_writable(DATA_DIR) ? DATA_DIR : DATA_DIR . ' is not writable by the panel account'];

$rows[] = ['Command runner', function_exists('proc_open') ? 'ok' : 'bad',
    function_exists('proc_open') ? 'proc_open is available for the bounded helper client' : 'proc_open is disabled; privileged features cannot run'];
$rows[] = ['Privileged helper', $helper ? 'ok' : 'bad',
    $helper ? NEBULA_HELPER : 'Not installed — re-run install.sh from a reviewed commit'];

if ($helper) {
    [$hc] = helper_cmd('php-versions', 20);
    $rows[] = ['Privilege boundary', $hc === 0 ? 'ok' : 'bad',
        $hc === 0 ? 'the single nebula-helper sudo rule is callable' : 'helper rejected — check ownership and /etc/sudoers.d/nebula-panel'];
}

// Tool presence.
$tools = ['nginx', 'php', 'tar', 'certbot', 'crontab', 'rsync', 'curl'];
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
      and the helper: check out a reviewed tag or commit, then run <span class="mono">sudo ./install.sh</span>
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
