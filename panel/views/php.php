<?php
require_once APP_ROOT . '/lib/mod_apps.php';
require_once APP_ROOT . '/lib/mod_php.php';
require_once APP_ROOT . '/lib/mod_sites.php';

$versions = php_installed_versions();
$installable = php_installable_versions();
$sel = (string) ($_GET['version'] ?? php_default_version());
if (!in_array($sel, $versions, true)) { $sel = php_default_version(); }
$summaries = php_version_summaries();
$settings = $sel !== '' ? php_read_settings($sel) : [];
$extensions = $sel !== '' ? php_extension_states($sel) : [];
$xdebug = current(array_filter($extensions, fn($extension) => ($extension['key'] ?? '') === 'xdebug')) ?: ['installed' => false, 'enabled' => false];
$opcache = current(array_filter($extensions, fn($extension) => ($extension['key'] ?? '') === 'opcache')) ?: ['installed' => false, 'enabled' => false];
$ini = $sel !== '' ? php_ini_content($sel) : ['ok' => false, 'content' => '', 'backup' => false];
$pools = $sel !== '' ? php_fpm_pools($sel) : [];
$sites = sites_list();
$composer = php_composer_version();
$helper = helper_available();
$opcacheEnabled = in_array(strtolower((string) ($settings['opcache.enable'] ?? '')), ['1', 'on', 'true', 'yes'], true);
$opcacheValidate = in_array(strtolower((string) ($settings['opcache.validate_timestamps'] ?? '')), ['1', 'on', 'true', 'yes'], true);
$xdebugMode = (string) ($settings['xdebug.mode'] ?? '') ?: 'off';
$xdebugPort = (string) ($settings['xdebug.client_port'] ?? '') ?: '9003';
$xdebugStart = (string) ($settings['xdebug.start_with_request'] ?? '') ?: 'trigger';

$fieldLabels = [
    'memory_limit' => ['Memory limit', 'Maximum memory available to one script'],
    'upload_max_filesize' => ['Upload limit', 'Maximum size of one uploaded file'],
    'post_max_size' => ['POST limit', 'Maximum size of an entire POST request'],
    'max_execution_time' => ['Execution time', 'Maximum script runtime in seconds'],
    'max_input_time' => ['Input parse time', 'Maximum request parsing time in seconds'],
    'max_input_vars' => ['Input variables', 'Maximum accepted request variables'],
    'max_file_uploads' => ['File uploads', 'Maximum files in one request'],
    'default_socket_timeout' => ['Socket timeout', 'Default network timeout in seconds'],
    'display_errors' => ['Display errors', 'Show errors in HTTP responses'],
    'log_errors' => ['Log errors', 'Write PHP errors to the configured log'],
];
$formatBytes = static function (int $bytes): string {
    if ($bytes <= 0) { return '—'; }
    $units = ['B', 'KB', 'MB', 'GB']; $i = 0; $value = (float) $bytes;
    while ($value >= 1024 && $i < count($units) - 1) { $value /= 1024; $i++; }
    return number_format($value, $i ? 1 : 0) . ' ' . $units[$i];
};
?>
<div class="page-header">
  <div>
    <h1 class="page-title">PHP</h1>
    <p class="page-subtitle">Runtimes, website assignments, extensions, FPM pools and configuration</p>
  </div>
  <div class="page-actions">
    <?php if ($installable): ?><button class="btn btn-secondary" type="button" id="phpInstallToggle" aria-expanded="false" aria-controls="phpInstallCard"><i data-lucide="download"></i>Install version</button><?php endif; ?>
    <?php if ($versions): ?><button class="btn btn-secondary" type="button" data-php-restart="all"><i data-lucide="rotate-cw"></i>Restart all PHP-FPM</button><?php endif; ?>
  </div>
</div>

<?php if ($installable): ?>
<div class="card hidden" id="phpInstallCard" style="margin-bottom:16px">
  <div class="card-header"><h3>Install another PHP version</h3><span class="muted">PHP-FPM, CLI and common modules</span></div>
  <div class="card-pad flex items-center gap-2" style="flex-wrap:wrap">
    <label class="sr-only" for="phpInstallVersion">PHP version</label>
    <select class="select mono" id="phpInstallVersion" style="width:auto;min-width:170px">
      <?php foreach (array_reverse($installable) as $version): ?><option value="<?= e($version) ?>"<?= $version === php_latest_version() ? ' selected' : '' ?>>PHP <?= e($version) ?><?= $version === php_latest_version() ? ' (latest)' : '' ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="button" id="phpInstallRun" <?= $helper ? '' : 'disabled' ?>><i data-lucide="download"></i>Install</button>
    <?php if (!$helper): ?><span class="field-help" style="margin:0">The privileged helper is required — re-run install.sh.</span><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!$versions): ?>
