<?php
$facts = system_facts();
$net = net_interfaces();
$mem = mem_info();
$disk = disk_info('/');
$rows = [
    ['Hostname',    $facts['hostname']],
    ['Operating system', $facts['os']],
    ['Kernel',      $facts['kernel']],
    ['Architecture', $facts['arch']],
    ['CPU',         $facts['cpu_model']],
    ['CPU cores',   (string) $facts['cpu_cores']],
    ['PHP version', $facts['php_version']],
    ['Uptime',      $facts['uptime']],
    ['Server time', fmt_datetime(time()) . ' ' . date('T')],
];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">System Info</h1>
    <p class="page-subtitle">Hardware, operating system and network details</p>
  </div>
</div>

<div class="grid grid-split">
  <div class="card">
    <div class="card-header"><h3>Server</h3></div>
    <div class="table-wrap">
      <table class="data-table">
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr><th scope="row" class="text-tertiary" style="width:40%;font-weight:400;text-align:left;padding:12px 14px;border-bottom:1px solid var(--border-subtle)"><?= e($r[0]) ?></th><td style="font-weight:500"><?= e($r[1] !== '' ? $r[1] : '—') ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:16px">
    <div class="card">
      <div class="card-header"><h3>Resources</h3></div>
      <div class="card-pad" style="display:flex;flex-direction:column;gap:16px">
        <div>
          <div class="flex items-center" style="justify-content:space-between;font-size:13px;margin-bottom:6px">
            <span class="text-secondary">Memory</span>
            <span class="mono text-tertiary"><?= $mem ? e(human_bytes($mem['used'])) . ' / ' . e(human_bytes($mem['total'])) . ' · ' . (int) round($mem['used'] / max(1, $mem['total']) * 100) . '%' : 'n/a' ?></span>
          </div>
          <?php $memPct = $mem ? round($mem['used'] / max(1, $mem['total']) * 100) : null; ?>
          <div class="progress" role="progressbar" aria-label="Memory usage" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $memPct ?>"><div style="width:<?= (int) $memPct ?>%;background:<?= e(meter_color($memPct)) ?>"></div></div>
        </div>
        <div>
          <div class="flex items-center" style="justify-content:space-between;font-size:13px;margin-bottom:6px">
            <span class="text-secondary">Disk /</span>
            <span class="mono text-tertiary"><?= $disk ? e(human_bytes($disk['used'])) . ' / ' . e(human_bytes($disk['total'])) . ' · ' . (int) round($disk['used'] / max(1, $disk['total']) * 100) . '%' : 'n/a' ?></span>
          </div>
          <?php $diskPct = $disk ? round($disk['used'] / max(1, $disk['total']) * 100) : null; ?>
          <div class="progress" role="progressbar" aria-label="Disk usage" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $diskPct ?>"><div style="width:<?= (int) $diskPct ?>%;background:<?= e(meter_color($diskPct)) ?>"></div></div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>Network interfaces</h3></div>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Interface</th><th>IPv4</th></tr></thead>
          <tbody>
            <?php if (!$net): ?>
              <tr class="empty-row"><td colspan="2">Interface details are unavailable (the <span class="mono">ip</span> command returned nothing).</td></tr>
            <?php endif; ?>
            <?php foreach ($net as $n): ?>
              <tr><td style="font-weight:600"><?= e($n['name']) ?></td><td class="mono text-tertiary"><?= e($n['addr']) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>window.NEBULA_PAGE = 'sysinfo';</script>
