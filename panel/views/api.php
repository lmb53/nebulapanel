<?php
require_once APP_ROOT . '/lib/mod_api.php';
$tokens = array_map('api_token_public', api_tokens_load());
?>
<div class="page-header">
  <div><h1 class="page-title">API tokens</h1><p class="page-subtitle">Named, expiring bearer credentials for automation</p></div>
</div>
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><h3>Generate token</h3></div>
  <div class="card-pad">
    <label class="field-label" for="apiLabel">Automation identity</label>
    <div class="grid grid-3">
      <input class="input" id="apiLabel" maxlength="80" placeholder="monitoring-bot">
      <select class="input" id="apiRole"><option value="auditor">Auditor</option><option value="operator">Operator</option><option value="developer">Developer</option><option value="admin">Administrator</option></select>
      <input class="input mono" id="apiTtl" type="number" min="1" max="365" value="30" aria-label="Token lifetime in days">
    </div>
    <label class="field-label" for="apiScopes" style="margin-top:10px">Scopes (comma-separated method:endpoint)</label>
    <div style="display:flex;gap:8px"><input class="input mono" id="apiScopes" value="get:health"><button class="btn btn-primary" id="apiGenerate">Generate token</button></div>
    <label class="field-label" for="apiIps" style="margin-top:10px">Allowed source IPs (optional, comma-separated)</label>
    <input class="input mono" id="apiIps" placeholder="203.0.113.10, 2001:db8::10">
    <div id="apiReveal" class="hidden" style="margin-top:12px">
      <label class="field-label" for="apiPlain">Copy now; this value is shown once</label>
      <div style="display:flex;gap:8px"><input class="input mono" id="apiPlain" readonly><button class="btn btn-secondary" id="apiCopy">Copy</button></div>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-header"><h3>Active tokens</h3><span class="muted"><?= count($tokens) ?></span></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Identity</th><th>Role / scopes</th><th>Expires</th><th>Last used</th><th></th></tr></thead><tbody>
  <?php foreach ($tokens as $token): ?><tr>
    <td><?= e($token['label']) ?></td>
    <td class="mono"><?= e($token['role'] ?? 'auditor') ?> · <?= e(implode(', ', (array) ($token['scopes'] ?? []))) ?><?php if(!empty($token['allowed_ips'])): ?><br><span class="text-tertiary"><?= e(implode(', ', $token['allowed_ips'])) ?></span><?php endif; ?></td>
    <td class="mono"><?= e($token['expires_at'] ?? '') ?></td>
    <td class="mono"><?= e($token['last_used_at'] ?? 'Never') ?></td>
    <td><button class="btn btn-danger btn-sm" data-api-revoke="<?= e($token['id']) ?>">Revoke</button></td>
  </tr><?php endforeach; ?>
  <?php if (!$tokens): ?><tr><td colspan="5" class="text-tertiary" style="text-align:center;padding:24px">No active API tokens.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<script>
document.addEventListener('DOMContentLoaded',()=>{const{apiPost,toast}=window.Nebula;
document.getElementById('apiGenerate').onclick=async()=>{const label=document.getElementById('apiLabel').value.trim();if(!label)return toast('Enter an identity','warning');const role=document.getElementById('apiRole').value,ttl_days=Number(document.getElementById('apiTtl').value),scopes=document.getElementById('apiScopes').value.split(',').map(x=>x.trim()).filter(Boolean),allowed_ips=document.getElementById('apiIps').value.split(',').map(x=>x.trim()).filter(Boolean);const r=await apiPost('tokens',{action:'generate',label,role,scopes,ttl_days,allowed_ips});if(!r.ok)return toast(r.error||'Failed','error');document.getElementById('apiPlain').value=r.token;document.getElementById('apiReveal').classList.remove('hidden');};
document.getElementById('apiCopy').onclick=()=>navigator.clipboard.writeText(document.getElementById('apiPlain').value);
document.querySelectorAll('[data-api-revoke]').forEach(b=>b.onclick=async()=>{if(!confirm('Revoke this token?'))return;const r=await apiPost('tokens',{action:'revoke',id:b.dataset.apiRevoke});if(r.ok)location.reload();else toast(r.error||'Failed','error');});});
</script>
