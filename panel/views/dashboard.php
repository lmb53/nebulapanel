<?php
/** @var array $config */
$facts = system_facts();
$initialCpu = cpu_usage();
$initialMem = mem_info();
$initialDisk = disk_info('/');
$initialLoad = load_avg();
$initialMemPct = $initialMem ? round($initialMem['used'] / max(1, $initialMem['total']) * 100, 1) : null;
$initialDiskPct = $initialDisk ? round($initialDisk['used'] / max(1, $initialDisk['total']) * 100, 1) : null;
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Dashboard</h1>
    <p class="page-subtitle"><?= e($facts['hostname']) ?> · <?= e($facts['os']) ?> · Up <?= e($facts['uptime']) ?></p>
  </div>
  <div class="page-actions">
    <button class="btn btn-secondary" type="button" id="refreshBtn"><i data-lucide="refresh-cw"></i>Refresh all</button>
  </div>
</div>

<div class="grid grid-4" style="margin-bottom:16px">
  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:rgba(59,130,246,.12)"><i data-lucide="cpu" style="color:var(--blue-400)"></i></div>
    </div>
    <div class="stat-val" data-stat="cpu"><?= e($initialCpu ?? 'n/a') ?><span class="unit">%</span></div>
    <div class="stat-label" data-stat="load">CPU · Load <?= e(implode(', ', array_map(fn($n) => number_format((float) $n, 2), $initialLoad))) ?></div>
    <div class="progress" role="progressbar" aria-label="CPU usage" aria-valuemin="0" aria-valuemax="100"><div data-stat-bar="cpu" style="width:<?= e($initialCpu ?? 0) ?>%;background:<?= e(meter_color($initialCpu)) ?>"></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:rgba(245,158,11,.12)"><i data-lucide="memory-stick" style="color:var(--orange-400)"></i></div>
    </div>
    <div class="stat-val" data-stat="mem"><?= e($initialMemPct ?? 'n/a') ?><span class="unit">%</span></div>
    <div class="stat-label" data-stat="mem-detail"><?= $initialMem ? e(human_bytes($initialMem['used']) . ' / ' . human_bytes($initialMem['total'])) : 'Memory unavailable' ?></div>
    <div class="progress" role="progressbar" aria-label="Memory usage" aria-valuemin="0" aria-valuemax="100"><div data-stat-bar="mem" style="width:<?= e($initialMemPct ?? 0) ?>%;background:<?= e(meter_color($initialMemPct)) ?>"></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:rgba(168,85,247,.12)"><i data-lucide="hard-drive" style="color:var(--purple-400)"></i></div>
    </div>
    <div class="stat-val" data-stat="disk"><?= e($initialDiskPct ?? 'n/a') ?><span class="unit">%</span></div>
    <div class="stat-label" data-stat="disk-detail"><?= $initialDisk ? e(human_bytes($initialDisk['used']) . ' / ' . human_bytes($initialDisk['total'])) : 'Disk unavailable' ?></div>
    <div class="progress" role="progressbar" aria-label="Disk usage" aria-valuemin="0" aria-valuemax="100"><div data-stat-bar="disk" style="width:<?= e($initialDiskPct ?? 0) ?>%;background:<?= e(meter_color($initialDiskPct)) ?>"></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:rgba(16,185,129,.12)"><i data-lucide="server" style="color:var(--emerald-400)"></i></div>
      <span class="badge badge-emerald"><span class="bdot"></span>Online</span>
    </div>
    <div class="stat-val"><?= e($facts['cpu_cores'] ?: '?') ?><span class="unit">vCPU</span></div>
    <div class="stat-label"><?= e($facts['kernel']) ?> · <?= e($facts['arch']) ?></div>
  </div>
</div>

