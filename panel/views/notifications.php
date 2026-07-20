<?php
require_once APP_ROOT . '/lib/mod_notifications.php';
$items = notifications_items();
$unread = count(array_filter($items, fn($item) => empty($item['read'])));
?>
<div class="page-header">
  <div><h1 class="page-title">Notifications</h1><p class="page-subtitle"><?= $unread ?> unread operational alert<?= $unread === 1 ? '' : 's' ?></p></div>
  <div class="page-actions"><button class="btn btn-secondary" id="notifRead" <?= !$items ? 'disabled' : '' ?>><i data-lucide="check-check"></i>Mark all as read</button></div>
</div>
<div class="card">
  <?php if (!$items): ?><div class="empty-state"><div class="es-icon"><i data-lucide="bell-check"></i></div><div style="font-weight:600">All clear</div><div class="text-tertiary" style="font-size:13px;margin-top:4px">No current health or maintenance notifications.</div></div><?php endif; ?>
  <?php foreach ($items as $i => $item): ?>
    <a href="<?= e(url($item['route'] ?? 'dashboard')) ?>" class="service-row" style="padding:16px;border-radius:0;<?= $i ? 'border-top:1px solid var(--border-subtle);' : '' ?>text-decoration:none">
      <div class="svc-icon"><i data-lucide="<?= e($item['icon'] ?? 'bell') ?>" style="color:<?= ($item['level'] ?? '') === 'critical' ? 'var(--red-400)' : (($item['level'] ?? '') === 'warning' ? 'var(--orange-400)' : 'var(--blue-400)') ?>"></i></div>
      <div style="flex:1"><div style="font-size:13px;font-weight:600"><?= e($item['title'] ?? '') ?></div><div class="text-tertiary" style="font-size:12px;margin-top:3px"><?= e($item['detail'] ?? '') ?></div></div>
      <span class="badge <?= !empty($item['read']) ? 'badge-slate' : 'badge-blue' ?>"><?= !empty($item['read']) ? 'Read' : 'New' ?></span>
      <i data-lucide="chevron-right" style="width:15px;color:var(--text-tertiary)"></i>
    </a>
  <?php endforeach; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast } = window.Nebula;
  document.getElementById('notifRead')?.addEventListener('click', async () => {
    const res = await apiPost('notifications', { action: 'mark-all-read' });
    if (res.ok) { toast('Notifications marked as read', 'success'); setTimeout(() => location.reload(), 350); }
    else toast(res.error || 'Failed', 'error');
  });
});
</script>
