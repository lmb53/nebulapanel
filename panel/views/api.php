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
      <div><label class="field-label" for="apiRole">Role</label><select class="select" id="apiRole"><option value="auditor">Auditor (read-only)</option><option value="operator">Operator</option><option value="admin">Administrator</option></select></div>
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
  <div class="card-pad field-help" style="margin:0;font-size:13px">A token acts with its role’s permissions, limited further to its scopes, and never with more access than the person who created it: it stops working if that account is disabled or deleted. Everyone can also manage their own tokens on their Account page. Prefer <strong>Auditor</strong> with narrow <span class="mono">get:</span> scopes for monitoring.</div>
</div>
</div>
<div class="card">
  <div class="card-header"><h3>Tokens</h3><span class="muted" id="apiActiveCount"><?= $activeCount ?> active<?= count($tokens) > $activeCount ? ' · ' . (count($tokens) - $activeCount) . ' expired' : '' ?></span></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Role / scopes</th><th>Expires</th><th>Last used</th><th class="actions-col"><span class="sr-only">Actions</span></th></tr></thead><tbody id="apiTokenRows">
  <?php foreach ($tokens as $token): ?><tr>
    <td><div style="font-weight:600"><?= e($token['label']) ?></div><div class="text-tertiary" style="font-size:12px"><?= isset($token['owner']) ? 'Owner: ' . e($token['owner']) : 'No owner (created before per-user tokens)' ?></div></td>
    <td><span class="badge badge-blue"><?= e(panel_roles()[$token['role'] ?? 'auditor']['label'] ?? ($token['role'] ?? 'auditor')) ?></span> <span class="mono text-tertiary" style="font-size:12px"><?= e(implode(', ', (array) ($token['scopes'] ?? []))) ?></span><?php if(!empty($token['allowed_ips'])): ?><div class="mono text-tertiary" style="font-size:12px;margin-top:3px"><?= e(implode(', ', $token['allowed_ips'])) ?></div><?php endif; ?></td>
    <td><?= e(fmt_datetime($token['expires_at'] ?? '')) ?><?php if ($isExpired($token)): ?> <span class="badge badge-red">Expired</span><?php endif; ?></td>
    <td class="text-tertiary"><?= !empty($token['last_used_at']) ? e(fmt_datetime($token['last_used_at'])) : 'Never' ?></td>
    <td class="actions-col"><button class="btn btn-danger btn-sm" type="button" data-api-revoke="<?= e($token['id']) ?>">Revoke</button></td>
  </tr><?php endforeach; ?>
  <?php if (!$tokens): ?><tr class="empty-row" id="apiNoTokens"><td colspan="5">No API tokens yet.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<script nonce="<?= e(csp_nonce()) ?>">
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast } = window.Nebula;
  const $ = (id) => document.getElementById(id);
  const list = (id) => $(id).value.split(',').map((x) => x.trim()).filter(Boolean);
  const roleLabels = { auditor: 'Auditor', operator: 'Operator', developer: 'Developer', admin: 'Administrator' };

  function bindRevoke(button) {
    button.addEventListener('click', async () => {
      if (!await window.Nebula.confirm({ title: 'Revoke this token?', message: 'Automation using it will stop working immediately.', danger: true, confirmLabel: 'Revoke' })) return;
      const res = await apiPost('tokens', { action: 'revoke', id: button.dataset.apiRevoke });
      if (res.ok) location.reload(); else toast(res.error || 'Could not revoke the token', 'error');
    });
  }

  // Show the new token in the list straight away; a reload would hide the one-time value above.
  function addRow(t) {
    $('apiNoTokens')?.remove();
    const cell = (text, cls) => { const td = document.createElement('td'); if (cls) td.className = cls; if (text !== undefined) td.textContent = text; return td; };
    const name = cell();
    const label = document.createElement('div'); label.style.fontWeight = '600'; label.textContent = t.label;
    const owner = document.createElement('div'); owner.className = 'text-tertiary'; owner.style.fontSize = '12px'; owner.textContent = t.owner ? 'Owner: ' + t.owner : '';
    name.append(label, owner);
    const role = cell();
    const badge = document.createElement('span'); badge.className = 'badge badge-blue'; badge.textContent = roleLabels[t.role] || t.role;
    const scopes = document.createElement('span'); scopes.className = 'mono text-tertiary'; scopes.style.fontSize = '12px'; scopes.textContent = ' ' + (t.scopes || []).join(', ');
    role.append(badge, scopes);
    if ((t.allowed_ips || []).length) {
      const ips = document.createElement('div'); ips.className = 'mono text-tertiary'; ips.style.cssText = 'font-size:12px;margin-top:3px'; ips.textContent = t.allowed_ips.join(', ');
      role.append(ips);
    }
    const actions = cell(undefined, 'actions-col');
    if (t.id) {
      const revoke = document.createElement('button'); revoke.type = 'button'; revoke.className = 'btn btn-danger btn-sm'; revoke.dataset.apiRevoke = t.id; revoke.textContent = 'Revoke';
      bindRevoke(revoke); actions.append(revoke);
    }
    const tr = document.createElement('tr');
    tr.append(name, role, cell(window.Nebula.fmtDate(t.expires_at)), cell('Never', 'text-tertiary'), actions);
    $('apiTokenRows').prepend(tr);
  }

  $('apiForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!window.Nebula.validateForm(event.currentTarget)) return;
    const button = $('apiGenerate');
    if (button.disabled) return;
    const body = { action: 'generate', label: $('apiLabel').value.trim(), role: $('apiRole').value, ttl_days: Number($('apiTtl').value), scopes: list('apiScopes'), allowed_ips: list('apiIps') };
    window.Nebula.setBusy(button, true);
    const res = await apiPost('tokens', body);
    window.Nebula.setBusy(button, false);
    if (!res.ok) { toast(res.error || 'Could not generate the token', 'error'); return; }
    $('apiPlain').value = res.token;
    $('apiReveal').classList.remove('hidden');
    addRow(res.record || body);
    toast('Token created', 'success');
  });
  $('apiCopy').addEventListener('click', async () => {
    const ok = await window.Nebula.copyText($('apiPlain').value);
    toast(ok ? 'Token copied' : 'Copy failed — select the value and copy it manually', ok ? 'success' : 'error');
  });
  document.querySelectorAll('[data-api-revoke]').forEach(bindRevoke);
});
</script>
