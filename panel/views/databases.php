<?php
require_once APP_ROOT . '/lib/mod_db.php';
require_once APP_ROOT . '/lib/mod_sites.php';
require_once APP_ROOT . '/lib/mod_pma.php';
// The page is only usable when the database *server* is reachable, not merely
// when the client binary exists — otherwise every action fails at the socket.
$dbStatus = db_server_status();
$available = $dbStatus['ready'];
$sites = sites_list();
$pmaInstalled = pma_installed();
$selectedWebsite = (string) ($_GET['website'] ?? '');
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Databases</h1>
    <p class="page-subtitle">Website-owned databases and users, with secure phpMyAdmin access</p>
  </div>
  <?php if ($available): ?>
    <div class="page-actions">
      <?php if ($pmaInstalled): ?><a class="btn btn-secondary" href="<?= e(url('phpmyadmin')) ?>"><i data-lucide="table-properties"></i>phpMyAdmin</a><?php endif; ?>
      <button class="btn btn-primary" type="button" id="dbCreateToggle" aria-expanded="false" aria-controls="dbCreateCard"><i data-lucide="plus"></i>Create database</button>
    </div>
  <?php endif; ?>
</div>

<?php if (!$available): ?>
  <?php
  $dbHeadline = [
      'no-client' => 'MySQL/MariaDB client not found',
      'absent'    => 'No database server installed',
      'stopped'   => 'Database server is not running',
  ][$dbStatus['state']] ?? 'Database server unavailable';
  ?>
  <?php
  $dbActions = $dbStatus['state'] === 'stopped'
      ? [['label' => 'Open Services', 'href' => url('services'), 'icon' => 'server-cog', 'primary' => true]]
      : [['label' => 'Install MariaDB', 'href' => url('apps'), 'icon' => 'package-plus', 'primary' => true]];
  $dbActions[] = ['label' => 'Re-check', 'href' => url('databases'), 'icon' => 'refresh-cw'];
  ?>
  <?= requirement_missing('database', $dbHeadline, e($dbStatus['message']), $dbActions) ?>
