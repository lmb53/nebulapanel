<?php
require_once APP_ROOT . '/lib/mod_cron.php';
$available = cron_available();
$jobs = $available ? cron_list() : [];
$runs = cron_runs();
$whoami = trim(shell_exec('whoami 2>/dev/null') ?: 'web user');
$scheduled = array_values(array_filter($jobs, fn($j) => ($j['type'] ?? '') === 'job'));
?>
<div class="page-header">
  <div><h1 class="page-title">Cron Jobs</h1><p class="page-subtitle"><?= count($scheduled) ?> scheduled tasks · running as <span class="mono"><?= e($whoami) ?></span></p></div>
  <?php if ($available): ?><div class="page-actions"><button class="btn btn-primary" id="cronNew"><i data-lucide="plus"></i>New Cron Job</button></div><?php endif; ?>
</div>
<?php if (!$available): ?>
  <div class="card"><div class="empty-state"><div class="es-icon"><i data-lucide="clock"></i></div><div>crontab is not available.</div></div></div>
<?php else: ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><h3>Scheduled jobs</h3><span class="muted"><?= count($scheduled) ?> jobs</span></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Schedule</th><th>Command</th><th>User</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead><tbody>
  <?php foreach ($scheduled as $job): $enabled = ($job['enabled'] ?? true) === true; ?>
    <tr class="<?= $enabled ? '' : 'cron-disabled' ?>">
      <td><div class="mono" style="font-weight:600;color:var(--blue-400)"><?= e($job['schedule']) ?></div></td>
      <td class="mono" style="word-break:break-all"><?= e($job['command']) ?></td>
      <td class="mono text-tertiary"><?= e($whoami) ?></td>
      <td><div class="flex gap-2" style="align-items:center"><button class="toggle<?= $enabled ? ' on' : '' ?> cron-toggle" type="button" data-cron-toggle="<?= (int)$job['index'] ?>" data-enabled="<?= $enabled ? '1' : '0' ?>" aria-pressed="<?= $enabled ? 'true' : 'false' ?>" title="<?= $enabled ? 'Disable' : 'Enable' ?> job"><span class="knob"></span></button><span class="badge <?= $enabled ? 'badge-emerald' : 'badge-slate' ?>"><?= $enabled ? 'Enabled' : 'Disabled' ?></span></div></td>
      <td style="text-align:right"><div class="flex gap-1" style="justify-content:flex-end"><button class="icon-btn" data-cron-run="<?= (int)$job['index'] ?>" title="Run now"><i data-lucide="play"></i></button><button class="icon-btn" data-cron-edit="<?= (int)$job['index'] ?>" data-schedule="<?= e($job['schedule']) ?>" data-command="<?= e($job['command']) ?>" title="Edit"><i data-lucide="pencil"></i></button><button class="icon-btn" data-cron-del="<?= (int)$job['index'] ?>" title="Delete" style="color:var(--red-400)"><i data-lucide="trash-2"></i></button></div></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$scheduled): ?><tr><td colspan="5" class="text-tertiary" style="text-align:center;padding:28px">No cron jobs yet.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<div class="card">
  <div class="card-header"><h3>Recent runs</h3><span class="muted">Manual executions from Nebula</span></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Time</th><th>Command</th><th>Exit</th><th>Output</th></tr></thead><tbody>
  <?php foreach ($runs as $run): ?><tr><td class="mono text-tertiary"><?= e(date('M j, H:i', strtotime($run['time'] ?? 'now'))) ?></td><td class="mono"><?= e($run['command'] ?? '') ?></td><td><span class="badge <?= ($run['exit'] ?? 1) === 0 ? 'badge-emerald' : 'badge-red' ?>"><?= (int)($run['exit'] ?? 1) ?></span></td><td class="mono text-tertiary" style="max-width:420px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e(trim($run['output'] ?? '') ?: '–') ?></td></tr><?php endforeach; ?>
  <?php if (!$runs): ?><tr><td colspan="4" class="text-tertiary" style="text-align:center;padding:24px">No manual runs yet.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<div class="drawer-overlay hidden" id="cronDrawer"><div class="drawer"><div class="drawer-header"><div><strong id="cronDrawerTitle">New Cron Job</strong><div class="muted" style="font-size:11px">Build a schedule with dropdowns or enter an advanced expression</div></div><button class="icon-btn" data-close-cron><i data-lucide="x"></i></button></div>
  <div class="drawer-body">
    <input type="hidden" id="cronIndex">
    <div style="margin-bottom:18px"><label class="field-label">Quick presets</label><div class="flex gap-2" style="flex-wrap:wrap"><?php foreach (['@hourly','@daily','@weekly','@monthly','@reboot','*/5 * * * *'] as $preset): ?><button class="chip" data-cron-preset="<?= e($preset) ?>" type="button"><?= e($preset) ?></button><?php endforeach; ?></div></div>
    <div style="margin-bottom:18px"><label class="field-label">Visual schedule</label><div class="cron-builder">
      <label><span>Second</span><select class="input" disabled><option>0</option></select><small>Linux cron starts on the minute</small></label>
      <label><span>Minute</span><select class="input" data-cron-part="0"><option value="*">Every minute</option><?php foreach (['*/5'=>'Every 5 min','*/10'=>'Every 10 min','*/15'=>'Every 15 min','*/30'=>'Every 30 min'] as $v=>$label): ?><option value="<?= e($v) ?>"><?= e($label) ?></option><?php endforeach; ?><?php for($i=0;$i<60;$i++): ?><option value="<?= $i ?>"><?= str_pad((string)$i,2,'0',STR_PAD_LEFT) ?></option><?php endfor; ?></select></label>
      <label><span>Hour</span><select class="input" data-cron-part="1"><option value="*">Every hour</option><?php for($i=0;$i<24;$i++): ?><option value="<?= $i ?>"><?= str_pad((string)$i,2,'0',STR_PAD_LEFT) ?>:00</option><?php endfor; ?></select></label>
      <label><span>Day</span><select class="input" data-cron-part="2"><option value="*">Every day</option><?php for($i=1;$i<=31;$i++): ?><option value="<?= $i ?>"><?= $i ?></option><?php endfor; ?></select></label>
      <label><span>Month</span><select class="input" data-cron-part="3"><option value="*">Every month</option><?php foreach(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $i=>$month): ?><option value="<?= $i+1 ?>"><?= $month ?></option><?php endforeach; ?></select></label>
      <label><span>Weekday</span><select class="input" data-cron-part="4"><option value="*">Every day</option><?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $i=>$day): ?><option value="<?= $i ?>"><?= $day ?></option><?php endforeach; ?></select></label>
    </div><p class="muted" id="cronBuilderHint" style="font-size:11px;margin-top:8px">The dropdowns update the five-part cron expression below.</p></div>
    <div style="margin-bottom:18px"><label class="field-label" for="cronSchedule">Schedule expression</label><input class="input mono" id="cronSchedule" value="0 2 * * *"><p class="muted" style="font-size:11px;margin-top:6px">Advanced ranges, steps, lists, and @keywords are supported.</p></div>
    <div><label class="field-label" for="cronCommand">Command</label><textarea class="input mono" id="cronCommand" rows="6" placeholder="/usr/bin/php /var/www/site/artisan schedule:run"></textarea></div>
  </div>
  <div class="drawer-footer"><button class="btn btn-secondary" data-close-cron>Cancel</button><button class="btn btn-primary" id="cronSave"><i data-lucide="save"></i>Save job</button></div>
