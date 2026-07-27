<?php require_once APP_ROOT.'/lib/mod_firewall.php';require_once APP_ROOT.'/lib/mod_fail2ban.php';require_once APP_ROOT.'/lib/mod_modsecurity.php';$modsec=modsec_status();$modsecOn=$modsec['installed']&&$modsec['enabled']&&$modsec['mode']==='On';$available=fw_available();$status=$available?fw_status():['ok'=>false,'active'=>false,'rules'=>[]];$f2b=f2b_status();$f2bActive=$f2b['installed']&&$f2b['active']==='active';$score=$status['active']?min(96,78+count($status['rules'])*2):35;if($f2bActive)$score=min(99,$score+4);if($modsecOn)$score=min(99,$score+3);$audit=is_readable(DATA_DIR.'/audit.log')?array_slice(array_reverse(file(DATA_DIR.'/audit.log',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)),0,80):[]; ?>
<div class="page-header"><div><h1 class="page-title">Security</h1><p class="page-subtitle">Security score: <?= $score ?>/100 · <?= $score>=85?'Good':($score>=60?'Needs attention':'At risk') ?></p></div><?php if($available): ?><div class="page-actions"><button class="btn btn-primary" id="fwAddOpen"><i data-lucide="plus"></i>Add Firewall Rule</button></div><?php endif; ?></div>
<?php if(!$available||empty($status['ok'])): ?><div class="card"><div class="empty-state"><div class="es-icon"><i data-lucide="shield-off"></i></div><div style="font-weight:600"><?= $available?'Could not read firewall status':'UFW is not available' ?></div><div style="font-size:13px;margin-top:4px"><?= e($status['error']??'Install UFW to manage firewall rules.') ?></div></div></div><?php else: ?>
<div class="card security-score" style="margin-bottom:18px"><div class="card-pad"><div class="score-ring" style="--score:<?= $score ?>"><strong><?= $score ?></strong><span>/ 100</span></div><div><h3><?= $score>=85?'Good security posture':($score>=60?'Review recommended':'Firewall requires attention') ?></h3><p><?= $status['active']?'UFW is active and enforcing '.count($status['rules']).' configured rules.':'UFW is installed but not currently enforcing rules.' ?></p></div><div class="security-checks"><span><i data-lucide="<?= $status['active']?'check-circle-2':'alert-triangle' ?>"></i>Firewall <?= $status['active']?'active':'inactive' ?></span><span><i data-lucide="list-checks"></i><?= count($status['rules']) ?> explicit rules</span><span><i data-lucide="file-clock"></i><?= count($audit) ?> recent audit events</span><span><i data-lucide="<?= $f2bActive?'shield-ban':'shield-off' ?>"></i>Fail2Ban <?= $f2b['installed']?($f2bActive?(int)$f2b['totals']['banned'].' IPs banned':'stopped'):'not installed' ?></span><span><i data-lucide="<?= $modsecOn?'shield-alert':'shield-off' ?>"></i>ModSecurity <?= $modsec['installed']?($modsec['enabled']?strtolower($modsec['mode']==='DetectionOnly'?'detection only':$modsec['mode']):'disabled'):'not installed' ?></span></div><button class="btn <?= $status['active']?'btn-danger':'btn-primary' ?>" data-fw="<?= $status['active']?'disable':'enable' ?>"><i data-lucide="power"></i><?= $status['active']?'Disable UFW':'Enable UFW' ?></button></div></div>
<div class="tabs" id="securityTabs" style="margin-bottom:16px"><button class="tab active" data-tab-target="securityFirewall"><i data-lucide="shield"></i>Firewall (UFW)</button><button class="tab" data-tab-target="securityFail2ban"><i data-lucide="shield-ban"></i>Fail2Ban<?php if($f2bActive&&$f2b['totals']['banned']): ?> <span class="badge badge-red"><?= (int)$f2b['totals']['banned'] ?></span><?php endif; ?></button><button class="tab" data-tab-target="securityModsec"><i data-lucide="shield-alert"></i>ModSecurity<?php if($modsecOn): ?> <span class="badge badge-emerald">on</span><?php endif; ?></button><button class="tab" data-tab-target="securityAudit"><i data-lucide="history"></i>Audit Log</button></div>
<div data-tab-panels>
<section data-tab-panel id="securityFirewall" class="card"><div class="card-header"><h3>Firewall rules (UFW)</h3><span class="badge <?= $status['active']?'badge-emerald':'badge-slate' ?>"><span class="bdot"></span><?= $status['active']?'Active':'Inactive' ?></span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>#</th><th>Rule</th><th>Action</th><th>Source / destination</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($status['rules'] as $rule): $raw=$rule['raw'];$deny=stripos($raw,'DENY')!==false||stripos($raw,'REJECT')!==false; ?><tr><td class="mono"><?= (int)$rule['num'] ?></td><td class="mono"><?= e($raw) ?></td><td><span class="badge <?= $deny?'badge-red':'badge-emerald' ?>"><?= $deny?'Block':'Allow' ?></span></td><td class="mono text-tertiary"><?= stripos($raw,'Anywhere')!==false?'Anywhere':'Custom' ?></td><td><span class="badge badge-blue">Enabled</span></td><td style="text-align:right"><button class="icon-btn" data-fw-del="<?= (int)$rule['num'] ?>" style="color:var(--red-400)"><i data-lucide="trash-2"></i></button></td></tr><?php endforeach; ?><?php if(!$status['rules']): ?><tr><td colspan="6" class="text-tertiary" style="text-align:center;padding:28px">No firewall rules yet.</td></tr><?php endif; ?></tbody></table></div></section>
<section data-tab-panel id="securityFail2ban" class="hidden">
<?php if(!$f2b['installed']): ?>
  <div class="card"><div class="empty-state"><div class="es-icon"><i data-lucide="shield-off"></i></div><div style="font-weight:600">Fail2Ban is not installed</div><div style="font-size:13px;margin-top:4px">Install it from <a href="<?= e(url('apps')) ?>">Install Apps</a> to block brute-force attacks on SSH, mail and the panel.</div></div></div>
