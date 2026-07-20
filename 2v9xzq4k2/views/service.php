<?php
require_once APP_ROOT . '/lib/mod_apps.php';
$name = (string) ($_GET['name'] ?? '');
$services = manageable_services();
$svc = null;
foreach ($services as $s) {
    if ($s['unit'] === $name) { $svc = $s; break; }
}
?>
<?php if ($svc === null): ?>
  <div class="page-header"><div><h1 class="page-title">Service</h1></div></div>
  <div class="card"><div class="empty-state">
    <div class="es-icon"><i data-lucide="server-off"></i></div>
    <div style="font-weight:600;color:var(--text-secondary)">Unknown or unmanaged service</div>
    <div style="font-size:13px;margin-top:4px">This service is not installed or not managed by the panel.</div>
  </div></div>
<?php else: ?>
  <?php
    $status = service_status($name);
    $badge = [
        'active'   => ['badge-emerald', 'Running'],
        'inactive' => ['badge-slate',   'Stopped'],
        'failed'   => ['badge-red',     'Failed'],
    ][$status] ?? ['badge-slate', ucfirst($status)];
    [$jc, $jout] = run_cmd('journalctl -u ' . escapeshellarg($name) . ' -n 80 --no-pager 2>&1');
    if ($jc !== 0) {
        [$jc, $jout] = sudo_cmd('journalctl -u ' . escapeshellarg($name) . ' -n 80 --no-pager');
    }
  ?>
  <div class="page-header">
    <div>
      <div class="breadcrumb"><a href="<?= e(url('services')) ?>"><i data-lucide="server-cog"></i>Services</a><i data-lucide="chevron-right"></i><span><?= e($svc['label']) ?></span></div>
      <h1 class="page-title" style="display:flex;align-items:center;gap:10px"><i data-lucide="<?= e($svc['icon']) ?>"></i><?= e($svc['label']) ?></h1>
      <p class="page-subtitle">systemd unit <span class="mono"><?= e($name) ?></span></p>
    </div>
    <div class="page-actions" data-svc="<?= e($name) ?>">
      <span class="badge <?= e($badge[0]) ?>" data-svc-badge style="align-self:center"><span class="bdot"></span><?= e($badge[1]) ?></span>
      <button class="btn btn-secondary" data-action="start"><i data-lucide="play"></i>Start</button>
      <button class="btn btn-secondary" data-action="restart"><i data-lucide="rotate-cw"></i>Restart</button>
      <button class="btn btn-danger" data-action="stop"><i data-lucide="square"></i>Stop</button>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Recent logs</h3><span class="muted">journalctl · last 80 lines</span></div>
    <pre class="mono" style="margin:0;padding:16px;font-size:12px;line-height:1.55;white-space:pre-wrap;max-height:60vh;overflow:auto"><?= e($jout !== '' ? $jout : '(no journal output — may require a sudoers rule for journalctl)') ?></pre>
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const { apiPost, toast } = window.Nebula;
    const wrap = document.querySelector('[data-svc]');
    const name = wrap.dataset.svc;
    const map = { active: ['badge-emerald', 'Running'], inactive: ['badge-slate', 'Stopped'], failed: ['badge-red', 'Failed'] };
    wrap.querySelectorAll('[data-action]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        const res = await apiPost('services', { name, action: btn.dataset.action });
        btn.disabled = false;
        if (res.ok) {
          toast(name + ': ' + btn.dataset.action + ' ok', 'success');
          const b = wrap.querySelector('[data-svc-badge]');
          const [cls, label] = map[res.status] || ['badge-slate', res.status];
          if (b) { b.className = 'badge ' + cls; b.innerHTML = '<span class="bdot"></span>' + label; b.style.alignSelf = 'center'; }
        } else toast(res.error || 'Action failed', 'error');
      });
    });
  });
  </script>
<?php endif; ?>
