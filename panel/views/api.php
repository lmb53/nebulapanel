<?php
require_once APP_ROOT . '/lib/mod_api.php';
$tokens = array_map('api_token_public', api_tokens_load());
$isExpired = static fn(array $t): bool => ($ts = strtotime((string) ($t['expires_at'] ?? ''))) !== false && $ts < time();
$activeCount = count(array_filter($tokens, fn($t) => !$isExpired($t)));
?>
<div class="page-header">
  <div><h1 class="page-title">API Tokens</h1><p class="page-subtitle">Named, expiring bearer credentials for automation (<span class="mono">/?r=api/v1/&lt;endpoint&gt;</span>)</p></div>
</div>
<div class="grid grid-main-side" style="margin-bottom:16px">
<div class="card">
  <div class="card-header"><h3>Generate token</h3></div>
  <form class="card-pad form-stack" id="apiForm" novalidate>
    <div class="form-grid" style="--cols:minmax(0,1.4fr) minmax(0,1fr) minmax(0,.8fr)">
      <div><label class="field-label" for="apiLabel">Name<span class="req" aria-hidden="true">*</span></label><input class="input" id="apiLabel" required maxlength="80" placeholder="monitoring-bot" autocomplete="off"></div>
      <div><label class="field-label" for="apiRole">Role</label><select class="select" id="apiRole"><option value="auditor">Auditor (read-only)</option><option value="operator">Operator</option><option value="developer">Developer</option><option value="admin">Administrator</option></select></div>
      <div><label class="field-label" for="apiTtl">Expires after (days)<span class="req" aria-hidden="true">*</span></label><input class="input mono" id="apiTtl" type="number" required min="1" max="365" value="30"></div>
    </div>
    <div><label class="field-label" for="apiScopes">Scopes<span class="req" aria-hidden="true">*</span></label><input class="input mono" id="apiScopes" required value="get:health" aria-describedby="apiScopesHelp"><div class="field-help" id="apiScopesHelp">Comma-separated <span class="mono">method:endpoint</span> pairs, e.g. <span class="mono">get:health, get:metrics</span>.</div></div>
    <div><label class="field-label" for="apiIps">Allowed source IPs <span class="text-tertiary" style="font-weight:400">(optional)</span></label><input class="input mono" id="apiIps" placeholder="203.0.113.10, 2001:db8::10" aria-describedby="apiIpsHelp"><div class="field-help" id="apiIpsHelp">Leave empty to accept the token from any address.</div></div>
    <div><button class="btn btn-primary" type="submit" id="apiGenerate"><i data-lucide="key-round"></i>Generate token</button></div>
    <div id="apiReveal" class="notice notice-success hidden" role="status">
      <i data-lucide="circle-check"></i>
      <div style="flex:1;min-width:0"><strong>Token created — copy it now</strong><div>This value is shown only once.</div>
        <div class="flex gap-2" style="margin-top:8px"><input class="input mono" id="apiPlain" readonly aria-label="New token value"><button class="btn btn-secondary" type="button" id="apiCopy"><i data-lucide="copy"></i>Copy</button></div>
      </div>
    </div>
  </form>
</div>
<div class="card">
  <div class="card-header"><h3>About roles</h3></div>
  <div class="card-pad field-help" style="margin:0;font-size:13px">A token acts with its role’s permissions, limited further to its scopes. Prefer <strong>Auditor</strong> with narrow <span class="mono">get:</span> scopes for monitoring.</div>