<?php else: ?>
  <?php if(!empty($f2b['error'])): ?><div class="notice notice-warning" style="margin-bottom:14px"><i data-lucide="alert-triangle"></i><div><strong>Fail2Ban status unavailable</strong><div><?= e($f2b['error']) ?></div></div></div><?php endif; ?>
  <div class="grid grid-4" style="margin-bottom:16px">
    <div class="stat-card"><div class="stat-val" id="f2bBanned"><?= (int)$f2b['totals']['banned'] ?></div><div class="stat-label">Currently banned</div></div>
    <div class="stat-card"><div class="stat-val" id="f2bTotalBanned"><?= (int)$f2b['totals']['total_banned'] ?></div><div class="stat-label">Bans since restart</div></div>
    <div class="stat-card"><div class="stat-val" id="f2bFailed"><?= (int)$f2b['totals']['total_failed'] ?></div><div class="stat-label">Failed attempts</div></div>
    <div class="stat-card"><div class="stat-val" id="f2bJails"><?= count($f2b['jails']) ?></div><div class="stat-label">Active jails</div></div>
  </div>
  <div class="card" style="margin-bottom:16px"><div class="card-header"><h3>Jails</h3><div class="flex items-center gap-2"><span class="badge <?= $f2bActive?'badge-emerald':'badge-orange' ?>"><span class="bdot"></span><?= $f2bActive?'Running':ucfirst((string)$f2b['active']) ?></span><?php if(!empty($f2b['version'])): ?><span class="muted" style="font-size:12px">v<?= e((string)$f2b['version']) ?></span><?php endif; ?><button class="btn btn-secondary btn-sm" id="f2bRefresh"><i data-lucide="refresh-cw"></i>Refresh</button></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Jail</th><th>Currently banned</th><th>Total banned</th><th>Currently failed</th><th>Total failed</th><th>Watching</th></tr></thead><tbody id="f2bJailRows"></tbody></table></div></div>
  <div class="card" style="margin-bottom:16px"><div class="card-header"><h3>Banned IP addresses</h3><span class="muted">Unban releases the address immediately</span></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>IP address</th><th>Jail</th><th style="text-align:right">Actions</th></tr></thead><tbody id="f2bBanRows"></tbody></table></div>
    <div class="card-pad" style="border-top:1px solid var(--border-subtle);display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <select class="select" id="f2bBanJail" style="width:auto"></select>
      <input class="input mono" id="f2bBanIp" placeholder="203.0.113.10" style="max-width:220px">
      <button class="btn btn-danger btn-sm" id="f2bBanAdd"><i data-lucide="ban"></i>Ban IP</button>
    </div></div>
  <div class="card"><div class="card-header"><h3>Recent activity</h3><button class="btn btn-secondary btn-sm" id="f2bLogLoad"><i data-lucide="scroll-text"></i>Load log</button></div>
    <pre class="mono" id="f2bLog" style="margin:0;padding:16px;font-size:12px;line-height:1.55;white-space:pre-wrap;max-height:44vh;overflow:auto">Load the log to see recent bans, unbans and detections.</pre></div>
