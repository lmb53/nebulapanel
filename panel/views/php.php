<?php
require_once APP_ROOT . '/lib/mod_apps.php';
require_once APP_ROOT . '/lib/mod_php.php';

$versions = php_installed_versions();

// Selected version: validate ?version= against installed, default to first.
$sel = (string) ($_GET['version'] ?? '');
if (!in_array($sel, $versions, true)) {
    $sel = $versions[0] ?? '';
}

$settings = $sel !== '' ? php_read_settings($sel) : [];
$modules  = $sel !== '' ? php_modules($sel) : [];

// Field metadata for the whitelisted ini keys.
$fieldLabels = [
    'memory_limit'        => 'Memory Limit',
    'upload_max_filesize' => 'Upload Max Filesize',
    'post_max_size'       => 'Post Max Size',
    'max_execution_time'  => 'Max Execution Time',
    'max_input_time'      => 'Max Input Time',
    'max_input_vars'      => 'Max Input Vars',
    'display_errors'      => 'Display Errors',
];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">PHP</h1>
    <p class="page-subtitle">
      <?php if ($versions): ?>
        <?= count($versions) ?> version<?= count($versions) === 1 ? '' : 's' ?> installed · Selected: <span class="mono"><?= e($sel) ?></span>
      <?php else: ?>
        Manage installed PHP versions and settings
      <?php endif; ?>
    </p>
  </div>
</div>

<?php if (!$versions): ?>
  <div class="card"><div class="empty-state">
    <div class="es-icon"><i data-lucide="code-2"></i></div>
    <div style="font-weight:600;color:var(--text-secondary)">No PHP versions detected</div>
    <div style="font-size:13px;margin-top:4px">Install one from <a class="mono" href="?r=apps">Install Apps</a> to manage its settings and extensions.</div>
  </div></div>
<?php else: ?>

  <!-- Version selector -->
  <div class="card" style="margin-bottom:16px">
    <div class="tabs" id="phpTabs">
      <?php foreach ($versions as $v): ?>
        <a class="tab <?= $v === $sel ? 'active' : '' ?>" href="?r=php&amp;version=<?= e(urlencode($v)) ?>" style="font-family:var(--font-mono);text-decoration:none">PHP <?= e($v) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Settings -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-header">
      <h3>Settings — PHP <?= e($sel) ?></h3>
      <button class="btn btn-primary btn-sm" id="phpSaveAll"><i data-lucide="save"></i>Save all</button>
    </div>
    <div class="card-pad">
      <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px">
        <?php foreach ($fieldLabels as $key => $label): ?>
          <?php $val = (string) ($settings[$key] ?? ''); ?>
          <div>
            <label class="field-label" for="phpf_<?= e($key) ?>"><?= e($label) ?> <span class="mono text-tertiary" style="font-size:11px">(<?= e($key) ?>)</span></label>
            <?php if ($key === 'display_errors'): ?>
              <?php $on = in_array(strtolower($val), ['on', '1', 'true', 'yes'], true); ?>
              <select class="input" id="phpf_<?= e($key) ?>" data-php-key="<?= e($key) ?>" data-php-orig="<?= $on ? 'On' : 'Off' ?>">
                <option value="On" <?= $on ? 'selected' : '' ?>>On</option>
                <option value="Off" <?= !$on ? 'selected' : '' ?>>Off</option>
              </select>
            <?php else: ?>
              <input class="input mono" id="phpf_<?= e($key) ?>" data-php-key="<?= e($key) ?>" data-php-orig="<?= e($val) ?>" value="<?= e($val) ?>" autocomplete="off">
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="font-size:12px;color:var(--text-tertiary);margin-top:14px">
        Changes are written to both FPM and CLI <span class="mono">php.ini</span> and <span class="mono">php<?= e($sel) ?>-fpm</span> is reloaded. Values may contain letters, digits, <span class="mono">.</span> and <span class="mono">_</span> only.
      </div>
    </div>
  </div>

  <!-- Loaded extensions -->
  <div class="card">
    <div class="card-header">
      <h3>Loaded extensions</h3>
      <span class="muted"><?= count($modules) ?> loaded</span>
    </div>
    <div class="card-pad">
      <?php if (!$modules): ?>
        <div class="text-tertiary" style="font-size:13px">No extensions reported (could not run <span class="mono">php<?= e($sel) ?> -m</span>).</div>
      <?php else: ?>
        <div style="display:flex;flex-wrap:wrap;gap:8px">
          <?php foreach ($modules as $m): ?>
            <span class="chip mono"><?= e($m) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const { apiPost, toast } = window.Nebula;
    const version = <?= json_encode($sel) ?>;

    document.getElementById('phpSaveAll')?.addEventListener('click', async () => {
      const fields = Array.from(document.querySelectorAll('[data-php-key]'));
      const changed = fields.filter((f) => f.value !== f.getAttribute('data-php-orig'));
      if (!changed.length) { toast('No changes to save', 'info'); return; }

      let ok = 0;
      for (const f of changed) {
        const res = await apiPost('php', {
          action: 'set',
          version,
          key: f.getAttribute('data-php-key'),
          value: f.value,
        });
        if (res.ok) { ok++; f.setAttribute('data-php-orig', f.value); }
        else { toast((f.getAttribute('data-php-key')) + ': ' + (res.error || 'Failed'), 'error'); }
      }
      if (ok) {
        toast(ok + ' setting' + (ok === 1 ? '' : 's') + ' saved · PHP-FPM ' + version + ' reloaded', 'success');
        setTimeout(() => location.reload(), 600);
      }
    });
  });
  </script>
<?php endif; ?>
