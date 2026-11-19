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

  <div class="card" id="passkeys">
    <div class="card-header"><div><h3>Passkeys</h3><span class="muted">Sign in with Touch ID, Windows Hello, your phone or a security key instead of typing a code</span></div>
      <button class="btn btn-secondary btn-sm" type="button" id="pkAdd"<?= $mfa['enabled'] ? '' : ' disabled title="Turn on two-factor sign-in first"' ?>><i data-lucide="plus"></i>Add passkey</button></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Added</th><th>Last used</th><th class="actions-col"><span class="sr-only">Actions</span></th></tr></thead><tbody id="pkRows"></tbody></table></div>
    <div class="card-pad field-help" style="margin:0" id="pkHelp"><?= $mfa['enabled'] ? 'Passkeys work next to your authenticator app; turning two-factor sign-in off removes them.' : 'Turn on two-factor sign-in with an authenticator app first — passkeys are added next to it, and your recovery codes stay valid.' ?></div>
  </div>

  <div class="card" id="sessions">
    <div class="card-header"><h3>Where you’re signed in</h3><button class="btn btn-secondary btn-sm" type="button" id="sessRevokeOthers"><i data-lucide="log-out"></i>Sign out other sessions</button></div>
    <div class="session-list" id="sessList"><div class="card-pad text-tertiary" style="font-size:13px">Loading…</div></div>
  </div>
</div>