<div class="grid grid-main-side" style="margin-bottom:16px">
  <div class="card">
    <div class="card-header"><h3>Live resources</h3><span class="muted">Sampled every 5 seconds</span></div>
    <div class="card-pad"><div class="chart-box"><canvas id="liveChart" data-cpu="<?= e($initialCpu ?? 0) ?>" data-mem="<?= e($initialMemPct ?? 0) ?>" aria-label="CPU and memory usage over time" role="img"></canvas></div></div>
  </div>
  <div class="card">
    <div class="card-header"><h3>Services</h3><a href="<?= e(url('services')) ?>" class="btn btn-secondary btn-sm">Manage<i data-lucide="arrow-right"></i></a></div>
    <div class="card-pad dashboard-services" id="svcSummary">
      <div class="text-tertiary" style="font-size:13px">Loading…</div>
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><div><h3>Top processes</h3><span class="muted"><span id="procCount">–</span> processes running · top 20 by CPU</span></div><button class="btn btn-secondary btn-sm" type="button" id="procRefresh"><i data-lucide="refresh-cw"></i>Refresh</button></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Process</th><th>User</th><th class="num">CPU %</th><th class="num">Memory %</th><th class="num">RSS</th><th class="num">PID</th></tr></thead><tbody id="procBody"><tr class="empty-row"><td colspan="6">Loading…</td></tr></tbody></table></div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header">
    <h3>System health</h3>
    <span class="badge badge-slate" id="healthStatus"><span class="bdot"></span>Checking</span>
  </div>
  <div class="card-pad" id="healthItems">
    <div class="text-tertiary" style="font-size:13px">Running operational checks…</div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiGet, fmtBytes } = window.Nebula;
  const box = document.getElementById('healthItems');
  const status = document.getElementById('healthStatus');
  const levelMap = {
    critical: ['badge-red', 'Critical', 'var(--red-400)'],
    warning: ['badge-orange', 'Needs attention', 'var(--orange-400)'],
    healthy: ['badge-emerald', 'Healthy', 'var(--emerald-400)'],
  };
  const loadHealth = () => apiGet('health').then((res) => {
    const [cls, label] = levelMap[res.status] || ['badge-slate', 'Unknown'];
    status.className = 'badge ' + cls;
    status.innerHTML = '<span class="bdot"></span>';
    status.append(document.createTextNode(label));
    box.replaceChildren();
    if (!res.items.length) {
      const empty = document.createElement('div');
      empty.className = 'flex items-center gap-3';
      const icon = document.createElement('i'); icon.dataset.lucide = 'circle-check-big'; icon.style.color = 'var(--emerald-400)';
      const copy = document.createElement('div'); copy.textContent = 'No operational issues detected.'; copy.style.fontSize = '13px';
      empty.append(icon, copy); box.appendChild(empty);
    } else {
      res.items.forEach((item) => {
        const row = document.createElement('a');
        row.className = 'service-row'; row.href = <?= json_encode(base_url() . '/?r=') ?> + encodeURIComponent(item.route);
        row.style.marginBottom = '8px';
        const iconWrap = document.createElement('div'); iconWrap.className = 'svc-icon';
        const icon = document.createElement('i'); icon.dataset.lucide = item.icon; icon.style.color = (levelMap[item.level] || [null, null, 'var(--blue-400)'])[2];
        iconWrap.appendChild(icon);
        const copy = document.createElement('div'); copy.style.flex = '1';
        const title = document.createElement('div'); title.style.fontWeight = '600'; title.style.fontSize = '13px'; title.textContent = item.title;
        const detail = document.createElement('div'); detail.className = 'text-tertiary'; detail.style.fontSize = '12px'; detail.style.marginTop = '2px'; detail.textContent = item.detail;
        copy.append(title, detail);
        const arrow = document.createElement('i'); arrow.dataset.lucide = 'chevron-right'; arrow.style.color = 'var(--text-tertiary)'; arrow.setAttribute('aria-hidden', 'true');
        row.append(iconWrap, copy, arrow); box.appendChild(row);
      });
    }
    if (window.lucide) lucide.createIcons();
  }).catch((error) => {
    status.className = 'badge badge-red'; status.textContent = 'Check failed';
    box.textContent = error.message || 'Could not load system health.';
  });
  loadHealth();
  window.Nebula.registerRefresh(loadHealth);

  const procBody = document.getElementById('procBody');
  let processBusy = false, processTimer = null;
  async function loadProcesses() {
    if (processBusy || document.hidden) return;
    processBusy = true;
    try {
      const res = await apiGet('processes');
      document.getElementById('procCount').textContent = res.count ?? 0;
      procBody.replaceChildren();
      (res.processes || []).forEach((row) => {
        const tr = document.createElement('tr');
        [row.command, row.user, (+row.cpu).toFixed(1), (+row.mem).toFixed(1), fmtBytes(row.rss), row.pid].forEach((value, index) => {
          const td = document.createElement('td'); td.textContent = value;
          if (index > 1) td.className = 'mono num';
          if (index === 0) { td.style.fontWeight = '600'; td.title = row.command; }
          tr.appendChild(td);
        });
        procBody.appendChild(tr);
      });
      if (!procBody.children.length) { const tr=document.createElement('tr');const td=document.createElement('td');tr.className='empty-row';td.colSpan=6;td.textContent='No process data available.';tr.appendChild(td);procBody.appendChild(tr); }
    } catch (error) { /* Keep the dashboard usable if process inspection is unavailable. */ }
    finally { processBusy = false; }
  }
  document.getElementById('procRefresh')?.addEventListener('click', loadProcesses);
  window.Nebula.registerRefresh(loadProcesses);
  const scheduleProcesses=async()=>{await loadProcesses();clearTimeout(processTimer);processTimer=setTimeout(scheduleProcesses,8000);};
  scheduleProcesses();document.addEventListener('visibilitychange',()=>{if(document.hidden)clearTimeout(processTimer);else{clearTimeout(processTimer);scheduleProcesses();}});
});
</script>
<script>window.NEBULA_PAGE = 'dashboard';</script>