<?= requirement_missing('code-2', 'No PHP versions detected', 'Install PHP-FPM ' . e(php_latest_version()) . ' (the latest release) with its common extensions to host PHP websites.', [['label' => 'Install a PHP version', 'icon' => 'download', 'primary' => true, 'attrs' => 'data-open-php-install']]) ?>
<div class="card hidden" id="phpOutputCard" style="margin-top:16px">
  <div class="card-header"><h3>Installation output</h3></div>
  <pre class="mono log-pre" id="phpOutput"></pre>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const { streamPost, toast } = window.Nebula;
  const toggleInstall = () => { const card = document.getElementById('phpInstallCard'); card?.classList.toggle('hidden'); document.getElementById('phpInstallToggle')?.setAttribute('aria-expanded', card?.classList.contains('hidden') ? 'false' : 'true'); if (card && !card.classList.contains('hidden')) document.getElementById('phpInstallVersion')?.focus(); };
  document.getElementById('phpInstallToggle')?.addEventListener('click', toggleInstall);
  document.querySelectorAll('[data-open-php-install]').forEach((b) => b.addEventListener('click', () => { document.getElementById('phpInstallCard')?.classList.remove('hidden'); document.getElementById('phpInstallVersion')?.focus(); }));
  document.getElementById('phpInstallRun')?.addEventListener('click', async function () {
    const outputCard = document.getElementById('phpOutputCard'); const output = document.getElementById('phpOutput');
    outputCard?.classList.remove('hidden'); if (output) output.textContent = ''; this.disabled = true;
    const result = await streamPost('php', {action:'install', version:document.getElementById('phpInstallVersion').value}, (event) => {
      if (event.type === 'output' && output) { output.textContent += event.text; output.scrollTop = output.scrollHeight; }
    });
    toast(result.ok ? 'PHP installed' : (result.error || 'Installation failed'), result.ok ? 'success' : 'error');
    if (result.ok) setTimeout(() => location.reload(), 700); else this.disabled = false;
  });
});
</script>
<?php else: ?>

<div class="grid grid-auto" style="gap:14px;margin-bottom:16px">
  <?php foreach ($summaries as $summary):
    $version = (string) $summary['version']; $selected = $version === $sel;
    $status = (string) $summary['status']; $support = $summary['support'];
    $supportClass = ($support['key'] ?? '') === 'active' ? 'badge-emerald' : (($support['key'] ?? '') === 'security' ? 'badge-orange' : 'badge-red');
  ?>
  <a href="<?= e(url('php', ['version' => $version])) ?>" class="card php-version-card<?= $selected ? ' selected' : '' ?>"<?= $selected ? ' aria-current="true"' : '' ?>>
    <div class="flex items-center gap-3" style="margin-bottom:14px">
      <div style="width:42px;height:42px;border-radius:12px;background:rgba(99,102,241,.14);display:flex;align-items:center;justify-content:center"><i data-lucide="code-2" style="color:var(--blue-400)"></i></div>
      <div style="flex:1"><div style="font-size:18px;font-weight:700">PHP <?= e($version) ?></div><div class="muted" style="font-size:12px">FPM + CLI</div></div>
      <?php if (!empty($summary['default'])): ?><span class="badge badge-blue">Default</span><?php endif; ?>
    </div>
    <div class="flex gap-2" style="flex-wrap:wrap;margin-bottom:13px">
      <span class="badge <?= $status === 'active' ? 'badge-emerald' : ($status === 'failed' ? 'badge-red' : 'badge-slate') ?>"><span class="bdot"></span><?= e(['active' => 'Running', 'inactive' => 'Stopped', 'failed' => 'Failed', 'not-installed' => 'Not installed'][$status] ?? ucfirst($status)) ?></span>
      <span class="badge <?= e($supportClass) ?>" title="<?= !empty($support['until']) ? 'Supported until ' . e(fmt_datetime($support['until'], false)) : '' ?>"><?= e((string) ($support['label'] ?? 'Unknown')) ?></span>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px;color:var(--text-tertiary)">
      <span><strong style="display:block;color:var(--text-secondary);font-size:14px"><?= (int) $summary['sites'] ?></strong>Websites</span>
      <span><strong style="display:block;color:var(--text-secondary);font-size:14px"><?= e($formatBytes((int) $summary['memory'])) ?></strong>FPM memory</span>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<div>
  <div class="tabs page-tabs" id="phpManagerTabs">
    <button class="tab active" type="button" data-tab-target="php-sites" data-tab-hash="sites"><i data-lucide="globe"></i>Websites</button>
    <button class="tab" type="button" data-tab-target="php-settings" data-tab-hash="settings"><i data-lucide="sliders-horizontal"></i>Settings</button>
    <button class="tab" type="button" data-tab-target="php-extensions" data-tab-hash="extensions"><i data-lucide="blocks"></i>Extensions</button>
    <button class="tab" type="button" data-tab-target="php-ini" data-tab-hash="ini"><i data-lucide="file-code-2"></i>php.ini</button>
    <button class="tab" type="button" data-tab-target="php-opcache" data-tab-hash="opcache"><i data-lucide="zap"></i>OPcache</button>
    <button class="tab" type="button" data-tab-target="php-pools" data-tab-hash="pools"><i data-lucide="network"></i>FPM pools</button>
    <button class="tab" type="button" data-tab-target="php-xdebug" data-tab-hash="xdebug"><i data-lucide="bug"></i>Xdebug</button>
    <button class="tab" type="button" data-tab-target="php-composer" data-tab-hash="composer"><i data-lucide="package"></i>Composer</button>
    <button class="tab" type="button" data-tab-target="php-health" data-tab-hash="health"><i data-lucide="activity"></i>Health</button>
  </div>
