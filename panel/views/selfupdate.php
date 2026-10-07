<?php
/** @var array $config */
require_once APP_ROOT . '/lib/mod_selfupdate.php';
$cur = su_current();
?>
<?php /* Panel self-update: rendered as the "Nebula Panel" tab of the Updates page. */ ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header">
    <div><h3>Nebula Panel</h3><span class="muted">Updates from <span class="mono"><?= e($config['repo'] ?? '') ?>@<?= e($config['repo_ref'] ?? 'main') ?></span></span></div>
    <div class="flex gap-2">
      <button class="btn btn-secondary btn-sm" type="button" id="suCheck"><i data-lucide="refresh-cw"></i>Check for updates</button>
      <button class="btn btn-primary btn-sm" type="button" id="suApply" disabled><i data-lucide="download-cloud"></i>Update now</button>
    </div>
  </div>
  <div class="card-pad">
    <div class="grid grid-2" style="gap:20px">
      <div>
        <div class="field-label">Installed version</div>
        <div id="suCurrent" style="font-size:13px;color:var(--text-secondary)">
          <?= $cur ? '<span class="mono">' . e(substr($cur['sha'], 0, 12)) . '</span> · ' . e(fmt_datetime($cur['applied_at'] ?? '')) : 'Not recorded yet' ?>
        </div>
      </div>
      <div>
        <div class="field-label">Latest available</div>
        <div id="suLatest" style="font-size:13px;color:var(--text-secondary)">Not checked yet</div>
      </div>
    </div>
    <div id="suStatus" style="margin-top:16px"></div>
    <div id="suMessage" class="field-help" style="white-space:pre-wrap"></div>
  </div>
</div>

<div class="card hidden" id="suLogCard">
  <div class="card-header"><h3>Update log</h3></div>
  <pre class="mono log-pre" id="suLog"></pre>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiGet, apiPost, toast } = window.Nebula;
  const applyBtn = document.getElementById('suApply');
  const statusEl = document.getElementById('suStatus');
  const msgEl = document.getElementById('suMessage');

  function badge(cls, text, icon) {
    statusEl.innerHTML = `<span class="badge ${cls}"><span class="bdot"></span>${text}</span>`;
    if (window.lucide) lucide.createIcons();
  }

  async function check() {
    statusEl.innerHTML = '<span class="text-tertiary" style="font-size:13px">Checking…</span>';
    msgEl.textContent = '';
    let res;
    try {
      res = await apiGet('selfupdate');
    } catch (e) {
      const message = e?.message || 'Could not contact the update source.';
      badge('badge-red', 'Check failed');
      msgEl.textContent = message;
      toast(message, 'error');
      return;
    }
    if (!res.ok) {
      const message = res.error || 'Could not check for updates.';
      badge('badge-red', 'Check failed');
      msgEl.textContent = message;
      toast(message, 'error');
      return;
    }
    const latest = document.getElementById('suLatest');
    latest.replaceChildren();
    const sha = document.createElement('span'); sha.className = 'mono'; sha.textContent = res.latest_sha.slice(0, 12);
    latest.append(sha, document.createTextNode(res.date ? ' · ' + window.Nebula.fmtDate(res.date) : ''));
    msgEl.textContent = res.message ? 'Latest change: ' + res.message.split('\n')[0] : '';
    if (res.update_available) {
      badge(res.known ? 'badge-orange' : 'badge-blue', res.known ? 'Update available' : 'Installed version not recorded');
      if (!res.known) msgEl.textContent = 'The installed version was not recorded, so the panel cannot tell whether it is current. Updating installs the latest reviewed commit and records it.' + (msgEl.textContent ? '\n' + msgEl.textContent : '');
      applyBtn.classList.toggle('btn-primary', !!res.known);
      applyBtn.classList.toggle('btn-secondary', !res.known);
      applyBtn.disabled = false;
    } else {
      badge('badge-emerald', 'Up to date');
      applyBtn.disabled = true;
    }
  }

  async function apply() {
    if (!await window.Nebula.confirm({ title: 'Update Nebula Panel now?', message: 'Your data/ folder and config.php are preserved, and a snapshot is taken first.', warning: true, icon: 'download-cloud', confirmLabel: 'Update now' })) return;
    applyBtn.disabled = true;
    const orig = applyBtn.innerHTML;
    applyBtn.innerHTML = '<i data-lucide="loader-circle"></i>Updating…';
    if (window.lucide) lucide.createIcons();
    const logCard = document.getElementById('suLogCard');
    const logEl = document.getElementById('suLog');
    logCard.classList.remove('hidden');
    logEl.textContent = 'Starting…';
    let res;
    try { res = await apiPost('selfupdate', { action: 'apply' }); }
    catch (e) { logEl.textContent += '\n[request failed]'; applyBtn.innerHTML = orig; return; }
    logEl.textContent = (res.log || []).join('\n');
    if (res.ok) {
      logEl.textContent += '\n\nDone. Reloading…';
      toast('Panel updated', 'success');
      setTimeout(() => location.reload(), 1500);
    } else {
      logEl.textContent += '\n\nERROR: ' + (res.error || 'failed');
      if (res.rollback) logEl.textContent += '\n' + res.rollback;
      toast(res.error || 'Update failed', 'error');
      applyBtn.innerHTML = orig;
      applyBtn.disabled = false;
    }
  }

  document.getElementById('suCheck').addEventListener('click', check);
  applyBtn.addEventListener('click', apply);
  // Check when the Nebula Panel tab is first shown (avoids a GitHub request on
  // every visit to the system-packages tab).
  const panelTab = document.getElementById('updPanel');
  let checked = false;
  const maybeCheck = () => { if (!checked && panelTab && !panelTab.classList.contains('hidden')) { checked = true; check(); } };
  document.querySelector('[data-tab-target="updPanel"]')?.addEventListener('click', () => setTimeout(maybeCheck));
  maybeCheck();
});
</script>
