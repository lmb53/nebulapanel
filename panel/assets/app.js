// Nebula Panel — live control panel client
(function () {
  const META = (n) => document.querySelector(`meta[name="${n}"]`)?.content || '';
  const BASE = META('base-url');
  const CSRF = META('csrf-token');
  const api = (endpoint) => {
    endpoint = String(endpoint);
    const separator = endpoint.indexOf('&');
    const name = separator === -1 ? endpoint : endpoint.slice(0, separator);
    const query = separator === -1 ? '' : endpoint.slice(separator + 1);
    const target = new URL(`${BASE || ''}/`, window.location.origin);
    target.searchParams.set('r', `api/${name}`);
    new URLSearchParams(query).forEach((value, key) => target.searchParams.append(key, value));
    return target.pathname + target.search;
  };

  // Apply the persisted theme immediately (before any DOMContentLoaded handler,
  // e.g. code editors) so light/dark is settled when views initialise.
  if (localStorage.getItem('nebula-theme') === 'light') {
    document.documentElement.classList.add('light');
  }

  async function apiGet(endpoint) {
    const r = await fetch(api(endpoint), { headers: { Accept: 'application/json' } });
    const type = (r.headers.get('content-type') || '').toLowerCase();
    const text = await r.text();
    let data;
    try { data = JSON.parse(text); }
    catch (e) {
      const detail = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 2000);
      data = { ok: false, error: detail || `Invalid server response (HTTP ${r.status})` };
    }
    if (!type.includes('application/json') && r.ok) {
      throw new Error(data.error || `Unexpected response type (HTTP ${r.status})`);
    }
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
      const type = (r.headers.get('content-type') || '').toLowerCase();
      const text = await r.text();
      if (!type.includes('application/json')) {
        const detail = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 2000);
        return { ok: false, error: detail || `Unexpected response type (HTTP ${r.status})` };
      }
      try {
        const data = JSON.parse(text);
        return r.ok ? data : { ...data, ok: false, error: data.message || data.error || `HTTP ${r.status}` };
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
    const i = Math.min(u.length - 1, Math.floor(Math.log(b) / Math.log(1024)));
    return (i === 0 ? String(b) : (b / Math.pow(1024, i)).toFixed(1)) + ' ' + u[i];
  }

  // ---- CodeMirror theming -------------------------------------------------
  // Editors registered here follow the active light/dark panel theme.
  const codeMirrors = new Set();
  const cmTheme = () => (document.documentElement.classList.contains('light') ? 'default' : 'material-darker');
  function registerCM(cm) {
    if (!cm) return cm;
    codeMirrors.add(cm);
    cm.setOption('theme', cmTheme());
    return cm;
  }
  function syncCmThemes() {
    const theme = cmTheme();
    codeMirrors.forEach((cm) => cm.setOption('theme', theme));
  }

  // Copy text to the clipboard with a fallback for insecure contexts. The
  // async Clipboard API is unavailable over plain HTTP (the panel's default
  // when accessed by IP), where navigator.clipboard is undefined — fall back to
  // a hidden textarea + execCommand so "Copy" works there too. Returns a promise.
  async function copyText(text) {
    text = String(text ?? '');
    if (navigator.clipboard && window.isSecureContext) {
      try { await navigator.clipboard.writeText(text); return true; } catch (e) { /* fall through */ }
    }
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none';
    document.body.appendChild(ta);
    ta.focus(); ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    ta.remove();
    return ok;
  }

  // ---- Cross-widget refresh bus -------------------------------------------
  // A page can render the same server state through several independent view
  // scripts (Docker draws containers in one block and compose stacks in
  // another). Without a shared signal, an action handled by one block leaves
  // every other block showing pre-action state until a full page reload.
  // Each block registers its own loader here; any mutation calls refreshAll().
  const refreshers = new Set();
  function registerRefresh(fn) {
    if (typeof fn === 'function') { refreshers.add(fn); }
    return fn;
  }
  // `delay` re-runs the refresh once more after the given ms. Some backends
  // (docker compose up) return before the daemon has finished registering
  // every container, so an immediate read can still miss it.
  function refreshAll(delay) {
    refreshers.forEach((fn) => { try { fn(); } catch (e) { /* a stale widget must not block the rest */ } });
    if (delay) { setTimeout(() => refreshers.forEach((fn) => { try { fn(); } catch (e) {} }), delay); }
  }

  // Public API for per-module view scripts (available by DOMContentLoaded).
  window.Nebula = { api, apiGet, apiPost, streamPost, toast, copyText, fmtBytes, fmtDate, cmTheme, registerCM, registerRefresh, refreshAll,
    confirm: confirmDialog, prompt: promptDialog, updateSvcBadge, updateSvcEnabled, dialog, validateForm, setFieldError, clearFieldError, setBusy, colorFor, openEditor };

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


  // ---- Shared formatting --------------------------------------------------
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  /** One date format across the panel: "7 Oct 2026, 09:21" (local time). */
  function fmtDate(value, withTime = true) {
    if (value === null || value === undefined || value === '' || value === 0) return '—';
    const d = value instanceof Date ? value : new Date(typeof value === 'number' && value < 1e12 ? value * 1000 : value);
    if (Number.isNaN(d.getTime())) return String(value);
    const date = `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
    if (!withTime) return date;
    return `${date}, ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
  }

  // ---- Theme-aware colours -----------------------------------------------
  const cssVar = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  const thresholds = (() => {
    const [warn, crit] = META('health-thresholds').split(',').map(Number);
    return { warn: Number.isFinite(warn) && warn > 0 ? warn : 80, crit: Number.isFinite(crit) && crit > 0 ? crit : 90 };
  })();
  /** Meter colour from the configured health thresholds (Settings). */
  function colorFor(pct) {
    if (pct == null) return 'var(--slate-500)';
    if (pct >= thresholds.crit) return 'var(--red-500)';
    if (pct >= thresholds.warn) return 'var(--orange-500)';
    return 'var(--emerald-500)';
  }

  // ---- Busy state + inline validation ------------------------------------
  function setBusy(button, busy) {
    if (!button) return;
    button.disabled = !!busy;
    button.classList.toggle('btn-loading', !!busy);
    button.setAttribute('aria-busy', busy ? 'true' : 'false');
  }
  function clearFieldError(input) {
    input.removeAttribute('aria-invalid');
    const next = input.parentElement?.querySelector(`.field-error[data-for="${input.id}"]`);
    next?.remove();
  }
  function setFieldError(input, message) {
    clearFieldError(input);
    input.setAttribute('aria-invalid', 'true');
    const err = document.createElement('div');
    err.className = 'field-error';
    err.dataset.for = input.id || '';
    err.id = (input.id || 'field') + '-error';
    err.textContent = message;
    input.setAttribute('aria-describedby', [input.getAttribute('aria-describedby') || '', err.id].join(' ').trim());
    input.insertAdjacentElement('afterend', err);
    input.addEventListener('input', () => clearFieldError(input), { once: true });
  }
  /** Run native constraint validation plus extra [input, message] pairs; shows messages inline. */
  function validateForm(form, extra = []) {
    let first = null;
    form.querySelectorAll('input, select, textarea').forEach((input) => {
      if (input.type === 'hidden' || input.hidden) return;
      clearFieldError(input);
      if (!input.checkValidity()) {
        setFieldError(input, (input.validity.patternMismatch && input.dataset.patternMessage) || (input.validity.valueMissing && input.dataset.requiredMessage) || input.validationMessage);
        first = first || input;
      }
    });
    extra.forEach(([input, message]) => { if (input && !input.hasAttribute('aria-invalid')) { setFieldError(input, message); first = first || input; } });
    first?.focus();
    return !first;
  }

  // ---- In-app dialogs (replace window.confirm / window.prompt) -----------
  /**
   * Nebula.dialog({title, message, confirmLabel, cancelLabel, danger, icon,
   *   input: {label, value, placeholder, validate}, option: {label, checked, help}})
   * resolves {ok, value, option}.
   */
  function dialog(opts = {}) {
    return new Promise((resolve) => {
      const returnFocus = document.activeElement;
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay';
      const box = document.createElement('div');
      box.className = 'modal';
      box.setAttribute('role', opts.danger ? 'alertdialog' : 'dialog');
      box.setAttribute('aria-modal', 'true');
      const titleId = 'dlg-' + Math.random().toString(36).slice(2);
      box.setAttribute('aria-labelledby', titleId);
      const head = document.createElement('div'); head.className = 'modal-header';
      const icon = document.createElement('div');
      icon.className = 'modal-icon' + (opts.danger ? ' danger' : (opts.warning ? ' warning' : ''));
      icon.innerHTML = `<i data-lucide="${opts.icon || (opts.danger ? 'triangle-alert' : (opts.input ? 'pencil' : 'circle-help'))}"></i>`;
      const title = document.createElement('h2'); title.className = 'modal-title'; title.id = titleId; title.textContent = opts.title || 'Are you sure?';
      head.append(icon, title);
      const body = document.createElement('div'); body.className = 'modal-body';
      if (opts.message) { const p = document.createElement('div'); p.textContent = opts.message; body.appendChild(p); }
      let input = null, option = null;
      const form = document.createElement('form'); form.noValidate = true;
      if (opts.input) {
        const id = titleId + '-input';
        const label = document.createElement('label'); label.className = 'field-label'; label.htmlFor = id; label.textContent = opts.input.label || 'Value';
        input = document.createElement('input'); input.className = 'input'; input.id = id; input.required = opts.input.required !== false;
        input.value = opts.input.value || ''; input.placeholder = opts.input.placeholder || ''; input.autocomplete = 'off';
        if (opts.input.mono) input.classList.add('mono');
        body.append(label, input);
      }
      if (opts.option) {
        const wrap = document.createElement('label'); wrap.className = 'modal-option';
        option = document.createElement('input'); option.type = 'checkbox'; option.checked = !!opts.option.checked;
        const text = document.createElement('span'); text.textContent = opts.option.label;
        if (opts.option.help) { const help = document.createElement('div'); help.className = 'field-help'; help.textContent = opts.option.help; text.appendChild(help); }
        wrap.append(option, text); body.appendChild(wrap);
      }
      const foot = document.createElement('div'); foot.className = 'modal-footer';
      const cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'btn btn-secondary'; cancel.textContent = opts.cancelLabel || 'Cancel';
      const ok = document.createElement('button'); ok.type = 'submit'; ok.className = 'btn ' + (opts.danger ? 'btn-danger-solid' : 'btn-primary'); ok.textContent = opts.confirmLabel || (opts.danger ? 'Delete' : 'OK');
      if (opts.danger && opts.option && opts.optionConfirmLabel) {
        option.addEventListener('change', () => { ok.textContent = option.checked ? opts.optionConfirmLabel : (opts.confirmLabel || 'Delete'); });
      }
      foot.append(cancel, ok);
      form.append(body, foot);
      box.append(head, form);
      overlay.appendChild(box);
      document.body.appendChild(overlay);
      if (window.lucide) lucide.createIcons();
      const finish = (confirmed) => {
        overlay.remove();
        document.removeEventListener('keydown', onKey, true);
        returnFocus?.focus?.();
        resolve({ ok: confirmed, value: input ? input.value.trim() : null, option: option ? option.checked : null });
      };
      const onKey = (event) => {
        if (event.key === 'Escape') { event.stopPropagation(); event.preventDefault(); finish(false); }
        else if (event.key === 'Tab') {
          const items = Array.from(box.querySelectorAll('button, input')).filter((el) => !el.disabled);
          const first = items[0], last = items[items.length - 1];
          if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
          else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
      };
      document.addEventListener('keydown', onKey, true);
      cancel.addEventListener('click', () => finish(false));
      overlay.addEventListener('mousedown', (event) => { if (event.target === overlay) finish(false); });
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        if (input) {
          const error = !input.value.trim() && input.required ? 'This field is required.' : (opts.input.validate ? opts.input.validate(input.value.trim()) : '');
          if (error) { setFieldError(input, error); input.focus(); return; }
        }
        finish(true);
      });
      (input || (opts.danger ? cancel : ok)).focus();
      if (input && opts.input.selectStem) {
        const dot = input.value.lastIndexOf('.');
        input.setSelectionRange(0, dot > 0 ? dot : input.value.length);
      } else if (input) input.select();
    });
  }
  async function confirmDialog(message, opts = {}) {
    if (typeof message === 'object') { opts = message; message = opts.message; }
    return (await dialog({ ...opts, message })).ok;
  }
  async function promptDialog(label, value = '', opts = {}) {
    if (typeof label === 'object') { opts = label; label = opts.label; value = opts.value || ''; }
    const res = await dialog({ title: opts.title || label, ...opts, input: { label, value, placeholder: opts.placeholder, validate: opts.validate, mono: opts.mono, selectStem: opts.selectStem }, confirmLabel: opts.confirmLabel || 'Save' });
    return res.ok ? res.value : null;
  }

  // ---- Chart defaults -----------------------------------------------------
  function applyChartTheme() {
    if (!window.Chart) return;
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = cssVar('--chart-text') || '#8296ab';
    Chart.defaults.borderColor = cssVar('--chart-grid') || 'rgba(255,255,255,.06)';
    if (liveChart) {
      liveChart.options.scales.y.grid.color = cssVar('--chart-grid');
      liveChart.options.scales.y.ticks.color = cssVar('--chart-text');
      liveChart.options.plugins.legend.labels.color = cssVar('--chart-text');
      liveChart.update('none');
    }
  }

  // ---- Metrics rendering --------------------------------------------------
  function setBar(sel, pct, color) {
    document.querySelectorAll(sel).forEach((el) => {
      el.style.width = (pct == null ? 0 : Math.min(100, pct)) + '%';
      if (color) el.style.background = color;
      el.parentElement?.setAttribute('aria-valuenow', pct == null ? '' : String(pct));
    });
  }

  let liveChart = null;
  const SERIES_KEY = 'nebula-live-series';
  const series = (() => {
    try {
      const saved = JSON.parse(sessionStorage.getItem(SERIES_KEY) || 'null');
      if (saved && Array.isArray(saved.cpu) && Date.now() - (saved.at || 0) < 10 * 60 * 1000) return saved;
    } catch (e) { /* storage unavailable */ }
    return { cpu: [], mem: [], labels: [], at: 0 };
  })();

  function initLiveChart() {
    const ctx = document.getElementById('liveChart');
    if (!ctx || !window.Chart) return;
    applyChartTheme();
    const seed = ctx.dataset;
    if (!series.cpu.length && seed.cpu !== undefined) {
      series.labels.push(''); series.cpu.push(Number(seed.cpu) || 0); series.mem.push(Number(seed.mem) || 0);
    }
    const point = (c) => (c.dataset.data.length < 2 ? 3 : 0);
    liveChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: series.labels,
        datasets: [
          { label: 'CPU %', data: series.cpu, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,.08)', fill: true, tension: .35, pointRadius: point, borderWidth: 2 },
          { label: 'Memory %', data: series.mem, borderColor: '#f59e0b', pointRadius: point, borderWidth: 2, tension: .35 },
        ],
      },
      options: {
        animation: false,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'top', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, color: cssVar('--chart-text') } } },
        scales: { x: { display: false }, y: { min: 0, max: 100, grid: { color: cssVar('--chart-grid') }, ticks: { color: cssVar('--chart-text') } } },
      },
    });
  }

  function applyMetrics(m) {
    const cpuPct = m.cpu, memPct = m.mem?.pct, diskPct = m.disk?.pct;
    const put = (attr, val) => document.querySelectorAll(`[data-mh="${attr}"]`).forEach((e) => (e.textContent = val));
    put('cpu', cpuPct == null ? 'n/a' : cpuPct + '%');
    put('mem', memPct == null ? 'n/a' : memPct + '%');
    put('disk', diskPct == null ? 'n/a' : diskPct + '%');
    setBar('[data-mh-bar="cpu"]', cpuPct, colorFor(cpuPct));
    setBar('[data-mh-bar="mem"]', memPct, colorFor(memPct));
    setBar('[data-mh-bar="disk"]', diskPct, colorFor(diskPct));

    const stat = (attr, value) => document.querySelectorAll(`[data-stat="${attr}"]`).forEach((e) => {
      e.textContent = value == null ? 'n/a' : value;
      if (value != null) { const unit = document.createElement('span'); unit.className = 'unit'; unit.textContent = '%'; e.appendChild(unit); }
    });
    const text = (attr, value) => document.querySelectorAll(`[data-stat="${attr}"]`).forEach((e) => (e.textContent = value));
    if (document.querySelector('[data-stat="cpu"]')) {
      stat('cpu', cpuPct);
      if (m.load) text('load', `CPU · Load ${m.load.map((n) => (+n).toFixed(2)).join(', ')}`);
      stat('mem', memPct);
      if (m.mem) text('mem-detail', `${fmtBytes(m.mem.used)} / ${fmtBytes(m.mem.total)}`);
      stat('disk', diskPct);
      if (m.disk) text('disk-detail', `${fmtBytes(m.disk.used)} / ${fmtBytes(m.disk.total)}`);
      setBar('[data-stat-bar="cpu"]', cpuPct, colorFor(cpuPct));
      setBar('[data-stat-bar="mem"]', memPct, colorFor(memPct));
      setBar('[data-stat-bar="disk"]', diskPct, colorFor(diskPct));
    }

    if (liveChart) {
      series.labels.push('');
      series.cpu.push(cpuPct ?? 0);
      series.mem.push(memPct ?? 0);
      if (series.labels.length > 60) { series.labels.shift(); series.cpu.shift(); series.mem.shift(); }
      series.at = Date.now();
      try { sessionStorage.setItem(SERIES_KEY, JSON.stringify(series)); } catch (e) { /* ignore */ }
      liveChart.update();
    }
  }

  const METRICS_INTERVAL = { dashboard: 5000, other: 12000 };
  let metricsTimer = null, metricsBusy = false;
  async function pollMetrics() {
    if (metricsBusy || document.hidden) return;
    metricsBusy = true;
    try { applyMetrics(await apiGet('metrics')); } catch (e) { /* silent: the top bar keeps its last values */ }
    finally { metricsBusy = false; }
  }
  function startMetricsPolling(page) {
    const interval = page === 'dashboard' ? METRICS_INTERVAL.dashboard : METRICS_INTERVAL.other;
    const tick = async () => { await pollMetrics(); clearTimeout(metricsTimer); metricsTimer = setTimeout(tick, interval); };
    tick();
    document.addEventListener('visibilitychange', () => { clearTimeout(metricsTimer); if (!document.hidden) tick(); });
  }

  // ---- Services -----------------------------------------------------------
  const SVC_BADGE = {
    active: ['badge-emerald', 'Running'], inactive: ['badge-slate', 'Stopped'],
    failed: ['badge-red', 'Failed'], 'not-installed': ['badge-slate', 'Not installed'], unknown: ['badge-slate', 'Unknown'],
  };
  function updateSvcBadge(tr, status) {
    const [cls, label] = SVC_BADGE[status] || ['badge-slate', status];
    const badge = tr.querySelector('[data-svc-badge]');
    if (badge) { badge.className = 'badge ' + cls; badge.innerHTML = '<span class="bdot"></span>'; badge.append(document.createTextNode(label)); }
    // Offer only the actions that make sense for the current state.
    const running = status === 'active';
    const installed = status !== 'not-installed';
    tr.querySelectorAll('[data-action]').forEach((btn) => {
      const action = btn.dataset.action;
      if (action === 'start') btn.disabled = !installed || running;
      else if (action === 'stop' || action === 'restart') btn.disabled = !installed || !running;
      else btn.disabled = !installed;
    });
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

  async function loadSvcSummary() {
    const box = document.getElementById('svcSummary');
    if (!box) return;
    try {
      const res = await apiGet('services');
      if (!res.ok) return;
      box.innerHTML = '';
      res.services.filter((s) => s.status !== 'not-installed').forEach((s) => {
        const [cls, label] = SVC_BADGE[s.status] || ['badge-slate', s.status];
        const row = document.createElement('a');
        row.className = 'service-row';
        row.href = `${BASE}/?r=service&name=${encodeURIComponent(s.name)}`;
        const iconWrap = document.createElement('div');
        iconWrap.className = 'svc-icon';
        iconWrap.innerHTML = '<i data-lucide="server" aria-hidden="true"></i>';
        const nameWrap = document.createElement('div');
        nameWrap.style.flex = '1';
        const name = document.createElement('div');
        name.className = 'svc-name'; name.textContent = s.label || s.name;
        nameWrap.appendChild(name);
        const badge = document.createElement('span');
        badge.className = `badge ${cls}`;
        badge.innerHTML = '<span class="bdot"></span>';
        badge.append(document.createTextNode(label));
        row.append(iconWrap, nameWrap, badge);
        box.appendChild(row);
      });
      if (!box.children.length) box.innerHTML = '<div class="text-tertiary" style="font-size:13px">No managed services are installed.</div>';
      if (window.lucide) lucide.createIcons();
    } catch (e) { box.innerHTML = '<div class="text-tertiary" style="font-size:13px">Could not load services.</div>'; }
  }

  // ---- Dropdown menus (notifications, account) ---------------------------
  const menus = [];
  function closeMenus(except) {
    menus.forEach(({ trigger, menu }) => {
      if (menu === except || menu.classList.contains('hidden')) return;
      menu.classList.add('hidden'); trigger.setAttribute('aria-expanded', 'false');
    });
  }
  function wireMenu(trigger, menu, onOpen) {
    if (!trigger || !menu) return;
    menus.push({ trigger, menu });
    trigger.addEventListener('click', (event) => {
      event.stopPropagation();
      const opening = menu.classList.contains('hidden');
      closeMenus(menu);
      menu.classList.toggle('hidden', !opening);
      trigger.setAttribute('aria-expanded', opening ? 'true' : 'false');
      if (opening) {
        onOpen?.();
        menu.querySelector('a[href], button:not([disabled])')?.focus({ preventScroll: true });
      }
    });
    menu.addEventListener('click', (event) => event.stopPropagation());
    menu.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return;
      event.stopPropagation();
      menu.classList.add('hidden'); trigger.setAttribute('aria-expanded', 'false'); trigger.focus();
    });
  }
  document.addEventListener('click', () => closeMenus());

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
        trigger.setAttribute('aria-label', res.unread ? `Notifications (${res.unread} unread)` : 'Notifications');
        if (count) count.textContent = `${res.unread} unread`;
        list.innerHTML = '';
        const items = (res.items || []).slice(0, 6);
        if (!items.length) {
          const empty = document.createElement('div'); empty.className = 'fm-empty-hint'; empty.textContent = 'You’re all caught up.'; list.appendChild(empty); return;
        }
        items.forEach((item) => {
          const row = document.createElement('div'); row.className = 'notification-menu-item' + (item.read ? '' : ' unread');
          const icon = document.createElement('a'); icon.className = 'notif-icon'; icon.href = `${BASE}/?r=${encodeURIComponent(item.route || 'dashboard')}`; icon.innerHTML = `<i data-lucide="${item.icon || 'bell'}" aria-hidden="true"></i>`; icon.style.color = color(item.level); icon.tabIndex = -1;
          const copy = document.createElement('a'); copy.className = 'notification-menu-copy'; copy.href = icon.href;
          const title = document.createElement('strong'); title.textContent = item.title || '';
          const detail = document.createElement('span'); detail.textContent = item.detail || ''; detail.title = item.detail || '';
          copy.append(title, detail);
          const actions = document.createElement('div'); actions.className = 'notification-menu-actions';
          if (!item.read) {
            const read = document.createElement('button'); read.type = 'button'; read.className = 'icon-btn'; read.title = 'Mark as read'; read.setAttribute('aria-label', `Mark “${item.title}” as read`); read.innerHTML = '<i data-lucide="check"></i>';
            read.addEventListener('click', async () => { await apiPost('notifications', { action: 'mark-read', id: item.id }); await render(); }); actions.appendChild(read);
          }
          const del = document.createElement('button'); del.type = 'button'; del.className = 'icon-btn'; del.title = 'Dismiss'; del.setAttribute('aria-label', `Dismiss “${item.title}”`); del.innerHTML = '<i data-lucide="x"></i>';
          del.addEventListener('click', async () => { await apiPost('notifications', { action: 'delete', id: item.id }); await render(); }); actions.appendChild(del);
          row.append(icon, copy, actions); list.appendChild(row);
        });
        if (window.lucide) lucide.createIcons();
      } catch (e) {
        list.innerHTML = '<div class="fm-empty-hint">Could not load notifications.</div>';
      }
    };
    wireMenu(trigger, menu, render);
    document.getElementById('notificationReadAll')?.addEventListener('click', async () => { await apiPost('notifications', { action: 'mark-all-read' }); await render(); });
    render();
  }

  // ---- File manager: open editor links in the shared popup ---------------
  function openEditor(href) {
    const popup = window.open(href, 'nebulaFileEditor', 'popup,width=1280,height=840,resizable=yes,scrollbars=yes');
    if (popup) popup.focus(); else toast('Allow pop-ups to use the multi-tab file editor', 'warning');
  }
  function wireFiles() {
    const wireEditorLinks = (root) => root.querySelectorAll('a[href*="r=file-edit"]').forEach((link) => {
      if (link.dataset.editorWired) return;
      link.dataset.editorWired = '1';
      link.addEventListener('click', (event) => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        openEditor(link.href);
      });
    });
    wireEditorLinks(document);
    const treePane = document.querySelector('.fm-tree-pane');
    treePane?.addEventListener('click', async (event) => {
      const toggle = event.target.closest('.tree-toggle');
      if (!toggle || !treePane.contains(toggle)) return;
      event.preventDefault(); event.stopPropagation();
      const row = toggle.closest('[data-tree-row]');
      const path = toggle.dataset.treePath || '';
      let children = row?.nextElementSibling;
      if (!children?.hasAttribute?.('data-tree-children') || children.dataset.treeParent !== path) {
        children = document.createElement('div');
        children.className = 'tree-children hidden';
        children.dataset.treeChildren = '';
        children.dataset.treeParent = path;
        row?.after(children);
      }
      const opening = children.classList.contains('hidden');
      children.classList.toggle('hidden', !opening);
      toggle.classList.toggle('open', opening);
      toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
      toggle.innerHTML = `<i data-lucide="${opening ? 'chevron-down' : 'chevron-right'}"></i>`;
      if (opening && !children.dataset.loaded && !children.children.length) {
        children.innerHTML = '<div class="tree-loading">Loading…</div>';
        try {
          const res = await apiGet('file-tree&path=' + encodeURIComponent(path));
          children.replaceChildren();
          (res.entries || []).forEach((entry) => {
            const item = document.createElement(entry.dir ? 'div' : 'a');
            item.className = 'tree-node';
            if (entry.dir) {
              item.dataset.treeRow = '';
              item.innerHTML = '<button class="tree-toggle" type="button" aria-expanded="false"><i data-lucide="chevron-right"></i></button><i data-lucide="folder" class="folder-ic" style="color:var(--purple-400)"></i><a><span></span></a>';
              const btn = item.querySelector('.tree-toggle');
              btn.dataset.treePath = entry.path; btn.setAttribute('aria-label', `Expand ${entry.name}`);
              const link = item.querySelector('a'); link.href = entry.href; link.querySelector('span').textContent = entry.name; link.title = entry.name;
            } else {
              item.href = entry.href; item.title = entry.name;
              item.innerHTML = '<i data-lucide="file" class="folder-ic" style="color:var(--text-tertiary)"></i><span></span>';
              item.querySelector('span').textContent = entry.name;
            }
            children.appendChild(item);
          });
          if (!children.children.length) children.innerHTML = '<div class="tree-loading">Empty folder</div>';
          children.dataset.loaded = '1';
          wireEditorLinks(children);
        } catch (error) {
          children.innerHTML = '<div class="tree-loading">Preview unavailable</div>';
        }
      }
      if (window.lucide) lucide.createIcons();
    });
  }

  // ---- Chrome (sidebar, theme) -------------------------------------------
  const store = {
    get(key) { try { return localStorage.getItem(key); } catch (e) { return null; } },
    set(key, value) { try { localStorage.setItem(key, value); } catch (e) { /* ignore */ } },
  };
  const isLight = () => document.documentElement.classList.contains('light');
  function syncThemeIcon() {
    const themeBtn = document.getElementById('themeToggle');
    if (!themeBtn) return;
    themeBtn.innerHTML = `<i data-lucide="${isLight() ? 'sun' : 'moon'}"></i>`;
    const label = isLight() ? 'Switch to dark theme' : 'Switch to light theme';
    themeBtn.title = label; themeBtn.setAttribute('aria-label', label);
    if (window.lucide) lucide.createIcons();
  }
  function applyTheme(light) {
    document.documentElement.classList.toggle('light', light);
    syncThemeIcon();
    syncCmThemes();
    applyChartTheme();
    document.dispatchEvent(new CustomEvent('nebula:theme', { detail: { light } }));
  }
  function wireChrome() {
    const sidebar = document.getElementById('sidebar');
    const collapseBtn = document.getElementById('collapseBtn');
    const setCollapsed = (collapsed) => {
      sidebar?.classList.toggle('collapsed', collapsed);
      collapseBtn?.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
      if (collapseBtn) collapseBtn.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
      const label = collapseBtn?.querySelector('.nav-label'); if (label) label.textContent = collapsed ? 'Expand' : 'Collapse';
    };
    setCollapsed(store.get('nebula-sidebar-collapsed') === '1');
    collapseBtn?.addEventListener('click', () => {
      const collapsed = !sidebar.classList.contains('collapsed');
      setCollapsed(collapsed);
      store.set('nebula-sidebar-collapsed', collapsed ? '1' : '0');
    });

    // Collapsible nav sections; the section holding the current page stays open.
    let closedGroups = [];
    try { closedGroups = JSON.parse(store.get('nebula-nav-closed') || '[]'); } catch (e) { closedGroups = []; }
    document.querySelectorAll('[data-nav-group]').forEach((group) => {
      const name = group.dataset.navGroup;
      const button = group.querySelector('.nav-section-title');
      const setOpen = (open) => { group.classList.toggle('collapsed', !open); button.setAttribute('aria-expanded', open ? 'true' : 'false'); };
      setOpen(group.querySelector('.nav-item.active') ? true : !closedGroups.includes(name));
      button.addEventListener('click', () => {
        const open = group.classList.contains('collapsed');
        setOpen(open);
        closedGroups = closedGroups.filter((g) => g !== name);
        if (!open) closedGroups.push(name);
        store.set('nebula-nav-closed', JSON.stringify(closedGroups));
      });
    });
    document.querySelector('.nav-item.active')?.scrollIntoView({ block: 'nearest' });

    // Off-canvas navigation on small screens.
    const navToggle = document.getElementById('navToggle');
    const backdrop = document.getElementById('sidebarBackdrop');
    const setNav = (open) => {
      sidebar?.classList.toggle('open', open);
      backdrop?.classList.toggle('open', open);
      navToggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) sidebar?.querySelector('.nav-item.active, .nav-item')?.focus();
      else if (document.activeElement && sidebar?.contains(document.activeElement)) navToggle?.focus();
    };
    navToggle?.addEventListener('click', () => setNav(!sidebar.classList.contains('open')));
    backdrop?.addEventListener('click', () => setNav(false));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && sidebar?.classList.contains('open')) setNav(false); });
    window.matchMedia('(min-width: 901px)').addEventListener?.('change', (event) => { if (event.matches) setNav(false); });

    syncThemeIcon();
    const toggleTheme = () => {
      const light = !isLight();
      applyTheme(light);
      store.set('nebula-theme', light ? 'light' : 'dark');
    };
    document.getElementById('themeToggle')?.addEventListener('click', toggleTheme);
    document.querySelectorAll('[data-theme-toggle]').forEach((btn) => btn.addEventListener('click', () => { toggleTheme(); closeMenus(); }));
    // Follow theme changes made in another window (e.g. the editor popup).
    window.addEventListener('storage', (event) => {
      if (event.key === 'nebula-theme') applyTheme(event.newValue === 'light');
    });

    wireMenu(document.getElementById('accountTrigger'), document.getElementById('accountMenu'));
    document.getElementById('refreshBtn')?.addEventListener('click', () => { pollMetrics(); refreshAll(); toast('Dashboard refreshed', 'info'); });
  }

  // Shared tab behaviour used by service instances and mockup-parity pages.
  function wireTabs() {
    document.querySelectorAll('.tabs').forEach((group) => {
      const tabs = Array.from(group.querySelectorAll(':scope > .tab[data-tab-target]'));
      if (!tabs.length) return;
      group.setAttribute('role', 'tablist');
      const activate = (tab, focus) => {
        tabs.forEach((item) => {
          const on = item === tab;
          item.classList.toggle('active', on);
          item.setAttribute('aria-selected', on ? 'true' : 'false');
          item.tabIndex = on ? 0 : -1;
        });
        const panels = document.querySelector(tab.dataset.tabPanelGroup || '[data-tab-panels]');
        panels?.querySelectorAll(':scope > [data-tab-panel]').forEach((panel) => panel.classList.add('hidden'));
        document.getElementById(tab.dataset.tabTarget)?.classList.remove('hidden');
        if (focus) tab.focus();
        if (tab.dataset.tabHash) history.replaceState(null, '', '#' + tab.dataset.tabHash);
      };
      tabs.forEach((tab) => {
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-controls', tab.dataset.tabTarget);
        tab.setAttribute('aria-selected', tab.classList.contains('active') ? 'true' : 'false');
        tab.tabIndex = tab.classList.contains('active') ? 0 : -1;
        tab.addEventListener('click', () => activate(tab));
        tab.addEventListener('keydown', (event) => {
          const i = tabs.indexOf(tab);
          if (event.key === 'ArrowRight') { event.preventDefault(); activate(tabs[(i + 1) % tabs.length], true); }
          else if (event.key === 'ArrowLeft') { event.preventDefault(); activate(tabs[(i - 1 + tabs.length) % tabs.length], true); }
        });
      });
      // Restore a tab named in the URL hash (survives reloads after actions).
      const fromHash = tabs.find((tab) => tab.dataset.tabHash && '#' + tab.dataset.tabHash === location.hash);
      if (fromHash) activate(fromHash);
    });
  }

  // ---- Command palette (⌘K / Ctrl+K) -------------------------------------
  function wireCmdk() {
    const overlay = document.getElementById('cmdk');
    if (!overlay) return;
    const input = document.getElementById('cmdkInput');
    const list = document.getElementById('cmdkList');
    const empty = document.getElementById('cmdkEmpty');
    const items = Array.from(list.querySelectorAll('.cmdk-item'));
    let sel = 0;
    const visible = () => items.filter((it) => !it.classList.contains('hidden'));
    function setSel(i) {
      const vis = visible();
      items.forEach((it) => { it.classList.remove('sel'); it.setAttribute('aria-selected', 'false'); });
      if (!vis.length) return;
      sel = (i + vis.length) % vis.length;
      vis[sel].classList.add('sel');
      vis[sel].setAttribute('aria-selected', 'true');
      vis[sel].scrollIntoView({ block: 'nearest' });
    }
    function filter(q) {
      const words = q.toLowerCase().trim().split(/\s+/).filter(Boolean);
      items.forEach((it) => it.classList.toggle('hidden', words.some((w) => !it.dataset.label.includes(w))));
      empty?.classList.toggle('hidden', visible().length > 0);
      setSel(0);
    }
    let returnFocus = null;
    function open() {
      returnFocus = document.activeElement;
      closeMenus();
      overlay.classList.remove('hidden');
      input.value = '';
      filter('');
      input.focus();
    }
    function close() {
      overlay.classList.add('hidden');
      (returnFocus && returnFocus !== document.body ? returnFocus : document.getElementById('searchTrigger'))?.focus?.();
    }
    function go() { const vis = visible(); if (vis[sel]) window.location.href = vis[sel].dataset.href; }

    document.getElementById('searchTrigger')?.addEventListener('click', open);
    document.querySelectorAll('[data-open-search]').forEach((btn) => btn.addEventListener('click', open));
    document.addEventListener('keydown', (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); open(); }
      else if (e.key === 'Escape' && !overlay.classList.contains('hidden')) { e.stopPropagation(); close(); }
    });
    input.addEventListener('input', () => filter(input.value));
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); setSel(sel + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setSel(sel - 1); }
      else if (e.key === 'Enter') { e.preventDefault(); go(); }
      else if (e.key === 'Tab') { e.preventDefault(); }
    });
    items.forEach((it) => it.addEventListener('click', () => (window.location.href = it.dataset.href)));
    overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close(); });
  }

  // ---- Accessibility ------------------------------------------------------
  const ICON_LABELS = { x: 'Close', 'trash-2': 'Delete', pencil: 'Edit', 'square-pen': 'Edit', 'refresh-cw': 'Refresh', play: 'Start', square: 'Stop', 'rotate-cw': 'Restart', 'rotate-ccw': 'Restart', download: 'Download', copy: 'Copy', check: 'Confirm', info: 'Details', eye: 'View', 'scroll-text': 'Logs' };
  function wireAccessibility() {
    const dialogState = new WeakMap();
    // Name every icon-only control (aria-label + visible tooltip).
    const labelIcons = (root = document) => {
      const candidates = [];
      if (root instanceof Element && root.matches('button, a')) candidates.push(root);
      root.querySelectorAll?.('button, a').forEach((el) => candidates.push(el));
      candidates.forEach((el) => {
        if (el.textContent.trim() || el.hasAttribute('aria-label')) {
          if (!el.title && el.getAttribute('aria-label') && !el.textContent.trim()) el.title = el.getAttribute('aria-label');
          return;
        }
        const iconName = el.querySelector('[data-lucide]')?.getAttribute('data-lucide') || el.querySelector('svg')?.getAttribute('class')?.match(/lucide-([a-z0-9-]+)/)?.[1] || '';
        const label = el.getAttribute('title') || ICON_LABELS[iconName] || iconName.replace(/-/g, ' ').replace(/^\w/, (l) => l.toUpperCase());
        if (label) { el.setAttribute('aria-label', label); if (!el.title) el.title = label; }
      });
    };
    const setBackgroundInert = (overlay, inert) => {
      const state = dialogState.get(overlay) || {};
      if (inert) {
        state.inerted = [];
        let branch = overlay;
        while (branch.parentElement) {
          Array.from(branch.parentElement.children).forEach((sibling) => {
            if (sibling === branch || sibling.inert || sibling.matches('.toast-stack, .modal-overlay')) return;
            sibling.inert = true;
            state.inerted.push(sibling);
          });
          branch = branch.parentElement;
        }
      } else {
        (state.inerted || []).forEach((element) => { element.inert = false; });
        state.inerted = [];
      }
      dialogState.set(overlay, state);
    };
    const firstField = (dialog) => dialog.querySelector('[autofocus]')
      || dialog.querySelector('.drawer-body input:not([disabled]):not([type=hidden]), .drawer-body select:not([disabled]), .drawer-body textarea:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled])')
      || dialog.querySelector('button:not([disabled]), a[href]');
    const prepareDialog = (overlay) => {
      const dialog = overlay.querySelector('.drawer, .modal, .cmdk');
      if (!dialog) return;
      dialog.setAttribute('role', 'dialog');
      dialog.setAttribute('aria-modal', 'true');
      const heading = dialog.querySelector('.drawer-header strong, .modal-title');
      if (heading && !dialog.hasAttribute('aria-labelledby')) {
        heading.id = heading.id || 'dlg-title-' + Math.random().toString(36).slice(2);
        dialog.setAttribute('aria-labelledby', heading.id);
      }
      const open = !overlay.classList.contains('hidden');
      const state = dialogState.get(overlay) || { open: false, returnFocus: null, inerted: [] };
      overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
      if (open && !state.open) {
        state.returnFocus = document.activeElement;
        dialogState.set(overlay, state);
        setBackgroundInert(overlay, true);
        const first = firstField(dialog);
        if (first) first.focus();
        else { dialog.setAttribute('tabindex', '-1'); dialog.focus(); }
      } else if (!open && state.open) {
        setBackgroundInert(overlay, false);
        state.returnFocus?.focus?.();
      }
      state.open = open;
      dialogState.set(overlay, state);
    };
    labelIcons();
    document.querySelectorAll('.drawer-overlay, #cmdk').forEach(prepareDialog);
    // Clicking the dimmed backdrop closes a drawer, like Escape does.
    document.addEventListener('mousedown', (event) => {
      if (event.target instanceof Element && event.target.matches('.drawer-overlay:not(.hidden)')) {
        event.target.classList.add('hidden');
      }
    });

    let labelQueued = false;
    const observer = new MutationObserver((changes) => {
      changes.forEach((change) => {
        change.addedNodes.forEach((node) => {
          if (!(node instanceof Element)) return;
          if (node.matches('.drawer-overlay, #cmdk')) prepareDialog(node);
          node.querySelectorAll?.('.drawer-overlay, #cmdk').forEach(prepareDialog);
        });
        if (change.type === 'attributes' && change.target instanceof Element
            && change.target.matches('.drawer-overlay, #cmdk')) {
          prepareDialog(change.target);
        }
      });
      // lucide replaces <i> with <svg> after insertion; label once per frame.
      if (!labelQueued) { labelQueued = true; requestAnimationFrame(() => { labelQueued = false; labelIcons(); }); }
    });
    observer.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['class'] });

    document.addEventListener('keydown', (event) => {
      const overlay = Array.from(document.querySelectorAll('.drawer-overlay:not(.hidden)')).pop();
      if (!overlay) return;
      if (event.key === 'Escape') {
        overlay.classList.add('hidden');
        return;
      }
      if (event.key !== 'Tab') return;
      const focusable = Array.from(overlay.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )).filter((el) => !el.closest('.hidden'));
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault(); last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault(); first.focus();
      }
    });
  }

  // ---- Boot ---------------------------------------------------------------
  document.addEventListener('DOMContentLoaded', () => {
    if (window.lucide) lucide.createIcons();
    wireChrome();
    wireCmdk();
    wireTabs();
    wireNotifications();
    wireAccessibility();

    const page = window.NEBULA_PAGE;
    if (document.getElementById('miniHealth')) {
      if (page === 'dashboard') { initLiveChart(); registerRefresh(loadSvcSummary); }
      startMetricsPolling(page);
    }
    if (page === 'dashboard') loadSvcSummary();
    if (page === 'files') wireFiles();
  });
})();
