<?php
/** @var array $config */
require_once APP_ROOT . '/lib/files.php';
$abs = fm_resolve($_GET['path'] ?? '');
if ($abs === null || !is_file($abs)) {
    echo '<div class="card"><div class="empty-state"><div class="es-icon"><i data-lucide="file-x"></i></div>'
       . '<div style="font-weight:600;color:var(--text-secondary)">File not found</div></div></div>';
    return;
}
$rel = fm_rel($abs);
fm_record_recent($rel);
$dir = trim(dirname($rel), '.'); $dir = $dir === '/' ? '' : $dir;
if (!fm_is_text($abs)) {
    ?>
    <div class="page-header">
      <div>
        <div class="breadcrumb"><a href="<?= e(url('files', ['path' => $dir])) ?>"><i data-lucide="corner-left-up"></i>Back to folder</a></div>
        <h1 class="page-title" style="font-size:18px"><?= e(basename($rel)) ?></h1>
      </div>
    </div>
    <div class="card"><div class="empty-state">
      <div class="es-icon"><i data-lucide="file-lock-2"></i></div>
      <div style="font-weight:600;color:var(--text-secondary)">Not an editable text file</div>
      <div style="font-size:13px;margin-top:4px">This file is binary or too large to edit inline.</div>
      <div style="margin-top:12px"><a class="btn btn-secondary" href="<?= e(url('file-download', ['path' => $rel])) ?>"><i data-lucide="download"></i>Download</a></div>
    </div></div>
    <?php
    return;
}
$content = file_get_contents($abs);
$size = filesize($abs);
?>
<div class="page-header">
  <div>
    <div class="breadcrumb"><a href="<?= e(url('files', ['path' => $dir])) ?>"><i data-lucide="corner-left-up"></i>Back to folder</a></div>
    <h1 class="page-title" style="font-size:18px"><?= e(basename($rel)) ?></h1>
    <p class="page-subtitle"><span class="mono"><?= e($rel) ?></span> · <?= e(human_bytes($size)) ?></p>
  </div>
  <div class="page-actions">
    <button class="btn btn-secondary" id="fwrap"><i data-lucide="wrap-text"></i>Wrap</button>
    <button class="btn btn-secondary" id="ffind"><i data-lucide="search"></i>Find</button>
    <span id="fsaved" class="muted" style="font-size:13px">Saved</span>
    <button class="btn btn-primary" id="fsave"><i data-lucide="save"></i>Save</button>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/lib/codemirror.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/theme/material-darker.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/dialog/dialog.min.css">
<div class="card code-editor-card">
  <div class="code-editor-toolbar">
    <span class="badge badge-slate mono" id="fmode">text</span>
    <span class="muted mono" id="fcursor">Ln 1, Col 1</span>
    <span class="topbar-spacer"></span>
    <span class="muted">Ctrl/Cmd+S save · Ctrl/Cmd+F find · Tab indent</span>
  </div>
  <div class="code-editor-host">
    <textarea id="fedit" class="input mono" style="width:100%;height:60vh;white-space:pre"><?= e($content) ?></textarea>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/lib/codemirror.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/edit/matchbrackets.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/edit/closebrackets.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/search/searchcursor.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/search/search.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/dialog/dialog.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/xml/xml.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/javascript/javascript.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/css/css.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/clike/clike.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/php/php.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/yaml/yaml.min.js"></script>
<script>
const FEDIT_PATH = <?= json_encode($rel) ?>;
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast } = window.Nebula;
  const ta = document.getElementById('fedit');
  const saved = document.getElementById('fsaved');
  const extension = (FEDIT_PATH.split('.').pop() || '').toLowerCase();
  const modes = { php:'application/x-httpd-php', phtml:'application/x-httpd-php', js:'javascript', mjs:'javascript', json:{name:'javascript',json:true}, ts:{name:'javascript',typescript:true}, css:'css', scss:'text/x-scss', html:'htmlmixed', htm:'htmlmixed', xml:'xml', svg:'xml', yml:'yaml', yaml:'yaml', c:'text/x-csrc', cpp:'text/x-c++src', h:'text/x-csrc', java:'text/x-java' };
  let editor = null, dirty = false, wrapping = false;
  if (window.CodeMirror) {
    editor = CodeMirror.fromTextArea(ta, {
      mode: modes[extension] || 'text/plain', theme: 'material-darker', lineNumbers: true,
      indentUnit: 2, tabSize: 2, indentWithTabs: false, matchBrackets: true,
      autoCloseBrackets: true, lineWrapping: false, viewportMargin: 20,
      extraKeys: { Tab: (cm) => cm.somethingSelected() ? cm.indentSelection('add') : cm.replaceSelection('  ', 'end') }
    });
    editor.setSize('100%', '68vh');
    editor.on('change', () => { dirty = true; saved.textContent = 'Unsaved changes'; saved.style.color = 'var(--orange-400)'; });
    editor.on('cursorActivity', () => { const p=editor.getCursor(); document.getElementById('fcursor').textContent=`Ln ${p.line+1}, Col ${p.ch+1}`; });
  }
  document.getElementById('fmode').textContent = extension || 'text';
  const value = () => editor ? editor.getValue() : ta.value;
  const save = async () => {
    const res = await apiPost('file-save', { path: FEDIT_PATH, content: value() });
    if (res.ok) {
      toast('Saved', 'success');
      dirty = false; saved.textContent = 'Saved'; saved.style.color = '';
    } else {
      toast(res.error || 'Save failed', 'error');
    }
  };
  document.getElementById('fsave')?.addEventListener('click', save);
  document.getElementById('ffind')?.addEventListener('click', () => editor ? editor.execCommand('find') : ta.focus());
  document.getElementById('fwrap')?.addEventListener('click', (event) => { wrapping=!wrapping; if(editor)editor.setOption('lineWrapping',wrapping); else ta.style.whiteSpace=wrapping?'pre-wrap':'pre'; event.currentTarget.classList.toggle('active',wrapping); });
  document.addEventListener('keydown', (event) => { if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase()==='s') { event.preventDefault(); save(); } });
  window.addEventListener('beforeunload', (event) => { if (dirty) { event.preventDefault(); event.returnValue=''; } });
});
</script>