<?php $myRole = current_role(); $tokenRoles = array_filter(['auditor' => 'Auditor (read-only)', 'operator' => 'Operator', 'admin' => 'Administrator'], static fn($r) => ['auditor' => 0, 'developer' => 1, 'operator' => 2, 'admin' => 3][$r] <= (['auditor' => 0, 'developer' => 1, 'operator' => 2, 'admin' => 3][$myRole] ?? 0), ARRAY_FILTER_USE_KEY); ?>
<div class="card" id="tokens" style="margin-top:16px">
  <div class="card-header"><div><h3>Your API tokens</h3><span class="muted">For scripts and monitoring; they act as you, never with more access than your role</span></div></div>
  <form class="card-pad form-grid" id="tokForm" style="--cols:minmax(0,1.3fr) minmax(0,1fr) minmax(0,.7fr) minmax(0,1.3fr) auto;align-items:end" novalidate>
    <div><label class="field-label" for="tokLabel">Name</label><input class="input" id="tokLabel" required maxlength="80" placeholder="uptime-monitor" autocomplete="off"></div>
    <div><label class="field-label" for="tokRole">Access</label><select class="select" id="tokRole"><?php foreach ($tokenRoles as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div><label class="field-label" for="tokTtl">Days</label><input class="input mono" id="tokTtl" type="number" min="1" max="365" value="30" required></div>
    <div><label class="field-label" for="tokScopes">Scopes</label><input class="input mono" id="tokScopes" value="get:health" required></div>
    <button class="btn btn-primary" type="submit" id="tokCreate"><i data-lucide="key-round"></i>Create</button>
  </form>
  <div id="tokReveal" class="card-pad hidden" style="padding-top:0"><div class="notice notice-success" role="status"><i data-lucide="circle-check"></i><div style="flex:1;min-width:0"><strong>Copy the token now — it is shown only once.</strong>
    <div class="flex gap-2" style="margin-top:8px"><input class="input mono" id="tokPlain" readonly aria-label="New token value"><button class="btn btn-secondary" type="button" id="tokCopy"><i data-lucide="copy"></i>Copy</button></div></div></div></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Access</th><th>Expires</th><th>Last used</th><th class="actions-col"><span class="sr-only">Actions</span></th></tr></thead><tbody id="tokRows"><tr class="empty-row"><td colspan="5">Loading…</td></tr></tbody></table></div>
</div>

<script src="<?= e(asset('passkeys.js')) ?>"></script>
<script nonce="<?= e(csp_nonce()) ?>">
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast, validateForm, setBusy } = window.Nebula;
  // ---- Passkeys ----
  const pkRows = document.getElementById('pkRows');
  const askCreds = async (title) => {
    const password = await window.Nebula.prompt({ title, label: 'Your password', type: 'password', autocomplete: 'current-password', confirmLabel: 'Continue' });
    if (password === null) return null;
    const code = await window.Nebula.prompt({ title, label: 'Authentication code from your app', placeholder: '123 456', mono: true, autocomplete: 'one-time-code', confirmLabel: 'Continue' });
    return code === null ? null : { password, code };
  };
  const renderPasskeys = (keys) => {
    pkRows.replaceChildren();
    if (!keys.length) { const tr = document.createElement('tr'); tr.className = 'empty-row'; const td = document.createElement('td'); td.colSpan = 4; td.textContent = 'No passkeys yet.'; tr.append(td); pkRows.append(tr); return; }
    keys.forEach((k) => {
      const tr = document.createElement('tr');
      const cell = (t, c) => { const td = document.createElement('td'); if (c) td.className = c; td.textContent = t; return td; };
      const act = cell('', 'actions-col');
      const rm = document.createElement('button'); rm.type = 'button'; rm.className = 'btn btn-danger btn-sm'; rm.textContent = 'Remove';
      rm.addEventListener('click', async () => {
        const creds = await askCreds('Remove passkey “' + k.name + '”');
        if (!creds) return;
        const r = await apiPost('account', { action: 'passkey-delete', id: k.id, ...creds });
        if (r.ok) { toast('Passkey removed', 'success'); renderPasskeys(r.passkeys); } else toast(r.error || 'Could not remove it', 'error');
      });
      act.append(rm);
      const nm = cell(k.name); nm.style.fontWeight = '600';
      tr.append(nm, cell(window.Nebula.fmtDate(k.created_at), 'text-tertiary'), cell(k.last_used_at ? window.Nebula.fmtDate(k.last_used_at) : 'Never', 'text-tertiary'), act);
      pkRows.append(tr);
    });
  };
  renderPasskeys(<?= js_json($mfa['passkeys'] ?? []) ?>);
  document.getElementById('pkAdd')?.addEventListener('click', async () => {
    if (!window.NebulaPasskeys || !NebulaPasskeys.supported()) { toast('This browser or address does not support passkeys. Open the panel by its host name over HTTPS.', 'error'); return; }
    const creds = await askCreds('Add a passkey');
    if (!creds) return;
    const opts = await apiPost('account', { action: 'passkey-options', ...creds });
    if (!opts.ok) { toast(opts.error || 'Could not start', 'error'); return; }
    let cred;
    try { cred = await NebulaPasskeys.create(opts.options); } catch (e) { toast(NebulaPasskeys.message(e), 'error'); return; }
    const name = await window.Nebula.prompt({ title: 'Name this passkey', label: 'Name', value: /Mac|iPhone|iPad/.test(navigator.userAgent) ? 'Apple device' : (/Windows/.test(navigator.userAgent) ? 'Windows Hello' : 'Passkey'), confirmLabel: 'Save' });
    const r = await apiPost('account', { action: 'passkey-register', name: name || 'Passkey', clientDataJSON: cred.clientDataJSON, attestationObject: cred.attestationObject });
    if (r.ok) { toast('Passkey added', 'success'); renderPasskeys(r.passkeys); } else toast(r.error || 'Could not add the passkey', 'error');
  });
  // ---- Personal API tokens ----
  const tokRows = document.getElementById('tokRows');
  const roleNames = { auditor: 'Auditor', developer: 'Developer', operator: 'Operator', admin: 'Administrator' };
  const tcell = (text, cls) => { const td = document.createElement('td'); if (cls) td.className = cls; td.textContent = text; return td; };
  const renderTokens = (tokens) => {
    tokRows.replaceChildren();
    if (!tokens.length) { const tr = document.createElement('tr'); tr.className = 'empty-row'; const td = tcell('You have no API tokens.'); td.colSpan = 5; tr.append(td); tokRows.append(tr); return; }
    tokens.forEach((t) => {
      const tr = document.createElement('tr');
      const expired = t.expires_at && new Date(t.expires_at) < new Date();
      const act = tcell('', 'actions-col');
      const rv = document.createElement('button'); rv.type = 'button'; rv.className = 'btn btn-danger btn-sm'; rv.textContent = 'Revoke';
      rv.addEventListener('click', async () => {
        if (!await window.Nebula.confirm({ title: 'Revoke ' + t.label + '?', message: 'Anything using it stops working immediately.', danger: true, confirmLabel: 'Revoke' })) return;
        const r = await apiPost('account', { action: 'token-revoke', id: t.id });
        if (r.ok) { toast('Token revoked', 'success'); loadTokens(); } else toast(r.error || 'Could not revoke', 'error');
      });
      act.append(rv);
      const name = tcell(t.label); name.style.fontWeight = '600';
      tr.append(name, tcell((roleNames[t.role] || t.role) + ' · ' + (t.scopes || []).join(', '), 'mono text-tertiary'),
        tcell(window.Nebula.fmtDate(t.expires_at) + (expired ? ' (expired)' : '')), tcell(t.last_used_at ? window.Nebula.fmtDate(t.last_used_at) : 'Never', 'text-tertiary'), act);
      tokRows.append(tr);
    });
  };
  const loadTokens = async () => { const r = await apiPost('account', { action: 'token-list' }); if (r.ok) renderTokens(r.tokens); };
  loadTokens();
  document.getElementById('tokForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!validateForm(event.currentTarget)) return;
    const btn = document.getElementById('tokCreate'); setBusy(btn, true);
    const r = await apiPost('account', { action: 'token-generate', label: document.getElementById('tokLabel').value.trim(), role: document.getElementById('tokRole').value,
      ttl_days: Number(document.getElementById('tokTtl').value), scopes: document.getElementById('tokScopes').value.split(',').map((x) => x.trim()).filter(Boolean) });
    setBusy(btn, false);
    if (!r.ok) { toast(r.error || 'Could not create the token', 'error'); return; }
    document.getElementById('tokPlain').value = r.token; document.getElementById('tokReveal').classList.remove('hidden');
    event.target.reset(); loadTokens(); toast('Token created', 'success');
  });
  document.getElementById('tokCopy').addEventListener('click', async () => {
    const ok = await window.Nebula.copyText(document.getElementById('tokPlain').value);
    toast(ok ? 'Token copied' : 'Copy failed — select it and copy manually', ok ? 'success' : 'error');
  });

  const form = document.getElementById('accountPwForm');
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const pw = document.getElementById('accNewPw'), pw2 = document.getElementById('accNewPw2');
    if (!validateForm(form, pw.value !== pw2.value ? [[pw2, 'The passwords do not match.']] : [])) return;
    const button = document.getElementById('accSavePw');
    setBusy(button, true);
    const res = await apiPost('account', { action: 'password', current: document.getElementById('accCurPw').value, new: pw.value });
    setBusy(button, false);
    if (res.ok) {
      toast(res.tokens_revoked ? `Password updated. Your ${res.tokens_revoked} API token${res.tokens_revoked === 1 ? ' was' : 's were'} revoked; create new ones if you still need them.` : 'Password updated', 'success');
      form.reset();
      if (res.tokens_revoked) loadTokens();
    }
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
