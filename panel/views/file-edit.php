<?php
/** @var array $config */
require_once APP_ROOT . '/lib/files.php';
$abs = fm_resolve($_GET['path'] ?? '');
if ($abs === null || !is_file($abs)) {
    echo '<div class="editor-missing"><div class="page-header"><div><h1 class="page-title">File not found</h1></div></div>' . requirement_missing('file-x', 'This file no longer exists', 'It may have been moved, renamed or deleted.', [['label' => 'Open File Manager', 'href' => url('files'), 'icon' => 'folder-open']]) . '</div>';
    return;
}
$rel = fm_rel($abs);
fm_record_recent($rel);
$dir = str_replace('\\', '/', dirname($rel));
$dir = ($dir === '.' || $dir === '/') ? '' : $dir;
$blockReason = fm_edit_block_reason($abs);
if ($blockReason !== null) {
    ?>
    <div class="page-header"><div><div class="breadcrumb"><a href="<?= e(url('files', ['path' => $dir])) ?>"><i data-lucide="arrow-left"></i>Back to folder</a></div><h1 class="page-title editor-title"><?= e(basename($rel)) ?></h1></div></div>
    <?= requirement_missing('file-lock-2', 'Not an editable text file', e($blockReason), [['label' => 'Download', 'href' => url('file-download', ['path' => $rel]), 'icon' => 'download']]) ?>
    <?php return;
}
$content = (string) file_get_contents($abs);
$size = filesize($abs);
$siblingListing = fm_list(dirname($abs));
// Each candidate is sampled from disk, so bound the work in huge folders.
$siblingFiles = array_values(array_filter(array_slice($siblingListing['files'], 0, 300), fn($file) => fm_is_text(dirname($abs) . DIRECTORY_SEPARATOR . $file['name'])));
?>
<div class="editor-workspace">
<aside class="editor-sidebar">
  <div class="editor-sidebar-head">
    <div><strong><?= e(fm_display_name(dirname($abs), basename(dirname($rel))) ?: 'root') ?></strong><span class="mono"><?= e(fm_display_path($dir) ?: '/') ?></span></div>
    <div class="editor-sidebar-actions">
      <button class="icon-btn" type="button" id="fnewFile" title="New file in this folder" aria-label="New file in this folder"><i data-lucide="file-plus"></i></button>
      <a class="icon-btn" href="<?= e(url('files',['path'=>$dir])) ?>" target="_blank" rel="opener" title="Open folder in File Manager" aria-label="Open folder in File Manager"><i data-lucide="folder-open"></i></a>
    </div>
  </div>
  <div class="editor-file-list"><?php foreach ($siblingFiles as $file): $isActive = $file['rel'] === $rel; ?><div class="editor-file-row<?= $isActive ? ' active' : '' ?>" data-path="<?= e($file['rel']) ?>" data-name="<?= e($file['name']) ?>"><a class="editor-file-link<?= $isActive ? ' active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?> title="<?= e($file['name']) ?>" href="<?= e(url('file-edit',['path'=>$file['rel']])) ?>"><i data-lucide="file-code-2"></i><span><?= e($file['name']) ?></span></a><span class="editor-file-actions"><button class="icon-btn" type="button" data-file-rename title="Rename" aria-label="Rename <?= e($file['name']) ?>"><i data-lucide="pencil"></i></button><button class="icon-btn danger" type="button" data-file-delete title="Delete" aria-label="Delete <?= e($file['name']) ?>"><i data-lucide="trash-2"></i></button></span></div><?php endforeach; ?></div>
</aside>
<section class="editor-main">
<div class="page-header">
  <div><h1 class="page-title editor-title"><?= e(basename($rel)) ?></h1><p class="page-subtitle"><span class="mono"><?= e(fm_display_path($rel)) ?></span> · <?= e(human_bytes($size)) ?></p></div>
  <div class="page-actions">
    <span id="fsaved" class="editor-save-state hidden" role="status"></span>
    <button class="btn btn-secondary btn-sm" type="button" id="fwrap" aria-pressed="false"><i data-lucide="wrap-text"></i>Wrap</button>
    <button class="btn btn-secondary btn-sm" type="button" id="ffind"><i data-lucide="search"></i>Find</button>
    <button class="icon-btn" type="button" id="ffindPrev" title="Previous match" aria-label="Previous match"><i data-lucide="chevron-up"></i></button>
    <button class="icon-btn" type="button" id="ffindNext" title="Next match" aria-label="Next match"><i data-lucide="chevron-down"></i></button>
    <button class="btn btn-primary btn-sm" type="button" id="fsave"><i data-lucide="save"></i>Save</button>
  </div>
