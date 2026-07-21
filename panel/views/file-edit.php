<?php
/** @var array $config */
require_once APP_ROOT . '/lib/files.php';
$abs = fm_resolve($_GET['path'] ?? '');
if ($abs === null || !is_file($abs)) {
    echo '<div class="card"><div class="empty-state"><div class="es-icon"><i data-lucide="file-x"></i></div><div style="font-weight:600;color:var(--text-secondary)">File not found</div></div></div>';
    return;
}
$rel = fm_rel($abs);
fm_record_recent($rel);
$dir = trim(dirname($rel), '.');
$dir = $dir === '/' ? '' : $dir;
if (!fm_is_text($abs)) {
    ?>
    <div class="page-header"><div><div class="breadcrumb"><a href="<?= e(url('files', ['path' => $dir])) ?>"><i data-lucide="corner-left-up"></i>Back to folder</a></div><h1 class="page-title" style="font-size:18px"><?= e(basename($rel)) ?></h1></div></div>
    <div class="card"><div class="empty-state"><div class="es-icon"><i data-lucide="file-lock-2"></i></div><div style="font-weight:600;color:var(--text-secondary)">Not an editable text file</div><div style="font-size:13px;margin-top:4px">This file is binary or too large to edit inline.</div><div style="margin-top:12px"><a class="btn btn-secondary" href="<?= e(url('file-download', ['path' => $rel])) ?>"><i data-lucide="download"></i>Download</a></div></div></div>
    <?php return;
}
$content = (string) file_get_contents($abs);
$size = filesize($abs);
$siblingListing = fm_list(dirname($abs));
$siblingFiles = array_values(array_filter($siblingListing['files'], fn($file) => fm_is_text(dirname($abs) . DIRECTORY_SEPARATOR . $file['name'])));
?>
<div class="editor-workspace">
<aside class="editor-sidebar">
  <div class="editor-sidebar-head"><div><strong><?= e(basename(dirname($rel)) ?: 'root') ?></strong><span class="mono"><?= e($dir ?: '/') ?></span></div><a class="icon-btn" href="<?= e(url('files',['path'=>$dir])) ?>" target="_blank" title="Open folder in File Manager"><i data-lucide="folder-open"></i></a></div>
  <div class="editor-file-list"><?php foreach ($siblingFiles as $file): ?><a class="editor-file-link<?= $file['rel'] === $rel ? ' active' : '' ?>" href="<?= e(url('file-edit',['path'=>$file['rel']])) ?>"><i data-lucide="file-code-2"></i><span><?= e($file['name']) ?></span></a><?php endforeach; ?></div>
</aside>
<section class="editor-main">
<div class="page-header">
  <div><div class="breadcrumb"><a href="<?= e(url('files', ['path' => $dir])) ?>"><i data-lucide="corner-left-up"></i>Back to folder</a></div><h1 class="page-title" style="font-size:18px"><?= e(basename($rel)) ?></h1><p class="page-subtitle"><span class="mono"><?= e($rel) ?></span> · <?= e(human_bytes($size)) ?></p></div>
  <div class="page-actions">
    <button class="btn btn-secondary" id="fwrap"><i data-lucide="wrap-text"></i>Wrap</button>
    <button class="btn btn-secondary" id="ffind"><i data-lucide="search"></i>Find</button>
    <button class="icon-btn" id="ffindPrev" title="Previous match"><i data-lucide="chevron-up"></i></button>
    <button class="icon-btn" id="ffindNext" title="Next match"><i data-lucide="chevron-down"></i></button>
    <span id="fsaved" class="editor-save-state hidden"></span>
    <button class="btn btn-primary" id="fsave"><i data-lucide="save"></i>Save</button>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/lib/codemirror.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/theme/material-darker.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/dialog/dialog.min.css">