<?php else: ?>
  <div class="card hidden" id="dbCreateCard" style="margin-bottom:16px">
    <div class="card-header"><div><h3>Create website database</h3><span class="muted">Every database is owned by one of your panel-managed websites.</span></div></div>
    <div class="card-pad">
      <div class="form-grid" style="--cols:minmax(0,1.2fr) minmax(0,1fr) minmax(0,.8fr) minmax(0,1fr) minmax(0,1.2fr) auto">
        <div><label class="field-label" for="dbName">Database name<span class="req" aria-hidden="true">*</span></label><input class="input mono" id="dbName" placeholder="site_database" autocomplete="off"></div>
        <div><label class="field-label" for="dbUser">User <span class="text-tertiary" style="font-weight:400">(optional)</span></label><input class="input mono" id="dbUser" placeholder="site_user" autocomplete="off"></div>
        <div><label class="field-label" for="dbHost">Host</label><input class="input mono" id="dbHost" value="localhost" autocomplete="off"></div>
        <div><label class="field-label" for="dbPassword">Password</label><input class="input mono" id="dbPassword" type="password" autocomplete="new-password"></div>
        <div><label class="field-label" for="dbWebsite">Website<span class="req" aria-hidden="true">*</span></label><select class="select" id="dbWebsite"><option value="">Select website…</option><?php foreach ($sites as $site): ?><?php $siteDomain=(string)($site['domain']??''); ?><option value="<?= e($siteDomain) ?>"<?= $siteDomain===$selectedWebsite?' selected':'' ?>><?= e($siteDomain) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-primary" type="button" id="dbCreate"><i data-lucide="database-zap"></i>Create</button>
      </div>
    </div>
  </div>

  <div class="grid grid-3" style="margin-bottom:16px">
    <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:color-mix(in srgb,var(--accent) 12%,transparent)"><i data-lucide="database" style="color:var(--blue-400)"></i></div></div><div class="stat-val" id="dbStatCount">–</div><div class="stat-label">User databases</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:color-mix(in srgb,var(--ok) 12%,transparent)"><i data-lucide="hard-drive" style="color:var(--emerald-400)"></i></div></div><div class="stat-val" id="dbStatSize">–</div><div class="stat-label">Total database size</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:color-mix(in srgb,var(--violet) 12%,transparent)"><i data-lucide="users" style="color:var(--purple-400)"></i></div></div><div class="stat-val" id="dbStatUsers">–</div><div class="stat-label">Database users</div></div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><div><h3>Databases</h3><span class="muted" id="dbEngine">Loading database service…</span></div><input class="input input-sm table-filter" type="search" placeholder="Filter databases…" aria-label="Filter databases" data-table-filter="#dbTable"></div>
    <div class="table-wrap"><table class="data-table" id="dbTable">
      <thead><tr><th>Database name</th><th>Engine</th><th>Owner website</th><th class="num">Size</th><th class="num">Tables</th><th>Collation</th><th>Users</th><th class="actions-col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody id="dbBody"><tr class="empty-row"><td colspan="8">Loading…</td></tr></tbody>
    </table></div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Database users</h3><div class="card-actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><input class="input input-sm mono" id="duUser" placeholder="user" aria-label="New user name" style="max-width:140px"><input class="input input-sm mono" id="duHost" value="localhost" aria-label="Host" style="max-width:115px"><input class="input input-sm mono" id="duPass" type="password" placeholder="password" aria-label="Password" autocomplete="new-password" style="max-width:160px"><select class="select" id="duGrant" style="max-width:190px"><option value="">No initial grant</option></select><button class="btn btn-primary btn-sm" id="duCreate"><i data-lucide="user-plus"></i>Create user</button></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>User</th><th>Host</th><th>Access</th><th class="actions-col"><span class="sr-only">Actions</span></th></tr></thead><tbody id="dbUserBody"><tr class="empty-row"><td colspan="4">Loading…</td></tr></tbody></table></div>
    <input type="file" id="dbImportFile" accept=".sql,.gz" style="display:none" tabindex="-1" aria-label="Database dump to import">
  </div>

  <script nonce="<?= e(csp_nonce()) ?>">
  document.addEventListener('DOMContentLoaded', () => {
    const { apiGet, apiPost, toast, fmtBytes } = window.Nebula;
    const pmaInstalled = <?= $pmaInstalled ? 'true' : 'false' ?>;
    let websites = <?= js_json(array_values(array_map(fn($s) => (string) ($s['domain'] ?? ''), $sites))) ?>;
    const make = (tag, cls, text) => { const el = document.createElement(tag); if (cls) el.className = cls; if (text !== undefined) el.textContent = text; return el; };

    async function openPma(database, button) {
      if (!pmaInstalled) { toast('Install phpMyAdmin first', 'warning'); return; }
      const popup = window.open('about:blank', '_blank');
      if (!popup) { toast('Allow pop-ups to open phpMyAdmin', 'warning'); return; }
      popup.opener = null;
      popup.document.title = 'Opening phpMyAdmin…';
      button.disabled = true;
      const res = await apiPost('pma', { action: 'launch', database });
      button.disabled = false;
      if (res.ok && res.url) popup.location.replace(res.url);
      else { popup.close(); toast(res.error || 'Could not create a phpMyAdmin session', 'error'); }
    }

    async function loadAll() {
      let data;
      try { data = await apiGet('databases'); } catch (e) { toast('Failed to load databases', 'error'); return; }
      websites = data.websites || websites;
      const dbs = data.databases || [], userDbs = dbs, users = data.users || [];
      document.getElementById('dbStatCount').textContent = userDbs.length;
      document.getElementById('dbStatSize').textContent = fmtBytes(userDbs.reduce((n, db) => n + Number(db.size || 0), 0));
      document.getElementById('dbStatUsers').textContent = users.length;
      document.getElementById('dbEngine').textContent = data.version ? `MariaDB / MySQL ${data.version}` : 'MariaDB / MySQL';

      const grant = document.getElementById('duGrant');
      grant.innerHTML = '<option value="">No initial grant</option>';
      userDbs.forEach((db) => { const o = make('option', '', db.name); o.value = db.name; grant.appendChild(o); });

      const body = document.getElementById('dbBody'); body.innerHTML = '';
      if (!dbs.length) { const tr=make('tr'); const td=make('td','text-tertiary','No databases yet. Create one and assign it to a website.'); td.colSpan=8; td.style.cssText='text-align:center;padding:28px'; tr.appendChild(td); body.appendChild(tr); }
      const engine = data.version ? (String(data.version).toLowerCase().includes('mariadb') ? 'MariaDB' : 'MySQL') : 'MySQL';
      dbs.forEach((db) => {
        const tr = make('tr');
        if (!db.system) tr.dataset.bulkId = db.name;
        const name = make('td'); const strong = make('div','mono',db.name); strong.style.fontWeight='700'; name.appendChild(strong); if (db.system) name.appendChild(make('span','badge badge-slate','system')); tr.appendChild(name);
        tr.appendChild(make('td','text-tertiary', engine));
        const siteTd=make('td'); const select=make('select','select'); select.setAttribute('aria-label','Website that owns ' + db.name); select.style.cssText='min-width:150px;padding:6px 28px 6px 9px'; const none=make('option','','Not linked'); none.value=''; none.selected=!db.website; select.appendChild(none); websites.forEach((site)=>{ const o=make('option','',site); o.value=site; o.selected=site===db.website; select.appendChild(o); }); select.addEventListener('change',async()=>{ const res=await apiPost('databases',{action:'link_website',database:db.name,website:select.value}); toast(res.ok?'Website link updated':(res.error||'Could not update link'),res.ok?'success':'error'); }); siteTd.appendChild(select); tr.appendChild(siteTd);
        const size = make('td','num mono text-tertiary',fmtBytes(db.size)); size.dataset.sortValue = db.size || 0; tr.appendChild(size);
        tr.appendChild(make('td','num mono text-tertiary',String(db.tables || 0)));
        tr.appendChild(make('td','mono text-tertiary',db.collation || '–'));
        const usersTd=make('td'); (db.users || []).slice(0,2).forEach((u)=>usersTd.appendChild(make('span','badge badge-slate',String(u).replace("'@'", '@')))); if ((db.users||[]).length>2) usersTd.appendChild(make('span','badge badge-slate',`+${db.users.length-2}`)); if (!(db.users||[]).length) usersTd.textContent='–'; tr.appendChild(usersTd);
        const actions=make('td','actions-col'); const wrap=make('div','flex gap-1'); wrap.style.justifyContent='flex-end';
        const iconBtn = (icon, title, fn, danger) => { const b=make('button','icon-btn' + (danger ? ' danger' : '')); b.type='button'; b.title=title; b.setAttribute('aria-label', title + ' ' + db.name); b.innerHTML=`<i data-lucide="${icon}"></i>`; b.addEventListener('click',()=>fn(b)); wrap.appendChild(b); return b; };
        if (!db.system) {
          const pma = iconBtn('table-properties', pmaInstalled ? 'Open in phpMyAdmin' : 'phpMyAdmin is not installed', (b) => openPma(db.name, b));
          pma.disabled = !pmaInstalled;
          iconBtn('download', 'Export (download a .sql.gz dump)', () => exportDb(db.name));
          iconBtn('upload', 'Import a dump into', () => importDb(db.name));
          iconBtn('trash-2', 'Drop database', () => dropDbs([db.name]), true);
        }
        actions.appendChild(wrap); tr.appendChild(actions); body.appendChild(tr);
      });
      bulk.refresh();

      // Who can reach which database, from the schema grants.
      const access = {};
      dbs.forEach((db) => (db.users || []).forEach((grantee) => { (access[grantee] = access[grantee] || []).push(db.name); }));
      const userBody=document.getElementById('dbUserBody'); userBody.innerHTML='';
      users.forEach((u)=>{
        const tr=make('tr'); tr.appendChild(make('td','mono',u.user||'(anonymous)')); tr.appendChild(make('td','mono text-tertiary',u.host));
        const reach = access[`${u.user}'@'${u.host}`] || [];
        const acc = make('td');
        reach.forEach((d) => {
          const badge = make('span','badge badge-slate access-badge',d);
          const x = make('button','access-revoke','×'); x.type='button'; x.title=`Revoke access to ${d}`; x.setAttribute('aria-label', `Revoke ${u.user}@${u.host} access to ${d}`);
          x.addEventListener('click', async () => {
            if (!await window.Nebula.confirm({ title: `Revoke access to ${d}?`, message: `${u.user}@${u.host} will no longer be able to use this database.`, danger: true, confirmLabel: 'Revoke' })) return;
            const res = await apiPost('databases', { action: 'grant', user: u.user, host: u.host, database: d, level: 'none' });
            if (res.ok) { toast('Access revoked', 'success'); loadAll(); } else toast(res.error || 'Failed', 'error');
          });
          badge.appendChild(x); acc.appendChild(badge);
        });
        if (!reach.length) acc.textContent = '–';
        tr.appendChild(acc);
        const td=make('td','actions-col'); const w=make('div','flex gap-1'); w.style.justifyContent='flex-end';
        const btn=(icon,title,fn,danger)=>{const b=make('button','icon-btn'+(danger?' danger':''));b.type='button';b.title=title;b.setAttribute('aria-label',`${title} ${u.user}@${u.host}`);b.innerHTML=`<i data-lucide="${icon}"></i>`;b.addEventListener('click',fn);w.appendChild(b);};
        btn('key-round','Change password',async()=>{
          const pw = await window.Nebula.prompt({ title: `New password for ${u.user}@${u.host}`, label: 'Password', type: 'password', autocomplete: 'new-password', confirmLabel: 'Change password', validate: (v) => v.length < 8 ? 'Use at least 8 characters.' : (v.includes('\\') ? 'Passwords cannot contain a backslash.' : '') });
          if (!pw) return;
          const res = await apiPost('databases', { action: 'user_password', user: u.user, host: u.host, password: pw });
          toast(res.ok ? 'Password changed — update the application that signs in as this user' : (res.error || 'Failed'), res.ok ? 'success' : 'error');
        });
        btn('shield-check','Set database access',()=>setAccess(u, dbs.filter((d)=>!d.system).map((d)=>d.name)));
        btn('trash-2','Drop user',async()=>{if(!await window.Nebula.confirm({title:`Drop user "${u.user}"@"${u.host}"?`,message:'Applications signing in as this user will stop working.',danger:true,confirmLabel:'Drop user'}))return;const res=await apiPost('databases',{action:'drop_user',user:u.user,host:u.host});if(res.ok){toast('User dropped','success');loadAll();}else toast(res.error||'Failed','error');},true);
        td.appendChild(w); tr.appendChild(td); userBody.appendChild(tr);
      });
      if (!users.length) { const tr=make('tr');const td=make('td','text-tertiary','No users.');td.colSpan=4;td.style.cssText='text-align:center;padding:24px';tr.appendChild(td);userBody.appendChild(tr); }
      if (window.lucide) lucide.createIcons();
    }

    const bulk = window.Nebula.bulkSelect(document.getElementById('dbTable'), [
      { label: 'Export selected', icon: 'download', run: async (names) => { for (const name of names) await exportDb(name); } },
      { label: 'Drop selected', icon: 'trash-2', danger: true, run: (names) => dropDbs(names) },
    ]);
    async function dropDbs(names) {
      if (!await window.Nebula.confirm({ title: names.length === 1 ? `Drop database "${names[0]}"?` : `Drop ${names.length} databases?`, message: (names.length > 1 ? names.join(', ') + '. ' : '') + 'All tables and data are deleted. Export first if you may need them.', danger: true, confirmLabel: 'Drop' })) return;
      let dropped = 0;
      for (const name of names) { const res = await apiPost('databases', { action: 'drop_db', name }); if (res.ok) dropped += 1; else toast(res.error || 'Could not drop ' + name, 'error'); }
      if (dropped) { toast(`Dropped ${dropped} database${dropped === 1 ? '' : 's'}`, 'success'); loadAll(); }
    }
    async function exportDb(name) {
      const res = await window.Nebula.runTask({ title: 'Exporting ' + name, endpoint: 'databases', body: { action: 'export', name }, initialStep: 'Running mysqldump', successMessage: 'Export ready — downloading' });
      if (res.ok && res.url) { const a = document.createElement('a'); a.href = res.url; a.download = res.file || ''; document.body.appendChild(a); a.click(); a.remove(); }
    }
    async function setAccess(u, names) {
      if (!names.length) { toast('Create a database first', 'info'); return; }
      const overlay = await window.Nebula.dialog({ title: `Access for ${u.user}@${u.host}`, icon: 'shield-check', confirmLabel: 'Apply',
        input: { label: 'Database (' + names.join(', ') + ')', value: names[0], validate: (v) => names.includes(v) ? '' : 'Pick one of: ' + names.join(', ') },
        option: { label: 'Read-only (SELECT) instead of full access', help: 'Untick for full access. Remove access with the × on the user’s database badge.' } });
      if (!overlay.ok) return;
      const res = await apiPost('databases', { action: 'grant', user: u.user, host: u.host, database: overlay.value, level: overlay.option ? 'read' : 'all' });
      toast(res.ok ? 'Access updated' : (res.error || 'Failed'), res.ok ? 'success' : 'error');
      if (res.ok) loadAll();
    }
    const importInput = document.getElementById('dbImportFile');
    let importTarget = '';
    function importDb(name) { importTarget = name; importInput.value = ''; importInput.click(); }
    importInput?.addEventListener('change', async () => {
      const file = importInput.files[0];
      if (!file || !importTarget) return;
      if (!await window.Nebula.confirm({ title: `Import ${file.name} into ${importTarget}?`, message: 'Tables in the dump replace tables with the same name. Export the database first if you may need its current contents.', confirmLabel: 'Import', warning: true, icon: 'upload' })) return;
      // Upload in chunks to private staging, then import as a background job.
      const id = Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('');
      const CHUNK = 8 * 1024 * 1024; let offset = 0, staged = '';
      toast('Uploading ' + file.name + '…', 'info');
      while (offset < file.size || (file.size === 0 && !staged)) {
        const q = new URLSearchParams({ upload_id: id, name: file.name, purpose: 'staging', dir: '', total: file.size, offset });
        const r = await fetch(window.Nebula.api('file-upload-chunk&' + q), { method: 'POST', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content, 'Content-Type': 'application/octet-stream', Accept: 'application/json' }, body: file.slice(offset, offset + CHUNK) });
        const res = await r.json().catch(() => ({ ok: false, error: 'Upload failed (HTTP ' + r.status + ')' }));
        if (res.resume) { offset = res.received; continue; }
        if (!res.ok) { toast(res.error || 'Upload failed', 'error'); return; }
        offset = res.received;
        if (res.done) { staged = res.staged; break; }
      }
      const res = await window.Nebula.runTask({ title: 'Importing into ' + importTarget, endpoint: 'databases', body: { action: 'import', name: importTarget, staged }, initialStep: 'Importing the dump', successMessage: 'Import finished' });
      if (res.ok) loadAll();
    });

    document.getElementById('dbCreateToggle')?.addEventListener('click',(ev)=>{const card=document.getElementById('dbCreateCard');card.classList.toggle('hidden');ev.currentTarget.setAttribute('aria-expanded',card.classList.contains('hidden')?'false':'true');if(!card.classList.contains('hidden'))document.getElementById('dbName').focus();});
    document.getElementById('dbCreate')?.addEventListener('click',async()=>{const name=document.getElementById('dbName').value.trim(),user=document.getElementById('dbUser').value.trim(),host=document.getElementById('dbHost').value.trim()||'localhost',password=document.getElementById('dbPassword').value,website=document.getElementById('dbWebsite').value;if(!name){toast('Enter a database name','warning');return;}if(!website){toast('Select the website that owns this database','warning');return;}if(user&&!password){toast('Enter a password for the new user','warning');return;}const res=await apiPost('databases',{action:'create_bundle',name,user,host,password,website});if(res.ok){toast('Database created','success');['dbName','dbUser','dbPassword'].forEach(id=>document.getElementById(id).value='');loadAll();}else toast(res.error||'Failed','error');});
    document.getElementById('duCreate')?.addEventListener('click',async()=>{const user=document.getElementById('duUser').value.trim(),host=document.getElementById('duHost').value.trim()||'localhost',password=document.getElementById('duPass').value,grant_db=document.getElementById('duGrant').value;if(!user||!password){toast('Enter a user and password','warning');return;}const res=await apiPost('databases',{action:'create_user',user,host,password,grant_db});if(res.ok){toast('User created','success');document.getElementById('duUser').value='';document.getElementById('duPass').value='';loadAll();}else toast(res.error||'Failed','error');});
    loadAll();
  });
  </script>
<?php endif; ?>
