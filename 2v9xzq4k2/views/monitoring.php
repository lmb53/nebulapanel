<?php
require_once APP_ROOT . '/lib/mod_monitor.php';
/** @var array $config */
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Monitoring</h1>
    <p class="page-subtitle"><span id="procCount">–</span> processes running</p>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><h3>CPU &amp; Memory over time</h3><span class="muted">Sampled every 3s</span></div>
  <div class="card-pad"><canvas id="monChart" height="200"></canvas></div>
</div>

<div class="card">
  <div class="card-header"><h3>Top processes</h3><span class="muted">Sorted by CPU</span></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Process</th>
          <th>User</th>
          <th style="text-align:right">CPU %</th>
          <th style="text-align:right">Mem %</th>
          <th style="text-align:right">RSS</th>
          <th style="text-align:right">PID</th>
        </tr>
      </thead>
      <tbody id="procBody">
        <tr><td colspan="6" class="text-tertiary" style="text-align:center;padding:24px">Loading…</td></tr>
      </tbody>
    </table>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiGet, fmtBytes } = window.Nebula;

  // ---- Chart -------------------------------------------------------------
  let monChart = null;
  const series = { cpu: [], mem: [], labels: [] };
  const ctx = document.getElementById('monChart');
  if (ctx && window.Chart) {
    monChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: series.labels,
        datasets: [
          { label: 'CPU %', data: series.cpu, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,.08)', fill: true, tension: .4, pointRadius: 0, borderWidth: 2 },
          { label: 'Mem %', data: series.mem, borderColor: '#f59e0b', pointRadius: 0, borderWidth: 2, tension: .4 },
        ],
      },
      options: {
        animation: false,
        plugins: { legend: { position: 'top', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true } } },
        scales: { x: { display: false }, y: { min: 0, max: 100, grid: { color: 'rgba(255,255,255,.05)' } } },
      },
    });
  }

  async function pollMetrics() {
    try {
      const m = await apiGet('metrics');
      if (!monChart) return;
      series.labels.push('');
      series.cpu.push(m.cpu ?? 0);
      series.mem.push(m.mem?.pct ?? 0);
      if (series.labels.length > 40) { series.labels.shift(); series.cpu.shift(); series.mem.shift(); }
      monChart.update();
    } catch (e) { /* silent */ }
  }

  // ---- Process table -----------------------------------------------------
  const body = document.getElementById('procBody');
  const countEl = document.getElementById('procCount');

  async function pollProcesses() {
    try {
      const res = await apiGet('processes');
      if (!res.ok) return;
      countEl.textContent = res.count;
      body.innerHTML = '';
      if (!res.processes.length) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 6;
        td.className = 'text-tertiary';
        td.style.textAlign = 'center';
        td.style.padding = '24px';
        td.textContent = 'No process data available.';
        tr.appendChild(td);
        body.appendChild(tr);
        return;
      }
      res.processes.forEach((row) => {
        const tr = document.createElement('tr');

        const cmd = document.createElement('td');
        cmd.style.fontWeight = '600';
        cmd.textContent = row.command;
        tr.appendChild(cmd);

        const user = document.createElement('td');
        user.className = 'mono';
        user.style.fontSize = '12.5px';
        user.textContent = row.user;
        tr.appendChild(user);

        const cpu = document.createElement('td');
        cpu.className = 'mono';
        cpu.style.textAlign = 'right';
        cpu.textContent = (+row.cpu).toFixed(1);
        tr.appendChild(cpu);

        const mem = document.createElement('td');
        mem.className = 'mono';
        mem.style.textAlign = 'right';
        mem.textContent = (+row.mem).toFixed(1);
        tr.appendChild(mem);

        const rss = document.createElement('td');
        rss.className = 'mono';
        rss.style.textAlign = 'right';
        rss.textContent = fmtBytes(row.rss);
        tr.appendChild(rss);

        const pid = document.createElement('td');
        pid.className = 'mono text-tertiary';
        pid.style.textAlign = 'right';
        pid.textContent = row.pid;
        tr.appendChild(pid);

        body.appendChild(tr);
      });
    } catch (e) { /* silent */ }
  }

  // ---- Boot --------------------------------------------------------------
  pollMetrics();
  setInterval(pollMetrics, 3000);
  pollProcesses();
  setInterval(pollProcesses, 5000);
});
</script>
