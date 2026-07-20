<?php
require_once APP_ROOT . '/lib/mod_sshkeys.php';
$users = sshkey_users();
$selected = (string) ($_GET['user'] ?? ($users[0]['name'] ?? ''));
if (!sshkey_user_allowed($selected)) { $selected = (string) ($users[0]['name'] ?? ''); }
$keys = $selected ? sshkey_list($selected) : [];
?>
<div class="page-header">
  <div><h1 class="page-title">SSH Keys</h1><p class="page-subtitle">Manage authorized public keys for interactive server users</p></div>
  <?php if ($users): ?><form method="get" class="page-actions"><input type="hidden" name="r" value="sshkeys"><select class="select" name="user" onchange="this.form.submit()"><?php foreach ($users as $u): ?><option value="<?= e($u['name']) ?>" <?= $u['name'] === $selected ? 'selected' : '' ?>><?= e($u['name']) ?> — <?= e($u['home']) ?></option><?php endforeach; ?></select></form><?php endif; ?>
</div>
<?php if (!helper_available()): ?><div class="card"><div class="empty-state"><div class="es-icon"><i data-lucide="key-round"></i></div><div>Re-run install.sh to install the privileged helper.</div></div></div>
<?php elseif (!$users): ?><div class="card"><div class="empty-state"><div class="es-icon"><i data-lucide="user-x"></i></div><div>No interactive human users found.</div></div></div>
<?php else: ?>
<div class="card" style="margin-bottom:16px"><div class="card-header"><h3>Add public key</h3></div><div class="card-pad"><label class="field-label">OpenSSH public key</label><textarea class="input mono" id="sshKey" rows="4" placeholder="ssh-ed25519 AAAA… comment"></textarea><div style="margin-top:12px"><button class="btn btn-primary" id="sshAdd"><i data-lucide="key-round"></i>Add Key</button></div></div></div>
<div class="card"><div class="card-header"><h3>Authorized keys — <?= e($selected) ?></h3><span class="muted"><?= count($keys) ?> key<?= count($keys) === 1 ? '' : 's' ?></span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>#</th><th>Fingerprint / comment</th><th></th></tr></thead><tbody>
<?php foreach ($keys as $key): ?><tr><td class="mono"><?= $key['number'] ?></td><td class="mono" style="font-size:12px"><?= e($key['meta']) ?></td><td style="text-align:right"><button class="btn btn-danger btn-sm" data-key-delete="<?= $key['number'] ?>"><i data-lucide="trash-2"></i></button></td></tr><?php endforeach; ?>
<?php if (!$keys): ?><tr><td colspan="3" class="text-tertiary" style="text-align:center;padding:24px">No authorized keys for this user.</td></tr><?php endif; ?>
</tbody></table></div></div>
<script>document.addEventListener('DOMContentLoaded',()=>{const{apiPost,toast}=window.Nebula;const user=<?= json_encode($selected) ?>;document.getElementById('sshAdd')?.addEventListener('click',async()=>{const key=document.getElementById('sshKey').value.trim();const res=await apiPost('sshkeys',{action:'add',user,key});if(res.ok){toast('SSH key added','success');setTimeout(()=>location.reload(),350)}else toast(res.error||'Failed','error')});document.querySelectorAll('[data-key-delete]').forEach(btn=>btn.addEventListener('click',async()=>{if(!confirm('Remove this SSH key?'))return;const res=await apiPost('sshkeys',{action:'delete',user,number:Number(btn.dataset.keyDelete)});if(res.ok){toast('SSH key removed','success');setTimeout(()=>location.reload(),350)}else toast(res.error||'Failed','error')}))});</script>
<?php endif; ?>