<?php endif; ?>
</section>
<section data-tab-panel id="securityModsec" class="hidden">
<?php if(!$modsec['installed']): ?>
  <div class="card"><div class="empty-state"><div class="es-icon"><i data-lucide="shield-off"></i></div><div style="font-weight:600">ModSecurity is not installed</div><div style="font-size:13px;margin-top:4px">Install it from <a href="<?= e(url('apps')) ?>">Install Apps</a> to run the OWASP Core Rule Set in front of every nginx site.<?= $modsec['error']?' ':'' ?><?= e($modsec['error']) ?></div></div></div>
<?php else: ?>
  <?php if(!empty($modsec['error'])): ?><div class="notice notice-warning" style="margin-bottom:14px"><i data-lucide="alert-triangle"></i><div><strong>ModSecurity status unavailable</strong><div><?= e($modsec['error']) ?></div></div></div><?php endif; ?>
  <?php if(!$modsec['enabled']): ?><div class="notice notice-warning" style="margin-bottom:14px"><i data-lucide="alert-triangle"></i><div><strong>The module is installed but not enabled in nginx</strong><div>Re-install ModSecurity from <a href="<?= e(url('apps')) ?>">Install Apps</a> to rewrite the nginx configuration.</div></div></div><?php endif; ?>
  <div class="grid grid-4" style="margin-bottom:16px">
    <div class="stat-card"><div class="stat-val" id="msMode"><?= e($modsec['mode']==='DetectionOnly'?'Detect':$modsec['mode']) ?></div><div class="stat-label">Rule engine</div></div>
    <div class="stat-card"><div class="stat-val" id="msCrs"><?= $modsec['crs']?'Yes':'No' ?></div><div class="stat-label">OWASP CRS rules</div></div>
    <div class="stat-card"><div class="stat-val" id="msDenied"><?= (int)$modsec['denied_recent'] ?></div><div class="stat-label">Requests denied (recent)</div></div>
    <div class="stat-card"><div class="stat-val" id="msEvents"><?= (int)$modsec['audit_events'] ?></div><div class="stat-label">Audit log entries</div></div>
  </div>
  <div class="card" style="margin-bottom:16px"><div class="card-header"><h3>Web application firewall</h3><div class="flex items-center gap-2"><span class="badge <?= $modsecOn?'badge-emerald':($modsec['mode']==='DetectionOnly'?'badge-orange':'badge-slate') ?>" id="msBadge"><span class="bdot"></span><?= e($modsec['mode']) ?></span><button class="btn btn-secondary btn-sm" id="msRefresh"><i data-lucide="refresh-cw"></i>Refresh</button></div></div>
    <div class="card-pad">
      <div class="field-label">Rule engine mode</div>
      <div class="flex gap-2" style="flex-wrap:wrap;margin-bottom:10px">
        <button class="btn btn-primary btn-sm" data-ms-mode="On"><i data-lucide="shield-check"></i>Blocking (On)</button>
        <button class="btn btn-secondary btn-sm" data-ms-mode="DetectionOnly"><i data-lucide="eye"></i>Detection only</button>
        <button class="btn btn-secondary btn-sm" data-ms-mode="Off"><i data-lucide="power-off"></i>Off</button>
      </div>
      <div class="field-help">Blocking rejects matching requests; detection only logs them. Changes are validated with <span class="mono">nginx -t</span> and reload nginx — a rejected config is rolled back automatically.</div>
      <div style="margin-top:14px;display:grid;gap:6px;font-size:12px">
        <div><span class="text-tertiary">Rules file</span> <span class="mono" id="msRules"><?= e($modsec['rules_file']?:'—') ?></span></div>
        <div><span class="text-tertiary">Base config</span> <span class="mono" id="msConfig"><?= e($modsec['config_file']?:'—') ?></span></div>
        <div><span class="text-tertiary">Audit log</span> <span class="mono" id="msAudit"><?= e($modsec['audit_log']?:'—') ?></span></div>
      </div>
    </div></div>
  <div class="card"><div class="card-header"><h3>Recent findings</h3><button class="btn btn-secondary btn-sm" id="msLogLoad"><i data-lucide="scroll-text"></i>Load log</button></div>
    <pre class="mono" id="msLog" style="margin:0;padding:16px;font-size:12px;line-height:1.55;white-space:pre-wrap;max-height:44vh;overflow:auto">Load the log to see recent rule matches and blocked requests.</pre></div>
