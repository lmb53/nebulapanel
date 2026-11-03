<?php /** @var ?string $error */ /** @var array $config */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Two-factor sign-in · <?= e($config['panel_name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="<?= e(asset('vendor/lucide-1.8.0.min.js')) ?>"></script>
<?= theme_boot_script() ?>
<link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px">
  <div class="card auth-card">
    <div class="card-pad">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
        <div class="logo-mark lg" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>
        </div>
        <div>
          <div class="auth-brand"><?= e($config['panel_name']) ?></div>
          <div style="font-size:12px;color:var(--text-tertiary)">Two-factor sign-in</div>
        </div>
      </div>
      <?php if ($error): ?>
        <div class="notice notice-danger" role="alert" style="margin-bottom:16px"><i data-lucide="circle-x"></i><div><?= e($error) ?></div></div>
      <?php endif; ?>
      <form method="post" action="<?= e(url('login-2fa')) ?>" id="mfaForm">
        <?= csrf_field() ?>
        <label class="field-label" for="mfa-code" id="mfaLabel">Authentication code</label>
        <input id="mfa-code" class="input mono mfa-code-input" type="text" name="code" required autofocus autocomplete="one-time-code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="123 456" aria-describedby="mfaHelp" style="margin-bottom:8px">
        <div class="field-help" id="mfaHelp" style="margin-bottom:18px">Enter the 6-digit code from your authenticator app.</div>
        <button class="btn btn-primary" type="submit" style="width:100%"><i data-lucide="shield-check"></i>Verify</button>
      </form>
      <div class="flex items-center" style="justify-content:space-between;margin-top:14px;gap:8px;flex-wrap:wrap">
        <button class="btn btn-ghost btn-sm" type="button" id="useRecovery">Use a recovery code</button>
        <form method="post" action="<?= e(url('login-2fa')) ?>" style="margin:0"><?= csrf_field() ?><input type="hidden" name="cancel" value="1"><button class="btn btn-ghost btn-sm" type="submit">Cancel</button></form>
      </div>
    </div>
  </div>
</div>
<script>
lucide.createIcons();
const code = document.getElementById('mfa-code');
document.getElementById('useRecovery').addEventListener('click', (e) => {
  const recovery = code.inputMode === 'numeric';
  code.inputMode = recovery ? 'text' : 'numeric';
  code.pattern = recovery ? '[A-Za-z0-9-]{11,14}' : '[0-9 ]{6,7}';
  code.maxLength = recovery ? 14 : 7;
  code.placeholder = recovery ? 'xxxx-xxxx-xxx' : '123 456';
  code.autocomplete = recovery ? 'off' : 'one-time-code';
  document.getElementById('mfaLabel').textContent = recovery ? 'Recovery code' : 'Authentication code';
  document.getElementById('mfaHelp').textContent = recovery ? 'Each recovery code works once.' : 'Enter the 6-digit code from your authenticator app.';
  e.currentTarget.textContent = recovery ? 'Use an authenticator code' : 'Use a recovery code';
  code.value = ''; code.focus();
});
document.getElementById('mfaForm').addEventListener('submit', (e) => { const b = e.currentTarget.querySelector('button[type=submit]'); b.disabled = true; });
</script>
</body>
</html>