</div>

<link rel="stylesheet" href="<?= e(asset('vendor/codemirror-5.65.16.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/codemirror-material-darker-5.65.16.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/codemirror-dialog-5.65.16.min.css')) ?>">
<div class="editor-tabs" id="editorTabs"></div>
<div class="card code-editor-card"><div class="code-editor-toolbar"><span class="badge badge-slate mono" id="fmode">text</span><span class="muted mono" id="fcursor">Ln 1, Col 1</span><span class="topbar-spacer"></span><span class="muted">Ctrl/Cmd+S save · Ctrl/Cmd+F find · F3 next · Tab indent</span></div><div class="code-editor-host"><textarea id="fedit" aria-label="File contents" class="input mono" style="width:100%;height:60vh;white-space:pre"><?= e($content) ?></textarea></div></div>
</section>
</div>

<script src="<?= e(asset('vendor/codemirror-5.65.16.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/codemirror-matchbrackets-5.65.16.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/codemirror-closebrackets-5.65.16.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/codemirror-searchcursor-5.65.16.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/codemirror-search-5.65.16.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/codemirror-dialog-5.65.16.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/codemirror-xml-5.65.16.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/codemirror-javascript-5.65.16.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/codemirror-css-5.65.16.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/codemirror-htmlmixed-5.65.16.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/codemirror-clike-5.65.16.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/codemirror-php-5.65.16.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/codemirror-yaml-5.65.16.min.js')) ?>"></script>
<script>
const FEDIT_PATH = <?= json_encode($rel) ?>;
const FEDIT_DIR = <?= json_encode($dir) ?>;
const FEDIT_EDIT_URL = <?= json_encode(url('file-edit')) ?>;
let FEDIT_HASH = <?= json_encode(hash_file('sha256', $abs) ?: '') ?>;
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
      close.addEventListener('click',async(event)=>{event.preventDefault();event.stopPropagation();if(isDirty&&!await window.Nebula.confirm({title:`Discard changes to "${item.name}"?`,message:'Your unsaved edits will be lost.',danger:true,confirmLabel:'Discard'}))return;delete drafts[item.path];persistDrafts();const wasCurrent=item.path===FEDIT_PATH;openTabs.splice(position,1);persistTabs();if(wasCurrent&&openTabs.length){allowNavigation=true;location.href=openTabs[Math.min(position,openTabs.length-1)].href;}else if(wasCurrent)window.close();else renderTabs();});
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
  if(window.CodeMirror){editor=CodeMirror.fromTextArea(ta,{mode:modes[extension]||'text/plain',theme:window.Nebula.cmTheme(),lineNumbers:true,indentUnit:2,tabSize:2,indentWithTabs:false,matchBrackets:true,autoCloseBrackets:true,lineWrapping:false,viewportMargin:20,extraKeys:{Tab:(cm)=>cm.somethingSelected()?cm.indentSelection('add'):cm.replaceSelection('  ','end')}});window.Nebula.registerCM(editor);editor.setSize('100%','68vh');editor.on('change',()=>rememberDraft(editor.getValue()));editor.on('cursorActivity',()=>{const p=editor.getCursor();document.getElementById('fcursor').textContent=`Ln ${p.line+1}, Col ${p.ch+1}`;});}
  if(dirty)setSaveState('Unsaved changes','var(--orange-400)');
  document.getElementById('fmode').textContent=extension||'text';
  const value=()=>editor?editor.getValue():ta.value;
  // One save at a time: a second Ctrl+S while the first is in flight would
  // carry the pre-save hash and be rejected as a conflict.
  let saving=false;
  const save=async()=>{if(saving)return;saving=true;const content=value();try{const res=await apiPost('file-save',{path:FEDIT_PATH,content,base_hash:FEDIT_HASH});if(res.ok){FEDIT_HASH=res.hash||FEDIT_HASH;toast('Saved','success');lastSavedContent=content;dirty=value()!==content;if(!dirty)delete drafts[FEDIT_PATH];persistDrafts();renderTabs();if(dirty)setSaveState('Unsaved changes','var(--orange-400)');else setSaveState('Saved','var(--emerald-400)',true);}else{toast(res.error||'Save failed','error');if(res.conflict)setSaveState('Changed on disk — reload before saving','var(--red-400)');}}finally{saving=false;}};
  document.getElementById('fsave')?.addEventListener('click',save);
  document.getElementById('ffind')?.addEventListener('click',()=>editor?editor.execCommand('find'):ta.focus());
  document.getElementById('ffindPrev')?.addEventListener('click',()=>editor?.execCommand('findPrev'));
  document.getElementById('ffindNext')?.addEventListener('click',()=>editor?.execCommand('findNext'));
  document.getElementById('fwrap')?.addEventListener('click',(event)=>{wrapping=!wrapping;if(editor)editor.setOption('lineWrapping',wrapping);else ta.style.whiteSpace=wrapping?'pre-wrap':'pre';event.currentTarget.classList.toggle('active',wrapping);event.currentTarget.setAttribute('aria-pressed',wrapping?'true':'false');});
  document.addEventListener('keydown',(event)=>{if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='s'){event.preventDefault();save();}if(event.key==='F3'){event.preventDefault();editor?.execCommand(event.shiftKey?'findPrev':'findNext');}});
  // ---- Sidebar file management: new / rename / delete -------------------
  const editUrl=(path)=>FEDIT_EDIT_URL+(FEDIT_EDIT_URL.includes('?')?'&':'?')+'path='+encodeURIComponent(path);
  const joinPath=(dir,name)=>dir?dir+'/'+name:name;
  const go=(href)=>{allowNavigation=true;location.href=href;};
  const forgetTab=(path)=>{const i=openTabs.findIndex((item)=>item.path===path);if(i>=0)openTabs.splice(i,1);delete drafts[path];persistTabs();persistDrafts();};
  document.getElementById('fnewFile')?.addEventListener('click',async()=>{
    const name=await window.Nebula.prompt('New file name','',{title:'New file',confirmLabel:'Create',mono:true,placeholder:'index.php'});
    if(!name)return;
    const res=await apiPost('file-mkfile',{dir:FEDIT_DIR,name:name.trim()});
    if(!res.ok){toast(res.error||'Could not create file','error');return;}
    toast('File created','success');go(editUrl(joinPath(FEDIT_DIR,name.trim())));
  });
  document.querySelectorAll('.editor-file-row').forEach((row)=>{
    const path=row.dataset.path,name=row.dataset.name;
    row.querySelector('[data-file-rename]')?.addEventListener('click',async(event)=>{
      event.preventDefault();
      const next=await window.Nebula.prompt('New name',name,{title:`Rename "${name}"`,confirmLabel:'Rename',selectStem:true,mono:true});
      if(!next||next.trim()===name)return;
      if(path===FEDIT_PATH&&dirty&&!await window.Nebula.confirm({title:'Rename with unsaved changes?',message:'Save first to keep your edits. Unsaved changes stay in this tab as a draft only.',confirmLabel:'Rename anyway'}))return;
      const res=await apiPost('file-rename',{path,name:next.trim()});
      if(!res.ok){toast(res.error||'Rename failed','error');return;}
      const newPath=joinPath(FEDIT_DIR,next.trim());
      const tab=openTabs.find((item)=>item.path===path);
      if(tab){tab.path=newPath;tab.name=next.trim();tab.href=editUrl(newPath);}
      if(drafts[path]){drafts[newPath]=drafts[path];delete drafts[path];}
      persistTabs();persistDrafts();
      toast('Renamed','success');
      go(path===FEDIT_PATH?editUrl(newPath):location.href);
    });
    row.querySelector('[data-file-delete]')?.addEventListener('click',async(event)=>{
      event.preventDefault();
      if(!await window.Nebula.confirm({title:`Delete "${name}"?`,message:'It moves to the File Manager trash, where it can be restored.',danger:true,confirmLabel:'Delete'}))return;
      const res=await apiPost('file-delete',{path});
      if(!res.ok){toast(res.error||'Delete failed','error');return;}
      forgetTab(path);
      toast(res.notice||'Moved to trash','success');
      if(path!==FEDIT_PATH){row.remove();renderTabs();return;}
      dirty=false;
      if(openTabs.length)go(openTabs[openTabs.length-1].href);
      else{const next=document.querySelector(`.editor-file-row:not([data-path="${CSS.escape(path)}"]) a`);if(next)go(next.href);else window.close();}
    });
  });
  window.addEventListener('beforeunload',(event)=>{if(dirty&&!allowNavigation){event.preventDefault();event.returnValue='';}});
});
</script>
