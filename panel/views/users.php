<?php
require_once APP_ROOT . '/lib/mod_users.php';
$systemUsers = system_users();
?>
<div class="page-header">
  <div><h1 class="page-title">Users &amp; access</h1><p class="page-subtitle">Control-panel accounts, roles and local system account visibility</p></div>
  <div class="page-actions"><button class="btn btn-primary" id="panelUserNew"><i data-lucide="user-plus"></i>Add panel user</button></div>
</div>

<div class="grid grid-4" style="margin-bottom:16px">
  <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(59,130,246,.12)"><i data-lucide="users" style="color:var(--blue-400)"></i></div></div><div class="stat-val" id="puTotal">–</div><div class="stat-label">Panel users</div></div>
  <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(16,185,129,.12)"><i data-lucide="shield-check" style="color:var(--emerald-400)"></i></div></div><div class="stat-val" id="puAdmins">–</div><div class="stat-label">Administrators</div></div>
  <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(168,85,247,.12)"><i data-lucide="key-round" style="color:var(--purple-400)"></i></div></div><div class="stat-val">4</div><div class="stat-label">Built-in roles</div></div>
  <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(245,158,11,.12)"><i data-lucide="user-cog" style="color:var(--orange-400)"></i></div></div><div class="stat-val"><?= count(array_filter($systemUsers, fn($u) => $u['human'])) ?></div><div class="stat-label">Linux users</div></div>
</div>

<div class="tabs access-tabs" id="userTabs" style="margin-bottom:16px">
  <button class="tab active" data-tab-target="panelUsers"><i data-lucide="shield-user"></i>Panel users</button>
  <button class="tab" data-tab-target="roleGuide"><i data-lucide="key-square"></i>Role guide</button>
  <button class="tab" data-tab-target="systemUsers"><i data-lucide="server"></i>System accounts</button>
</div>

<section class="card" data-tab-panel id="panelUsers">
  <div class="card-header"><div><h3>Control-panel access</h3><span class="muted">Accounts that can sign in to Nebula Panel</span></div></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Last sign in</th><th>Created</th><th style="text-align:right">Actions</th></tr></thead><tbody id="panelUserBody"><tr><td colspan="6" class="text-tertiary" style="text-align:center;padding:28px">Loading…</td></tr></tbody></table></div>
</section>

<section class="hidden" data-tab-panel id="roleGuide">
  <div class="grid grid-2" id="roleCards"></div>
</section>

<section class="card hidden" data-tab-panel id="systemUsers">
  <div class="card-header"><div><h3>Linux accounts</h3><span class="muted">Read-only operating-system account inventory</span></div></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>UID</th><th>Home</th><th>Shell</th><th>Groups</th><th>Type</th></tr></thead><tbody>
    <?php if (!$systemUsers): ?><tr><td colspan="6" class="text-tertiary" style="text-align:center;padding:24px">No user data (/etc/passwd not readable)</td></tr><?php endif; ?>
    <?php foreach ($systemUsers as $u): ?><tr><td style="font-weight:600"><?= e($u['name']) ?></td><td class="mono"><?= e($u['uid']) ?></td><td class="mono text-tertiary"><?= e($u['home']) ?></td><td class="mono text-tertiary"><?= e($u['shell']) ?></td><td><?php if ($u['human']): foreach (user_groups($u['name']) as $g): ?><span class="badge badge-slate"><?= e($g) ?></span><?php endforeach; else: ?>–<?php endif; ?></td><td><span class="badge <?= $u['human'] ? 'badge-blue' : 'badge-slate' ?>"><?= $u['human'] ? 'Human' : 'System' ?></span></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>

<div class="drawer-overlay hidden" id="panelUserDrawer"><div class="drawer">
  <div class="drawer-header"><div><strong id="puDrawerTitle">Add panel user</strong><div class="muted" style="font-size:11.5px;margin-top:3px">Role changes apply on the next request.</div></div><button class="icon-btn" data-close-user-drawer><i data-lucide="x"></i></button></div>
  <div class="drawer-body">
    <input type="hidden" id="puId">
    <div style="margin-bottom:16px"><label class="field-label">Username</label><input class="input" id="puUsername" autocomplete="off"></div>
    <div style="margin-bottom:16px"><label class="field-label">Role</label><select class="select" id="puRole"></select></div>
    <div style="margin-bottom:16px"><label class="field-label">Password <span class="text-tertiary" id="puPasswordHint"></span></label><input class="input" id="puPassword" type="password" autocomplete="new-password"><div class="muted" style="font-size:11.5px;margin-top:6px">Minimum 12 characters.</div></div>
    <label class="flex items-center gap-2" style="font-size:13px"><input type="checkbox" id="puEnabled" checked>Allow this user to sign in</label>
  </div>
  <div class="drawer-footer"><button class="btn btn-secondary" data-close-user-drawer>Cancel</button><button class="btn btn-primary" id="puSave"><i data-lucide="save"></i>Save user</button></div>
