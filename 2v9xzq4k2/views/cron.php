<?php
require_once APP_ROOT . '/lib/mod_cron.php';
$available = cron_available();
$jobs = $available ? cron_list() : [];
$whoami = trim(shell_exec('whoami 2>/dev/null') ?: 'web user');
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Cron Jobs</h1>
    <p class="page-subtitle">Scheduled tasks for user <span class="mono"><?= e($whoami) ?></span></p>
  </div>
</div>

<?php if (!$available): ?>
  <div class="card"><div class="empty-state">
    <div class="es-icon"><i data-lucide="clock"></i></div>
    <div style="font-weight:600;color:var(--text-secondary)">cron is not available</div>
    <div style="font-size:13px;margin-top:4px">The <span class="mono">crontab</span> command was not found on this system.</div>
  </div></div>
<?php else: ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3>Add a job</h3></div>
    <div class="card-pad">
      <div class="grid" style="grid-template-columns:220px 1fr auto;gap:12px;align-items:end">
        <div>
          <label class="field-label">Schedule</label>
          <input class="input mono" id="cronSchedule" placeholder="0 2 * * *" value="0 2 * * *">
        </div>
        <div>
          <label class="field-label">Command</label>
          <input class="input mono" id="cronCommand" placeholder="/usr/bin/backup.sh">
        </div>
        <button class="btn btn-primary" id="cronAdd"><i data-lucide="plus"></i>Add job</button>
      </div>
      <div style="font-size:12px;color:var(--text-tertiary);margin-top:10px">
        Format: <span class="mono">minute hour day month weekday</span> — or an @keyword like <span class="mono">@reboot</span>, <span class="mono">@daily</span>.
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Current crontab</h3><span class="muted" id="cronCount"><?= count(array_filter($jobs, fn($j) => $j['type'] === 'job')) ?> jobs</span></div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th style="width:200px">Schedule</th><th>Command</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody id="cronBody">
          <?php foreach ($jobs as $j): ?>
            <?php if ($j['type'] === 'job'): ?>
              <tr data-index="<?= (int) $j['index'] ?>">
                <td class="mono" style="color:var(--blue-400)"><?= e($j['schedule']) ?></td>
                <td class="mono" style="font-size:12.5px"><?= e($j['command']) ?></td>
                <td style="text-align:right"><button class="btn btn-danger btn-sm" data-cron-del="<?= (int) $j['index'] ?>"><i data-lucide="trash-2"></i></button></td>
              </tr>
            <?php elseif ($j['type'] === 'comment' || $j['type'] === 'env'): ?>
              <tr><td colspan="3" class="mono text-tertiary" style="font-size:12px"><?= e($j['raw']) ?></td></tr>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php if (!$jobs): ?>
            <tr><td colspan="3" class="text-tertiary" style="text-align:center;padding:24px">No cron jobs yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast } = window.Nebula;
  document.getElementById('cronAdd')?.addEventListener('click', async () => {
    const schedule = document.getElementById('cronSchedule').value;
    const command = document.getElementById('cronCommand').value;
    const res = await apiPost('cron', { action: 'add', schedule, command });
    if (res.ok) { toast('Cron job added', 'success'); setTimeout(() => location.reload(), 500); }
    else toast(res.error || 'Failed', 'error');
  });
  document.querySelectorAll('[data-cron-del]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!confirm('Delete this cron job?')) return;
      const res = await apiPost('cron', { action: 'delete', index: +btn.dataset.cronDel });
      if (res.ok) { toast('Deleted', 'success'); btn.closest('tr').remove(); }
      else toast(res.error || 'Failed', 'error');
    });
  });
});
</script>
