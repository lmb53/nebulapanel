<?php
require_once APP_ROOT . '/lib/mod_db.php';
require_once APP_ROOT . '/lib/mod_sites.php';
require_once APP_ROOT . '/lib/mod_pma.php';
$available = db_available();
$sites = sites_list();
$pmaInstalled = pma_installed();
$selectedWebsite = (string) ($_GET['website'] ?? '');
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Databases</h1>
    <p class="page-subtitle">Create databases and users, attach them to websites, and open them securely in phpMyAdmin</p>
  </div>
  <?php if ($available): ?>
    <div class="page-actions">
      <?php if ($pmaInstalled): ?><a class="btn btn-secondary" href="<?= e(url('phpmyadmin')) ?>"><i data-lucide="table-properties"></i>phpMyAdmin</a><?php endif; ?>
      <button class="btn btn-primary" id="dbCreateToggle"><i data-lucide="plus"></i>Create Database</button>
    </div>
  <?php endif; ?>
</div>

<?php if (!$available): ?>
  <div class="card"><div class="empty-state">
    <div class="es-icon"><i data-lucide="database"></i></div>
    <div style="font-weight:600;color:var(--text-secondary)">MySQL/MariaDB client not found</div>
    <div style="font-size:13px;margin-top:4px">Install MariaDB or MySQL, then reload this page.</div>
  </div></div>
