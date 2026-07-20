<?php
require_once APP_ROOT . '/lib/mod_db.php';
$available = db_available();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Databases</h1>
    <p class="page-subtitle">Manage MariaDB / MySQL databases and users</p>
  </div>
</div>

<?php if (!$available): ?>
  <div class="card"><div class="empty-state">
    <div class="es-icon"><i data-lucide="database"></i></div>
    <div style="font-weight:600;color:var(--text-secondary)">MySQL/MariaDB client not found</div>
    <div style="font-size:13px;margin-top:4px">The <span class="mono">mysql</span> command was not found on this system.</div>
  </div></div>
<?php else: ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card-header">
      <h3>Databases</h3>
      <div class="card-actions" style="display:flex;gap:8px;align-items:center">
        <input class="input mono" id="dbName" placeholder="my_database" style="max-width:220px">
        <button class="btn btn-primary btn-sm" id="dbCreate"><i data-lucide="plus"></i>Create</button>
      </div>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Name</th><th>Size</th><th>Type</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody id="dbBody">
          <tr><td colspan="4" class="text-tertiary" style="text-align:center;padding:24px">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h3>Database users</h3>
      <div class="card-actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input class="input mono" id="duUser" placeholder="user" style="max-width:140px">
        <input class="input mono" id="duHost" placeholder="%" value="%" style="max-width:100px">
        <input class="input mono" id="duPass" type="password" placeholder="password" style="max-width:160px">
        <input class="input mono" id="duGrant" placeholder="grant db (optional)" style="max-width:180px">
        <button class="btn btn-primary btn-sm" id="duCreate"><i data-lucide="user-plus"></i>Create user</button>
      </div>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>User</th><th>Host</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody id="dbUserBody">
          <tr><td colspan="3" class="text-tertiary" style="text-align:center;padding:24px">Loading…</td></tr>
        </tbody>
      </table>
    </div>
    <div class="card-pad" style="font-size:12px;color:var(--text-tertiary)">
      Requires a sudoers rule allowing <span class="mono">sudo mysql</span>.
    </div>
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const { apiGet, apiPost, toast, fmtBytes } = window.Nebula;

    function cell(text) {
      const td = document.createElement('td');
      td.textContent = text;
      return td;
    }

    async function loadAll() {
      let data;
      try { data = await apiGet('databases'); }
      catch (e) { toast('Failed to load databases', 'error'); return; }

      const dbBody = document.getElementById('dbBody');
      dbBody.innerHTML = '';
      const dbs = data.databases || [];
      if (!dbs.length) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 4; td.className = 'text-tertiary';
        td.style.textAlign = 'center'; td.style.padding = '24px';
        td.textContent = 'No databases.';
        tr.appendChild(td); dbBody.appendChild(tr);
      } else {
        for (const db of dbs) {
          const tr = document.createElement('tr');
          const nameTd = cell(db.name); nameTd.style.fontWeight = '600';
          tr.appendChild(nameTd);
          tr.appendChild(cell(fmtBytes(db.size)));

          const typeTd = document.createElement('td');
          const badge = document.createElement('span');
          badge.className = 'badge ' + (db.system ? 'badge-slate' : 'badge-emerald');
          badge.textContent = db.system ? 'system' : 'user';
          typeTd.appendChild(badge);
          tr.appendChild(typeTd);

          const actTd = document.createElement('td');
          actTd.style.textAlign = 'right';
          if (!db.system) {
            const btn = document.createElement('button');
            btn.className = 'btn btn-danger btn-sm';
            btn.setAttribute('data-db-drop', db.name);
            btn.innerHTML = '<i data-lucide="trash-2"></i>';
            actTd.appendChild(btn);
          } else {
            actTd.innerHTML = '<span class="text-tertiary" style="font-size:12px">—</span>';
          }
          tr.appendChild(actTd);
          dbBody.appendChild(tr);
        }
      }

      const userBody = document.getElementById('dbUserBody');
      userBody.innerHTML = '';
      const users = data.users || [];
      if (!users.length) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 3; td.className = 'text-tertiary';
        td.style.textAlign = 'center'; td.style.padding = '24px';
        td.textContent = 'No users.';
        tr.appendChild(td); userBody.appendChild(tr);
      } else {
        for (const u of users) {
          const tr = document.createElement('tr');
          const uTd = cell(u.user); uTd.style.fontWeight = '600';
          tr.appendChild(uTd);
          tr.appendChild(cell(u.host));

          const actTd = document.createElement('td');
          actTd.style.textAlign = 'right';
          const btn = document.createElement('button');
          btn.className = 'btn btn-danger btn-sm';
          btn.setAttribute('data-user-drop', u.user);
          btn.setAttribute('data-host', u.host);
          btn.innerHTML = '<i data-lucide="trash-2"></i>';
          actTd.appendChild(btn);
          tr.appendChild(actTd);
          userBody.appendChild(tr);
        }
      }

      bindDrops();
      if (window.lucide) lucide.createIcons();
    }

    function bindDrops() {
      document.querySelectorAll('[data-db-drop]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const name = btn.getAttribute('data-db-drop');
          if (!confirm('Drop database "' + name + '"? This cannot be undone.')) return;
          const res = await apiPost('databases', { action: 'drop_db', name });
          if (res.ok) { toast('Database dropped', 'success'); loadAll(); }
          else toast(res.error || 'Failed', 'error');
        });
      });
      document.querySelectorAll('[data-user-drop]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const user = btn.getAttribute('data-user-drop');
          const host = btn.getAttribute('data-host');
          if (!confirm('Drop user "' + user + '"@"' + host + '"?')) return;
          const res = await apiPost('databases', { action: 'drop_user', user, host });
          if (res.ok) { toast('User dropped', 'success'); loadAll(); }
          else toast(res.error || 'Failed', 'error');
        });
      });
    }

    document.getElementById('dbCreate')?.addEventListener('click', async () => {
      const name = document.getElementById('dbName').value.trim();
      if (!name) { toast('Enter a database name', 'warning'); return; }
      const res = await apiPost('databases', { action: 'create_db', name });
      if (res.ok) { toast('Database created', 'success'); document.getElementById('dbName').value = ''; loadAll(); }
      else toast(res.error || 'Failed', 'error');
    });

    document.getElementById('duCreate')?.addEventListener('click', async () => {
      const user = document.getElementById('duUser').value.trim();
      const host = document.getElementById('duHost').value.trim() || '%';
      const password = document.getElementById('duPass').value;
      const grant_db = document.getElementById('duGrant').value.trim();
      if (!user) { toast('Enter a user name', 'warning'); return; }
      const res = await apiPost('databases', { action: 'create_user', user, host, password, grant_db });
      if (res.ok) {
        toast('User created', 'success');
        document.getElementById('duUser').value = '';
        document.getElementById('duPass').value = '';
        document.getElementById('duGrant').value = '';
        loadAll();
      } else toast(res.error || 'Failed', 'error');
    });

    loadAll();
  });
  </script>
<?php endif; ?>
