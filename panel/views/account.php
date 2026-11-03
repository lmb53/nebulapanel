<?php
/** The signed-in person's own account: identity, role and password. Open to every role. */
$roleKey = current_role();
$role = panel_roles()[$roleKey] ?? ['label' => ucfirst($roleKey), 'description' => ''];
require_once APP_ROOT . '/lib/mod_mfa.php';
$mfa = mfa_status((int) ($_SESSION['uid'] ?? 0));
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
        <div class="field-help" id="accNewPwHelp">At least 12 characters. Your other signed-in sessions are signed out; this one stays signed in.</div>
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

<div class="grid grid-2" style="margin-top:16px">
  <div class="card" id="mfa">
    <div class="card-header"><h3>Two-factor sign-in</h3><span class="badge <?= $mfa['enabled'] ? 'badge-emerald' : 'badge-slate' ?>" id="mfaBadge"><span class="bdot"></span><?= $mfa['enabled'] ? 'On' : 'Off' ?></span></div>
    <div class="card-pad">
      <?php if ($mfa['enabled']): ?>
        <p class="field-help" style="margin-top:0">Signing in needs your password and a code from your authenticator app. Turned on <?= e(fmt_datetime($mfa['enabled_at'])) ?>.</p>
        <div class="fm-prop-row"><span class="k">Recovery codes left</span><span class="v"><span class="badge <?= $mfa['recovery_left'] <= 2 ? 'badge-orange' : 'badge-slate' ?>"><?= (int) $mfa['recovery_left'] ?> of <?= MFA_RECOVERY_COUNT ?></span></span></div>
        <div class="flex gap-2" style="margin-top:14px;flex-wrap:wrap">
          <button class="btn btn-secondary" type="button" id="mfaRecoveryBtn"><i data-lucide="list-restart"></i>New recovery codes</button>
          <button class="btn btn-danger" type="button" id="mfaDisableBtn"><i data-lucide="shield-off"></i>Turn off</button>
        </div>
      <?php else: ?>
        <p class="field-help" style="margin-top:0">Protect this account with a one-time code from an authenticator app such as Google Authenticator, 1Password, Authy or Bitwarden, in addition to your password.</p>
        <button class="btn btn-primary" type="button" id="mfaSetupBtn"><i data-lucide="shield-plus"></i>Set up two-factor sign-in</button>
        <div class="hidden" id="mfaSetup" style="margin-top:16px">
          <ol class="mfa-steps">
            <li>Scan this code with your authenticator app.<div class="mfa-qr" id="mfaQr"></div><div class="field-help">Can’t scan? Enter this key manually: <span class="mono" id="mfaSecret" style="user-select:all"></span> <button class="btn btn-ghost btn-sm" type="button" id="mfaCopy"><i data-lucide="copy"></i>Copy</button></div></li>
            <li>Enter the 6-digit code the app shows.
              <form class="flex gap-2" id="mfaConfirmForm" style="margin-top:8px;flex-wrap:wrap" novalidate>
                <input class="input mono" id="mfaConfirmCode" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required placeholder="123 456" aria-label="Authentication code" style="max-width:160px">
                <button class="btn btn-primary" type="submit" id="mfaConfirmBtn"><i data-lucide="check"></i>Turn on</button>
              </form>
            </li>
          </ol>
        </div>
      <?php endif; ?>
      <div class="hidden" id="mfaCodes" style="margin-top:16px">
        <div class="notice notice-warning"><i data-lucide="key-round"></i><div><strong>Save your recovery codes</strong>Each works once if you lose your phone. They are shown only now.</div></div>
        <pre class="log-pre mfa-codes mono" id="mfaCodeList"></pre>
        <div class="flex gap-2" style="flex-wrap:wrap"><button class="btn btn-secondary btn-sm" type="button" id="mfaCodesCopy"><i data-lucide="copy"></i>Copy</button><button class="btn btn-secondary btn-sm" type="button" id="mfaCodesDownload"><i data-lucide="download"></i>Download .txt</button><button class="btn btn-primary btn-sm" type="button" id="mfaCodesDone">I saved them</button></div>
      </div>
    </div>
  </div>

  <div class="card" id="sessions">
    <div class="card-header"><h3>Where you’re signed in</h3><button class="btn btn-secondary btn-sm" type="button" id="sessRevokeOthers"><i data-lucide="log-out"></i>Sign out other sessions</button></div>
    <div class="session-list" id="sessList"><div class="card-pad text-tertiary" style="font-size:13px">Loading…</div></div>
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
  // ---- Active sessions ----
  const sessList = document.getElementById('sessList');
  const ago = (ts) => {
    const s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
    if (s < 90) return 'Active now';
    if (s < 3600) return `Active ${Math.round(s / 60)} min ago`;
    return 'Active ' + window.Nebula.fmtDate(ts * 1000);
  };
  const renderSessions = (sessions) => {
    sessList.replaceChildren();
    if (!sessions.length) { const d = document.createElement('div'); d.className = 'card-pad text-tertiary'; d.textContent = 'No active sessions.'; sessList.appendChild(d); }
    sessions.forEach((s) => {
      const row = document.createElement('div'); row.className = 'session-row';
      const icon = document.createElement('div'); icon.className = 'svc-icon'; icon.innerHTML = `<i data-lucide="${s.icon}"></i>`;
      const copy = document.createElement('div'); copy.style.flex = '1'; copy.style.minWidth = '0';
      const title = document.createElement('div'); title.className = 'session-title'; title.textContent = s.browser + (s.os ? ' on ' + s.os : '');
      if (s.current) { const b = document.createElement('span'); b.className = 'badge badge-emerald'; b.textContent = 'This device'; title.append(' ', b); }
      const meta = document.createElement('div'); meta.className = 'text-tertiary'; meta.style.fontSize = '12px';
      meta.textContent = `${s.ip || 'unknown address'} · ${s.current ? 'Active now' : ago(s.last_seen)} · signed in ${window.Nebula.fmtDate(s.created * 1000)}`;
      copy.append(title, meta); row.append(icon, copy);
      if (!s.current) {
        const out = document.createElement('button'); out.type = 'button'; out.className = 'btn btn-secondary btn-sm'; out.textContent = 'Sign out';
        out.addEventListener('click', async () => {
          setBusy(out, true);
          const res = await apiPost('account', { action: 'revoke-session', id: s.id });
          setBusy(out, false);
          if (res.ok) { toast('Session signed out', 'success'); renderSessions(res.sessions || []); } else toast(res.error || 'Could not sign out that session', 'error');
        });
        row.appendChild(out);
      }
      sessList.appendChild(row);
    });
    document.getElementById('sessRevokeOthers').disabled = sessions.filter((s) => !s.current).length === 0;
    if (window.lucide) lucide.createIcons();
  };
  window.Nebula.apiGet('account').then((res) => renderSessions(res.sessions || [])).catch((e) => { sessList.textContent = e.message; });
  document.getElementById('sessRevokeOthers').addEventListener('click', async (event) => {
    if (!await window.Nebula.confirm({ title: 'Sign out all other sessions?', message: 'Every other browser and device signed in to your account is signed out on its next request. This one stays signed in.', confirmLabel: 'Sign out others', danger: true })) return;
    const res = await apiPost('account', { action: 'revoke-other-sessions' });
    if (res.ok) { toast(`Signed out ${res.ended} other session${res.ended === 1 ? '' : 's'}`, 'success'); renderSessions(res.sessions || []); } else toast(res.error || 'Failed', 'error');
  });

  // ---- Two-factor ----
  const showCodes = (codes) => {
    document.getElementById('mfaCodeList').textContent = codes.join('\n');
    document.getElementById('mfaCodes').classList.remove('hidden');
    document.getElementById('mfaSetup')?.classList.add('hidden');
    document.getElementById('mfaCodesDone').focus();
  };
  document.getElementById('mfaCodesCopy').addEventListener('click', () => window.Nebula.copyText(document.getElementById('mfaCodeList').textContent).then((ok) => toast(ok ? 'Recovery codes copied' : 'Copy failed — select the codes instead', ok ? 'success' : 'error')));
  document.getElementById('mfaCodesDownload').addEventListener('click', () => {
    const blob = new Blob([`<?= e($config['panel_name']) ?> recovery codes for <?= e(current_user() ?? '') ?>\nEach code works once.\n\n` + document.getElementById('mfaCodeList').textContent + '\n'], { type: 'text/plain' });
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'nebula-recovery-codes.txt'; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  });
  document.getElementById('mfaCodesDone').addEventListener('click', () => location.reload());
  document.getElementById('mfaSetupBtn')?.addEventListener('click', async (event) => {
    const button = event.currentTarget; setBusy(button, true);
    const res = await apiPost('account', { action: 'mfa-setup' });
    setBusy(button, false);
    if (!res.ok) { toast(res.error || 'Could not start setup', 'error'); return; }
    document.getElementById('mfaQr').innerHTML = res.qr_svg;
    document.getElementById('mfaSecret').textContent = res.secret;
    button.classList.add('hidden');
    document.getElementById('mfaSetup').classList.remove('hidden');
    document.getElementById('mfaConfirmCode').focus();
  });
  document.getElementById('mfaCopy')?.addEventListener('click', () => window.Nebula.copyText(document.getElementById('mfaSecret').textContent.replace(/\s+/g, '')).then((ok) => toast(ok ? 'Key copied' : 'Copy failed — select the key instead', ok ? 'success' : 'error')));
  document.getElementById('mfaConfirmForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!validateForm(event.currentTarget)) return;
    const button = document.getElementById('mfaConfirmBtn'); setBusy(button, true);
    const res = await apiPost('account', { action: 'mfa-confirm', code: document.getElementById('mfaConfirmCode').value });
    setBusy(button, false);
    if (res.ok) { toast('Two-factor sign-in is on', 'success'); showCodes(res.recovery_codes || []); }
    else window.Nebula.setFieldError(document.getElementById('mfaConfirmCode'), res.error || 'That code is not valid.');
  });
  const reauth = async (title, confirmLabel, danger) => {
    const pw = await window.Nebula.dialog({ title, icon: 'lock', confirmLabel: 'Continue', danger: false, input: { label: 'Your password', value: '', type: 'password', autocomplete: 'current-password' }, message: 'Confirm it’s you.' });
    if (!pw.ok) return null;
    const code = await window.Nebula.dialog({ title, icon: 'shield-check', confirmLabel, danger, input: { label: 'Authentication or recovery code', value: '', mono: true, autocomplete: 'one-time-code' } });
    return code.ok ? { password: pw.value, code: code.value } : null;
  };
  document.getElementById('mfaDisableBtn')?.addEventListener('click', async () => {
    const creds = await reauth('Turn off two-factor sign-in', 'Turn off', true);
    if (!creds) return;
    const res = await apiPost('account', { action: 'mfa-disable', ...creds });
    if (res.ok) { toast('Two-factor sign-in is off', 'success'); setTimeout(() => location.reload(), 500); } else toast(res.error || 'Could not turn it off', 'error');
  });
  document.getElementById('mfaRecoveryBtn')?.addEventListener('click', async () => {
    const creds = await reauth('New recovery codes', 'Generate codes', false);
    if (!creds) return;
    const res = await apiPost('account', { action: 'mfa-recovery', ...creds });
    if (res.ok) showCodes(res.recovery_codes || []); else toast(res.error || 'Could not generate codes', 'error');
  });
});
</script>
