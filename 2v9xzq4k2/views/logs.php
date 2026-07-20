<?php
require_once APP_ROOT . '/lib/mod_logs.php';
$sources = log_sources();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Logs</h1>
    <p class="page-subtitle">System journals and log files</p>
  </div>
</div>

<?php if (!$sources): ?>
  <div class="card"><div class="empty-state">
    <div class="es-icon"><i data-lucide="scroll-text"></i></div>
    <div style="font-weight:600;color:var(--text-secondary)">No log sources available</div>
    <div style="font-size:13px;margin-top:4px">No configured services or readable log files were found on this system.</div>
  </div></div>
<?php else: ?>
  <div class="card">
    <div class="card-header" style="gap:12px;flex-wrap:wrap">
      <select id="logSource" class="input" style="max-width:320px">
        <?php foreach ($sources as $s): ?>
          <option value="<?= e($s['id']) ?>"><?= e($s['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="logLines" class="input" style="max-width:120px">
        <option value="100">100 lines</option>
        <option value="200" selected>200 lines</option>
        <option value="500">500 lines</option>
        <option value="1000">1000 lines</option>
      </select>
      <button class="btn btn-secondary btn-sm" id="logRefresh"><i data-lucide="refresh-cw"></i>Refresh</button>
    </div>
    <pre id="logOutput" class="mono" style="margin:0;padding:16px;overflow:auto;font-size:12px;line-height:1.55;max-height:70vh;white-space:pre-wrap">Select a source…</pre>
  </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const select = document.getElementById('logSource');
  const linesSelect = document.getElementById('logLines');
  const refresh = document.getElementById('logRefresh');
  if (!select) return;

  function load() {
    const s = select.value, n = linesSelect.value;
    fetch(window.Nebula.api('logs') + '&source=' + encodeURIComponent(s) + '&lines=' + n)
      .then(r => r.json())
      .then(d => { document.getElementById('logOutput').textContent = d.text || '(empty)'; })
      .catch(() => window.Nebula.toast('Failed to load log', 'error'));
  }

  select.addEventListener('change', load);
  linesSelect.addEventListener('change', load);
  refresh?.addEventListener('click', load);
  if (select.value) load();
});
</script>
