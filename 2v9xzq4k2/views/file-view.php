<?php
/** @var string $rel */ /** @var bool $is_text */ /** @var ?string $content */ /** @var int $size */
$dir = trim(dirname($rel), '.'); $dir = $dir === '/' ? '' : $dir;
?>
<div class="page-header">
  <div>
    <div class="breadcrumb"><a href="<?= e(url('files', ['path' => $dir])) ?>"><i data-lucide="corner-left-up"></i>Back to folder</a></div>
    <h1 class="page-title" style="font-size:18px"><?= e(basename($rel)) ?></h1>
    <p class="page-subtitle"><span class="mono"><?= e($rel) ?></span> · <?= e(human_bytes($size)) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-secondary" href="<?= e(url('file-download', ['path' => $rel])) ?>"><i data-lucide="download"></i>Download</a>
  </div>
</div>

<div class="card">
  <?php if ($is_text): ?>
    <pre class="mono" style="margin:0;padding:18px;overflow:auto;font-size:12.5px;line-height:1.6;max-height:70vh"><?= e($content) ?></pre>
  <?php else: ?>
    <div class="empty-state">
      <div class="es-icon"><i data-lucide="file-lock-2"></i></div>
      <div style="font-weight:600;color:var(--text-secondary)">Preview unavailable</div>
      <div style="font-size:13px;margin-top:4px">This file is binary or too large to display inline. Use Download instead.</div>
    </div>
  <?php endif; ?>
</div>
<script>window.NEBULA_PAGE = 'file-view';</script>
