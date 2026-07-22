<?php
require_once APP_ROOT . '/lib/mod_sites.php';
require_once APP_ROOT . '/lib/mod_git.php';
require_once APP_ROOT . '/lib/files.php';
$available = sites_available();
$sites = $available ? sites_with_runtime() : sites_list();
$phpv = $available ? php_versions() : [];
$gitAvailable = git_available();
$nginxStatus = service_status('nginx');
$apacheStatus = service_status('apache2');
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
  <div class="grid grid-3" style="margin-bottom:16px">
    <a class="stat-card" href="<?= e(url('service', ['name' => 'nginx'])) ?>" style="color:inherit">
      <div class="stat-top"><div class="stat-icon" style="background:rgba(16,185,129,.12)"><i data-lucide="server-cog" style="color:var(--emerald-400)"></i></div><span class="badge <?= $nginxStatus === 'active' ? 'badge-emerald' : 'badge-slate' ?>"><span class="bdot"></span><?= e(ucfirst($nginxStatus)) ?></span></div>
      <div class="stat-val" style="font-size:18px">Nginx</div><div class="stat-label">Underlying reverse proxy / web server</div>
    </a>
    <a class="stat-card" href="<?= e(url('service', ['name' => 'apache2'])) ?>" style="color:inherit">
      <div class="stat-top"><div class="stat-icon" style="background:rgba(245,158,11,.12)"><i data-lucide="server" style="color:var(--orange-400)"></i></div><span class="badge <?= $apacheStatus === 'active' ? 'badge-emerald' : 'badge-slate' ?>"><span class="bdot"></span><?= e(ucfirst($apacheStatus)) ?></span></div>
      <div class="stat-val" style="font-size:18px">Apache</div><div class="stat-label">Available underlying HTTP service</div>
    </a>
    <div class="stat-card">
      <div class="stat-top"><div class="stat-icon" style="background:rgba(59,130,246,.12)"><i data-lucide="hard-drive" style="color:var(--blue-400)"></i></div></div>
      <div class="stat-val" style="font-size:18px"><?= e(human_bytes(array_sum(array_map(fn($site) => (int) ($site['disk_used'] ?? 0), $sites)))) ?></div><div class="stat-label">Disk used by tracked document roots</div>
    </div>
  </div>

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
          $server = (string) ($s['server'] ?? 'nginx');
          $service = (string) ($s['service'] ?? 'unknown');
          $diskUsed = (int) ($s['disk_used'] ?? 0);
          $diskTotal = (int) ($s['disk_total'] ?? 0);
          $diskPct = $diskTotal > 0 ? min(100, ($diskUsed / $diskTotal) * 100) : 0;
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
            <span class="badge <?= $service === 'active' ? 'badge-emerald' : 'badge-slate' ?>"><span class="bdot"></span><?= e($server === 'apache2' ? 'Apache' : 'Nginx') ?> · <?= e($service) ?></span>
            <?php if ($ssl): ?>
              <span class="badge badge-emerald"><span class="bdot"></span>HTTPS</span>
            <?php else: ?>
              <span class="badge badge-slate">No SSL</span>
            <?php endif; ?>
            <?php if (!empty($s['git']) && is_array($s['git'])): ?>
              <span class="badge badge-purple" title="<?= e((string) ($s['git']['url'] ?? '')) ?>"><i data-lucide="git-branch" style="width:11px;height:11px;vertical-align:-1px"></i> <?= e((string) ($s['git']['branch'] ?? 'git')) ?></span>
            <?php endif; ?>
          </div>
          <div style="font-size:11.5px;color:var(--text-tertiary);margin-bottom:4px">Disk used · <?= e(human_bytes($diskUsed)) ?><?= $diskTotal ? ' of ' . e(human_bytes($diskTotal)) . ' filesystem' : '' ?></div>
          <div class="progress" style="margin-bottom:8px"><div style="width:<?= e(number_format($diskPct, 1, '.', '')) ?>%;background:var(--blue-500)"></div></div>
          <div class="flex items-center gap-3" style="font-size:11.5px;color:var(--text-tertiary);margin-bottom:12px"><span><i data-lucide="files" style="width:12px;height:12px;vertical-align:-2px"></i> <?= (int) ($s['file_count'] ?? 0) ?> files</span><?php if ($diskTotal): ?><span><?= e(human_bytes((int) ($s['disk_free'] ?? 0))) ?> free</span><?php endif; ?></div>
          <div class="flex items-center gap-1" style="border-top:1px solid var(--border-subtle);padding-top:10px">
            <a class="icon-btn" href="<?= e($url) ?>" target="_blank" rel="noopener" title="Visit"><i data-lucide="external-link"></i></a>
            <?php if ($filesPath !== null): ?>
              <a class="icon-btn" href="<?= e(url('files', ['path' => $filesPath])) ?>" title="Explore website files"><i data-lucide="folder-open"></i></a>
            <?php else: ?>
              <button class="icon-btn" disabled title="Document root is outside the File Manager root or does not exist"><i data-lucide="folder-x"></i></button>
            <?php endif; ?>
            <a class="icon-btn" href="<?= e(url('databases', ['website' => $domain])) ?>" title="Databases"><i data-lucide="database"></i></a>
            <a class="icon-btn" href="<?= e(url('logs')) ?>" title="Logs"><i data-lucide="scroll-text"></i></a>
            <?php if ($gitAvailable): ?>
              <button class="icon-btn" data-ws-git="<?= e($domain) ?>"<?= !empty($s['git']) ? ' style="color:var(--purple-400)"' : '' ?> title="Git deployment"><i data-lucide="git-branch"></i></button>
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
        if (!confirm('Delete ' + domain + '?\n\nThis removes the Nginx config, the DNS zone, SSL certificates, AND the website folder with all its files. This cannot be undone.')) return;
        const res = await apiPost('sites', { action: 'delete', domain, purge: true });
        if (res.ok) { toast('Deleted', 'success'); btn.closest('[data-ws-card]').remove(); }
        else toast(res.error || 'Failed', 'error');
      });
    });
  });
  </script>

  <?php if ($gitAvailable): ?>
  <div class="drawer-overlay hidden" id="wsGitDrawer"><div class="drawer" style="width:min(560px,96vw)">
    <div class="drawer-header"><div><strong id="wsGitTitle">Git deployment</strong><div class="muted mono" id="wsGitDomain" style="font-size:11px"></div></div><button class="icon-btn" data-close-git><i data-lucide="x"></i></button></div>
    <div class="drawer-body"><div class="form-stack">
      <div id="wsGitStatus" class="muted" style="font-size:12.5px">Loading…</div>
      <div><label class="field-label">Repository URL</label><input class="input mono" id="wsGitUrl" placeholder="https://github.com/user/repo.git" autocomplete="off"><div class="field-help">Public repositories work as-is. For a private repo embed a token: <span class="mono">https://user:token@host/repo.git</span></div></div>
      <div><label class="field-label">Branch</label><input class="input mono" id="wsGitBranch" value="main" autocomplete="off"></div>
      <div class="notice notice-warning" style="font-size:12px"><i data-lucide="alert-triangle"></i><div>Connecting or pulling force-updates tracked files in the document root to match the repository. Uncommitted local changes there are discarded.</div></div>
      <pre class="mono hidden" id="wsGitOut" style="margin:0;padding:12px;font-size:12px;line-height:1.5;white-space:pre-wrap;max-height:28vh;overflow:auto;background:var(--bg-surface-2);border-radius:8px"></pre>
    </div></div>
    <div class="drawer-footer"><button class="btn btn-ghost" id="wsGitDisconnect" style="margin-right:auto;color:var(--red-400)">Disconnect</button><button class="btn btn-secondary" id="wsGitPull"><i data-lucide="refresh-cw"></i>Pull latest</button><button class="btn btn-primary" id="wsGitConnect"><i data-lucide="git-branch"></i>Connect</button></div>
  </div></div>
  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const { apiGet, apiPost, streamPost, toast } = window.Nebula;
    const $ = (id) => document.getElementById(id);
    const drawer = $('wsGitDrawer'); if (!drawer) return;
    let domain = '';
    const esc = (t) => String(t == null ? '' : t).replace(/[&<>"]/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]));
    document.querySelectorAll('[data-ws-git]').forEach((b) => b.addEventListener('click', () => openGit(b.getAttribute('data-ws-git'))));
    document.querySelectorAll('[data-close-git]').forEach((b) => (b.onclick = () => drawer.classList.add('hidden')));
    drawer.addEventListener('click', (e) => { if (e.target === drawer) drawer.classList.add('hidden'); });

    async function openGit(d) {
      domain = d; $('wsGitDomain').textContent = d;
      $('wsGitOut').classList.add('hidden'); $('wsGitOut').textContent = '';
      $('wsGitStatus').textContent = 'Loading…'; drawer.classList.remove('hidden');
      try { renderStatus(await apiGet('git&domain=' + encodeURIComponent(d))); }
      catch (e) { $('wsGitStatus').textContent = 'Could not load git status.'; }
    }
    function renderStatus(r) {
      const s = $('wsGitStatus');
      if (!r || r.available === false) { s.textContent = 'Git is not installed on the server.'; }
      else if (r.connected) {
        s.innerHTML = `Connected to <span class="mono">${esc(r.remote)}</span> · branch <strong>${esc(r.branch)}</strong>`
          + (r.commit ? `<br>Last commit <span class="mono">${esc(r.commit)}</span> ${esc(r.subject)}` : '')
          + (r.dirty ? '<br><span style="color:var(--orange-400)">Working tree has local changes.</span>' : '');
        if (r.remote) $('wsGitUrl').value = r.remote;
        if (r.branch) $('wsGitBranch').value = r.branch;
        $('wsGitDisconnect').style.display = ''; $('wsGitPull').style.display = '';
        $('wsGitConnect').innerHTML = '<i data-lucide="rotate-cw"></i>Reconnect';
      } else {
        s.textContent = 'Not connected. Enter a repository to deploy into this document root.';
        $('wsGitDisconnect').style.display = 'none'; $('wsGitPull').style.display = 'none';
        $('wsGitConnect').innerHTML = '<i data-lucide="git-branch"></i>Connect';
        const m = r.meta || {};
        if (m.url) $('wsGitUrl').value = m.url;
        if (m.branch) $('wsGitBranch').value = m.branch;
      }
      if (window.lucide) lucide.createIcons();
    }
    async function runStream(body, ok) {
      const out = $('wsGitOut'); out.classList.remove('hidden'); out.textContent = '';
      const r = await streamPost('git', body, (ev) => { if (ev.type === 'output') { out.textContent += ev.text; out.scrollTop = out.scrollHeight; } });
      toast(r.ok ? ok : (r.error || 'Git operation failed'), r.ok ? 'success' : 'error');
      if (r.ok) { try { renderStatus(await apiGet('git&domain=' + encodeURIComponent(domain))); } catch (e) {} }
      return r;
    }
    $('wsGitConnect').onclick = () => {
      const url = $('wsGitUrl').value.trim(), branch = $('wsGitBranch').value.trim() || 'main';
      if (!url) { toast('Enter a repository URL', 'warning'); return; }
      runStream({ action: 'connect', domain, url, branch }, 'Repository connected');
    };
    $('wsGitPull').onclick = () => runStream({ action: 'pull', domain }, 'Pulled latest changes');
    $('wsGitDisconnect').onclick = async () => {
      if (!confirm('Disconnect this repository? The files stay; only the .git link is removed.')) return;
      const r = await apiPost('git', { action: 'disconnect', domain, remove: true });
      toast(r.ok ? 'Disconnected' : (r.error || 'Failed'), r.ok ? 'success' : 'error');
      if (r.ok) { try { renderStatus(await apiGet('git&domain=' + encodeURIComponent(domain))); } catch (e) {} }
    };
  });
  </script>
  <?php endif; ?>
<?php endif; ?>
