<?php /** @var array $config */ $facts = system_facts(); ?>
<?php if (!empty($permissionError)): ?><div class="notice notice-warning" style="margin-bottom:16px"><i data-lucide="shield-alert"></i><div><strong>Access restricted</strong><div>Your role does not have permission to open that area.</div></div></div><?php endif; ?>
<div class="page-header">
  <div>
    <h1 class="page-title">Dashboard</h1>
    <p class="page-subtitle"><?= e($facts['hostname']) ?> · <?= e($facts['os']) ?> · Uptime <?= e($facts['uptime']) ?></p>
  </div>
  <div class="page-actions">
    <button class="btn btn-secondary" id="refreshBtn"><i data-lucide="refresh-cw"></i>Refresh</button>
  </div>
</div>

<div class="grid grid-4" style="margin-bottom:16px">
  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:rgba(59,130,246,.12)"><i data-lucide="cpu" style="color:var(--blue-400)"></i></div>
    </div>
    <div class="stat-val" data-stat="cpu">–<span style="font-size:14px;color:var(--text-tertiary)">%</span></div>
    <div class="stat-label" data-stat="load">CPU · Load –, –, –</div>
    <div class="progress" style="margin-top:10px"><div data-stat-bar="cpu" style="width:0;background:var(--blue-500)"></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:rgba(245,158,11,.12)"><i data-lucide="memory-stick" style="color:var(--orange-400)"></i></div>
    </div>
    <div class="stat-val" data-stat="mem">–<span style="font-size:14px;color:var(--text-tertiary)">%</span></div>
    <div class="stat-label" data-stat="mem-detail">Memory</div>
    <div class="progress" style="margin-top:10px"><div data-stat-bar="mem" style="width:0;background:var(--orange-500)"></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:rgba(168,85,247,.12)"><i data-lucide="hard-drive" style="color:var(--purple-400)"></i></div>
    </div>
    <div class="stat-val" data-stat="disk">–<span style="font-size:14px;color:var(--text-tertiary)">%</span></div>
    <div class="stat-label" data-stat="disk-detail">Disk /</div>
    <div class="progress" style="margin-top:10px"><div data-stat-bar="disk" style="width:0;background:var(--purple-500)"></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:rgba(16,185,129,.12)"><i data-lucide="server" style="color:var(--emerald-400)"></i></div>
      <span class="badge badge-emerald"><span class="bdot"></span>Online</span>
    </div>
    <div class="stat-val" style="font-size:20px"><?= e($facts['cpu_cores'] ?: '?') ?><span style="font-size:14px;color:var(--text-tertiary)"> vCPU</span></div>
    <div class="stat-label"><?= e($facts['kernel']) ?> · <?= e($facts['arch']) ?></div>
  </div>
</div>

<div class="grid" style="grid-template-columns:2fr 1fr;margin-bottom:16px">
  <div class="card">
    <div class="card-header"><h3>Live resources</h3><span class="muted">Sampled every 3s</span></div>
    <div class="card-pad"><canvas id="liveChart" height="200"></canvas></div>
  </div>
  <div class="card">
    <div class="card-header"><h3>Services</h3><a href="<?= e(url('services')) ?>" class="btn btn-ghost btn-sm">Manage</a></div>
    <div class="card-pad dashboard-services" id="svcSummary">
      <div class="text-tertiary" style="font-size:13px">Loading…</div>
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><div><h3>Top processes</h3><span class="muted"><span id="procCount">–</span> processes running · sorted by CPU</span></div><button class="btn btn-secondary btn-sm" id="procRefresh"><i data-lucide="refresh-cw"></i>Refresh</button></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Process</th><th>User</th><th style="text-align:right">CPU %</th><th style="text-align:right">Memory %</th><th style="text-align:right">RSS</th><th style="text-align:right">PID</th></tr></thead><tbody id="procBody"><tr><td colspan="6" class="text-tertiary" style="text-align:center;padding:24px">Loading…</td></tr></tbody></table></div>
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
  apiGet('health').then((res) => {
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
        row.style.textDecoration = 'none'; row.style.marginBottom = '8px';
        const iconWrap = document.createElement('div'); iconWrap.className = 'svc-icon';
        const icon = document.createElement('i'); icon.dataset.lucide = item.icon; icon.style.color = (levelMap[item.level] || [null, null, 'var(--blue-400)'])[2];
        iconWrap.appendChild(icon);
        const copy = document.createElement('div'); copy.style.flex = '1';
        const title = document.createElement('div'); title.style.fontWeight = '600'; title.style.fontSize = '13px'; title.textContent = item.title;
        const detail = document.createElement('div'); detail.className = 'text-tertiary'; detail.style.fontSize = '12px'; detail.style.marginTop = '2px'; detail.textContent = item.detail;
        copy.append(title, detail);
        const arrow = document.createElement('i'); arrow.dataset.lucide = 'chevron-right'; arrow.style.color = 'var(--text-tertiary)';
        row.append(iconWrap, copy, arrow); box.appendChild(row);
      });
    }
    if (window.lucide) lucide.createIcons();
  }).catch((error) => {
    status.className = 'badge badge-red'; status.textContent = 'Check failed';
    box.textContent = error.message || 'Could not load system health.';
  });

  const procBody = document.getElementById('procBody');
  async function loadProcesses() {
    try {
      const res = await apiGet('processes');
      document.getElementById('procCount').textContent = res.count ?? 0;
      procBody.replaceChildren();
      (res.processes || []).forEach((row) => {
        const tr = document.createElement('tr');
        [row.command, row.user, (+row.cpu).toFixed(1), (+row.mem).toFixed(1), fmtBytes(row.rss), row.pid].forEach((value, index) => {
          const td = document.createElement('td'); td.textContent = value;
          if (index > 1) { td.className = 'mono'; td.style.textAlign = 'right'; }
          if (index === 0) td.style.fontWeight = '600';
          tr.appendChild(td);
        });
        procBody.appendChild(tr);
      });
      if (!procBody.children.length) { const tr=document.createElement('tr');const td=document.createElement('td');td.colSpan=6;td.className='text-tertiary';td.style.cssText='text-align:center;padding:24px';td.textContent='No process data available.';tr.appendChild(td);procBody.appendChild(tr); }
    } catch (error) { /* Keep the dashboard usable if process inspection is unavailable. */ }
  }
  document.getElementById('procRefresh')?.addEventListener('click', loadProcesses);
  loadProcesses(); setInterval(loadProcesses, 5000);
});
</script>
<script>window.NEBULA_PAGE = 'dashboard';</script>
