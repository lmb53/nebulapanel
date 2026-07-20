<?php
require_once APP_ROOT . '/lib/mod_updates.php';
$available = upd_available();
$pkgs = $available ? upd_list() : [];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Updates</h1>
    <p class="page-subtitle"><?= count($pkgs) ?> update<?= count($pkgs) === 1 ? '' : 's' ?> available</p>
  </div>
  <?php if ($available): ?>
    <div class="page-actions">
      <button class="btn btn-secondary" id="updRefresh"><i data-lucide="refresh-cw"></i>Refresh</button>
      <button class="btn btn-primary" id="updUpgrade"><i data-lucide="arrow-up-circle"></i>Upgrade all</button>
    </div>
  <?php endif; ?>
</div>

<?php if (!$available): ?>
  <div class="card"><div class="empty-state">
    <div class="es-icon"><i data-lucide="package"></i></div>
    <div style="font-weight:600;color:var(--text-secondary)">apt is not available</div>
    <div style="font-size:13px;margin-top:4px">The <span class="mono">apt-get</span> command was not found on this system.</div>
  </div></div>
<?php else: ?>
  <div class="card">
    <div class="card-header"><h3>Available updates</h3></div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Package</th><th>Current</th><th>Candidate</th></tr></thead>
        <tbody>
          <?php foreach ($pkgs as $p): ?>
            <tr>
              <td style="font-weight:600"><?= e($p['package']) ?></td>
              <td class="mono" style="font-size:12.5px"><?= e($p['current']) ?></td>
              <td class="mono" style="font-size:12.5px;color:var(--blue-400)"><?= e($p['candidate']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$pkgs): ?>
            <tr><td colspan="3" class="text-tertiary" style="text-align:center;padding:24px">System is up to date.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card" style="margin-top:16px">
    <div class="card-header"><h3>Command output</h3></div>
    <div class="card-pad">
      <pre id="updOutput" class="mono" style="display:none;white-space:pre-wrap;word-break:break-word;font-size:12.5px;margin:0"></pre>
    </div>
  </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast } = window.Nebula;
  const out = document.getElementById('updOutput');

  async function run(btn, action, opts) {
    if (opts && opts.confirm && !confirm(opts.confirm)) return;
    btn.disabled = true;
    toast('Running…', 'info');
    try {
      const res = await apiPost('updates', { action });
      if (out) {
        out.textContent = res.output || res.error || '';
        out.style.display = '';
      }
      if (res.ok) {
        toast(opts && opts.done ? opts.done : 'Done', 'success');
        if (action === 'refresh') { setTimeout(() => location.reload(), 500); }
      } else {
        toast(res.error || 'Failed', 'error');
      }
    } finally {
      btn.disabled = false;
    }
  }

  document.getElementById('updRefresh')?.addEventListener('click', (e) =>
    run(e.currentTarget, 'refresh', { done: 'Package index refreshed' }));
  document.getElementById('updUpgrade')?.addEventListener('click', (e) =>
    run(e.currentTarget, 'upgrade', { confirm: 'Upgrade all packages now?', done: 'Upgrade complete' }));
});
</script>
