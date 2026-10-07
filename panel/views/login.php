<?php /** @var ?string $error */ /** @var array $config */ $username = $username ?? ''; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sign in · <?= e($config['panel_name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="<?= e(asset('vendor/lucide-1.8.0.min.js')) ?>"></script>
<?= theme_boot_script() ?>
<link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px">
  <div class="card" style="width:380px;max-width:100%">
    <div class="card-pad">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
        <div class="logo-mark" aria-hidden="true" style="width:34px;height:34px;border-radius:12px;background:linear-gradient(135deg,var(--blue-500),var(--purple-500));display:flex;align-items:center;justify-content:center;box-shadow:var(--shadow-glow-blue)">
          <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" style="width:18px;height:18px"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>
        </div>
        <div>
          <div style="font-weight:700;font-size:16px"><?= e($config['panel_name']) ?></div>
          <div style="font-size:12px;color:var(--text-tertiary)">Sign in to continue</div>
        </div>
      </div>
      <?php if ($error): ?>
        <div class="notice notice-danger" role="alert" style="margin-bottom:16px"><i data-lucide="circle-x"></i><div><?= e($error) ?></div></div>
      <?php endif; ?>
      <form method="post" action="<?= e(url('login')) ?>" id="loginForm">
        <?= csrf_field() ?>
        <label class="field-label" for="login-username-1">Username</label>
        <input id="login-username-1" class="input" type="text" name="username" required value="<?= e($username ?? '') ?>"<?= empty($username) ? ' autofocus' : '' ?> autocomplete="username" style="margin-bottom:14px">
        <label class="field-label" for="login-password-2">Password</label>
        <input id="login-password-2" class="input" type="password" name="password" required<?= !empty($username) ? ' autofocus' : '' ?> autocomplete="current-password" style="margin-bottom:18px">
        <button class="btn btn-primary" type="submit" style="width:100%"><i data-lucide="log-in"></i>Sign in</button>
      </form>
    </div>
  </div>
</div>
<script>lucide.createIcons();document.getElementById('loginForm').addEventListener('submit',(e)=>{const b=e.currentTarget.querySelector('button[type=submit]');b.disabled=true;b.lastChild.textContent='Signing in…';});</script>
</body>
</html>
