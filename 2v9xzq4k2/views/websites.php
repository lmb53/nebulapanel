<?php
require_once APP_ROOT . '/lib/mod_sites.php';
$available = sites_available();
$sites = sites_list();
$phpv = $available ? php_versions() : [];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Websites</h1>
    <p class="page-subtitle"><?= count($sites) ?> site<?= count($sites) === 1 ? '' : 's' ?> configured</p>
  </div>
</div>

<?php if (!$available): ?>
  <div class="card"><div class="empty-state">
    <div class="es-icon"><i data-lucide="globe"></i></div>
    <div style="font-weight:600;color:var(--text-secondary)">Privileged helper not installed</div>
    <div style="font-size:13px;margin-top:4px">Re-run <span class="mono">install.sh</span> to enable website management.</div>
  </div></div>
<?php else: ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3>Add website</h3></div>
    <div class="card-pad">
      <div class="grid" style="grid-template-columns:1fr 1fr auto auto auto;gap:12px;align-items:end">
        <div>
          <label class="field-label">Domain</label>
          <input class="input mono" id="wsDomain" placeholder="example.com">
        </div>
        <div>
          <label class="field-label">Document root</label>
          <input class="input mono" id="wsDocroot" placeholder="/var/www/example.com">
        </div>
        <div>
          <label class="field-label">PHP</label>
          <select class="input" id="wsPhp">
            <?php if (!$phpv): ?>
              <option value="" disabled selected>no PHP-FPM found</option>
            <?php else: ?>
              <?php foreach ($phpv as $v): ?>
                <option value="<?= e($v) ?>"><?= e($v) ?></option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </div>
        <div>
          <label class="field-label">SSL email</label>
          <input class="input mono" id="wsEmail" placeholder="optional">
        </div>
        <button class="btn btn-primary" id="wsCreate"><i data-lucide="plus"></i>Add Website</button>
      </div>
      <div style="font-size:12px;color:var(--text-tertiary);margin-top:10px">
        Document root defaults to <span class="mono">/var/www/&lt;domain&gt;</span> when left blank.
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Sites</h3></div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Domain</th><th>Doc root</th><th>PHP</th><th>SSL</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody id="wsBody">
          <?php foreach ($sites as $s): ?>
            <?php
              $domain = (string) ($s['domain'] ?? '');
              $docroot = (string) ($s['docroot'] ?? '');
              $php = (string) ($s['php'] ?? '');
              $ssl = !empty($s['ssl']);
            ?>
            <tr data-ws-row="<?= e($domain) ?>">
              <td style="font-weight:600">
                <a href="<?= e(($ssl ? 'https://' : 'http://') . $domain) ?>" target="_blank" rel="noopener"><?= e($domain) ?></a>
              </td>
              <td class="mono" style="font-size:12.5px"><?= e($docroot) ?></td>
              <td><span class="badge badge-blue"><?= e($php) ?></span></td>
              <td>
                <?php if ($ssl): ?>
                  <span class="badge badge-emerald"><span class="bdot"></span>HTTPS</span>
                <?php else: ?>
                  <button class="btn btn-secondary btn-sm" data-ws-ssl="<?= e($domain) ?>"><i data-lucide="shield"></i>Issue SSL</button>
                <?php endif; ?>
              </td>
              <td style="text-align:right">
                <a class="btn btn-secondary btn-sm" href="<?= e(($ssl ? 'https://' : 'http://') . $domain) ?>" target="_blank" rel="noopener"><i data-lucide="external-link"></i>Visit</a>
                <?php if (!$ssl): ?>
                  <button class="btn btn-secondary btn-sm" data-ws-ssl="<?= e($domain) ?>"><i data-lucide="shield"></i>Issue SSL</button>
                <?php endif; ?>
                <button class="btn btn-danger btn-sm" data-ws-del="<?= e($domain) ?>"><i data-lucide="trash-2"></i></button>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$sites): ?>
            <tr><td colspan="5" class="text-tertiary" style="text-align:center;padding:24px">No sites yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const { apiPost, toast } = window.Nebula;

    document.getElementById('wsCreate')?.addEventListener('click', async () => {
      const domain = document.getElementById('wsDomain').value.trim();
      const docroot = document.getElementById('wsDocroot').value.trim();
      const php = document.getElementById('wsPhp').value;
      if (!domain) { toast('Enter a domain', 'warning'); return; }
      if (!php) { toast('No PHP version available', 'warning'); return; }
      const res = await apiPost('sites', { action: 'create', domain, docroot, php });
      if (res.ok) { toast('Website added', 'success'); setTimeout(() => location.reload(), 500); }
      else toast(res.error || 'Failed', 'error');
    });

    document.querySelectorAll('[data-ws-ssl]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const domain = btn.getAttribute('data-ws-ssl');
        if (!confirm('Issue a Let’s Encrypt certificate for ' + domain + '?')) return;
        const email = document.getElementById('wsEmail').value.trim();
        const res = await apiPost('sites', { action: 'ssl', domain, email });
        if (res.ok) { toast('SSL issued', 'success'); setTimeout(() => location.reload(), 500); }
        else toast(res.error || 'Failed', 'error');
      });
    });

    document.querySelectorAll('[data-ws-del]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const domain = btn.getAttribute('data-ws-del');
        if (!confirm('Delete ' + domain + '? (config removed; files kept)')) return;
        const res = await apiPost('sites', { action: 'delete', domain, purge: false });
        if (res.ok) { toast('Deleted', 'success'); btn.closest('tr').remove(); }
        else toast(res.error || 'Failed', 'error');
      });
    });
  });
  </script>
<?php endif; ?>
