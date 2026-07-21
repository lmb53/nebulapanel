// Nebula Panel — live control panel client
(function () {
  const META = (n) => document.querySelector(`meta[name="${n}"]`)?.content || '';
  const BASE = META('base-url');
  const CSRF = META('csrf-token');
  const api = (endpoint) => `${BASE}/?r=api/${endpoint}`;

  async function apiGet(endpoint) {
    const r = await fetch(api(endpoint), { headers: { Accept: 'application/json' } });
    const data = await r.json().catch(() => ({ ok: false, error: `Invalid server response (HTTP ${r.status})` }));
    if (!r.ok) throw new Error(data.error || 'HTTP ' + r.status);
    return data;
  }
  async function apiPost(endpoint, body) {
    try {
      const r = await fetch(api(endpoint), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, Accept: 'application/json' },
        body: JSON.stringify(body || {}),
      });
      const text = await r.text();
      try {
        return JSON.parse(text);
      } catch (e) {
        return {
          ok: false,
          error: text.trim().slice(0, 8000) || `Invalid server response (HTTP ${r.status})`,
        };
      }
    } catch (e) {
      return { ok: false, error: e?.message || 'Network request failed' };
    }
  }

  /** POST JSON and consume newline-delimited progress events as they arrive. */
  async function streamPost(endpoint, body, onEvent) {
    try {
      const r = await fetch(api(endpoint) + '&stream=1', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, Accept: 'application/x-ndjson' },
        body: JSON.stringify(body || {}),
      });
      if (!r.body || !r.body.getReader) return await r.json();
      const reader = r.body.getReader();
      const decoder = new TextDecoder();
      let pending = '';
      let result = null;
      let rawError = '';
      const consumeLine = (line) => {
        if (!line.trim()) return;
        let event;
        try {
          event = JSON.parse(line);
        } catch (e) {
          // Preserve PHP/proxy errors in the visible output without letting one
          // malformed line discard the rest of a long-running operation.
          rawError += (rawError ? '\n' : '') + line;
          if (onEvent) onEvent({ type: 'output', channel: 'stderr', text: line + '\n' });
          return;
        }
        if (event.type === 'result') result = event.result;
        else if (typeof event.ok === 'boolean') result = event;
        if (onEvent) onEvent(event);
      };
      while (true) {
        const { value, done } = await reader.read();
        pending += decoder.decode(value || new Uint8Array(), { stream: !done });
        const lines = pending.split(/\r?\n/);
        pending = lines.pop() || '';
        for (const line of lines) consumeLine(line);
        if (done) break;
      }
      consumeLine(pending);
      return result || {
        ok: false,
        error: rawError.trim().slice(0, 8000) || `Stream ended without a result (HTTP ${r.status})`,
      };
    } catch (e) {
      return { ok: false, error: e?.message || 'Network request failed' };
    }
  }

  function fmtBytes(b) {
    if (!b) return '0 B';
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(b) / Math.log(1024));
    return (b / Math.pow(1024, i)).toFixed(1) + ' ' + u[i];
  }

  // Public API for per-module view scripts (available by DOMContentLoaded).
  window.Nebula = { api, apiGet, apiPost, streamPost, toast, fmtBytes };

  // ---- Toasts -------------------------------------------------------------
  function toast(msg, type = 'success') {
    const stack = document.getElementById('toastStack');
    if (!stack) return;
    const icons = { success: 'check-circle-2', error: 'x-circle', warning: 'alert-triangle', info: 'info' };
    const colors = { success: 'var(--emerald-400)', error: 'var(--red-400)', warning: 'var(--orange-400)', info: 'var(--blue-400)' };
    const el = document.createElement('div');
    el.className = 'toast';
    const icon = document.createElement('i');
    icon.dataset.lucide = icons[type] || 'info';
    icon.style.color = colors[type] || colors.info;
    const copy = document.createElement('div');
    copy.style.fontSize = '13px';
    copy.style.whiteSpace = 'pre-wrap';
    copy.style.wordBreak = 'break-word';
    copy.style.flex = '1';
    copy.textContent = String(msg);
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'toast-close';
    close.setAttribute('aria-label', 'Dismiss message');
    close.textContent = '×';
    close.addEventListener('click', () => el.remove());
    el.append(icon, copy, close);
    stack.appendChild(el);
    if (window.lucide) lucide.createIcons();
    // Errors remain until explicitly dismissed, so command output and
    // certificate failures cannot disappear before the user has read them.
    if (type !== 'error') {
      setTimeout(() => { el.style.opacity = '0'; el.style.transition = '.3s'; setTimeout(() => el.remove(), 300); }, 3500);
    }
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
      if (m.load) stat('load', `CPU · Load ${m.load.map((n) => (+n).toFixed(2)).join(', ')}`);
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
  function updateSvcEnabled(tr, enabled) {
    const badge = tr.querySelector('[data-svc-enabled]');
    if (badge) {
      badge.className = 'badge ' + (enabled === true ? 'badge-blue' : 'badge-slate');
      badge.textContent = enabled === true ? 'Enabled' : (enabled === false ? 'Disabled' : 'N/A');
    }
    const toggle = tr.querySelector('[data-enable-toggle]');
    if (toggle && enabled !== null) toggle.dataset.action = enabled ? 'disable' : 'enable';
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
          updateSvcEnabled(tr, res.enabled);
        } else {
          toast(res.error || 'Action failed', 'error');
        }
      });
    });
    document.getElementById('svcRefresh')?.addEventListener('click', async () => {
      const res = await apiGet('services');
      if (res.ok) res.services.forEach((s) => {
        const tr = Array.from(document.querySelectorAll('#svcBody [data-svc]'))
          .find((row) => row.dataset.svc === s.name);
        if (tr) updateSvcBadge(tr, s.status);
        if (tr) updateSvcEnabled(tr, s.enabled);
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
        const iconWrap = document.createElement('div');
        iconWrap.className = 'svc-icon';
        iconWrap.innerHTML = '<i data-lucide="server" style="color:var(--text-secondary)"></i>';
        const nameWrap = document.createElement('div');
        nameWrap.style.flex = '1';
        const name = document.createElement('div');
        name.style.fontWeight = '600'; name.style.fontSize = '13px'; name.textContent = s.name;
        nameWrap.appendChild(name);
        const badge = document.createElement('span');
        badge.className = `badge ${cls}`;
        badge.innerHTML = '<span class="bdot"></span>';
        badge.append(document.createTextNode(label));
        row.append(iconWrap, nameWrap, badge);
        box.appendChild(row);
      });
      if (!box.children.length) box.innerHTML = '<div class="text-tertiary" style="font-size:13px">No known services installed.</div>';
      if (window.lucide) lucide.createIcons();
    } catch (e) { box.innerHTML = '<div class="text-tertiary" style="font-size:13px">Could not load services.</div>'; }
  }

  // ---- Top-bar notifications ---------------------------------------------
  function wireNotifications() {
    const trigger = document.getElementById('notificationTrigger');
    const menu = document.getElementById('notificationMenu');
    const list = document.getElementById('notificationMenuList');
    const dot = document.getElementById('notificationDot');
    const count = document.getElementById('notificationCount');
    if (!trigger || !menu || !list) return;

    const color = (level) => level === 'critical' ? 'var(--red-400)' : (level === 'warning' ? 'var(--orange-400)' : 'var(--blue-400)');
    const render = async () => {
      try {
        const res = await apiGet('notifications');
        dot?.classList.toggle('hidden', !res.unread);
        if (count) count.textContent = `${res.unread} unread`;
        list.innerHTML = '';
        const items = (res.items || []).slice(0, 6);
        if (!items.length) {
          const empty = document.createElement('div'); empty.className = 'fm-empty-hint'; empty.textContent = 'No current notifications.'; list.appendChild(empty); return;
        }
        items.forEach((item) => {
          const row = document.createElement('div'); row.className = 'notification-menu-item' + (item.read ? '' : ' unread');
          const icon = document.createElement('a'); icon.className = 'notif-icon'; icon.href = `${BASE}/?r=${encodeURIComponent(item.route || 'dashboard')}`; icon.innerHTML = `<i data-lucide="${item.icon || 'bell'}"></i>`; icon.style.color = color(item.level);
          const copy = document.createElement('a'); copy.className = 'notification-menu-copy'; copy.href = icon.href;
          const title = document.createElement('strong'); title.textContent = item.title || '';
          const detail = document.createElement('span'); detail.textContent = item.detail || '';
          copy.append(title, detail);
          const actions = document.createElement('div'); actions.className = 'notification-menu-actions';
          if (!item.read) {
            const read = document.createElement('button'); read.className = 'icon-btn'; read.title = 'Mark as read'; read.innerHTML = '<i data-lucide="check"></i>';
            read.addEventListener('click', async () => { await apiPost('notifications', { action: 'mark-read', id: item.id }); await render(); }); actions.appendChild(read);
          }
          const del = document.createElement('button'); del.className = 'icon-btn'; del.title = 'Delete'; del.innerHTML = '<i data-lucide="trash-2"></i>';
          del.addEventListener('click', async () => { await apiPost('notifications', { action: 'delete', id: item.id }); await render(); }); actions.appendChild(del);
          row.append(icon, copy, actions); list.appendChild(row);
        });
        if (window.lucide) lucide.createIcons();
      } catch (e) {
        list.innerHTML = '<div class="fm-empty-hint">Could not load notifications.</div>';
      }
    };
    trigger.addEventListener('click', async (e) => {
      e.stopPropagation();
      const opening = menu.classList.contains('hidden');
      menu.classList.toggle('hidden', !opening); trigger.setAttribute('aria-expanded', opening ? 'true' : 'false');
      if (opening) await render();
    });
    menu.addEventListener('click', (e) => e.stopPropagation());
    document.addEventListener('click', () => { menu.classList.add('hidden'); trigger.setAttribute('aria-expanded', 'false'); });
    document.getElementById('notificationReadAll')?.addEventListener('click', async () => { await apiPost('notifications', { action: 'mark-all-read' }); await render(); });
    render();
  }

  // ---- File manager -------------------------------------------------------
  function wireFiles() {
    document.querySelectorAll('a[href*="r=file-edit"]').forEach((link) => {
      link.addEventListener('click', (event) => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        const popup = window.open(link.href, 'nebulaFileEditor', 'popup,width=1280,height=840,resizable=yes,scrollbars=yes');
        if (popup) popup.focus(); else toast('Allow pop-ups to use the multi-tab file editor', 'warning');
      });
    });
    const wireTreeToggle = (toggle) => {
      if (toggle.dataset.treeWired) return;
      toggle.dataset.treeWired = '1';
      toggle.addEventListener('click', async (event) => {
        event.preventDefault(); event.stopPropagation();
        const row = toggle.closest('[data-tree-row]');
        let children = row?.nextElementSibling;
        const matching = children?.matches?.(`[data-tree-children][data-tree-parent="${CSS.escape(toggle.dataset.treePath)}"]`);
        if (!matching) {
          children = document.createElement('div'); children.className = 'tree-children'; children.dataset.treeChildren = ''; children.dataset.treeParent = toggle.dataset.treePath;
          row?.after(children);
        }
        const opening = children.classList.contains('hidden') || !toggle.classList.contains('open');
        children.classList.toggle('hidden', !opening); toggle.classList.toggle('open', opening);
        const icon = toggle.querySelector('svg'); if (icon) icon.outerHTML = `<i data-lucide="${opening ? 'chevron-down' : 'chevron-right'}"></i>`;
        if (opening && !children.dataset.loaded && !children.children.length) {
          children.innerHTML = '<div class="tree-loading">Loading…</div>';
          try {
            const res = await apiGet('file-tree&path=' + encodeURIComponent(toggle.dataset.treePath)); children.innerHTML = '';
            (res.entries || []).forEach((entry) => {
              const item = document.createElement(entry.dir ? 'div' : 'a'); item.className = 'tree-node';
              if (entry.dir) {
                item.dataset.treeRow = '';
                item.innerHTML = `<button class="tree-toggle" type="button" data-tree-path=""><i data-lucide="chevron-right"></i></button><i data-lucide="folder" class="folder-ic" style="color:var(--purple-400)"></i><a><span></span></a>`;
                const childToggle=item.querySelector('[data-tree-path]');childToggle.dataset.treePath=entry.path;const link=item.querySelector('a');link.href=entry.href;link.querySelector('span').textContent=entry.name;wireTreeToggle(childToggle);
              } else {
                item.href = entry.href; item.innerHTML = '<i data-lucide="file" class="folder-ic" style="color:var(--text-tertiary)"></i><span></span>'; item.querySelector('span').textContent = entry.name;
              }
              children.appendChild(item);
            });
            if (!children.children.length) children.innerHTML = '<div class="tree-loading">Empty folder</div>';
            children.dataset.loaded = '1';
            wireEditorLinks(children);
          } catch (error) { children.innerHTML = '<div class="tree-loading">Preview unavailable</div>'; }
        }
        if (window.lucide) lucide.createIcons();
      });
    };
    document.querySelectorAll('[data-tree-path]').forEach(wireTreeToggle);
    function wireEditorLinks(root) {
      root.querySelectorAll('a[href*="r=file-edit"]').forEach((link) => link.addEventListener('click', (event) => {
        event.preventDefault(); const popup=window.open(link.href,'nebulaFileEditor','popup,width=1280,height=840,resizable=yes,scrollbars=yes'); if(popup)popup.focus();
      }));
    }
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

  // Shared tab behaviour used by service instances and mockup-parity pages.
  function wireTabs() {
    document.querySelectorAll('.tabs').forEach((group) => {
      group.querySelectorAll(':scope > .tab[data-tab-target]').forEach((tab) => {
        tab.addEventListener('click', () => {
          group.querySelectorAll(':scope > .tab').forEach((item) => item.classList.toggle('active', item === tab));
          const panels = document.querySelector(tab.dataset.tabPanelGroup || '[data-tab-panels]');
          panels?.querySelectorAll(':scope > [data-tab-panel]').forEach((panel) => panel.classList.add('hidden'));
          document.getElementById(tab.dataset.tabTarget)?.classList.remove('hidden');
        });
      });
    });
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
    wireTabs();
    wireNotifications();

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