</div>

<div data-tab-panels id="phpManagerPanels">
  <section data-tab-panel id="php-sites" class="card">
    <div class="card-header"><h3>Website PHP versions</h3><span class="muted">Changes reload Nginx after its configuration passes validation</span></div>
    <?php if (!$sites): ?><?= requirement_missing('globe', 'No websites yet', 'Websites appear here so you can choose which PHP version each one uses.', [['label' => 'Add website', 'href' => url('websites') . '#add', 'icon' => 'plus']], false) ?>
    <?php else: ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Website</th><th>Document root</th><th>PHP version</th><th>SSL</th></tr></thead><tbody>
      <?php foreach ($sites as $site): ?><tr>
        <td><a href="<?= e((!empty($site['ssl']) ? 'https://' : 'http://') . ($site['domain'] ?? '')) ?>" target="_blank" rel="noopener"><?= e((string) ($site['domain'] ?? '')) ?></a></td>
        <td class="mono text-tertiary"><?= e((string) ($site['docroot'] ?? '')) ?></td>
        <td><select class="select input-sm mono" aria-label="PHP version for <?= e((string) ($site['domain'] ?? '')) ?>" data-site-php="<?= e((string) ($site['domain'] ?? '')) ?>" data-original="<?= e((string) ($site['php'] ?? '')) ?>" style="width:auto">
          <?php foreach ($versions as $version): ?><option value="<?= e($version) ?>" <?= ($site['php'] ?? '') === $version ? 'selected' : '' ?>>PHP <?= e($version) ?></option><?php endforeach; ?>
        </select></td>
        <td><span class="badge <?= !empty($site['ssl']) ? 'badge-emerald' : 'badge-slate' ?>"><?= !empty($site['ssl']) ? 'HTTPS' : 'HTTP' ?></span></td>
      </tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>

  <section data-tab-panel id="php-settings" class="card hidden">
    <?php $settingsReadable = $settings !== [] && array_filter(array_intersect_key($settings, $fieldLabels), fn($v) => (string) $v !== '') !== []; ?>
    <div class="card-header"><div><h3>PHP <?= e($sel) ?> settings</h3><div class="muted">Common FPM and CLI directives</div></div><button class="btn btn-primary btn-sm" type="button" id="phpSaveSettings"<?= $settingsReadable ? '' : ' disabled title="The configuration could not be read"' ?>><i data-lucide="save"></i>Save changes</button></div>
    <?php if (!$settingsReadable): ?>
    <?= requirement_missing('file-x', 'PHP ' . e($sel) . ' configuration is not readable', 'The panel could not read the FPM <span class="mono">php.ini</span> for this version, so its settings cannot be shown or changed safely.' . ($helper ? ' Check that PHP-FPM ' . e($sel) . ' is installed correctly.' : ' The privileged helper is required — re-run install.sh.'), [], false) ?>
    <?php else: ?>
    <div class="card-pad"><div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(235px,1fr));gap:16px">
      <?php foreach ($fieldLabels as $key => [$label, $description]): $value = (string) ($settings[$key] ?? ''); $boolean = in_array($key, ['display_errors', 'log_errors', 'opcache.enable'], true); ?>
      <div><label class="field-label" for="phpf_<?= e($key) ?>"><?= e($label) ?> <span class="mono text-tertiary" style="font-size:11px"><?= e($key) ?></span></label>
        <?php if ($boolean): $on = in_array(strtolower($value), ['on', '1', 'true', 'yes'], true); ?>
        <select class="select" id="phpf_<?= e($key) ?>" data-php-key="<?= e($key) ?>" data-original="<?= $on ? 'On' : 'Off' ?>"><option value="On" <?= $on ? 'selected' : '' ?>>On</option><option value="Off" <?= !$on ? 'selected' : '' ?>>Off</option></select>
        <?php else: ?><input class="input mono" id="phpf_<?= e($key) ?>" value="<?= e($value) ?>" data-php-key="<?= e($key) ?>" data-original="<?= e($value) ?>" autocomplete="off"><?php endif; ?>
        <div class="field-help"><?= e($description) ?></div>
      </div>
      <?php endforeach; ?>
    </div></div>
  <?php endif; ?>
  </section>

  <section data-tab-panel id="php-extensions" class="card hidden">
    <div class="card-header"><div><h3>PHP <?= e($sel) ?> extensions</h3><div class="muted" style="font-size:12px">Install packages or enable and disable modules per version</div></div><span class="badge badge-blue"><?= count(array_filter($extensions, fn($ext) => !empty($ext['enabled']))) ?> enabled</span></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Extension</th><th>Purpose</th><th>Installed</th><th>Runtime</th><th></th></tr></thead><tbody>
      <?php foreach ($extensions as $extension):
        $operation = empty($extension['installed']) ? 'install' : (!empty($extension['enabled']) ? 'disable' : 'enable');
      ?><tr>
        <td><strong><?= e((string) $extension['label']) ?></strong><div class="mono text-tertiary" style="font-size:11px"><?= e((string) $extension['key']) ?></div></td>
        <td class="text-tertiary"><?= e((string) $extension['description']) ?></td>
        <td><span class="badge <?= !empty($extension['installed']) ? 'badge-blue' : 'badge-slate' ?>"><?= !empty($extension['installed']) ? 'Installed' : 'Not installed' ?></span></td>
        <td><span class="badge <?= !empty($extension['enabled']) ? 'badge-emerald' : 'badge-slate' ?>"><span class="bdot"></span><?= !empty($extension['enabled']) ? 'Enabled' : 'Disabled' ?></span></td>
        <td class="actions-col"><button type="button" class="btn <?= $operation === 'disable' ? 'btn-secondary' : 'btn-primary' ?> btn-sm" data-extension="<?= e((string) $extension['key']) ?>" data-operation="<?= e($operation) ?>"><?= e(ucfirst($operation)) ?></button></td>
      </tr><?php endforeach; ?>
    </tbody></table></div>
  </section>

  <section data-tab-panel id="php-ini" class="card code-editor-card hidden">
    <div class="card-header"><div><h3>php.ini editor</h3><div class="mono muted" style="font-size:12px"><?= e((string) ($ini['path'] ?? "/etc/php/$sel/fpm/php.ini")) ?></div></div><div class="flex gap-2">
      <button class="btn btn-secondary btn-sm" type="button" id="phpIniRestore" <?= !empty($ini['backup']) ? '' : 'disabled' ?>><i data-lucide="undo-2"></i>Restore backup</button>
      <button class="btn btn-primary btn-sm" type="button" id="phpIniSave" <?= !empty($ini['ok']) ? '' : 'disabled' ?>><i data-lucide="save"></i>Validate &amp; save</button>
    </div></div>
    <?php if (empty($ini['ok'])): ?><div class="empty-state"><div class="es-icon"><i data-lucide="file-warning"></i></div><div><?= e((string) ($ini['error'] ?? 'php.ini is unavailable.')) ?></div></div>
    <?php else: ?><div class="code-editor-host"><textarea id="phpIniContent" class="input mono" style="width:100%;min-height:65vh;white-space:pre"><?= e((string) $ini['content']) ?></textarea></div><?php endif; ?>
  </section>

  <section data-tab-panel id="php-opcache" class="card hidden">
    <div class="card-header"><div><h3>OPcache</h3><div class="muted">Bytecode cache controls for PHP <?= e($sel) ?></div></div><span class="badge <?= !empty($opcache['enabled']) ? ($opcacheEnabled ? 'badge-emerald' : 'badge-slate') : 'badge-slate' ?>"><span class="bdot"></span><?= empty($opcache['enabled']) ? 'Extension not loaded' : ($opcacheEnabled ? 'Active' : 'Loaded, cache off') ?></span></div>
    <div class="card-pad">
      <div class="grid grid-4" style="gap:14px;margin-bottom:18px">
        <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(16,185,129,.12)"><i data-lucide="zap" style="color:var(--emerald-400)"></i></div></div><div class="stat-val text"><?= $opcacheEnabled ? 'On' : 'Off' ?></div><div class="stat-label">Configured state</div></div>
        <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(59,130,246,.12)"><i data-lucide="memory-stick" style="color:var(--blue-400)"></i></div></div><div class="stat-val"><?php if ((string) ($settings['opcache.memory_consumption'] ?? '') !== ''): ?><?= e((string) $settings['opcache.memory_consumption']) ?><span class="unit">MB</span><?php else: ?><span class="text-tertiary" title="Not set">—</span><?php endif; ?></div><div class="stat-label">Shared memory</div></div>
        <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(168,85,247,.12)"><i data-lucide="file-code" style="color:var(--purple-400)"></i></div></div><div class="stat-val"><?= e((string) (($settings['opcache.max_accelerated_files'] ?? '') !== '' ? $settings['opcache.max_accelerated_files'] : '—')) ?></div><div class="stat-label">Maximum cached scripts</div></div>
        <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(245,158,11,.12)"><i data-lucide="clock-check" style="color:var(--orange-400)"></i></div></div><div class="stat-val text"><?= ($settings['opcache.validate_timestamps'] ?? '') === '' ? '—' : ($opcacheValidate ? 'On' : 'Off') ?></div><div class="stat-label">Validate timestamps</div></div>
      </div>
      <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;max-width:900px">
        <div><label class="field-label" for="php-enabled-1">Enabled</label><select id="php-enabled-1" class="select" data-opcache-setting data-php-key="opcache.enable" data-original="<?= $opcacheEnabled ? 'On' : 'Off' ?>"><option value="On" <?= $opcacheEnabled ? 'selected' : '' ?>>On</option><option value="Off" <?= !$opcacheEnabled ? 'selected' : '' ?>>Off</option></select></div>
        <div><label class="field-label" for="php-memory-mb-2">Memory (MB)</label><input id="php-memory-mb-2" class="input mono" data-opcache-setting data-php-key="opcache.memory_consumption" data-original="<?= e((string) ($settings['opcache.memory_consumption'] ?? '')) ?>" value="<?= e((string) ($settings['opcache.memory_consumption'] ?? '')) ?>"></div>
        <div><label class="field-label" for="php-maximum-cached-scripts-3">Maximum cached scripts</label><input id="php-maximum-cached-scripts-3" class="input mono" data-opcache-setting data-php-key="opcache.max_accelerated_files" data-original="<?= e((string) ($settings['opcache.max_accelerated_files'] ?? '')) ?>" value="<?= e((string) ($settings['opcache.max_accelerated_files'] ?? '')) ?>"></div>
        <div><label class="field-label" for="php-validate-timestamps-4">Validate timestamps</label><select id="php-validate-timestamps-4" class="select" data-opcache-setting data-php-key="opcache.validate_timestamps" data-original="<?= $opcacheValidate ? 'On' : 'Off' ?>"><option value="On" <?= $opcacheValidate ? 'selected' : '' ?>>On</option><option value="Off" <?= !$opcacheValidate ? 'selected' : '' ?>>Off</option></select></div>
      </div>
      <div class="flex gap-2" style="margin-top:16px"><button class="btn btn-primary" id="phpSaveOpcache"><i data-lucide="save"></i>Save OPcache settings</button><button class="btn btn-secondary" id="phpResetOpcache"><i data-lucide="refresh-cw"></i>Reset cache</button></div>
      <div class="muted" style="font-size:12px;margin-top:8px">Reset cache safely restarts this PHP-FPM service after the current request finishes.</div>
    </div>
  </section>

  <section data-tab-panel id="php-pools" class="card hidden">
    <div class="card-header"><h3>PHP <?= e($sel) ?> FPM pools</h3><button class="btn btn-secondary btn-sm" data-php-restart="<?= e($sel) ?>"><i data-lucide="refresh-cw"></i>Restart PHP <?= e($sel) ?></button></div>
    <?php if (!$pools): ?><div class="empty-state"><div class="es-icon"><i data-lucide="network"></i></div><div>No readable pool configurations found</div></div>
    <?php else: ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Pool</th><th>User</th><th>Process manager</th><th>Max children</th><th>Listen</th><th>Config</th></tr></thead><tbody>
      <?php foreach ($pools as $pool): ?><tr><td><strong><?= e((string) $pool['name']) ?></strong></td><td class="mono"><?= e((string) $pool['user']) ?></td><td class="mono"><?= e((string) $pool['pm']) ?></td><td class="mono"><?= e((string) $pool['max_children']) ?></td><td class="mono text-tertiary"><?= e((string) $pool['listen']) ?></td><td class="mono text-tertiary"><?= e((string) $pool['file']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>

  <section data-tab-panel id="php-xdebug" class="card hidden">
    <div class="card-header"><div><h3>Xdebug</h3><div class="muted" style="font-size:12px">Step debugging and profiling for PHP <?= e($sel) ?></div></div><span class="badge <?= !empty($xdebug['enabled']) ? 'badge-orange' : 'badge-slate' ?>"><span class="bdot"></span><?= !empty($xdebug['enabled']) ? 'Enabled' : (!empty($xdebug['installed']) ? 'Disabled' : 'Not installed') ?></span></div>
    <div class="card-pad" style="max-width:760px">
      <div class="flex gap-2" style="margin-bottom:16px">
        <?php if (empty($xdebug['installed'])): ?><button class="btn btn-primary" data-extension="xdebug" data-operation="install"><i data-lucide="download"></i>Install Xdebug</button>
        <?php elseif (empty($xdebug['enabled'])): ?><button class="btn btn-primary" data-extension="xdebug" data-operation="enable">Enable Xdebug</button>
        <?php else: ?><button class="btn btn-secondary" data-extension="xdebug" data-operation="disable">Disable Xdebug</button><?php endif; ?>
      </div>
      <div class="grid grid-3" style="gap:14px;margin-bottom:16px">
        <div><label class="field-label" for="php-mode-5">Mode</label><select id="php-mode-5" class="input" data-xdebug-setting data-php-key="xdebug.mode" data-original="<?= e($xdebugMode) ?>"><?php foreach (['off','develop','debug','develop,debug','profile','coverage','trace'] as $mode): ?><option value="<?= e($mode) ?>" <?= $xdebugMode === $mode ? 'selected' : '' ?>><?= e($mode) ?></option><?php endforeach; ?></select></div>
        <div><label class="field-label" for="php-client-port-6">Client port</label><input id="php-client-port-6" class="input mono" data-xdebug-setting data-php-key="xdebug.client_port" data-original="<?= e($xdebugPort) ?>" value="<?= e($xdebugPort) ?>"></div>
        <div><label class="field-label" for="php-start-with-request-7">Start with request</label><select id="php-start-with-request-7" class="input" data-xdebug-setting data-php-key="xdebug.start_with_request" data-original="<?= e($xdebugStart) ?>"><?php foreach (['no','yes','trigger','default'] as $start): ?><option value="<?= e($start) ?>" <?= $xdebugStart === $start ? 'selected' : '' ?>><?= e($start) ?></option><?php endforeach; ?></select></div>
      </div>
      <div style="padding:12px;border-radius:var(--radius-md);background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);font-size:13px;color:var(--text-secondary);margin-bottom:14px"><i data-lucide="alert-triangle" style="width:15px;height:15px;color:var(--orange-400);vertical-align:-3px;margin-right:6px"></i>Xdebug materially reduces performance. Keep it disabled on production runtimes unless you are actively debugging.</div>
      <button class="btn btn-primary" id="phpSaveXdebug" <?= empty($xdebug['installed']) ? 'disabled' : '' ?>><i data-lucide="save"></i>Save Xdebug settings</button>
    </div>
  </section>

  <section data-tab-panel id="php-composer" class="card hidden">
    <div class="card-header"><div><h3>Composer</h3><div class="muted" style="font-size:12px">PHP dependency manager</div></div><?php if ($composer !== ''): ?><span class="badge badge-emerald"><span class="bdot"></span>Installed</span><?php else: ?><span class="badge badge-slate">Not installed</span><?php endif; ?></div>
    <div class="card-pad">
      <?php if ($composer !== ''): ?>
        <div class="service-row" style="max-width:760px"><div class="svc-icon"><i data-lucide="package-check"></i></div><div style="flex:1"><strong><?= e($composer) ?></strong><div class="muted" style="font-size:12px">Run project-specific Composer commands from a website directory in Terminal.</div></div><a class="btn btn-primary" href="<?= e(url('terminal')) ?>"><i data-lucide="square-terminal"></i>Open Terminal</a></div>
      <?php else: ?>
        <div class="service-row" style="max-width:760px"><div class="svc-icon"><i data-lucide="package"></i></div><div style="flex:1"><strong>Composer is not installed</strong><div class="muted" style="font-size:12px">Install the Ubuntu package and its required PHP components.</div></div><button class="btn btn-primary" id="phpComposerInstall"><i data-lucide="download"></i>Install Composer</button></div>
      <?php endif; ?>
      <div class="muted" style="font-size:12px;margin-top:12px">Nebula does not run a global Composer update because each website has its own dependency constraints and execution user.</div>
    </div>
  </section>

  <section data-tab-panel id="php-health" class="card hidden">
    <div class="card-header"><h3>Runtime health</h3><span class="muted">PHP <?= e($sel) ?></span></div>
    <div class="card-pad grid grid-3" style="gap:14px">
      <?php $selectedSummary = current(array_filter($summaries, fn($summary) => $summary['version'] === $sel)) ?: []; $selectedSupport = $selectedSummary['support'] ?? php_support_status($sel); ?>
      <?php $fpmOk = ($selectedSummary['status'] ?? '') === 'active'; ?><div class="service-row"><div class="svc-icon"><i data-lucide="<?= $fpmOk ? 'heart-pulse' : 'triangle-alert' ?>" style="color:<?= $fpmOk ? 'var(--emerald-400)' : 'var(--orange-400)' ?>"></i></div><div><strong>PHP-FPM <?= $fpmOk ? 'running' : 'not running' ?></strong><div class="muted" style="font-size:12px"><?= $fpmOk ? 'Serving requests normally' : 'Start php' . e($sel) . '-fpm from Services' ?></div></div></div>
      <?php $supportKey = (string) ($selectedSupport['key'] ?? ''); ?><div class="service-row"><div class="svc-icon"><i data-lucide="<?= $supportKey === 'active' ? 'shield-check' : 'shield-alert' ?>" style="color:<?= $supportKey === 'active' ? 'var(--emerald-400)' : ($supportKey === 'security' ? 'var(--orange-400)' : 'var(--red-400)') ?>"></i></div><div><strong><?= e((string) $selectedSupport['label']) ?></strong><div class="muted" style="font-size:12px"><?= !empty($selectedSupport['until']) ? 'Supported until ' . e(fmt_datetime($selectedSupport['until'], false)) . ($supportKey !== 'active' ? ' — consider PHP ' . e(php_latest_version()) : '') : 'Upgrade this runtime to PHP ' . e(php_latest_version()) ?></div></div></div>
      <div class="service-row"><div class="svc-icon"><i data-lucide="rocket" style="color:<?= $opcacheEnabled ? 'var(--emerald-400)' : 'var(--orange-400)' ?>"></i></div><div><strong>OPcache <?= $opcacheEnabled ? 'enabled' : 'disabled' ?></strong><div class="muted" style="font-size:12px"><?= (string) ($settings['opcache.memory_consumption'] ?? '') !== '' ? e((string) $settings['opcache.memory_consumption']) . ' MB shared memory' : ($opcacheEnabled ? 'Default shared memory size' : 'Enable it for faster PHP websites') ?></div></div></div>
      <?php $displayErrors = in_array(strtolower((string) ($settings['display_errors'] ?? '')), ['1','on','true','yes'], true); ?><div class="service-row"><div class="svc-icon"><i data-lucide="bug" style="color:<?= $displayErrors ? 'var(--orange-400)' : 'var(--emerald-400)' ?>"></i></div><div><strong>Display errors <?= $displayErrors ? 'on' : 'off' ?></strong><div class="muted" style="font-size:12px"><?= $displayErrors ? 'Turn this off on production websites' : 'Errors are hidden from visitors' ?></div></div></div>
      <div class="service-row"><div class="svc-icon"><i data-lucide="package"></i></div><div><strong>Composer</strong><div class="muted" style="font-size:12px"><?= e($composer !== '' ? $composer : 'Not installed') ?></div></div></div>
      <div class="service-row"><div class="svc-icon"><i data-lucide="globe"></i></div><div><strong><?= (int) ($selectedSummary['sites'] ?? 0) ?> assigned websites</strong><div class="muted" style="font-size:12px">Using PHP <?= e($sel) ?> through FPM</div></div></div>
    </div>
  </section>
</div>

<div class="card hidden" id="phpOutputCard" style="margin-top:16px">
  <div class="card-header"><h3>Operation output</h3><button class="btn btn-secondary btn-sm" type="button" id="phpOutputClose"><i data-lucide="x"></i>Close</button></div>
  <pre class="mono log-pre" id="phpOutput"></pre>
</div>

<link rel="stylesheet" href="<?= e(asset('vendor/codemirror-5.65.16.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/codemirror-material-darker-5.65.16.min.css')) ?>">
<script src="<?= e(asset('vendor/codemirror-5.65.16.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/codemirror-properties-5.65.16.min.js')) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, streamPost, toast } = window.Nebula;
  const version = <?= json_encode($sel) ?>;
  const outputCard = document.getElementById('phpOutputCard');
  const output = document.getElementById('phpOutput');
  let iniEditor = null;
  const resetOutput = () => { outputCard?.classList.remove('hidden'); if (output) output.textContent = ''; };
  const appendOutput = (text) => { if (!output || !text) return; output.textContent += text; output.scrollTop = output.scrollHeight; };

  document.querySelectorAll('#phpManagerTabs [data-tab-target]').forEach((tab) => tab.addEventListener('click', () => {
    history.replaceState(null, '', '#'+tab.dataset.tabTarget);
    if (tab.dataset.tabTarget === 'php-ini' && iniEditor) setTimeout(() => iniEditor.refresh(), 0);
  }));
  const initial = location.hash.slice(1);
  if (initial && document.getElementById(initial)) document.querySelector(`#phpManagerTabs [data-tab-target="${CSS.escape(initial)}"]`)?.click();

  document.getElementById('phpInstallToggle')?.addEventListener('click', () => document.getElementById('phpInstallCard')?.classList.toggle('hidden'));
  document.getElementById('phpOutputClose')?.addEventListener('click', () => outputCard?.classList.add('hidden'));

  const runStream = async (button, body, busyText) => {
    const original = button.innerHTML; button.disabled = true; button.textContent = busyText; resetOutput();
    const result = await streamPost('php', body, (event) => { if (event.type === 'output') appendOutput(event.text); });
    if (result.ok) { toast('Operation completed', 'success'); setTimeout(() => location.reload(), 700); }
    else { toast(result.error || 'Operation failed', 'error'); appendOutput((result.error || 'Operation failed')+'\n'); button.disabled = false; button.innerHTML = original; if (window.lucide) lucide.createIcons(); }
  };
  document.getElementById('phpInstallRun')?.addEventListener('click', function () { runStream(this, {action:'install', version:document.getElementById('phpInstallVersion').value}, 'Installing…'); });
  document.getElementById('phpComposerInstall')?.addEventListener('click', function () { runStream(this, {action:'composer_install'}, 'Installing…'); });
  document.querySelectorAll('[data-extension]').forEach((button) => button.addEventListener('click', function () { runStream(this, {action:'extension', version, extension:this.dataset.extension, operation:this.dataset.operation}, `${this.dataset.operation}…`); }));

  document.querySelectorAll('[data-php-restart]').forEach((button) => button.addEventListener('click', async function () {
    const target = this.dataset.phpRestart; this.disabled = true;
    const result = await apiPost('php', {action:'restart', version:target});
    toast(result.ok ? `PHP-FPM ${target === 'all' ? 'services' : target} restart scheduled` : (result.error || 'Restart failed'), result.ok ? 'success' : 'error');
    this.disabled = false;
  }));

  document.querySelectorAll('[data-site-php]').forEach((select) => select.addEventListener('change', async function () {
    this.disabled = true;
    const result = await apiPost('sites', {action:'php', domain:this.dataset.sitePhp, version:this.value});
    if (result.ok) { this.dataset.original = this.value; toast(`${this.dataset.sitePhp} now uses PHP ${this.value}`, 'success'); }
    else { this.value = this.dataset.original; toast(result.error || 'Could not switch PHP version', 'error'); }
    this.disabled = false;
  }));

  const saveFields = async (button, selector) => {
    const changed = [...document.querySelectorAll(selector)].filter((field) => field.value !== field.dataset.original);
    if (!changed.length) { toast('No settings changed', 'info'); return; }
    button.disabled = true; let saved = 0;
    for (const field of changed) {
      const result = await apiPost('php', {action:'set', version, key:field.dataset.phpKey, value:field.value});
      if (result.ok) { field.dataset.original = field.value; saved++; }
      else toast(`${field.dataset.phpKey}: ${result.error || 'Save failed'}`, 'error');
    }
    if (saved) toast(`${saved} setting${saved === 1 ? '' : 's'} saved; reload scheduled`, 'success');
    button.disabled = false;
  };
  document.getElementById('phpSaveSettings')?.addEventListener('click', function () { saveFields(this, '#php-settings [data-php-key]'); });
  document.getElementById('phpSaveOpcache')?.addEventListener('click', function () { saveFields(this, '#php-opcache [data-opcache-setting]'); });
  document.getElementById('phpSaveXdebug')?.addEventListener('click', function () { saveFields(this, '#php-xdebug [data-xdebug-setting]'); });
  document.getElementById('phpResetOpcache')?.addEventListener('click', async function () {
    this.disabled = true; const result = await apiPost('php', {action:'opcache_reset', version});
    toast(result.ok ? 'OPcache reset scheduled through an FPM restart' : (result.error || 'Could not reset OPcache'), result.ok ? 'success' : 'error');
    this.disabled = false;
  });

  const iniTextarea = document.getElementById('phpIniContent');
  if (iniTextarea && window.CodeMirror) {
    iniEditor = CodeMirror.fromTextArea(iniTextarea, {mode:'text/x-properties',theme:window.Nebula.cmTheme(),lineNumbers:true,lineWrapping:false,indentUnit:2,viewportMargin:20});
    window.Nebula.registerCM(iniEditor);
    iniEditor.setSize('100%', '65vh');
    if (location.hash === '#php-ini') setTimeout(() => iniEditor.refresh(), 0);
  }
  document.getElementById('phpIniSave')?.addEventListener('click', async function () {
    this.disabled = true;
    const result = await apiPost('php', {action:'ini_save', version, content:iniEditor ? iniEditor.getValue() : iniTextarea.value});
    toast(result.ok ? 'php.ini validated and saved; reload scheduled' : (result.error || 'Could not save php.ini'), result.ok ? 'success' : 'error');
    this.disabled = false;
  });
  document.getElementById('phpIniRestore')?.addEventListener('click', async function () {
    if (!await window.Nebula.confirm({ title: 'Restore the previous php.ini?', message: 'The current file is kept as the next backup.', warning: true, icon: 'undo-2', confirmLabel: 'Restore' })) return;
    this.disabled = true; const result = await apiPost('php', {action:'ini_restore', version});
    toast(result.ok ? 'Previous php.ini restored' : (result.error || 'Restore failed'), result.ok ? 'success' : 'error');
    if (result.ok) setTimeout(() => location.reload(), 600); else this.disabled = false;
  });
});
</script>
<?php endif; ?>
