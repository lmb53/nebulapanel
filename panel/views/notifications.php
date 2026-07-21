<?php
require_once APP_ROOT . '/lib/mod_notifications.php';
$items = notifications_items();
$unread = count(array_filter($items, fn($item) => empty($item['read'])));
?>
<div class="page-header">
  <div><h1 class="page-title">Notifications</h1><p class="page-subtitle"><?= $unread ?> unread operational alert<?= $unread === 1 ? '' : 's' ?></p></div>
  <div class="page-actions"><button class="btn btn-secondary" id="notifRead" <?= !$items ? 'disabled' : '' ?>><i data-lucide="check-check"></i>Mark all as read</button></div>
</div>
<div class="flex gap-2" id="notifFilters" style="margin-bottom:16px">
  <button class="chip active" type="button" data-notif-filter="all">All</button>
  <button class="chip" type="button" data-notif-filter="unread">Unread</button>
  <button class="chip" type="button" data-notif-filter="critical">Critical</button>
  <button class="chip" type="button" data-notif-filter="warning">Warnings</button>
</div>
<div class="card">
  <?php if (!$items): ?><div class="empty-state"><div class="es-icon"><i data-lucide="bell-check"></i></div><div style="font-weight:600">All clear</div><div class="text-tertiary" style="font-size:13px;margin-top:4px">No current health or maintenance notifications.</div></div><?php endif; ?>
  <?php foreach ($items as $i => $item): ?>
    <div class="service-row notification-row" data-level="<?= e($item['level'] ?? 'info') ?>" data-read="<?= !empty($item['read']) ? '1' : '0' ?>" style="padding:16px;border-radius:0;<?= $i ? 'border-top:1px solid var(--border-subtle);' : '' ?>">
      <div class="svc-icon"><i data-lucide="<?= e($item['icon'] ?? 'bell') ?>" style="color:<?= ($item['level'] ?? '') === 'critical' ? 'var(--red-400)' : (($item['level'] ?? '') === 'warning' ? 'var(--orange-400)' : 'var(--blue-400)') ?>"></i></div>
      <a href="<?= e(url($item['route'] ?? 'dashboard')) ?>" style="flex:1;text-decoration:none;color:inherit"><div style="font-size:13px;font-weight:600"><?= e($item['title'] ?? '') ?></div><div class="text-tertiary" style="font-size:12px;margin-top:3px"><?= e($item['detail'] ?? '') ?></div></a>
      <span class="badge <?= !empty($item['read']) ? 'badge-slate' : 'badge-blue' ?>"><?= !empty($item['read']) ? 'Read' : 'New' ?></span>
      <?php if (empty($item['read'])): ?><button class="icon-btn" data-notif-read="<?= e($item['id']) ?>" title="Mark as read"><i data-lucide="check"></i></button><?php endif; ?>
      <button class="icon-btn" data-notif-delete="<?= e($item['id']) ?>" title="Delete"><i data-lucide="trash-2"></i></button>
    </div>
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
  document.querySelectorAll('[data-notif-read]').forEach((btn) => btn.addEventListener('click', async () => {
    const res = await apiPost('notifications', { action: 'mark-read', id: btn.dataset.notifRead });
    if (res.ok) location.reload(); else toast(res.error || 'Failed', 'error');
  }));
  document.querySelectorAll('[data-notif-delete]').forEach((btn) => btn.addEventListener('click', async () => {
    const res = await apiPost('notifications', { action: 'delete', id: btn.dataset.notifDelete });
    if (res.ok) btn.closest('.notification-row')?.remove(); else toast(res.error || 'Failed', 'error');
  }));
  document.querySelectorAll('[data-notif-filter]').forEach((chip) => chip.addEventListener('click', () => {
    document.querySelectorAll('[data-notif-filter]').forEach((item) => item.classList.toggle('active', item === chip));
    const filter = chip.dataset.notifFilter;
    document.querySelectorAll('.notification-row').forEach((row) => row.classList.toggle('hidden', !(filter === 'all' || (filter === 'unread' && row.dataset.read === '0') || row.dataset.level === filter)));
  }));
});
</script>
