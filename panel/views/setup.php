<?php /** @var ?string $error */ /** @var array $config */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Set up · <?= e($config['panel_name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest"></script>
<link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px">
  <div class="card" style="width:420px;max-width:100%">
    <div class="card-pad">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <div class="logo-mark" style="width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,var(--blue-500),var(--purple-500));display:flex;align-items:center;justify-content:center;box-shadow:var(--shadow-glow-blue)">
          <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" style="width:18px;height:18px"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>
        </div>
        <div style="font-weight:700;font-size:16px">Welcome to <?= e($config['panel_name']) ?></div>
      </div>
      <p style="font-size:13px;color:var(--text-tertiary);margin:0 0 18px">Create the administrator account. This is a one-time setup.</p>
      <?php if ($error): ?>
        <div class="badge badge-red" style="width:100%;justify-content:flex-start;padding:9px 12px;border-radius:9px;margin-bottom:14px"><i data-lucide="alert-triangle"></i><?= e($error) ?></div>
      <?php endif; ?>
      <form method="post" action="<?= e(url('setup')) ?>">
        <?= csrf_field() ?>
        <label class="field-label">Username</label>
        <input class="input" type="text" name="username" required minlength="3" maxlength="64" pattern="[A-Za-z0-9_.-]+" autocomplete="username" autofocus style="margin-bottom:14px">
        <label class="field-label">Password <span style="color:var(--text-tertiary);font-weight:400">(min 12 characters)</span></label>
        <input class="input" type="password" name="password" required minlength="12" maxlength="1024" autocomplete="new-password" style="margin-bottom:18px">
        <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center"><i data-lucide="user-plus"></i>Create account &amp; sign in</button>
      </form>
    </div>
  </div>
</div>
<script>lucide.createIcons();</script>
</body>
</html>
