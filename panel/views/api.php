<?php
require_once APP_ROOT . '/lib/mod_api.php';
$tokens = array_map('api_token_public', api_tokens_load());
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
  <div class="card-header"><h3>Active tokens</h3><span class="muted"><?= count($tokens) ?> active</span></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Role / scopes</th><th>Expires</th><th>Last used</th><th class="actions-col"><span class="sr-only">Actions</span></th></tr></thead><tbody>
  <?php foreach ($tokens as $token): ?><tr>
    <td style="font-weight:600"><?= e($token['label']) ?></td>
    <td><span class="badge badge-blue"><?= e(panel_roles()[$token['role'] ?? 'auditor']['label'] ?? ($token['role'] ?? 'auditor')) ?></span> <span class="mono text-tertiary" style="font-size:12px"><?= e(implode(', ', (array) ($token['scopes'] ?? []))) ?></span><?php if(!empty($token['allowed_ips'])): ?><div class="mono text-tertiary" style="font-size:12px;margin-top:3px"><?= e(implode(', ', $token['allowed_ips'])) ?></div><?php endif; ?></td>
    <td><?= e(fmt_datetime($token['expires_at'] ?? '')) ?></td>
    <td class="text-tertiary"><?= !empty($token['last_used_at']) ? e(fmt_datetime($token['last_used_at'])) : 'Never' ?></td>
    <td class="actions-col"><button class="btn btn-danger btn-sm" type="button" data-api-revoke="<?= e($token['id']) ?>">Revoke</button></td>
  </tr><?php endforeach; ?>
  <?php if (!$tokens): ?><tr class="empty-row"><td colspan="5">No active API tokens.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<script>
document.addEventListener('DOMContentLoaded',()=>{const{apiPost,toast}=window.Nebula;
document.getElementById('apiForm').onsubmit=async(event)=>{event.preventDefault();if(!window.Nebula.validateForm(event.currentTarget))return;const label=document.getElementById('apiLabel').value.trim();const role=document.getElementById('apiRole').value,ttl_days=Number(document.getElementById('apiTtl').value),scopes=document.getElementById('apiScopes').value.split(',').map(x=>x.trim()).filter(Boolean),allowed_ips=document.getElementById('apiIps').value.split(',').map(x=>x.trim()).filter(Boolean);const r=await apiPost('tokens',{action:'generate',label,role,scopes,ttl_days,allowed_ips});if(!r.ok)return toast(r.error||'Failed','error');document.getElementById('apiPlain').value=r.token;document.getElementById('apiReveal').classList.remove('hidden');};
document.getElementById('apiCopy').onclick=async()=>{const ok=await window.Nebula.copyText(document.getElementById('apiPlain').value);toast(ok?'Token copied':'Copy failed — select the value and copy it manually',ok?'success':'error');};
document.querySelectorAll('[data-api-revoke]').forEach(b=>b.onclick=async()=>{if(!await window.Nebula.confirm({title:'Revoke this token?',message:'Automation using it will stop working immediately.',danger:true,confirmLabel:'Revoke'}))return;const r=await apiPost('tokens',{action:'revoke',id:b.dataset.apiRevoke});if(r.ok)location.reload();else toast(r.error||'Failed','error');});});
</script>
