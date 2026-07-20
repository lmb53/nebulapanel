<?php
/** @var array $config */
require_once APP_ROOT . '/lib/mod_selfupdate.php';
$cur = su_current();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Panel Updates</h1>
    <p class="page-subtitle">Update Nebula Panel itself from
      <span class="mono"><?= e($config['repo'] ?? '') ?></span>@<span class="mono"><?= e($config['repo_ref'] ?? 'main') ?></span></p>
  </div>
  <div class="page-actions">
    <button class="btn btn-secondary" id="suCheck"><i data-lucide="refresh-cw"></i>Check for updates</button>
    <button class="btn btn-primary" id="suApply" disabled><i data-lucide="download-cloud"></i>Update now</button>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-pad">
    <div class="grid grid-2" style="gap:20px">
      <div>
        <div class="field-label">Installed version</div>
        <div class="mono" id="suCurrent" style="font-size:13px;color:var(--text-secondary)">
          <?= $cur ? e(substr($cur['sha'], 0, 12)) . ' · ' . e($cur['applied_at'] ?? '') : 'unknown (no version recorded yet)' ?>
        </div>
      </div>
      <div>
        <div class="field-label">Latest available</div>
        <div class="mono" id="suLatest" style="font-size:13px;color:var(--text-secondary)">— click “Check for updates”</div>
      </div>
    </div>
    <div id="suStatus" style="margin-top:16px"></div>
    <div id="suMessage" class="mono text-tertiary" style="margin-top:8px;font-size:12px;white-space:pre-wrap"></div>
  </div>
</div>

<div class="card hidden" id="suLogCard">
  <div class="card-header"><h3>Update log</h3></div>
  <pre class="mono" id="suLog" style="margin:0;padding:16px;font-size:12px;line-height:1.6;white-space:pre-wrap;max-height:50vh;overflow:auto"></pre>
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
    try { res = await apiGet('selfupdate'); } catch (e) { toast('Check failed', 'error'); return; }
    if (!res.ok) { statusEl.innerHTML = ''; toast(res.error || 'Check failed', 'error'); return; }
    document.getElementById('suLatest').textContent =
      res.latest_sha.slice(0, 12) + (res.date ? ' · ' + res.date : '');
    msgEl.textContent = res.message ? 'Latest commit: ' + res.message.split('\n')[0] : '';
    if (res.update_available) {
      badge('badge-orange', res.known ? 'Update available' : 'Update available (baseline unknown)');
      applyBtn.disabled = false;
    } else {
      badge('badge-emerald', 'Up to date');
      applyBtn.disabled = true;
    }
  }

  async function apply() {
    if (!confirm('Download and apply the latest version now? Your data/ and config.php are preserved, and a snapshot is taken first.')) return;
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
  check(); // auto-check on load
});
</script>
