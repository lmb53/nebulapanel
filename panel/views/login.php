<?php /** @var ?string $error */ /** @var array $config */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sign in · <?= e($config['panel_name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="<?= e(asset('vendor/lucide-1.8.0.min.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px">
  <div class="card" style="width:380px;max-width:100%">
    <div class="card-pad">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
        <div class="logo-mark" style="width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,var(--blue-500),var(--purple-500));display:flex;align-items:center;justify-content:center;box-shadow:var(--shadow-glow-blue)">
          <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" style="width:18px;height:18px"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>
        </div>
        <div>
          <div style="font-weight:700;font-size:16px"><?= e($config['panel_name']) ?></div>
          <div style="font-size:12px;color:var(--text-tertiary)">Sign in to continue</div>
        </div>
      </div>
      <?php if ($error): ?>
        <div class="badge badge-red" style="width:100%;justify-content:flex-start;padding:9px 12px;border-radius:9px;margin-bottom:14px"><i data-lucide="alert-triangle"></i><?= e($error) ?></div>
      <?php endif; ?>
      <form method="post" action="<?= e(url('login')) ?>">
        <?= csrf_field() ?>
        <label class="field-label">Username</label>
        <input class="input" type="text" name="username" autofocus autocomplete="username" style="margin-bottom:14px">
        <label class="field-label">Password</label>
        <input class="input" type="password" name="password" autocomplete="current-password" style="margin-bottom:18px">
        <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center"><i data-lucide="log-in"></i>Sign in</button>
      </form>
    </div>
  </div>
</div>
<script>lucide.createIcons();</script>
</body>
</html>
