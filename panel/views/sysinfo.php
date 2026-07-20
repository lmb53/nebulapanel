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
    ['Server time', $facts['server_time']],
];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">System Info</h1>
    <p class="page-subtitle">Hardware, OS, and network details</p>
  </div>
</div>

<div class="grid" style="grid-template-columns:1.2fr 1fr">
  <div class="card">
    <div class="card-header"><h3>Server</h3></div>
    <div class="table-wrap">
      <table class="data-table">
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr><td class="text-tertiary" style="width:40%"><?= e($r[0]) ?></td><td style="font-weight:500"><?= e($r[1]) ?></td></tr>
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
            <span class="mono text-tertiary"><?= $mem ? e(human_bytes($mem['used'])) . ' / ' . e(human_bytes($mem['total'])) : 'n/a' ?></span>
          </div>
          <div class="progress"><div style="width:<?= $mem ? round($mem['used'] / max(1, $mem['total']) * 100) : 0 ?>%;background:var(--orange-500)"></div></div>
        </div>
        <div>
          <div class="flex items-center" style="justify-content:space-between;font-size:13px;margin-bottom:6px">
            <span class="text-secondary">Disk /</span>
            <span class="mono text-tertiary"><?= $disk ? e(human_bytes($disk['used'])) . ' / ' . e(human_bytes($disk['total'])) : 'n/a' ?></span>
          </div>
          <div class="progress"><div style="width:<?= $disk ? round($disk['used'] / max(1, $disk['total']) * 100) : 0 ?>%;background:var(--purple-500)"></div></div>
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
              <tr><td colspan="2" class="text-tertiary" style="padding:18px;text-align:center">No interface data (Linux only)</td></tr>
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
