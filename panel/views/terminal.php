<div class="page-header">
  <div>
    <h1 class="page-title">Terminal</h1>
    <p class="page-subtitle">Runs as the web user · non-interactive · 30s timeout · every command is audited</p>
  </div>
</div>

<div class="card" style="margin-bottom:16px;border-color:rgba(245,158,11,.25)">
  <div class="card-pad flex items-center gap-3" style="color:var(--orange-400)">
    <i data-lucide="alert-triangle"></i>
    <div style="font-size:13px;color:var(--text-secondary)">
      This executes real shell commands on the server. Commands run as the web user; state does not persist
      between commands (each runs in a fresh shell). Every command is written to the audit log.
    </div>
  </div>
</div>

<div class="term-window">
  <div class="term-titlebar">
    <span class="term-dot" style="background:#ff5f56"></span>
    <span class="term-dot" style="background:#ffbd2e"></span>
    <span class="term-dot" style="background:#27c93f"></span>
    <span style="margin-left:8px;color:var(--text-tertiary);font-size:12px">bash — web user</span>
  </div>
  <div class="term-body" id="termBody"></div>
  <div class="flex items-center gap-2" style="padding:10px 14px;border-top:1px solid var(--border-subtle);background:#0e1117">
    <span class="mono" style="color:var(--emerald-400)">$</span>
    <input id="termInput" class="input mono" autocomplete="off" spellcheck="false"
           placeholder="type a command and press Enter"
           style="flex:1;background:transparent;border:none;padding:4px 0">
  </div>
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
    line('$ ' + cmd, 'var(--text-secondary)');
    let res;
    try { res = await apiPost('terminal', { command: cmd }); }
    catch (e) { line('[request failed]', 'var(--red-400)'); return; }
    if (!res.ok) { line(res.error || '[error]', 'var(--red-400)'); return; }
    if (res.stdout) line(res.stdout);
    if (res.stderr) line(res.stderr, 'var(--red-400)');
    if (res.code !== 0) line('[exit ' + res.code + ']', 'var(--text-tertiary)');
    body.scrollTop = body.scrollHeight;
  }

  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      const cmd = input.value.trim();
      if (!cmd) return;
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
