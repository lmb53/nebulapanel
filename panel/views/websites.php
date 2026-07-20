<?php
require_once APP_ROOT . '/lib/mod_sites.php';
require_once APP_ROOT . '/lib/files.php';
$available = sites_available();
$sites = sites_list();
$phpv = $available ? php_versions() : [];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Websites</h1>
    <p class="page-subtitle"><?= count($sites) ?> site<?= count($sites) === 1 ? '' : 's' ?> configured</p>
  </div>
  <?php if ($available): ?>
  <div class="page-actions">
    <button class="btn btn-primary" id="wsToggle"><i data-lucide="plus"></i>Add Website</button>
  </div>
  <?php endif; ?>
</div>

<?php if (!$available): ?>
  <div class="card"><div class="empty-state">
    <div class="es-icon"><i data-lucide="globe"></i></div>
    <div style="font-weight:600;color:var(--text-secondary)">Privileged helper not installed</div>
    <div style="font-size:13px;margin-top:4px">Re-run <span class="mono">install.sh</span> to enable website management.</div>
  </div></div>
<?php else: ?>
  <div class="card hidden" id="wsForm" style="margin-bottom:16px">
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

  <div class="flex items-center gap-2" style="margin-bottom:16px;flex-wrap:wrap">
    <span class="chip active" data-ws-filter="all">All · <?= count($sites) ?></span>
    <span class="chip" data-ws-filter="ssl">HTTPS · <?= count(array_filter($sites, fn($s) => !empty($s['ssl']))) ?></span>
    <span class="chip" data-ws-filter="nossl">No SSL · <?= count(array_filter($sites, fn($s) => empty($s['ssl']))) ?></span>
  </div>

  <?php if (!$sites): ?>
    <div class="card"><div class="empty-state" id="wsEmpty">
      <div class="es-icon"><i data-lucide="globe"></i></div>
      <div style="font-weight:600;color:var(--text-secondary)">No sites yet</div>
      <div style="font-size:13px;margin-top:4px">Click <span class="mono">Add Website</span> to create your first site.</div>
    </div></div>
  <?php else: ?>
    <div class="grid grid-3" id="wsGrid" style="margin-bottom:24px">
      <?php foreach ($sites as $s): ?>
        <?php
          $domain = (string) ($s['domain'] ?? '');
          $docroot = (string) ($s['docroot'] ?? '');
          $php = (string) ($s['php'] ?? '');
          $ssl = !empty($s['ssl']);
          $url = ($ssl ? 'https://' : 'http://') . $domain;
          $filesPath = fm_link_path($docroot);
        ?>
        <div class="card" data-ws-card data-ws-ssl-state="<?= $ssl ? 'ssl' : 'nossl' ?>" style="padding:16px">
          <div class="flex items-center gap-3" style="margin-bottom:12px">
            <div style="width:36px;height:36px;border-radius:9px;background:rgba(59,130,246,.12);display:flex;align-items:center;justify-content:center;flex-shrink:0"><i data-lucide="globe" style="width:17px;height:17px;color:var(--blue-400)"></i></div>
            <div style="flex:1;min-width:0">
              <div style="font-weight:700;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <a href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($domain) ?></a>
              </div>
              <div class="mono" style="font-size:11.5px;color:var(--text-tertiary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($docroot) ?></div>
            </div>
          </div>
          <div class="flex gap-2" style="margin-bottom:14px;flex-wrap:wrap">
            <?php if ($php !== ''): ?><span class="badge badge-slate">PHP <?= e($php) ?></span><?php endif; ?>
            <?php if ($ssl): ?>
              <span class="badge badge-emerald"><span class="bdot"></span>HTTPS</span>
            <?php else: ?>
              <span class="badge badge-slate">No SSL</span>
            <?php endif; ?>
          </div>
          <div class="flex items-center gap-1" style="border-top:1px solid var(--border-subtle);padding-top:10px">
            <a class="icon-btn" href="<?= e($url) ?>" target="_blank" rel="noopener" title="Visit"><i data-lucide="external-link"></i></a>
            <?php if ($filesPath !== null): ?>
              <a class="icon-btn" href="<?= e(url('files', ['path' => $filesPath])) ?>" title="Explore website files"><i data-lucide="folder-open"></i></a>
            <?php else: ?>
              <button class="icon-btn" disabled title="Document root is outside the File Manager root or does not exist"><i data-lucide="folder-x"></i></button>
            <?php endif; ?>
            <?php if (!$ssl): ?>
              <button class="icon-btn" data-ws-ssl="<?= e($domain) ?>" title="Issue SSL"><i data-lucide="shield"></i></button>
            <?php endif; ?>
            <div class="topbar-spacer" style="flex:1"></div>
            <button class="icon-btn" data-ws-del="<?= e($domain) ?>" title="Delete"><i data-lucide="trash-2"></i></button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="card hidden" id="wsNoMatch"><div class="empty-state">
      <div class="es-icon"><i data-lucide="globe"></i></div>
      <div style="font-weight:600;color:var(--text-secondary)">No matching sites</div>
    </div></div>
  <?php endif; ?>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const { apiPost, toast } = window.Nebula;

    const form = document.getElementById('wsForm');
    document.getElementById('wsToggle')?.addEventListener('click', () => {
      form?.classList.toggle('hidden');
      if (form && !form.classList.contains('hidden')) document.getElementById('wsDomain')?.focus();
    });

    document.querySelectorAll('[data-ws-filter]').forEach((chip) => {
      chip.addEventListener('click', () => {
        const f = chip.getAttribute('data-ws-filter');
        document.querySelectorAll('[data-ws-filter]').forEach((c) => c.classList.toggle('active', c === chip));
        let visible = 0;
        document.querySelectorAll('[data-ws-card]').forEach((card) => {
          const state = card.getAttribute('data-ws-ssl-state');
          const show = f === 'all' || f === state;
          card.classList.toggle('hidden', !show);
          if (show) visible++;
        });
        document.getElementById('wsNoMatch')?.classList.toggle('hidden', visible !== 0);
      });
    });

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
        if (res.ok) { toast('Deleted', 'success'); btn.closest('[data-ws-card]').remove(); }
        else toast(res.error || 'Failed', 'error');
      });
    });
  });
  </script>
<?php endif; ?>
