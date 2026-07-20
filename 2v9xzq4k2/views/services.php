<?php
/** @var array $config */
require_once APP_ROOT . '/lib/mod_apps.php';
$allowed = array_values(array_unique(array_merge($config['services'], manageable_units())));
$services = services_overview($allowed);
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Services</h1>
    <p class="page-subtitle">Start, stop, and restart system services via systemd</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-secondary" id="svcRefresh"><i data-lucide="refresh-cw"></i>Refresh</button>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Service</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody id="svcBody">
        <?php foreach ($services as $svc): ?>
          <?php
            $s = $svc['status'];
            $badge = [
                'active'        => ['badge-emerald', 'Running'],
                'inactive'      => ['badge-slate',   'Stopped'],
                'failed'        => ['badge-red',     'Failed'],
                'not-installed' => ['badge-slate',   'Not installed'],
                'unknown'       => ['badge-slate',   'Unknown'],
            ][$s] ?? ['badge-slate', $s];
            $installed = $s !== 'not-installed';
          ?>
          <tr data-svc="<?= e($svc['name']) ?>">
            <td style="font-weight:600"><?= e($svc['name']) ?></td>
            <td><span class="badge <?= e($badge[0]) ?>" data-svc-badge><span class="bdot"></span><?= e($badge[1]) ?></span></td>
            <td style="text-align:right">
              <?php if ($installed): ?>
                <button class="btn btn-secondary btn-sm" data-action="start" title="Start"><i data-lucide="play"></i></button>
                <button class="btn btn-secondary btn-sm" data-action="restart" title="Restart"><i data-lucide="rotate-cw"></i></button>
                <button class="btn btn-danger btn-sm" data-action="stop" title="Stop"><i data-lucide="square"></i></button>
              <?php else: ?>
                <span class="text-tertiary" style="font-size:12px">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<script>window.NEBULA_PAGE = 'services';</script>
