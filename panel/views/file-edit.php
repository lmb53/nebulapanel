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
    <span id="fsaved" class="muted" style="font-size:13px;display:none">Saved</span>
    <button class="btn btn-primary" id="fsave"><i data-lucide="save"></i>Save</button>
  </div>
</div>

<div class="card">
  <div class="card-pad">
    <textarea id="fedit" class="input mono" style="width:100%;height:60vh;white-space:pre"><?= e($content) ?></textarea>
  </div>
</div>

<script>
const FEDIT_PATH = <?= json_encode($rel) ?>;
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast } = window.Nebula;
  const ta = document.getElementById('fedit');
  const saved = document.getElementById('fsaved');
  document.getElementById('fsave')?.addEventListener('click', async () => {
    const res = await apiPost('file-save', { path: FEDIT_PATH, content: ta.value });
    if (res.ok) {
      toast('Saved', 'success');
      if (saved) { saved.style.display = ''; setTimeout(() => { saved.style.display = 'none'; }, 2000); }
    } else {
      toast(res.error || 'Save failed', 'error');
    }
  });
});
</script>
