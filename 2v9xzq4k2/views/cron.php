<?php
require_once APP_ROOT . '/lib/mod_cron.php';
$available = cron_available();
$jobs = $available ? cron_list() : [];
$whoami = trim(shell_exec('whoami 2>/dev/null') ?: 'web user');

if (!function_exists('cron_describe')) {
    /** Best-effort human-readable hint for a cron schedule. */
    function cron_describe(string $schedule): string
    {
        $s = trim($schedule);
        $keywords = [
            '@reboot'   => 'At startup',
            '@yearly'   => 'Once a year',
            '@annually' => 'Once a year',
            '@monthly'  => 'Once a month',
            '@weekly'   => 'Once a week',
            '@daily'    => 'Once a day',
            '@midnight' => 'Daily at midnight',
            '@hourly'   => 'Once an hour',
        ];
        if (isset($keywords[strtolower($s)])) {
            return $keywords[strtolower($s)];
        }
        $p = preg_split('/\s+/', $s);
        if (count($p) !== 5) {
            return '';
        }
        [$min, $hour, $dom, $mon, $dow] = $p;
        if ($s === '* * * * *')                       return 'Every minute';
        if (preg_match('#^\*/(\d+) \* \* \* \*$#', $s, $m)) return 'Every ' . $m[1] . ' minutes';
        if ($min === '0' && preg_match('#^\*/(\d+)$#', $hour, $m) && $dom === '*' && $mon === '*' && $dow === '*')
            return 'Every ' . $m[1] . ' hours';
        if ($hour === '*' && ctype_digit($min) && $dom === '*' && $mon === '*' && $dow === '*')
            return 'Hourly at :' . str_pad($min, 2, '0', STR_PAD_LEFT);
        if (ctype_digit($min) && ctype_digit($hour) && $dom === '*' && $mon === '*' && $dow === '*')
            return 'Daily at ' . str_pad($hour, 2, '0', STR_PAD_LEFT) . ':' . str_pad($min, 2, '0', STR_PAD_LEFT);
        if (ctype_digit($min) && ctype_digit($hour) && $dom === '*' && $mon === '*' && ctype_digit($dow)) {
            $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            $name = $days[(int) $dow % 7] ?? $dow;
            return 'Weekly, ' . $name . ' ' . str_pad($hour, 2, '0', STR_PAD_LEFT) . ':' . str_pad($min, 2, '0', STR_PAD_LEFT);
        }
        if (ctype_digit($min) && ctype_digit($hour) && ctype_digit($dom) && $mon === '*' && $dow === '*')
            return 'Monthly, day ' . $dom . ' ' . str_pad($hour, 2, '0', STR_PAD_LEFT) . ':' . str_pad($min, 2, '0', STR_PAD_LEFT);
        return '';
    }
}

$jobCount = count(array_filter($jobs, fn($j) => $j['type'] === 'job'));
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Cron Jobs</h1>
    <p class="page-subtitle"><?= (int) $jobCount ?> scheduled task<?= $jobCount === 1 ? '' : 's' ?> for user <span class="mono"><?= e($whoami) ?></span></p>
  </div>
</div>

<?php if (!$available): ?>
  <div class="card"><div class="empty-state">
    <div class="es-icon"><i data-lucide="clock"></i></div>
    <div style="font-weight:600;color:var(--text-secondary)">cron is not available</div>
    <div style="font-size:13px;margin-top:4px">The <span class="mono">crontab</span> command was not found on this system.</div>
  </div></div>
<?php else: ?>
  <div class="card" style="margin-bottom:18px">
    <div class="card-header">
      <h3>Add a job</h3>
      <span class="muted">Schedules run as <span class="mono"><?= e($whoami) ?></span></span>
    </div>
    <div class="card-pad">
      <div class="grid" style="grid-template-columns:240px 1fr auto;gap:14px;align-items:end">
        <div>
          <label class="field-label" for="cronSchedule">Schedule</label>
          <input class="input mono" id="cronSchedule" placeholder="0 2 * * *" value="0 2 * * *" autocomplete="off">
        </div>
        <div>
          <label class="field-label" for="cronCommand">Command</label>
          <input class="input mono" id="cronCommand" placeholder="/usr/bin/backup.sh" autocomplete="off">
        </div>
        <button class="btn btn-primary" id="cronAdd"><i data-lucide="plus"></i>Add job</button>
      </div>

      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:14px">
        <span class="chip" data-cron-preset="@hourly"><i data-lucide="clock"></i>@hourly</span>
        <span class="chip" data-cron-preset="@daily"><i data-lucide="sun"></i>@daily</span>
        <span class="chip" data-cron-preset="@weekly"><i data-lucide="calendar-days"></i>@weekly</span>
        <span class="chip" data-cron-preset="@monthly"><i data-lucide="calendar"></i>@monthly</span>
        <span class="chip" data-cron-preset="@reboot"><i data-lucide="power"></i>@reboot</span>
        <span class="chip" data-cron-preset="*/5 * * * *"><i data-lucide="timer"></i>Every 5 min</span>
      </div>

      <div style="font-size:12px;color:var(--text-tertiary);margin-top:12px">
        Format: <span class="mono">minute hour day month weekday</span> — or an @keyword like <span class="mono">@reboot</span>, <span class="mono">@daily</span>. Click a preset to fill the schedule.
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h3>Current crontab</h3>
      <span class="muted" id="cronCount"><?= (int) $jobCount ?> job<?= $jobCount === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th style="width:220px">Schedule</th><th>Command</th><th style="text-align:right;width:80px">Actions</th></tr></thead>
        <tbody id="cronBody">
          <?php foreach ($jobs as $j): ?>
            <?php if ($j['type'] === 'job'): ?>
              <?php $hint = cron_describe($j['schedule']); ?>
              <tr data-index="<?= (int) $j['index'] ?>">
                <td>
                  <div class="mono" style="color:var(--blue-400);font-weight:600"><?= e($j['schedule']) ?></div>
                  <?php if ($hint !== ''): ?>
                    <div class="text-tertiary" style="font-size:11.5px;margin-top:3px"><?= e($hint) ?></div>
                  <?php endif; ?>
                </td>
                <td class="mono" style="font-size:12.5px;word-break:break-all"><?= e($j['command']) ?></td>
                <td style="text-align:right"><button class="btn btn-danger btn-sm" data-cron-del="<?= (int) $j['index'] ?>" title="Delete job"><i data-lucide="trash-2"></i></button></td>
              </tr>
            <?php elseif ($j['type'] === 'comment' || $j['type'] === 'env'): ?>
              <tr>
                <td colspan="3" class="mono text-tertiary" style="font-size:12px;background:var(--bg-surface-2)">
                  <?php if ($j['type'] === 'env'): ?><span class="badge badge-slate" style="margin-right:8px">env</span><?php endif; ?><?= e($j['raw']) ?>
                </td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php if (!$jobCount): ?>
            <tr id="cronEmpty"><td colspan="3" class="text-tertiary" style="text-align:center;padding:28px">No cron jobs yet. Add one above to get started.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast } = window.Nebula;

  document.querySelectorAll('[data-cron-preset]').forEach((chip) => {
    chip.addEventListener('click', () => {
      const input = document.getElementById('cronSchedule');
      if (input) { input.value = chip.dataset.cronPreset; input.focus(); }
    });
  });

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