</div></div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiGet, apiPost, toast } = window.Nebula; let data = {users:[],roles:{},current_id:0};
  const make=(tag,cls,text)=>{const e=document.createElement(tag);if(cls)e.className=cls;if(text!==undefined)e.textContent=text;return e;};
  const drawer=document.getElementById('panelUserDrawer');
  const close=()=>drawer.classList.add('hidden');
  document.querySelectorAll('[data-close-user-drawer]').forEach(b=>b.addEventListener('click',close));
  document.querySelectorAll('#userTabs .tab').forEach(tab=>tab.addEventListener('click',()=>{document.querySelectorAll('#userTabs .tab').forEach(t=>t.classList.toggle('active',t===tab));document.querySelectorAll('[data-tab-panel]').forEach(p=>p.classList.toggle('hidden',p.id!==tab.dataset.tabTarget));}));
  function open(user=null){document.getElementById('puDrawerTitle').textContent=user?'Edit panel user':'Add panel user';document.getElementById('puId').value=user?.id||'';document.getElementById('puUsername').value=user?.username||'';document.getElementById('puUsername').disabled=!!user;document.getElementById('puRole').value=user?.role||'auditor';document.getElementById('puPassword').value='';document.getElementById('puPasswordHint').textContent=user?'(leave blank to keep current)':'';document.getElementById('puEnabled').checked=user?user.enabled:true;drawer.classList.remove('hidden');}
  document.getElementById('panelUserNew').addEventListener('click',()=>open());
  function render(){
    document.getElementById('puTotal').textContent=data.users.length;document.getElementById('puAdmins').textContent=data.users.filter(u=>u.role==='admin'&&u.enabled).length;
    const roleSelect=document.getElementById('puRole');roleSelect.innerHTML='';Object.entries(data.roles).forEach(([key,r])=>{const o=make('option','',r.label);o.value=key;roleSelect.appendChild(o);});
    const cards=document.getElementById('roleCards');cards.innerHTML='';Object.entries(data.roles).forEach(([key,r])=>{const c=make('div','card card-pad');c.innerHTML=`<div class="flex items-center gap-3"><span class="stat-icon"><i data-lucide="${key==='admin'?'shield-check':key==='operator'?'wrench':key==='developer'?'code-2':'eye'}"></i></span><div><strong>${r.label}</strong><div class="muted" style="font-size:12px;margin-top:4px">${r.description}</div></div></div>`;cards.appendChild(c);});
    const body=document.getElementById('panelUserBody');body.innerHTML='';data.users.forEach(u=>{const tr=make('tr');const name=make('td');name.innerHTML=`<div style="font-weight:700">${u.username}</div><div class="muted mono" style="font-size:11px">ID ${u.id}${u.id===data.current_id?' · current session':''}</div>`;tr.append(name);tr.append(make('td','',''));tr.lastChild.append(make('span','badge badge-blue',data.roles[u.role]?.label||u.role));tr.append(make('td','',''));tr.lastChild.append(make('span',`badge ${u.enabled?'badge-emerald':'badge-slate'}`,u.enabled?'Enabled':'Disabled'));tr.append(make('td','mono text-tertiary',u.last_login?new Date(u.last_login).toLocaleString():'Never'));tr.append(make('td','mono text-tertiary',u.created?new Date(u.created).toLocaleDateString():'–'));const actions=make('td');actions.style.textAlign='right';const edit=make('button','icon-btn');edit.title='Edit user';edit.innerHTML='<i data-lucide="pencil"></i>';edit.addEventListener('click',()=>open(u));actions.append(edit);if(u.id!==data.current_id){const del=make('button','icon-btn');del.title='Delete user';del.style.color='var(--red-400)';del.innerHTML='<i data-lucide="trash-2"></i>';del.addEventListener('click',async()=>{if(!confirm(`Delete panel user "${u.username}"?`))return;const r=await apiPost('users',{action:'delete',id:u.id});toast(r.ok?'Panel user deleted':(r.error||'Delete failed'),r.ok?'success':'error');if(r.ok)load();});actions.append(del);}tr.append(actions);body.append(tr);});if(!data.users.length){const tr=make('tr');const td=make('td','text-tertiary','No panel users.');td.colSpan=6;td.style.cssText='text-align:center;padding:28px';tr.append(td);body.append(tr);}if(window.lucide)lucide.createIcons();
  }
  async function load(){try{data=await apiGet('users');render();}catch(e){toast(e.message||'Could not load users','error');}}
  document.getElementById('puSave').addEventListener('click',async()=>{const id=+document.getElementById('puId').value;const payload={action:id?'update':'create',id,username:document.getElementById('puUsername').value.trim(),role:document.getElementById('puRole').value,password:document.getElementById('puPassword').value,enabled:document.getElementById('puEnabled').checked};const r=await apiPost('users',payload);toast(r.ok?'Panel user saved':(r.error||'Save failed'),r.ok?'success':'error');if(r.ok){close();load();}});
  load();
});
</script>