<?php else: ?>
  <div class="card hidden" id="dbCreateCard" style="margin-bottom:16px">
    <div class="card-header"><div><h3>Create database</h3><span class="muted">Optionally create its first user and link it to a website in one step.</span></div></div>
    <div class="card-pad">
      <div class="grid" style="grid-template-columns:1.2fr 1fr .8fr 1fr 1.2fr auto;gap:12px;align-items:end">
        <div><label class="field-label" for="dbName">Database name</label><input class="input mono" id="dbName" placeholder="site_database" autocomplete="off"></div>
        <div><label class="field-label" for="dbUser">User (optional)</label><input class="input mono" id="dbUser" placeholder="site_user" autocomplete="off"></div>
        <div><label class="field-label" for="dbHost">Host</label><input class="input mono" id="dbHost" value="localhost" autocomplete="off"></div>
        <div><label class="field-label" for="dbPassword">Password</label><input class="input mono" id="dbPassword" type="password" autocomplete="new-password"></div>
        <div><label class="field-label" for="dbWebsite">Website</label><select class="select" id="dbWebsite"><option value="">Not linked</option><?php foreach ($sites as $site): ?><?php $siteDomain=(string)($site['domain']??''); ?><option value="<?= e($siteDomain) ?>"<?= $siteDomain===$selectedWebsite?' selected':'' ?>><?= e($siteDomain) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-primary" id="dbCreate"><i data-lucide="database-zap"></i>Create</button>
      </div>
    </div>
  </div>

  <div class="grid grid-3" style="margin-bottom:16px">
    <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(59,130,246,.12)"><i data-lucide="database" style="color:var(--blue-400)"></i></div></div><div class="stat-val" id="dbStatCount">–</div><div class="stat-label">User databases</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(16,185,129,.12)"><i data-lucide="hard-drive" style="color:var(--emerald-400)"></i></div></div><div class="stat-val" id="dbStatSize">–</div><div class="stat-label">Total database size</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(168,85,247,.12)"><i data-lucide="users" style="color:var(--purple-400)"></i></div></div><div class="stat-val" id="dbStatUsers">–</div><div class="stat-label">Database users</div></div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><div><h3>All databases</h3><span class="muted" id="dbEngine">Loading database service…</span></div></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Database name</th><th>Engine</th><th>Owner website</th><th>Size</th><th>Tables</th><th>Collation</th><th>Users</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody id="dbBody"><tr><td colspan="8" class="text-tertiary" style="text-align:center;padding:28px">Loading…</td></tr></tbody>
    </table></div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Database users</h3><div class="card-actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><input class="input mono" id="duUser" placeholder="user" style="max-width:140px"><input class="input mono" id="duHost" value="localhost" style="max-width:115px"><input class="input mono" id="duPass" type="password" placeholder="password" style="max-width:160px"><select class="select" id="duGrant" style="max-width:190px"><option value="">No initial grant</option></select><button class="btn btn-primary btn-sm" id="duCreate"><i data-lucide="user-plus"></i>Create user</button></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>User</th><th>Host</th><th style="text-align:right">Actions</th></tr></thead><tbody id="dbUserBody"><tr><td colspan="3" class="text-tertiary" style="text-align:center;padding:24px">Loading…</td></tr></tbody></table></div>
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const { apiGet, apiPost, toast, fmtBytes } = window.Nebula;
    const pmaInstalled = <?= $pmaInstalled ? 'true' : 'false' ?>;
    let websites = <?= json_encode(array_values(array_map(fn($s) => (string) ($s['domain'] ?? ''), $sites))) ?>;
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
      const dbs = data.databases || [], userDbs = dbs.filter((db) => !db.system), users = data.users || [];
      document.getElementById('dbStatCount').textContent = userDbs.length;
      document.getElementById('dbStatSize').textContent = fmtBytes(userDbs.reduce((n, db) => n + Number(db.size || 0), 0));
      document.getElementById('dbStatUsers').textContent = users.length;
      document.getElementById('dbEngine').textContent = data.version ? `MariaDB / MySQL ${data.version}` : 'MariaDB / MySQL';

      const grant = document.getElementById('duGrant');
      grant.innerHTML = '<option value="">No initial grant</option>';
      userDbs.forEach((db) => { const o = make('option', '', db.name); o.value = db.name; grant.appendChild(o); });

      const body = document.getElementById('dbBody'); body.innerHTML = '';
      if (!dbs.length) { const tr=make('tr'); const td=make('td','text-tertiary','No databases.'); td.colSpan=8; td.style.cssText='text-align:center;padding:28px'; tr.appendChild(td); body.appendChild(tr); }
      dbs.forEach((db) => {
        const tr = make('tr');
        const name = make('td'); const strong = make('div','mono',db.name); strong.style.fontWeight='700'; name.appendChild(strong); if (db.system) name.appendChild(make('span','badge badge-slate','system')); tr.appendChild(name);
        tr.appendChild(make('td','text-tertiary', data.version ? (String(data.version).toLowerCase().includes('mariadb') ? 'MariaDB' : 'MySQL') : 'MySQL'));
        const siteTd=make('td'); const select=make('select','select'); select.style.cssText='min-width:150px;padding:6px 28px 6px 9px'; select.disabled=!!db.system; let opt=make('option','','Not linked'); opt.value=''; select.appendChild(opt); websites.forEach((site)=>{ const o=make('option','',site); o.value=site; o.selected=site===db.website; select.appendChild(o); }); select.addEventListener('change',async()=>{ const res=await apiPost('databases',{action:'link_website',database:db.name,website:select.value}); toast(res.ok?'Website link updated':(res.error||'Could not update link'),res.ok?'success':'error'); }); siteTd.appendChild(select); tr.appendChild(siteTd);
        tr.appendChild(make('td','mono text-tertiary',fmtBytes(db.size)));
        tr.appendChild(make('td','mono text-tertiary',String(db.tables || 0)));
        tr.appendChild(make('td','mono text-tertiary',db.collation || '–'));
        const usersTd=make('td'); (db.users || []).slice(0,2).forEach((u)=>usersTd.appendChild(make('span','badge badge-slate',u))); if ((db.users||[]).length>2) usersTd.appendChild(make('span','badge badge-slate',`+${db.users.length-2}`)); if (!(db.users||[]).length) usersTd.textContent='–'; tr.appendChild(usersTd);
        const actions=make('td'); actions.style.textAlign='right'; const wrap=make('div','flex gap-1'); wrap.style.justifyContent='flex-end';
        if (!db.system) { const pma=make('button','icon-btn'); pma.title=pmaInstalled?'Open this database in phpMyAdmin':'phpMyAdmin is not installed'; pma.disabled=!pmaInstalled; pma.innerHTML='<i data-lucide="table-properties"></i>'; pma.addEventListener('click',()=>openPma(db.name,pma)); wrap.appendChild(pma); const del=make('button','icon-btn'); del.title='Drop database'; del.style.color='var(--red-400)'; del.innerHTML='<i data-lucide="trash-2"></i>'; del.addEventListener('click',async()=>{ if(!confirm(`Drop database "${db.name}"? This cannot be undone.`))return; const res=await apiPost('databases',{action:'drop_db',name:db.name}); if(res.ok){toast('Database dropped','success');loadAll();}else toast(res.error||'Failed','error'); }); wrap.appendChild(del); }
        actions.appendChild(wrap); tr.appendChild(actions); body.appendChild(tr);
      });

      const userBody=document.getElementById('dbUserBody'); userBody.innerHTML='';
      users.forEach((u)=>{ const tr=make('tr'); tr.appendChild(make('td','mono',u.user||'(anonymous)')); tr.appendChild(make('td','mono text-tertiary',u.host)); const td=make('td');td.style.textAlign='right';const b=make('button','btn btn-danger btn-sm');b.innerHTML='<i data-lucide="trash-2"></i>';b.addEventListener('click',async()=>{if(!confirm(`Drop user "${u.user}"@"${u.host}"?`))return;const res=await apiPost('databases',{action:'drop_user',user:u.user,host:u.host});if(res.ok){toast('User dropped','success');loadAll();}else toast(res.error||'Failed','error');});td.appendChild(b);tr.appendChild(td);userBody.appendChild(tr); });
      if (!users.length) { const tr=make('tr');const td=make('td','text-tertiary','No users.');td.colSpan=3;td.style.cssText='text-align:center;padding:24px';tr.appendChild(td);userBody.appendChild(tr); }
      if (window.lucide) lucide.createIcons();
    }

    document.getElementById('dbCreateToggle')?.addEventListener('click',()=>{const card=document.getElementById('dbCreateCard');card.classList.toggle('hidden');if(!card.classList.contains('hidden'))document.getElementById('dbName').focus();});
    document.getElementById('dbCreate')?.addEventListener('click',async()=>{const name=document.getElementById('dbName').value.trim(),user=document.getElementById('dbUser').value.trim(),host=document.getElementById('dbHost').value.trim()||'localhost',password=document.getElementById('dbPassword').value,website=document.getElementById('dbWebsite').value;if(!name){toast('Enter a database name','warning');return;}if(user&&!password){toast('Enter a password for the new user','warning');return;}const res=await apiPost('databases',{action:'create_bundle',name,user,host,password,website});if(res.ok){toast('Database created','success');['dbName','dbUser','dbPassword'].forEach(id=>document.getElementById(id).value='');loadAll();}else toast(res.error||'Failed','error');});
    document.getElementById('duCreate')?.addEventListener('click',async()=>{const user=document.getElementById('duUser').value.trim(),host=document.getElementById('duHost').value.trim()||'localhost',password=document.getElementById('duPass').value,grant_db=document.getElementById('duGrant').value;if(!user||!password){toast('Enter a user and password','warning');return;}const res=await apiPost('databases',{action:'create_user',user,host,password,grant_db});if(res.ok){toast('User created','success');document.getElementById('duUser').value='';document.getElementById('duPass').value='';loadAll();}else toast(res.error||'Failed','error');});
    loadAll();
  });
  </script>
<?php endif; ?>
