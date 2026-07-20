<?php /** @var array $facts */ /** @var array $config */ ?>
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
    <div class="stat-label" data-stat="load">Load –, –, –</div>
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
    <div class="card-pad" id="svcSummary" style="display:flex;flex-direction:column;gap:8px;max-height:280px;overflow-y:auto">
      <div class="text-tertiary" style="font-size:13px">Loading…</div>
    </div>
  </div>
</div>
<script>window.NEBULA_PAGE = 'dashboard';</script>