</div>
</div>
<div class="card">
  <div class="card-header"><h3>Tokens</h3><span class="muted" id="apiActiveCount"><?= $activeCount ?> active<?= count($tokens) > $activeCount ? ' · ' . (count($tokens) - $activeCount) . ' expired' : '' ?></span></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Role / scopes</th><th>Expires</th><th>Last used</th><th class="actions-col"><span class="sr-only">Actions</span></th></tr></thead><tbody id="apiTokenRows">
  <?php foreach ($tokens as $token): ?><tr>
    <td style="font-weight:600"><?= e($token['label']) ?></td>
    <td><span class="badge badge-blue"><?= e(panel_roles()[$token['role'] ?? 'auditor']['label'] ?? ($token['role'] ?? 'auditor')) ?></span> <span class="mono text-tertiary" style="font-size:12px"><?= e(implode(', ', (array) ($token['scopes'] ?? []))) ?></span><?php if(!empty($token['allowed_ips'])): ?><div class="mono text-tertiary" style="font-size:12px;margin-top:3px"><?= e(implode(', ', $token['allowed_ips'])) ?></div><?php endif; ?></td>
    <td><?= e(fmt_datetime($token['expires_at'] ?? '')) ?><?php if ($isExpired($token)): ?> <span class="badge badge-red">Expired</span><?php endif; ?></td>
    <td class="text-tertiary"><?= !empty($token['last_used_at']) ? e(fmt_datetime($token['last_used_at'])) : 'Never' ?></td>
    <td class="actions-col"><button class="btn btn-danger btn-sm" type="button" data-api-revoke="<?= e($token['id']) ?>">Revoke</button></td>
  </tr><?php endforeach; ?>
  <?php if (!$tokens): ?><tr class="empty-row" id="apiNoTokens"><td colspan="5">No API tokens yet.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<script>
document.addEventListener('DOMContentLoaded',()=>{const{apiPost,toast}=window.Nebula;
document.getElementById('apiForm').onsubmit=async(event)=>{event.preventDefault();if(!window.Nebula.validateForm(event.currentTarget))return;const label=document.getElementById('apiLabel').value.trim();const role=document.getElementById('apiRole').value,ttl_days=Number(document.getElementById('apiTtl').value),scopes=document.getElementById('apiScopes').value.split(',').map(x=>x.trim()).filter(Boolean),allowed_ips=document.getElementById('apiIps').value.split(',').map(x=>x.trim()).filter(Boolean);const btn=document.getElementById('apiGenerate');if(btn.disabled)return;window.Nebula.setBusy(btn,true);const r=await apiPost('tokens',{action:'generate',label,role,scopes,ttl_days,allowed_ips});window.Nebula.setBusy(btn,false);if(!r.ok)return toast(r.error||'Could not generate the token','error');document.getElementById('apiPlain').value=r.token;document.getElementById('apiReveal').classList.remove('hidden');addRow(r.record||{label,role,scopes,allowed_ips});toast('Token created','success');};
// Show the new token in the list straight away; a reload would hide the one-time value above.
function addRow(t){document.getElementById('apiNoTokens')?.remove();const roleLabels={auditor:'Auditor',operator:'Operator',developer:'Developer',admin:'Administrator'};const tr=document.createElement('tr');const cell=(text,cls)=>{const td=document.createElement('td');if(cls)td.className=cls;if(text!==undefined)td.textContent=text;return td;};const name=cell(t.label);name.style.fontWeight='600';const roleTd=cell();const badge=document.createElement('span');badge.className='badge badge-blue';badge.textContent=roleLabels[t.role]||t.role;const sc=document.createElement('span');sc.className='mono text-tertiary';sc.style.fontSize='12px';sc.textContent=' '+(t.scopes||[]).join(', ');roleTd.append(badge,sc);if((t.allowed_ips||[]).length){const ips=document.createElement('div');ips.className='mono text-tertiary';ips.style.cssText='font-size:12px;margin-top:3px';ips.textContent=t.allowed_ips.join(', ');roleTd.appendChild(ips);}const act=cell(undefined,'actions-col');if(t.id){const rv=document.createElement('button');rv.type='button';rv.className='btn btn-danger btn-sm';rv.dataset.apiRevoke=t.id;rv.textContent='Revoke';bindRevoke(rv);act.appendChild(rv);}tr.append(name,roleTd,cell(window.Nebula.fmtDate(t.expires_at)),cell('Never','text-tertiary'),act);document.getElementById('apiTokenRows').prepend(tr);}
document.getElementById('apiCopy').onclick=async()=>{const ok=await window.Nebula.copyText(document.getElementById('apiPlain').value);toast(ok?'Token copied':'Copy failed — select the value and copy it manually',ok?'success':'error');};
function bindRevoke(b){b.onclick=async()=>{if(!await window.Nebula.confirm({title:'Revoke this token?',message:'Automation using it will stop working immediately.',danger:true,confirmLabel:'Revoke'}))return;const r=await apiPost('tokens',{action:'revoke',id:b.dataset.apiRevoke});if(r.ok)location.reload();else toast(r.error||'Could not revoke the token','error');};}
document.querySelectorAll('[data-api-revoke]').forEach(bindRevoke);});
</script>
