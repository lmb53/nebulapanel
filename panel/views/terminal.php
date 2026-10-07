<div class="page-header">
  <div>
    <h1 class="page-title">Terminal</h1>
    <p class="page-subtitle">Runs as the web user · non-interactive · 30s timeout · every command is audited</p>
  </div>
</div>

<div class="notice notice-warning" style="margin-bottom:16px"><i data-lucide="triangle-alert"></i><div><strong>Commands run for real on this server</strong><div>They run as the web user in a fresh, non-interactive shell (no state carries over between commands) and are written to the audit log.</div></div></div>

<div class="term-window">
  <div class="term-titlebar">
    <i data-lucide="square-terminal" aria-hidden="true"></i>
    <span>bash · web user</span>
    <span class="topbar-spacer"></span>
    <button class="btn btn-ghost btn-sm term-clear" type="button" id="termClear"><i data-lucide="eraser"></i>Clear</button>
  </div>
  <div class="term-body" id="termBody" role="log" aria-live="polite" tabindex="0"><div class="term-hint">Type a command below and press Enter. Use ↑ and ↓ to recall previous commands.</div></div>
  <form class="term-prompt" id="termForm">
    <label for="termInput" class="mono term-sigil">$<span class="sr-only">Command</span></label>
    <input id="termInput" class="mono" autocomplete="off" spellcheck="false" placeholder="Type a command and press Enter">
  </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast } = window.Nebula;
  const body = document.getElementById('termBody');
  const input = document.getElementById('termInput');
  const history = [];
  let hIndex = -1;

  function line(text, color) {
    const div = document.createElement('div');
    div.textContent = text;
    if (color) div.style.color = color;
    div.style.whiteSpace = 'pre-wrap';
    body.appendChild(div);
  }

  async function run(cmd) {
    line('$ ' + cmd, '#9fb0c3');
    let res;
    try { res = await apiPost('terminal', { command: cmd }); }
    catch (e) { line('[request failed]', '#f87171'); return; }
    if (!res.ok) { line(res.error || '[error]', '#f87171'); return; }
    if (res.stdout) line(res.stdout);
    if (res.stderr) line(res.stderr, '#f87171');
    if (res.code !== 0) line('[exit ' + res.code + ']', '#8296ab');
    body.scrollTop = body.scrollHeight;
  }

  document.getElementById('termClear').addEventListener('click', () => { body.replaceChildren(); input.focus(); });
  document.getElementById('termForm').addEventListener('submit', (e) => e.preventDefault());
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const cmd = input.value.trim();
      if (!cmd) return;
      body.querySelector('.term-hint')?.remove();
      history.push(cmd); hIndex = history.length;
      input.value = '';
      run(cmd);
    } else if (e.key === 'ArrowUp') {
      if (hIndex > 0) { hIndex--; input.value = history[hIndex]; }
      e.preventDefault();
    } else if (e.key === 'ArrowDown') {
      if (hIndex < history.length - 1) { hIndex++; input.value = history[hIndex]; }
      else { hIndex = history.length; input.value = ''; }
      e.preventDefault();
    }
  });
  input.focus();
});
</script>
