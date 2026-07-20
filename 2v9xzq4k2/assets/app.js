// Nebula Panel — live control panel client
(function () {
  const META = (n) => document.querySelector(`meta[name="${n}"]`)?.content || '';
  const BASE = META('base-url');
  const CSRF = META('csrf-token');
  const api = (endpoint) => `${BASE}/?r=api/${endpoint}`;

  async function apiGet(endpoint) {
    const r = await fetch(api(endpoint), { headers: { Accept: 'application/json' } });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
  }
  async function apiPost(endpoint, body) {
    const r = await fetch(api(endpoint), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, Accept: 'application/json' },
      body: JSON.stringify(body || {}),
    });
    return r.json();
  }

  function fmtBytes(b) {
    if (!b) return '0 B';
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(b) / Math.log(1024));
    return (b / Math.pow(1024, i)).toFixed(1) + ' ' + u[i];
  }

  // Public API for per-module view scripts (available by DOMContentLoaded).
  window.Nebula = { api, apiGet, apiPost, toast, fmtBytes };

  // ---- Toasts -------------------------------------------------------------
  function toast(msg, type = 'success') {
    const stack = document.getElementById('toastStack');
    if (!stack) return;
    const icons = { success: 'check-circle-2', error: 'x-circle', warning: 'alert-triangle', info: 'info' };
    const colors = { success: 'var(--emerald-400)', error: 'var(--red-400)', warning: 'var(--orange-400)', info: 'var(--blue-400)' };
    const el = document.createElement('div');
    el.className = 'toast';
    el.innerHTML = `<i data-lucide="${icons[type] || 'info'}" style="color:${colors[type] || colors.info}"></i><div style="font-size:13px">${msg}</div>`;
    stack.appendChild(el);
    if (window.lucide) lucide.createIcons();
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = '.3s'; setTimeout(() => el.remove(), 300); }, 3500);
  }
  window.nebulaToast = toast;

  // ---- Chart defaults -----------------------------------------------------
  if (window.Chart) {
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = '#8296ab';
    Chart.defaults.borderColor = 'rgba(255,255,255,.06)';
  }

  // ---- Metrics rendering --------------------------------------------------
  function setBar(sel, pct, color) {
    document.querySelectorAll(sel).forEach((el) => {
      el.style.width = (pct == null ? 0 : Math.min(100, pct)) + '%';
      if (color) el.style.background = color;
    });
  }
  function colorFor(pct) {
    if (pct == null) return 'var(--slate-500)';
    if (pct >= 85) return 'var(--red-500)';
    if (pct >= 60) return 'var(--orange-500)';
    return 'var(--emerald-500)';
  }

  let liveChart = null;
  const series = { cpu: [], mem: [], disk: [], labels: [] };

  function initLiveChart() {
    const ctx = document.getElementById('liveChart');
    if (!ctx || !window.Chart) return;
    liveChart = new Chart(ctx, {
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

  function applyMetrics(m) {
    // Topbar mini-health
    const cpuPct = m.cpu, memPct = m.mem?.pct, diskPct = m.disk?.pct;
    const put = (attr, val) => document.querySelectorAll(`[data-mh="${attr}"]`).forEach((e) => (e.textContent = val));
    put('cpu', cpuPct == null ? 'n/a' : cpuPct + '%');
    put('mem', memPct == null ? 'n/a' : memPct + '%');
    put('disk', diskPct == null ? 'n/a' : diskPct + '%');
    setBar('[data-mh-bar="cpu"]', cpuPct, colorFor(cpuPct));
    setBar('[data-mh-bar="mem"]', memPct, colorFor(memPct));
    setBar('[data-mh-bar="disk"]', diskPct, colorFor(diskPct));

    // Dashboard stat cards
    const stat = (attr, html) => document.querySelectorAll(`[data-stat="${attr}"]`).forEach((e) => (e.innerHTML = html));
    if (document.querySelector('[data-stat="cpu"]')) {
      stat('cpu', (cpuPct == null ? 'n/a' : cpuPct) + '<span style="font-size:14px;color:var(--text-tertiary)">%</span>');
      if (m.load) stat('load', `Load ${m.load.map((n) => (+n).toFixed(2)).join(', ')}`);
      stat('mem', (memPct == null ? 'n/a' : memPct) + '<span style="font-size:14px;color:var(--text-tertiary)">%</span>');
      if (m.mem) stat('mem-detail', `${fmtBytes(m.mem.used)} / ${fmtBytes(m.mem.total)}`);
      stat('disk', (diskPct == null ? 'n/a' : diskPct) + '<span style="font-size:14px;color:var(--text-tertiary)">%</span>');
      if (m.disk) stat('disk-detail', `${fmtBytes(m.disk.used)} / ${fmtBytes(m.disk.total)}`);
      setBar('[data-stat-bar="cpu"]', cpuPct, 'var(--blue-500)');
      setBar('[data-stat-bar="mem"]', memPct, 'var(--orange-500)');
      setBar('[data-stat-bar="disk"]', diskPct, 'var(--purple-500)');
    }

    // Live chart
    if (liveChart) {
      series.labels.push('');
      series.cpu.push(cpuPct ?? 0);
      series.mem.push(memPct ?? 0);
      if (series.labels.length > 40) { series.labels.shift(); series.cpu.shift(); series.mem.shift(); }
      liveChart.update();
    }
  }

  async function pollMetrics() {
    try { applyMetrics(await apiGet('metrics')); } catch (e) { /* silent */ }
  }

  // ---- Services -----------------------------------------------------------
  const SVC_BADGE = {
    active: ['badge-emerald', 'Running'], inactive: ['badge-slate', 'Stopped'],
    failed: ['badge-red', 'Failed'], 'not-installed': ['badge-slate', 'Not installed'], unknown: ['badge-slate', 'Unknown'],
  };
  function updateSvcBadge(tr, status) {
    const [cls, label] = SVC_BADGE[status] || ['badge-slate', status];
    const badge = tr.querySelector('[data-svc-badge]');
    if (badge) { badge.className = 'badge ' + cls; badge.innerHTML = '<span class="bdot"></span>' + label; }
  }

  function wireServices() {
    document.querySelectorAll('#svcBody [data-action]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const tr = btn.closest('[data-svc]');
        const name = tr.dataset.svc;
        const action = btn.dataset.action;
        btn.disabled = true;
        const res = await apiPost('services', { name, action });
        btn.disabled = false;
        if (res.ok) {
          toast(`${name}: ${action} ok`, 'success');
          updateSvcBadge(tr, res.status);
        } else {
          toast(res.error || 'Action failed', 'error');
        }
      });
    });
    document.getElementById('svcRefresh')?.addEventListener('click', async () => {
      const res = await apiGet('services');
      if (res.ok) res.services.forEach((s) => {
        const tr = document.querySelector(`#svcBody [data-svc="${s.name}"]`);
        if (tr) updateSvcBadge(tr, s.status);
      });
      toast('Services refreshed', 'info');
    });
  }

  async function loadSvcSummary() {
    const box = document.getElementById('svcSummary');
    if (!box) return;
    try {
      const res = await apiGet('services');
      if (!res.ok) return;
      box.innerHTML = '';
      res.services.filter((s) => s.status !== 'not-installed').forEach((s) => {
        const [cls, label] = SVC_BADGE[s.status] || ['badge-slate', s.status];
        const row = document.createElement('div');
        row.className = 'service-row';
        row.innerHTML = `<div class="svc-icon"><i data-lucide="server" style="color:var(--text-secondary)"></i></div>
          <div style="flex:1"><div style="font-weight:600;font-size:13px">${s.name}</div></div>
          <span class="badge ${cls}"><span class="bdot"></span>${label}</span>`;
        box.appendChild(row);
      });
      if (!box.children.length) box.innerHTML = '<div class="text-tertiary" style="font-size:13px">No known services installed.</div>';
      if (window.lucide) lucide.createIcons();
    } catch (e) { box.innerHTML = '<div class="text-tertiary" style="font-size:13px">Could not load services.</div>'; }
  }

  // ---- File manager -------------------------------------------------------
  function wireFiles() {
    document.querySelectorAll('[data-fm-delete]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const path = btn.dataset.fmDelete;
        if (!confirm(`Delete "${path}"? This cannot be undone.`)) return;
        const res = await apiPost('file-delete', { path });
        if (res.ok) { toast('Deleted', 'success'); btn.closest('tr')?.remove(); }
        else toast(res.error || 'Delete failed', 'error');
      });
    });
  }

  // ---- Chrome (sidebar, theme) -------------------------------------------
  function wireChrome() {
    const sidebar = document.getElementById('sidebar');
    document.getElementById('collapseBtn')?.addEventListener('click', () => sidebar.classList.toggle('collapsed'));
    const themeBtn = document.getElementById('themeToggle');
    if (localStorage.getItem('nebula-theme') === 'light') document.documentElement.classList.add('light');
    themeBtn?.addEventListener('click', () => {
      document.documentElement.classList.toggle('light');
      localStorage.setItem('nebula-theme', document.documentElement.classList.contains('light') ? 'light' : 'dark');
      if (window.lucide) lucide.createIcons();
    });
    document.getElementById('refreshBtn')?.addEventListener('click', pollMetrics);
  }

  // ---- Command palette (⌘K / Ctrl+K) -------------------------------------
  function wireCmdk() {
    const overlay = document.getElementById('cmdk');
    if (!overlay) return;
    const input = document.getElementById('cmdkInput');
    const list = document.getElementById('cmdkList');
    const items = Array.from(list.querySelectorAll('.cmdk-item'));
    let sel = 0;
    const visible = () => items.filter((it) => !it.classList.contains('hidden'));
    function setSel(i) {
      const vis = visible();
      if (!vis.length) return;
      sel = (i + vis.length) % vis.length;
      items.forEach((it) => it.classList.remove('sel'));
      vis[sel].classList.add('sel');
      vis[sel].scrollIntoView({ block: 'nearest' });
    }
    function filter(q) {
      q = q.toLowerCase().trim();
      items.forEach((it) => it.classList.toggle('hidden', !!q && !it.dataset.label.includes(q)));
      setSel(0);
    }
    function open() { overlay.classList.remove('hidden'); input.value = ''; filter(''); input.focus(); }
    function close() { overlay.classList.add('hidden'); }
    function go() { const vis = visible(); if (vis[sel]) window.location.href = vis[sel].dataset.href; }

    document.getElementById('searchTrigger')?.addEventListener('click', open);
    document.addEventListener('keydown', (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); open(); }
      else if (e.key === 'Escape' && !overlay.classList.contains('hidden')) { close(); }
    });
    input.addEventListener('input', () => filter(input.value));
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); setSel(sel + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setSel(sel - 1); }
      else if (e.key === 'Enter') { e.preventDefault(); go(); }
    });
    items.forEach((it) => it.addEventListener('click', () => (window.location.href = it.dataset.href)));
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
  }

  // ---- Boot ---------------------------------------------------------------
  document.addEventListener('DOMContentLoaded', () => {
    if (window.lucide) lucide.createIcons();
    wireChrome();
    wireCmdk();

    const page = window.NEBULA_PAGE;
    // Live metrics run on every authenticated page (topbar), chart on dashboard.
    if (document.getElementById('miniHealth')) {
      if (page === 'dashboard') initLiveChart();
      pollMetrics();
      setInterval(pollMetrics, 3000);
    }
    if (page === 'dashboard') loadSvcSummary();
    if (page === 'services') wireServices();
    if (page === 'files') wireFiles();
  });
})();
