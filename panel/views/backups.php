<?php
require_once APP_ROOT . '/lib/mod_backups.php';
require_once APP_ROOT . '/lib/mod_sites.php';
require_once APP_ROOT . '/lib/mod_offsite.php';
$offsite = offsite_public();
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
$kindLabels = ['manual' => ['Manual', 'badge-slate'], 'scheduled' => ['Scheduled', 'badge-blue'], 'pre-restore' => ['Safety copy', 'badge-purple'], 'system' => ['Server config', 'badge-orange']];
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
      <label class="check-line"><input type="checkbox" id="bsSystem"<?= !empty($schedule['include_system']) ? ' checked' : '' ?>><span>Also back up the server configuration <span class="text-tertiary">(web server, certificates, mail, DNS, panel settings)</span></span></label>
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
    <div class="flex gap-2" style="flex-wrap:wrap">
    <button class="btn btn-secondary btn-sm" type="button" id="bkSystem" title="Web server, certificates, mail, DNS and panel settings — not website files or mailboxes"><i data-lucide="server-cog"></i>Back up server config</button>
    <?php if ($sites): ?>
    <select class="select" id="bkFilter" aria-label="Show backups for" style="width:auto;min-width:200px">
      <option value="">All websites</option>
      <?php foreach ($sites as $site): ?><option value="<?= e($site['id']) ?>"<?= $filterSite === $site['id'] ? ' selected' : '' ?>><?= e($site['domain']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    </div>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Archive</th><th>Contents</th><th class="num">Size</th><th>Created</th><th class="actions-col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody id="bkBody">
        <?php foreach ($backups as $b): [$kindLabel, $kindClass] = $kindLabels[$b['kind']] ?? $kindLabels['manual']; ?>
          <tr data-file="<?= e($b['file']) ?>">
            <td>
              <div class="mono" style="font-size:13px;word-break:break-all"><?= e($b['file']) ?></div>
              <div class="text-tertiary" style="font-size:12px;margin-top:2px"><?= e($b['kind'] === 'system' ? 'Server configuration' : ($b['domain'] !== '' ? $b['domain'] : 'Unknown website')) ?> · <span class="badge <?= e($kindClass) ?>"><?= e($kindLabel) ?></span><?php if ($b['offsite_at'] !== ''): ?> <span class="badge badge-emerald" title="Copied off-site <?= e(fmt_datetime((int) strtotime($b['offsite_at']))) ?>"><i data-lucide="cloud" style="width:11px;height:11px;vertical-align:-1px"></i> Off-site</span><?php endif; ?></div>
            </td>
            <td><?php if ($b['kind'] === 'system'): ?><span class="badge badge-orange"><i data-lucide="key-round" style="width:11px;height:11px;vertical-align:-1px"></i> Configuration &amp; keys</span><?php else: ?><span class="badge badge-slate"><i data-lucide="folder" style="width:11px;height:11px;vertical-align:-1px"></i> Files</span><?php endif; ?><?php foreach ($b['databases'] as $db): ?> <span class="badge badge-blue mono"><i data-lucide="database" style="width:11px;height:11px;vertical-align:-1px"></i> <?= e($db) ?></span><?php endforeach; ?></td>
            <td class="mono text-tertiary num"><?= e(human_bytes($b['size'])) ?></td>
            <td class="text-tertiary" style="white-space:nowrap"><?= e(fmt_datetime($b['mtime'])) ?></td>
            <td class="actions-col" style="white-space:nowrap">
              <?php if ($b['kind'] !== 'system'): ?><button class="btn btn-secondary btn-sm" type="button" data-bk-restore="<?= e($b['file']) ?>" data-site="<?= e($b['site_id']) ?>" data-dbs="<?= e(implode(',', $b['databases'])) ?>" data-domain="<?= e($b['domain']) ?>"><i data-lucide="history"></i>Restore</button><?php endif; ?>
              <?php if ($offsite['configured']): ?><button class="btn btn-secondary btn-sm btn-icon" type="button" data-bk-offsite="<?= e($b['file']) ?>" title="Copy off-site" aria-label="Copy <?= e($b['file']) ?> off-site"><i data-lucide="cloud-upload"></i></button><?php endif; ?>
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

<div class="card" style="margin-top:16px" id="offsite">
  <div class="card-header"><div><h3>Off-site copies</h3><span class="muted">Encrypted on this server, then stored in S3-compatible object storage (AWS S3, Backblaze B2, Wasabi, Cloudflare R2, MinIO…)</span></div>
    <span class="badge <?= $offsite['enabled'] ? 'badge-emerald' : 'badge-slate' ?>"><span class="bdot"></span><?= $offsite['enabled'] ? 'On' : 'Off' ?></span></div>
  <form class="card-pad form-grid" id="osForm" style="--cols:repeat(auto-fit,minmax(220px,1fr))" novalidate>
    <div style="grid-column:1/-1"><label class="check-line"><input type="checkbox" id="osEnabled"<?= $offsite['enabled'] ? ' checked' : '' ?>><span>Copy every scheduled backup off-site</span></label></div>
    <div><label class="field-label" for="osEndpoint">Endpoint</label><input class="input mono" id="osEndpoint" value="<?= e($offsite['endpoint']) ?>" placeholder="https://s3.eu-central-1.amazonaws.com" autocomplete="off"></div>
    <div><label class="field-label" for="osRegion">Region</label><input class="input mono" id="osRegion" value="<?= e($offsite['region']) ?>" placeholder="us-east-1" autocomplete="off"></div>
    <div><label class="field-label" for="osBucket">Bucket</label><input class="input mono" id="osBucket" value="<?= e($offsite['bucket']) ?>" autocomplete="off"></div>
    <div><label class="field-label" for="osPrefix">Folder in bucket</label><input class="input mono" id="osPrefix" value="<?= e($offsite['prefix']) ?>" placeholder="nebula" autocomplete="off"></div>
    <div><label class="field-label" for="osAccess">Access key ID</label><input class="input mono" id="osAccess" value="<?= e($offsite['access_key']) ?>" autocomplete="off"></div>
    <div><label class="field-label" for="osSecret">Secret access key</label><input class="input mono" id="osSecret" type="password" placeholder="<?= $offsite['has_secret'] ? '•••••••• (saved — leave blank to keep)' : '' ?>" autocomplete="new-password"></div>
    <div><label class="field-label" for="osKeep">Keep off-site</label><input class="input mono" id="osKeep" type="number" min="1" max="365" value="<?= (int) $offsite['keep'] ?>"><div class="field-help">automatic copies per website</div></div>
    <div style="align-self:center"><label class="check-line"><input type="checkbox" id="osPathStyle"<?= $offsite['path_style'] ? ' checked' : '' ?>><span>Path-style addresses <span class="text-tertiary">(MinIO, most non-AWS)</span></span></label></div>
    <div style="grid-column:1/-1" class="field-help">Encryption: <span class="mono">openssl aes-256-cbc, pbkdf2</span>. <?= $offsite['has_passphrase'] ? 'A passphrase is set.' : 'A passphrase is generated when you first save.' ?> Without it the copies cannot be decrypted, so keep it somewhere safe off this server. Decrypt by hand with <span class="mono">openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in FILE.enc -out FILE</span>.
      <?php if ($offsite['enabled'] && is_array($offsite['last_result'])): ?><br>Last upload <?= e(fmt_datetime((int) $offsite['last_result']['at'])) ?>: <?= (int) $offsite['last_result']['uploaded'] ?> copied<?= !empty($offsite['last_result']['failed']) ? ', <span style="color:var(--crit)">' . count($offsite['last_result']['failed']) . ' failed</span>' : '' ?>.<?php endif; ?></div>
    <div style="grid-column:1/-1" class="flex gap-2">
      <button class="btn btn-primary" type="submit" id="osSave"><i data-lucide="save"></i>Save</button>
      <button class="btn btn-secondary" type="button" id="osTest"<?= $offsite['configured'] ? '' : ' disabled' ?>><i data-lucide="plug-zap"></i>Test connection</button>
      <button class="btn btn-secondary" type="button" id="osBrowse"<?= $offsite['configured'] ? '' : ' disabled' ?>><i data-lucide="cloud-download"></i>Browse off-site copies</button>
      <?php if ($offsite['has_passphrase']): ?><button class="btn btn-ghost" type="button" id="osReveal"><i data-lucide="eye"></i>Show passphrase</button><?php endif; ?>
    </div>
  </form>
  <div class="table-wrap hidden" id="osListWrap"><table class="data-table"><thead><tr><th>Off-site archive</th><th class="num">Size</th><th>Uploaded</th><th class="actions-col"><span class="sr-only">Actions</span></th></tr></thead><tbody id="osList"></tbody></table></div>
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

<script nonce="<?= e(csp_nonce()) ?>">
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast, runTask, validateForm, setBusy } = window.Nebula;
  const siteDbs = <?= js_json((object) $siteDbs, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
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
      hour: Number($('bsHour').value), weekday: Number($('bsWeekday').value), keep: Number($('bsKeep').value), include_databases: $('bsDbs').checked, include_system: $('bsSystem').checked, sites });
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

  // ---- Server configuration + off-site ----
  $('bkSystem')?.addEventListener('click', async () => {
    const res = await runTask({ title: 'Backing up server configuration', endpoint: 'backups', body: { action: 'system-backup' }, successMessage: 'Server configuration backed up' });
    if (res.ok) location.reload();
  });
  document.querySelectorAll('[data-bk-offsite]').forEach((btn) => btn.addEventListener('click', async () => {
    const res = await runTask({ title: 'Copying off-site', endpoint: 'backups', body: { action: 'offsite-upload', file: btn.dataset.bkOffsite }, successMessage: 'Copied off-site' });
    if (res.ok) location.reload();
  }));
  const showSecret = (title, value, message) => window.Nebula.dialog({ title, icon: 'key-round', message, input: { label: 'Passphrase', value, mono: true, required: false }, confirmLabel: 'Copy', cancelLabel: 'Close' })
    .then((r) => { if (r.ok) window.Nebula.copyText(value).then((ok) => toast(ok ? 'Copied' : 'Copy failed', ok ? 'success' : 'error')); });
  $('osForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = $('osSave'); setBusy(button, true);
    const res = await apiPost('backups', { action: 'offsite-save', settings: { enabled: $('osEnabled').checked, endpoint: $('osEndpoint').value.trim(), region: $('osRegion').value.trim(),
      bucket: $('osBucket').value.trim(), prefix: $('osPrefix').value.trim(), access_key: $('osAccess').value.trim(), secret_key: $('osSecret').value, keep: Number($('osKeep').value), path_style: $('osPathStyle').checked } });
    setBusy(button, false);
    if (!res.ok) { toast(res.error || 'Could not save', 'error'); return; }
    if (res.passphrase) await showSecret('Save your encryption passphrase', res.passphrase, res.message);
    toast('Off-site settings saved', 'success'); setTimeout(() => { location.hash = 'offsite'; location.reload(); }, 400);
  });
  $('osTest')?.addEventListener('click', async (event) => {
    setBusy(event.currentTarget, true);
    const res = await apiPost('backups', { action: 'offsite-test' });
    setBusy(event.currentTarget, false);
    toast(res.ok ? res.message : (res.error || 'Test failed'), res.ok ? 'success' : 'error');
  });
  $('osReveal')?.addEventListener('click', async () => {
    const password = await window.Nebula.prompt({ title: 'Show the encryption passphrase', label: 'Your panel password', type: 'password', autocomplete: 'current-password', confirmLabel: 'Show' });
    if (password === null) return;
    const res = await apiPost('backups', { action: 'offsite-reveal', password });
    if (res.ok) showSecret('Encryption passphrase', res.passphrase, 'Keep it somewhere safe outside this server.'); else toast(res.error || 'Could not show it', 'error');
  });
  $('osBrowse')?.addEventListener('click', async (event) => {
    setBusy(event.currentTarget, true);
    const res = await apiPost('backups', { action: 'offsite-list' });
    setBusy(event.currentTarget, false);
    if (!res.ok) { toast(res.error || 'Could not list the bucket', 'error'); return; }
    const body = $('osList'); body.replaceChildren(); $('osListWrap').classList.remove('hidden');
    const cell = (text, cls) => { const c = document.createElement('td'); if (cls) c.className = cls; c.textContent = text; return c; };
    if (!res.items.length) { const row = document.createElement('tr'); row.className = 'empty-row'; const c = cell('No off-site copies yet.'); c.colSpan = 4; row.append(c); body.append(row); return; }
    res.items.forEach((item) => {
      const row = document.createElement('tr');
      const actions = cell('', 'actions-col');
      const fetchBtn = document.createElement('button'); fetchBtn.type = 'button'; fetchBtn.className = 'btn btn-secondary btn-sm';
      fetchBtn.textContent = item.local ? 'On this server' : 'Download to server'; fetchBtn.disabled = item.local;
      fetchBtn.addEventListener('click', async () => {
        const out = await runTask({ title: 'Fetching ' + item.file, endpoint: 'backups', body: { action: 'offsite-fetch', file: item.file }, successMessage: 'Downloaded and verified' });
        if (out.ok) { toast(out.message || 'Ready to restore', 'success'); setTimeout(() => location.reload(), 800); }
      });
      const del = document.createElement('button'); del.type = 'button'; del.className = 'btn btn-danger btn-sm btn-icon'; del.setAttribute('aria-label', 'Delete off-site copy'); del.innerHTML = '<i data-lucide="trash-2"></i>';
      del.addEventListener('click', async () => {
        if (!await window.Nebula.confirm({ title: 'Delete the off-site copy?', message: item.file, danger: true })) return;
        const out = await apiPost('backups', { action: 'offsite-delete', file: item.file });
        if (out.ok) { row.remove(); toast('Deleted', 'success'); } else toast(out.error || 'Failed', 'error');
      });
      actions.append(fetchBtn, ' ', del);
      row.append(cell(item.file, 'mono'), cell(window.Nebula.fmtBytes(item.size), 'num mono text-tertiary'), cell(window.Nebula.fmtDate(item.mtime), 'text-tertiary'), actions);
      body.append(row);
    });
    if (window.lucide) lucide.createIcons();
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
