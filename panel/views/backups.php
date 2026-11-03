<?php
require_once APP_ROOT . '/lib/mod_backups.php';
require_once APP_ROOT . '/lib/mod_sites.php';
$filterSite = preg_match('/^[a-f0-9]{32}$/', (string) ($_GET['site'] ?? '')) ? (string) $_GET['site'] : null;
$backups = backup_list($filterSite);
$sites = array_values(array_filter(sites_list(), fn($site) => preg_match('/^[a-f0-9]{32}$/', (string) ($site['id'] ?? ''))));
$siteNames = [];
$siteDbs = [];
foreach ($sites as $site) {
    $siteNames[$site['id']] = (string) $site['domain'];
    $siteDbs[$site['id']] = backup_site_databases((string) $site['domain']);
}
$schedule = backup_schedule();
$nextRun = backup_schedule_next($schedule);
$last = is_array($schedule['last_result'] ?? null) ? $schedule['last_result'] : null;
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$kindLabels = ['manual' => ['Manual', 'badge-slate'], 'scheduled' => ['Scheduled', 'badge-blue'], 'pre-restore' => ['Safety copy', 'badge-purple']];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Backups</h1>
    <p class="page-subtitle">Back up, schedule and restore websites — files and their databases</p>
  </div>
</div>

<div class="grid grid-main-side" style="margin-bottom:16px">
  <div class="card">
    <div class="card-header"><h3>Create backup</h3></div>
    <div class="card-pad">
      <?php if (!$sites): ?>
      <?= requirement_missing('globe', 'No websites to back up yet', 'Backups archive a managed website’s files and linked databases. Create a website first.', [['label' => 'Add website', 'href' => url('websites') . '#add', 'icon' => 'plus', 'primary' => true]], false) ?>
      <?php else: ?>
      <form class="form-grid" id="bkForm" style="--cols:minmax(0,1fr) minmax(0,220px)" novalidate>
        <div>
          <label class="field-label" for="bkSite">Website<span class="req" aria-hidden="true">*</span></label>
          <select class="select" id="bkSite" required data-required-message="Choose the website to back up.">
            <option value="">Choose a website…</option>
            <?php foreach ($sites as $site): ?>
              <option value="<?= e($site['id']) ?>"<?= $filterSite === $site['id'] ? ' selected' : '' ?>><?= e($site['domain'] ?? $site['id']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="field-label" for="bkLabel">Label <span class="text-tertiary" style="font-weight:400">(optional)</span></label>
          <input class="input" id="bkLabel" maxlength="60" pattern="[A-Za-z0-9_.\-]+" data-pattern-message="Use letters, numbers, dots, dashes or underscores (no spaces)." placeholder="before-upgrade">
        </div>
        <label class="check-line" style="grid-column:1/-1"><input type="checkbox" id="bkDbs" checked><span>Include linked databases <span class="text-tertiary" id="bkDbList"></span></span></label>
        <div style="grid-column:1/-1"><button class="btn btn-primary" type="submit" id="bkCreate"><i data-lucide="archive"></i>Create backup</button></div>
      </form>
      <div class="field-help" style="margin-top:10px">Archives contain <span class="mono">public/</span> and a <span class="mono">mysqldump</span> of each database linked to the site on the <a href="<?= e(url('databases')) ?>">Databases</a> page, with a checksum manifest. They are stored privately in the panel’s data folder.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Schedule</h3><span class="badge <?= $schedule['enabled'] ? 'badge-emerald' : 'badge-slate' ?>" id="bsBadge"><span class="bdot"></span><?= $schedule['enabled'] ? 'On' : 'Off' ?></span></div>
    <form class="card-pad form-stack" id="bsForm" novalidate>
      <label class="check-line"><input type="checkbox" id="bsEnabled"<?= $schedule['enabled'] ? ' checked' : '' ?>><span>Back up automatically</span></label>
      <div class="form-grid" style="--cols:1fr 1fr">
        <div>
          <label class="field-label" for="bsFrequency">Frequency</label>
          <select class="select" id="bsFrequency">
            <option value="daily"<?= $schedule['frequency'] === 'daily' ? ' selected' : '' ?>>Every night</option>
            <option value="weekly"<?= $schedule['frequency'] === 'weekly' ? ' selected' : '' ?>>Weekly</option>
          </select>
        </div>
        <div>
          <label class="field-label" for="bsHour">At (server time)</label>
          <select class="select mono" id="bsHour">
            <?php for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>"<?= (int) $schedule['hour'] === $h ? ' selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?>
          </select>
        </div>
        <div id="bsWeekdayWrap"<?= $schedule['frequency'] === 'weekly' ? '' : ' class="hidden"' ?>>
          <label class="field-label" for="bsWeekday">On</label>
          <select class="select" id="bsWeekday">
            <?php foreach ($days as $i => $day): ?><option value="<?= $i ?>"<?= (int) $schedule['weekday'] === $i ? ' selected' : '' ?>><?= e($day) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="field-label" for="bsKeep">Keep the last</label>
          <input class="input mono" id="bsKeep" type="number" min="1" max="90" required value="<?= (int) $schedule['keep'] ?>" aria-describedby="bsKeepHelp">
          <div class="field-help" id="bsKeepHelp">scheduled backups per website</div>
        </div>
      </div>
      <label class="check-line"><input type="checkbox" id="bsDbs"<?= $schedule['include_databases'] ? ' checked' : '' ?>><span>Include linked databases</span></label>
      <?php if ($sites): ?>
      <fieldset class="check-group">
        <legend class="field-label">Websites</legend>
        <label class="check-line"><input type="checkbox" id="bsAllSites"<?= !$schedule['sites'] ? ' checked' : '' ?>><span>All websites, including ones added later</span></label>
        <div id="bsSiteList"<?= !$schedule['sites'] ? ' class="hidden"' : '' ?>>
          <?php foreach ($sites as $site): ?>
            <label class="check-line"><input type="checkbox" data-bs-site="<?= e($site['id']) ?>"<?= in_array($site['id'], $schedule['sites'], true) ? ' checked' : '' ?>><span><?= e($site['domain']) ?></span></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <?php endif; ?>
      <div class="field-help">
        <?php if ($nextRun): ?>Next run: <strong><?= e(fmt_datetime($nextRun)) ?></strong>. <?php endif; ?>
        <?php if ($last): ?>Last run <?= e(fmt_datetime((int) $last['at'])) ?>: <?= (int) $last['created'] ?> created<?= !empty($last['failed']) ? ', <span style="color:var(--crit)">' . count($last['failed']) . ' failed</span>' : '' ?><?= !empty($last['pruned']) ? ', ' . (int) $last['pruned'] . ' old removed' : '' ?>.<?php endif; ?>
        Manual backups are never removed automatically.
      </div>
      <div class="flex gap-2" style="flex-wrap:wrap">
        <button class="btn btn-primary" type="submit" id="bsSave"><i data-lucide="save"></i>Save schedule</button>
        <?php if ($sites): ?><button class="btn btn-secondary" type="button" id="bsRunNow"><i data-lucide="play"></i>Run now</button><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h3>Archives</h3>
    <?php if ($sites): ?>
    <select class="select" id="bkFilter" aria-label="Show backups for" style="width:auto;min-width:200px">
      <option value="">All websites</option>
      <?php foreach ($sites as $site): ?><option value="<?= e($site['id']) ?>"<?= $filterSite === $site['id'] ? ' selected' : '' ?>><?= e($site['domain']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Archive</th><th>Contents</th><th class="num">Size</th><th>Created</th><th class="actions-col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody id="bkBody">
        <?php foreach ($backups as $b): [$kindLabel, $kindClass] = $kindLabels[$b['kind']] ?? $kindLabels['manual']; ?>
          <tr data-file="<?= e($b['file']) ?>">
            <td>
              <div class="mono" style="font-size:13px;word-break:break-all"><?= e($b['file']) ?></div>
              <div class="text-tertiary" style="font-size:12px;margin-top:2px"><?= e($b['domain'] !== '' ? $b['domain'] : 'Unknown website') ?> · <span class="badge <?= e($kindClass) ?>"><?= e($kindLabel) ?></span></div>
            </td>
            <td><span class="badge badge-slate"><i data-lucide="folder" style="width:11px;height:11px;vertical-align:-1px"></i> Files</span><?php foreach ($b['databases'] as $db): ?> <span class="badge badge-blue mono"><i data-lucide="database" style="width:11px;height:11px;vertical-align:-1px"></i> <?= e($db) ?></span><?php endforeach; ?></td>
            <td class="mono text-tertiary num"><?= e(human_bytes($b['size'])) ?></td>
            <td class="text-tertiary" style="white-space:nowrap"><?= e(fmt_datetime($b['mtime'])) ?></td>
            <td class="actions-col" style="white-space:nowrap">
              <button class="btn btn-secondary btn-sm" type="button" data-bk-restore="<?= e($b['file']) ?>" data-site="<?= e($b['site_id']) ?>" data-dbs="<?= e(implode(',', $b['databases'])) ?>" data-domain="<?= e($b['domain']) ?>"><i data-lucide="history"></i>Restore</button>
              <a class="btn btn-secondary btn-sm btn-icon" href="<?= e(url('backup-download', ['file' => $b['file']])) ?>" title="Download" aria-label="Download <?= e($b['file']) ?>"><i data-lucide="download"></i></a>
              <button class="btn btn-secondary btn-sm btn-icon" type="button" data-bk-verify="<?= e($b['file']) ?>" title="Verify integrity" aria-label="Verify <?= e($b['file']) ?>"><i data-lucide="shield-check"></i></button>
              <button class="btn btn-danger btn-sm btn-icon" type="button" data-bk-del="<?= e($b['file']) ?>" title="Delete" aria-label="Delete <?= e($b['file']) ?>"><i data-lucide="trash-2"></i></button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$backups): ?>
          <tr class="empty-row"><td colspan="5"><?= $filterSite ? 'No backups for this website yet.' : 'No backups yet.' ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-overlay hidden" id="bkRestoreModal">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="bkRestoreTitle">
    <div class="modal-header"><div class="modal-icon warning"><i data-lucide="history"></i></div><div><h2 class="modal-title" id="bkRestoreTitle">Restore backup</h2><div class="task-step mono" id="bkRestoreFile" style="word-break:break-all"></div></div></div>
    <form id="bkRestoreForm" novalidate>
      <div class="modal-body form-stack" style="white-space:normal">
        <div>
          <label class="field-label" for="bkRestoreTarget">Restore into</label>
          <select class="select" id="bkRestoreTarget">
            <?php foreach ($sites as $site): ?><option value="<?= e($site['id']) ?>"><?= e($site['domain']) ?></option><?php endforeach; ?>
          </select>
          <div class="field-help" id="bkRestoreTargetHelp">To recover a deleted website, re-create it on the Websites page, then restore into it.</div>
        </div>
        <fieldset class="check-group">
          <legend class="field-label">What to restore</legend>
          <label class="check-line"><input type="checkbox" id="bkRestoreFiles" checked><span>Website files <span class="text-tertiary">— replaces everything in <span class="mono">public/</span></span></span></label>
          <div id="bkRestoreDbs"></div>
        </fieldset>
        <label class="check-line"><input type="checkbox" id="bkRestoreSafety" checked><span>Take a safety backup of the current site first <span class="text-tertiary">(recommended)</span></span></label>
        <div class="notice notice-warning"><i data-lucide="triangle-alert"></i><div>Restoring overwrites the live files and drops and re-imports each selected database. Changes made since this backup are lost unless a safety backup is taken.</div></div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bk-restore-cancel>Cancel</button><button class="btn btn-danger-solid" type="submit"><i data-lucide="history"></i>Restore</button></div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast, runTask, validateForm, setBusy } = window.Nebula;
  const siteDbs = <?= json_encode((object) $siteDbs, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const $ = (id) => document.getElementById(id);

  // ---- Create ----
  const site = $('bkSite');
  const syncDbList = () => {
    const dbs = siteDbs[site?.value] || [];
    if ($('bkDbList')) $('bkDbList').textContent = site?.value ? (dbs.length ? '(' + dbs.join(', ') + ')' : '(none linked to this site)') : '';
  };
  site?.addEventListener('change', syncDbList); syncDbList();
  $('bkForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!validateForm(event.currentTarget)) return;
    const res = await runTask({ title: 'Creating backup', endpoint: 'backups', successMessage: 'Backup created',
      body: { action: 'create', site_id: site.value, label: $('bkLabel').value.trim(), include_databases: $('bkDbs').checked } });
    if (res.ok) { toast('Backup created', 'success'); location.reload(); }
  });

  // ---- Schedule ----
  $('bsFrequency')?.addEventListener('change', () => $('bsWeekdayWrap').classList.toggle('hidden', $('bsFrequency').value !== 'weekly'));
  $('bsAllSites')?.addEventListener('change', () => $('bsSiteList').classList.toggle('hidden', $('bsAllSites').checked));
  $('bsForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const sites = $('bsAllSites')?.checked ? [] : Array.from(document.querySelectorAll('[data-bs-site]:checked')).map((cb) => cb.dataset.bsSite);
    const extra = [];
    if ($('bsAllSites') && !$('bsAllSites').checked && !sites.length) extra.push([$('bsAllSites'), 'Choose at least one website, or all websites.']);
    if (!validateForm(event.currentTarget, extra)) return;
    const button = $('bsSave'); setBusy(button, true);
    const res = await apiPost('backups', { action: 'schedule', enabled: $('bsEnabled').checked, frequency: $('bsFrequency').value,
      hour: Number($('bsHour').value), weekday: Number($('bsWeekday').value), keep: Number($('bsKeep').value), include_databases: $('bsDbs').checked, sites });
    setBusy(button, false);
    if (res.ok) { toast('Backup schedule saved', 'success'); setTimeout(() => location.reload(), 500); }
    else toast(res.error || 'Could not save the schedule', 'error');
  });
  $('bsRunNow')?.addEventListener('click', async () => {
    const res = await runTask({ title: 'Running scheduled backups', endpoint: 'backups', body: { action: 'run-schedule' }, successMessage: 'Scheduled backups finished' });
    if (res.ok) location.reload();
  });

  // ---- Filter ----
  $('bkFilter')?.addEventListener('change', () => {
    const url = new URL(location.href);
    if ($('bkFilter').value) url.searchParams.set('site', $('bkFilter').value); else url.searchParams.delete('site');
    location.href = url.toString();
  });

  // ---- Restore ----
  const modal = $('bkRestoreModal');
  let restoreFile = '';
  const closeModal = () => modal.classList.add('hidden');
  modal.addEventListener('mousedown', (e) => { if (e.target === modal) closeModal(); });
  modal.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });
  modal.querySelector('[data-bk-restore-cancel]').addEventListener('click', closeModal);
  document.querySelectorAll('[data-bk-restore]').forEach((btn) => btn.addEventListener('click', () => {
    restoreFile = btn.dataset.bkRestore;
    $('bkRestoreFile').textContent = restoreFile;
    const target = $('bkRestoreTarget');
    const original = Array.from(target.options).some((o) => o.value === btn.dataset.site);
    if (original) target.value = btn.dataset.site;
    $('bkRestoreTargetHelp').textContent = original
      ? 'Restores into the website this backup was taken from. Choose another site only to move content between sites.'
      : (btn.dataset.domain ? btn.dataset.domain + ' no longer exists. ' : '') + 'To recover a deleted website, re-create it on the Websites page, then restore into it.';
    const box = $('bkRestoreDbs'); box.replaceChildren();
    (btn.dataset.dbs ? btn.dataset.dbs.split(',') : []).forEach((db) => {
      const label = document.createElement('label'); label.className = 'check-line';
      const cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = true; cb.dataset.restoreDb = db;
      const text = document.createElement('span'); text.append('Database ');
      const name = document.createElement('span'); name.className = 'mono'; name.textContent = db;
      const hint = document.createElement('span'); hint.className = 'text-tertiary'; hint.textContent = ' — dropped and re-imported';
      text.append(name, hint); label.append(cb, text); box.appendChild(label);
    });
    if (!target.options.length) { toast('Create a website to restore into first.', 'warning'); return; }
    modal.classList.remove('hidden');
    if (window.lucide) lucide.createIcons();
    target.focus();
  }));
  $('bkRestoreForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const databases = Array.from(document.querySelectorAll('[data-restore-db]:checked')).map((cb) => cb.dataset.restoreDb);
    const files = $('bkRestoreFiles').checked;
    if (!files && !databases.length) { window.Nebula.setFieldError($('bkRestoreFiles'), 'Choose the files, a database, or both.'); return; }
    const targetName = $('bkRestoreTarget').selectedOptions[0]?.textContent || '';
    if (!await window.Nebula.confirm({ title: `Restore into ${targetName}?`, message: 'The live website content is replaced. This cannot be undone without a safety backup.', danger: true, confirmLabel: 'Restore now' })) return;
    closeModal();
    const res = await runTask({ title: 'Restoring ' + targetName, endpoint: 'backups', successMessage: 'Restore complete',
      body: { action: 'restore', file: restoreFile, files, databases, target_site_id: $('bkRestoreTarget').value, safety_backup: $('bkRestoreSafety').checked } });
    if (res.ok) { toast('Restored ' + targetName + (res.safety_backup ? ' — safety copy ' + res.safety_backup : ''), 'success'); setTimeout(() => location.reload(), 800); }
  });

  // ---- Delete / verify ----
  document.querySelectorAll('[data-bk-del]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!await window.Nebula.confirm({ title: 'Delete this backup?', message: 'The archive is deleted permanently.', danger: true })) return;
      const res = await apiPost('backups', { action: 'delete', file: btn.dataset.bkDel });
      if (res.ok) { toast('Deleted', 'success'); btn.closest('tr').remove(); }
      else toast(res.error || 'Failed', 'error');
    });
  });
  document.querySelectorAll('[data-bk-verify]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      setBusy(btn, true);
      const res = await apiPost('backups', { action: 'verify', file: btn.dataset.bkVerify });
      setBusy(btn, false);
      if (res.ok) toast(`Archive verified: ${res.entries} entries, checksum matches`, 'success');
      else toast(res.error || 'Integrity check failed', 'error');
    });
  });
});
</script>