<?php endif; ?>
</section>
<section data-tab-panel id="securityAudit" class="card hidden"><div class="card-header"><h3>Admin action audit log</h3><span class="muted">Most recent first</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Event</th></tr></thead><tbody><?php foreach($audit as $line): ?><tr><td class="mono text-tertiary" style="font-size:12px;white-space:normal"><?= e($line) ?></td></tr><?php endforeach; ?><?php if(!$audit): ?><tr><td class="text-tertiary" style="text-align:center;padding:24px">No audit entries yet.</td></tr><?php endif; ?></tbody></table></div></section>
</div>
<div class="drawer-overlay hidden" id="fwDrawer"><div class="drawer"><div class="drawer-header"><div><strong>Add Firewall Rule</strong><div class="muted" style="font-size:11px">Create a scoped UFW allow or block rule</div></div><button class="icon-btn" data-close-fw><i data-lucide="x"></i></button></div><div class="drawer-body"><div class="form-stack"><div><label class="field-label">Action</label><select class="select" id="fwAction"><option value="allow">Allow</option><option value="deny">Deny</option><option value="reject">Reject</option></select></div><div><label class="field-label">Port or service</label><input class="input mono" id="fwPort" placeholder="22, 53, http"></div><div><label class="field-label">Protocol</label><select class="select" id="fwProto"><option value="tcp">TCP</option><option value="udp">UDP</option><option value="any">Any</option></select></div><div><label class="field-label">Common services</label><div class="flex gap-2" style="flex-wrap:wrap"><?php foreach(['22'=>'SSH','80'=>'HTTP','443'=>'HTTPS','53'=>'DNS','3306'=>'MySQL'] as $port=>$name): ?><button class="chip" type="button" data-fw-port="<?= e($port) ?>"><?= e($name) ?></button><?php endforeach; ?></div></div></div></div><div class="drawer-footer"><button class="btn btn-secondary" data-close-fw>Cancel</button><button class="btn btn-primary" id="fwAdd"><i data-lucide="plus"></i>Add rule</button></div></div></div>
<script>document.addEventListener('DOMContentLoaded',()=>{const{apiPost,toast}=window.Nebula,drawer=document.getElementById('fwDrawer');document.getElementById('fwAddOpen').onclick=()=>drawer.classList.remove('hidden');document.querySelectorAll('[data-close-fw]').forEach(b=>b.onclick=()=>drawer.classList.add('hidden'));document.querySelectorAll('[data-fw-port]').forEach(b=>b.onclick=()=>document.getElementById('fwPort').value=b.dataset.fwPort);document.querySelectorAll('[data-fw]').forEach(b=>b.onclick=async()=>{if(b.dataset.fw==='disable'&&!confirm('Disable the firewall?'))return;const r=await apiPost('firewall',{action:b.dataset.fw});toast(r.ok?'Firewall updated':(r.error||'Failed'),r.ok?'success':'error');if(r.ok)setTimeout(()=>location.reload(),400);});document.getElementById('fwAdd').onclick=async()=>{const r=await apiPost('firewall',{action:'add',ufwAction:document.getElementById('fwAction').value,port:document.getElementById('fwPort').value,proto:document.getElementById('fwProto').value});toast(r.ok?'Firewall rule added':(r.error||'Failed'),r.ok?'success':'error');if(r.ok)setTimeout(()=>location.reload(),350);};document.querySelectorAll('[data-fw-del]').forEach(b=>b.onclick=async()=>{if(!confirm('Delete this firewall rule?'))return;const r=await apiPost('firewall',{action:'delete',num:+b.dataset.fwDel});toast(r.ok?'Firewall rule deleted':(r.error||'Failed'),r.ok?'success':'error');if(r.ok)setTimeout(()=>location.reload(),300);});});</script>
<?php if($f2b['installed']): ?>
<script>
document.addEventListener('DOMContentLoaded',()=>{const{apiGet,apiPost,toast}=window.Nebula;let f2b=null;
 const $=id=>document.getElementById(id);
 const mk=(tag,cls,text)=>{const e=document.createElement(tag);if(cls)e.className=cls;if(text!==undefined)e.textContent=text;return e;};
 const empty=(body,cols,text)=>{const tr=mk('tr'),td=mk('td','text-tertiary',text);td.colSpan=cols;td.style.cssText='text-align:center;padding:24px';tr.append(td);body.append(tr);};
 function render(){if(!f2b)return;
  $('f2bBanned').textContent=f2b.totals.banned;$('f2bTotalBanned').textContent=f2b.totals.total_banned;
  $('f2bFailed').textContent=f2b.totals.total_failed;$('f2bJails').textContent=f2b.jails.length;
  const jr=$('f2bJailRows');jr.innerHTML='';
  f2b.jails.forEach(j=>{const tr=mk('tr');const nm=mk('td');nm.append(mk('span','badge badge-blue',j.name));
   const cb=mk('td');cb.append(mk('span',`badge ${j.currently_banned?'badge-red':'badge-slate'}`,String(j.currently_banned)));
   tr.append(nm,cb,mk('td','mono',String(j.total_banned)),mk('td','mono',String(j.currently_failed)),mk('td','mono',String(j.total_failed)),mk('td','mono text-tertiary',j.logfiles||'—'));
   jr.append(tr);});
  if(!f2b.jails.length)empty(jr,6,f2b.active==='active'?'No jails are enabled. Configure them in /etc/fail2ban/jail.local.':'Fail2Ban is installed but not running.');
  const br=$('f2bBanRows');br.innerHTML='';let bans=0;
  f2b.jails.forEach(j=>j.banned_ips.forEach(ip=>{bans++;const tr=mk('tr');tr.append(mk('td','mono',ip),mk('td','',j.name));
   const td=mk('td');td.style.textAlign='right';const b=mk('button','btn btn-secondary btn-sm');b.innerHTML='<i data-lucide="shield-check"></i>Unban';
   b.onclick=async()=>{if(!confirm(`Unban ${ip} from ${j.name}?`))return;b.disabled=true;const r=await apiPost('fail2ban',{action:'unban',jail:j.name,ip});
    toast(r.ok?`${ip} unbanned`:(r.error||'Unban failed'),r.ok?'success':'error');load();};
   td.append(b);tr.append(td);br.append(tr);}));
  if(!bans)empty(br,3,'No addresses are currently banned.');
  const sel=$('f2bBanJail');const prev=sel.value;sel.innerHTML='';
  f2b.jails.forEach(j=>{const o=document.createElement('option');o.value=j.name;o.textContent=j.name;sel.append(o);});
  if(prev)sel.value=prev;
  if(window.lucide)lucide.createIcons();}
 async function load(){try{f2b=await apiGet('fail2ban');}catch(e){toast('Could not read Fail2Ban status','error');return;}render();}
 $('f2bRefresh')?.addEventListener('click',load);
 $('f2bBanAdd')?.addEventListener('click',async()=>{const jail=$('f2bBanJail').value,ip=$('f2bBanIp').value.trim();
  if(!jail){toast('No jail available to ban in','warning');return;}
  if(!ip){toast('Enter an IP address','warning');return;}
  const r=await apiPost('fail2ban',{action:'ban',jail,ip});
  toast(r.ok?`${ip} banned in ${jail}`:(r.error||'Ban failed'),r.ok?'success':'error');if(r.ok){$('f2bBanIp').value='';load();}});
 $('f2bLogLoad')?.addEventListener('click',async(e)=>{const btn=e.currentTarget,out=$('f2bLog');out.textContent='Loading…';btn.disabled=true;
  const r=await apiPost('fail2ban',{action:'log',lines:300});
  out.textContent=r.ok?((r.log||'').trim()||'(no recent activity)'):(r.error||'Could not read the log');
  out.scrollTop=out.scrollHeight;btn.disabled=false;});
 // Only hit the API once the tab is opened — status costs a fail2ban-client call.
 let loaded=false;document.querySelector('[data-tab-target="securityFail2ban"]')?.addEventListener('click',()=>{if(!loaded){loaded=true;load();}});
});
</script>
<?php endif; ?>
<?php if($modsec['installed']): ?>
<script>
document.addEventListener('DOMContentLoaded',()=>{const{apiGet,apiPost,toast}=window.Nebula;
 const $=id=>document.getElementById(id);
 function render(m){if(!m)return;
  $('msMode').textContent=m.mode==='DetectionOnly'?'Detect':m.mode;
  $('msCrs').textContent=m.crs?'Yes':'No';
  $('msDenied').textContent=m.denied_recent;$('msEvents').textContent=m.audit_events;
  $('msRules').textContent=m.rules_file||'—';$('msConfig').textContent=m.config_file||'—';$('msAudit').textContent=m.audit_log||'—';
  const badge=$('msBadge');badge.className=`badge ${m.mode==='On'?'badge-emerald':(m.mode==='DetectionOnly'?'badge-orange':'badge-slate')}`;
  badge.innerHTML='<span class="bdot"></span>';badge.append(m.mode);
  document.querySelectorAll('[data-ms-mode]').forEach(b=>b.classList.toggle('btn-primary',b.dataset.msMode===m.mode));
  document.querySelectorAll('[data-ms-mode]').forEach(b=>b.classList.toggle('btn-secondary',b.dataset.msMode!==m.mode));
  if(window.lucide)lucide.createIcons();}
 async function load(){try{render(await apiGet('modsecurity'));}catch(e){toast('Could not read ModSecurity status','error');}}
 $('msRefresh')?.addEventListener('click',load);
 document.querySelectorAll('[data-ms-mode]').forEach(b=>b.addEventListener('click',async()=>{
  const mode=b.dataset.msMode;
  if(mode==='On'&&!confirm('Switch ModSecurity to blocking mode? Requests matching a rule will be rejected — check the findings log for false positives first.'))return;
  if(mode==='Off'&&!confirm('Turn the web application firewall off completely?'))return;
  b.disabled=true;const r=await apiPost('modsecurity',{action:'mode',mode});b.disabled=false;
  toast(r.ok?`ModSecurity set to ${mode}`:(r.error||'Could not change the mode'),r.ok?'success':'error');if(r.ok)load();}));
 $('msLogLoad')?.addEventListener('click',async(e)=>{const btn=e.currentTarget,out=$('msLog');out.textContent='Loading…';btn.disabled=true;
  const r=await apiPost('modsecurity',{action:'log',lines:300});
  out.textContent=r.ok?((r.log||'').trim()||'(no recent findings)'):(r.error||'Could not read the log');
  out.scrollTop=out.scrollHeight;btn.disabled=false;});
 // Status costs a helper call — only refresh once the tab is actually opened.
 let msLoaded=false;document.querySelector('[data-tab-target="securityModsec"]')?.addEventListener('click',()=>{if(!msLoaded){msLoaded=true;load();}});
});
</script>
<?php endif; ?>
<?php endif; ?>