</div></div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast } = window.Nebula;
  const drawer = document.getElementById('cronDrawer');
  const idx = document.getElementById('cronIndex');
  const schedule = document.getElementById('cronSchedule');
  const command = document.getElementById('cronCommand');
  const parts = Array.from(document.querySelectorAll('[data-cron-part]'));
  const hint = document.getElementById('cronBuilderHint');
  const close = () => drawer.classList.add('hidden');
  const syncBuilder = () => {
    const fields = schedule.value.trim().split(/\s+/);
    const keyword = schedule.value.trim().startsWith('@');
    parts.forEach((select, i) => {
      select.disabled = keyword;
      select.querySelectorAll('[data-custom-value]').forEach((option) => option.remove());
      if (!keyword && fields.length === 5) {
        const value = fields[i];
        if (!Array.from(select.options).some((option) => option.value === value)) {
          const option = new Option(`Custom (${value})`, value); option.dataset.customValue = '1'; select.add(option);
        }
        select.value = value;
      }
    });
    hint.textContent = keyword ? 'This @keyword preset does not use individual time fields.' : 'The dropdowns update the five-part cron expression below.';
  };
  const open = (job = null) => {
    idx.value = job?.index ?? '';
    schedule.value = job?.schedule || '0 2 * * *';
    command.value = job?.command || '';
    document.getElementById('cronDrawerTitle').textContent = job ? 'Edit Cron Job' : 'New Cron Job';
    syncBuilder(); drawer.classList.remove('hidden');
  };
  document.getElementById('cronNew').onclick = () => open();
  document.querySelectorAll('[data-close-cron]').forEach((button) => button.onclick = close);
  drawer.addEventListener('click', (event) => { if (event.target === drawer) close(); });
  parts.forEach((select) => select.addEventListener('change', () => { schedule.value = parts.map((field) => field.value).join(' '); syncBuilder(); }));
  schedule.addEventListener('input', syncBuilder);
  document.querySelectorAll('[data-cron-preset]').forEach((button) => button.onclick = () => { schedule.value = button.dataset.cronPreset; syncBuilder(); });
  document.querySelectorAll('[data-cron-edit]').forEach((button) => button.onclick = () => open({ index:+button.dataset.cronEdit, schedule:button.dataset.schedule, command:button.dataset.command }));
  document.getElementById('cronSave').onclick = async () => {
    const result = await apiPost('cron', { action:idx.value ? 'update' : 'add', index:+idx.value, schedule:schedule.value, command:command.value });
    toast(result.ok ? 'Cron job saved' : (result.error || 'Save failed'), result.ok ? 'success' : 'error');
    if (result.ok) setTimeout(() => location.reload(), 350);
  };
  document.querySelectorAll('[data-cron-toggle]').forEach((button) => button.onclick = async () => {
    button.disabled = true;
    const result = await apiPost('cron', { action:'toggle', index:+button.dataset.cronToggle, enabled:button.dataset.enabled !== '1' });
    toast(result.ok ? `Cron job ${button.dataset.enabled === '1' ? 'disabled' : 'enabled'}` : (result.error || 'Update failed'), result.ok ? 'success' : 'error');
    if (result.ok) setTimeout(() => location.reload(), 250); else button.disabled = false;
  });
  document.querySelectorAll('[data-cron-del]').forEach((button) => button.onclick = async () => { if (!confirm('Delete this cron job?')) return; const result=await apiPost('cron',{action:'delete',index:+button.dataset.cronDel}); toast(result.ok?'Cron job deleted':(result.error||'Delete failed'),result.ok?'success':'error'); if(result.ok)setTimeout(()=>location.reload(),300); });
  document.querySelectorAll('[data-cron-run]').forEach((button) => button.onclick = async () => { button.disabled=true;const result=await apiPost('cron',{action:'run',index:+button.dataset.cronRun});button.disabled=false;toast(result.ok?'Cron command completed':(result.error||'Command failed'),result.ok?'success':'error');setTimeout(()=>location.reload(),500); });
});
</script>
<?php endif; ?>