<div class="editor-tabs" id="editorTabs"></div>
<div class="card code-editor-card"><div class="code-editor-toolbar"><span class="badge badge-slate mono" id="fmode">text</span><span class="muted mono" id="fcursor">Ln 1, Col 1</span><span class="topbar-spacer"></span><span class="muted">Ctrl/Cmd+S save · Ctrl/Cmd+F find · F3 next · Tab indent</span></div><div class="code-editor-host"><textarea id="fedit" class="input mono" style="width:100%;height:60vh;white-space:pre"><?= e($content) ?></textarea></div></div>
</section>
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
  const tabStore = 'nebula-editor-tabs-v2';
  const draftStore = 'nebula-editor-drafts-v2';
  let openTabs = [], drafts = {};
  try { openTabs = JSON.parse(sessionStorage.getItem(tabStore) || '[]'); } catch (e) {}
  try { drafts = JSON.parse(sessionStorage.getItem(draftStore) || '{}'); } catch (e) {}
  openTabs = openTabs.filter((item) => item?.path && item?.href);
  const currentTab = { path:FEDIT_PATH, name:FEDIT_PATH.split('/').pop(), href:location.href };
  const existingIndex = openTabs.findIndex((item) => item.path === FEDIT_PATH);
  if (existingIndex < 0) openTabs.push(currentTab); else openTabs[existingIndex] = { ...openTabs[existingIndex], ...currentTab };
  if (openTabs.length > 12) openTabs = openTabs.slice(-12);
  const persistTabs = () => { try { sessionStorage.setItem(tabStore, JSON.stringify(openTabs)); } catch (e) {} };
  const persistDrafts = () => { try { sessionStorage.setItem(draftStore, JSON.stringify(drafts)); } catch (e) {} };
  let allowNavigation = false;
  const renderTabs = () => {
    const host = document.getElementById('editorTabs'); host.replaceChildren();
    openTabs.forEach((item, position) => {
      const isDirty = Object.prototype.hasOwnProperty.call(drafts, item.path);
      const tab = document.createElement('div'); tab.className='editor-tab'+(item.path===FEDIT_PATH?' active':'')+(isDirty?' dirty':'');
      const link=document.createElement('a');link.href=item.href;link.textContent=item.name;link.title=item.path;link.addEventListener('click',()=>{allowNavigation=true;});
      if (isDirty) { const dot=document.createElement('span');dot.className='editor-dirty-dot';dot.title='Unsaved changes';tab.append(dot); }
      const close=document.createElement('button');close.type='button';close.title='Close tab';close.textContent='×';
      close.addEventListener('click',(event)=>{event.preventDefault();event.stopPropagation();if(isDirty&&!confirm(`Discard unsaved changes to "${item.name}"?`))return;delete drafts[item.path];persistDrafts();const wasCurrent=item.path===FEDIT_PATH;openTabs.splice(position,1);persistTabs();if(wasCurrent&&openTabs.length){allowNavigation=true;location.href=openTabs[Math.min(position,openTabs.length-1)].href;}else if(wasCurrent)window.close();else renderTabs();});
      tab.append(link,close);host.append(tab);
    });
  };
  document.querySelectorAll('.editor-file-link').forEach((link)=>link.addEventListener('click',()=>{allowNavigation=true;}));
  persistTabs(); renderTabs();
  const modes={php:'application/x-httpd-php',phtml:'application/x-httpd-php',js:'javascript',mjs:'javascript',json:{name:'javascript',json:true},ts:{name:'javascript',typescript:true},css:'css',scss:'text/x-scss',html:'htmlmixed',htm:'htmlmixed',xml:'xml',svg:'xml',yml:'yaml',yaml:'yaml',c:'text/x-csrc',cpp:'text/x-c++src',h:'text/x-csrc',java:'text/x-java'};
  let editor=null,wrapping=false,saveStateTimer=null;
  let lastSavedContent=ta.value;
  if(drafts[FEDIT_PATH]?.content!==undefined)ta.value=drafts[FEDIT_PATH].content;
  let dirty=ta.value!==lastSavedContent;
  const setSaveState=(message='',color='',autoHide=false)=>{clearTimeout(saveStateTimer);saved.textContent=message;saved.style.color=color;saved.classList.toggle('hidden',!message);if(autoHide)saveStateTimer=setTimeout(()=>saved.classList.add('hidden'),1600);};
  const rememberDraft=(content)=>{dirty=content!==lastSavedContent;if(dirty)drafts[FEDIT_PATH]={content,updated:Date.now()};else delete drafts[FEDIT_PATH];persistDrafts();renderTabs();setSaveState(dirty?'Unsaved changes':'',dirty?'var(--orange-400)':'');};
  if(window.CodeMirror){editor=CodeMirror.fromTextArea(ta,{mode:modes[extension]||'text/plain',theme:'material-darker',lineNumbers:true,indentUnit:2,tabSize:2,indentWithTabs:false,matchBrackets:true,autoCloseBrackets:true,lineWrapping:false,viewportMargin:20,extraKeys:{Tab:(cm)=>cm.somethingSelected()?cm.indentSelection('add'):cm.replaceSelection('  ','end')}});editor.setSize('100%','68vh');editor.on('change',()=>rememberDraft(editor.getValue()));editor.on('cursorActivity',()=>{const p=editor.getCursor();document.getElementById('fcursor').textContent=`Ln ${p.line+1}, Col ${p.ch+1}`;});}
  if(dirty)setSaveState('Unsaved changes','var(--orange-400)');
  document.getElementById('fmode').textContent=extension||'text';
  const value=()=>editor?editor.getValue():ta.value;
  const save=async()=>{const res=await apiPost('file-save',{path:FEDIT_PATH,content:value()});if(res.ok){toast('Saved','success');lastSavedContent=value();dirty=false;delete drafts[FEDIT_PATH];persistDrafts();renderTabs();setSaveState('Saved','var(--emerald-400)',true);}else toast(res.error||'Save failed','error');};
  document.getElementById('fsave')?.addEventListener('click',save);
  document.getElementById('ffind')?.addEventListener('click',()=>editor?editor.execCommand('find'):ta.focus());
  document.getElementById('ffindPrev')?.addEventListener('click',()=>editor?.execCommand('findPrev'));
  document.getElementById('ffindNext')?.addEventListener('click',()=>editor?.execCommand('findNext'));
  document.getElementById('fwrap')?.addEventListener('click',(event)=>{wrapping=!wrapping;if(editor)editor.setOption('lineWrapping',wrapping);else ta.style.whiteSpace=wrapping?'pre-wrap':'pre';event.currentTarget.classList.toggle('active',wrapping);});
  document.addEventListener('keydown',(event)=>{if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='s'){event.preventDefault();save();}if(event.key==='F3'){event.preventDefault();editor?.execCommand(event.shiftKey?'findPrev':'findNext');}});
  window.addEventListener('beforeunload',(event)=>{if(dirty&&!allowNavigation){event.preventDefault();event.returnValue='';}});
});
</script>
