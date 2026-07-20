<?php
require_once APP_ROOT . '/lib/mod_users.php';
$users = system_users();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Users</h1>
    <p class="page-subtitle">System accounts on this server</p>
  </div>
</div>

<div class="flex items-center" style="gap:8px;margin-bottom:16px" id="userFilters">
  <span class="chip active" data-filter="all">All</span>
  <span class="chip" data-filter="human">Human</span>
  <span class="chip" data-filter="system">System</span>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>UID</th>
          <th>Home</th>
          <th>Shell</th>
          <th>Groups</th>
          <th>Type</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$users): ?>
          <tr><td colspan="6" class="text-tertiary" style="text-align:center;padding:24px">No user data (/etc/passwd not readable)</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $u): ?>
          <?php $type = $u['human'] ? 'human' : 'system'; ?>
          <tr data-type="<?= e($type) ?>">
            <td style="font-weight:600"><?= e($u['name']) ?></td>
            <td class="mono"><?= e($u['uid']) ?></td>
            <td class="mono text-tertiary"><?= e($u['home']) ?></td>
            <td class="mono text-tertiary"><?= e($u['shell']) ?></td>
            <td>
              <?php if ($u['human']): ?>
                <?php $groups = user_groups($u['name']); ?>
                <?php if ($groups): ?>
                  <span style="display:flex;flex-wrap:wrap;gap:4px">
                    <?php foreach ($groups as $g): ?>
                      <span class="badge badge-slate"><?= e($g) ?></span>
                    <?php endforeach; ?>
                  </span>
                <?php else: ?>
                  <span class="text-tertiary">&mdash;</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-tertiary">&mdash;</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($u['human']): ?>
                <span class="badge badge-blue">Human</span>
              <?php else: ?>
                <span class="badge badge-slate">System</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const chips = document.querySelectorAll('#userFilters .chip');
  const rows = document.querySelectorAll('.data-table tbody tr[data-type]');
  chips.forEach((chip) => {
    chip.addEventListener('click', () => {
      chips.forEach((c) => c.classList.remove('active'));
      chip.classList.add('active');
      const filter = chip.dataset.filter;
      rows.forEach((row) => {
        const show = filter === 'all' || row.dataset.type === filter;
        row.classList.toggle('hidden', !show);
      });
    });
  });
});
</script>
<script>window.NEBULA_PAGE = 'users';</script>
