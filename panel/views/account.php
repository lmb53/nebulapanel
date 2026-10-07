<?php
/** The signed-in person's own account: identity, role and password. Open to every role. */
$roleKey = current_role();
$role = panel_roles()[$roleKey] ?? ['label' => ucfirst($roleKey), 'description' => ''];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">My account</h1>
    <p class="page-subtitle">Your sign-in details for <?= e($config['panel_name']) ?></p>
  </div>
</div>

<div class="grid grid-main-side">
  <div class="card">
    <div class="card-header"><h3>Change password</h3></div>
    <form class="card-pad form-stack" id="accountPwForm" novalidate>
      <input type="text" name="username" autocomplete="username" aria-label="Username" value="<?= e(current_user() ?? '') ?>" hidden>
      <div>
        <label class="field-label" for="accCurPw">Current password<span class="req" aria-hidden="true">*</span></label>
        <input class="input" id="accCurPw" type="password" required autocomplete="current-password">
      </div>
      <div>
        <label class="field-label" for="accNewPw">New password<span class="req" aria-hidden="true">*</span></label>
        <input class="input" id="accNewPw" type="password" required minlength="12" maxlength="1024" autocomplete="new-password" aria-describedby="accNewPwHelp">
        <div class="field-help" id="accNewPwHelp">At least 12 characters. Other sessions stay signed in until they expire.</div>
      </div>
      <div>
        <label class="field-label" for="accNewPw2">Confirm new password<span class="req" aria-hidden="true">*</span></label>
        <input class="input" id="accNewPw2" type="password" required autocomplete="new-password">
      </div>
      <div><button class="btn btn-primary" type="submit" id="accSavePw"><i data-lucide="key-round"></i>Update password</button></div>
    </form>
  </div>
  <div class="card">
    <div class="card-header"><h3>Profile</h3></div>
    <div class="card-pad">
      <div class="fm-prop-row"><span class="k">Username</span><span class="v"><?= e(current_user() ?? '') ?></span></div>
      <div class="fm-prop-row"><span class="k">Role</span><span class="v"><span class="badge badge-blue"><?= e($role['label']) ?></span></span></div>
      <p class="field-help" style="margin-top:12px"><?= e($role['description']) ?></p>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast, validateForm, setBusy } = window.Nebula;
  const form = document.getElementById('accountPwForm');
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const pw = document.getElementById('accNewPw'), pw2 = document.getElementById('accNewPw2');
    if (!validateForm(form, pw.value !== pw2.value ? [[pw2, 'The passwords do not match.']] : [])) return;
    const button = document.getElementById('accSavePw');
    setBusy(button, true);
    const res = await apiPost('account', { action: 'password', current: document.getElementById('accCurPw').value, new: pw.value });
    setBusy(button, false);
    if (res.ok) { toast('Password updated', 'success'); form.reset(); }
    else toast(res.error || 'Could not update the password', 'error');
  });
});
</script>
