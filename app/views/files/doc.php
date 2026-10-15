<?php
/** @var array $file */
$back = url('files') . '#f/' . (int) $file['folder_id'];
?>
<link rel="stylesheet" href="<?= e(asset('vendor/jodit/jodit.min.css')) ?>">
<div class="m4w-doc" data-doc data-id="<?= (int) $file['id'] ?>">
  <header class="m4w-sheet-bar">
    <a class="btn btn-ghost btn-sm" href="<?= e($back) ?>"><i class="bi bi-arrow-left me-1"></i><span class="d-none d-sm-inline"><?= te('office.back') ?></span></a>
    <i class="bi bi-file-earmark-word text-primary fs-5"></i>
    <h1 class="m4w-sheet-name text-truncate"><?= e($file['name']) ?></h1>
    <span class="m4w-sheet-status small text-muted text-truncate" data-status role="status"></span>
    <div class="m4w-sheet-people ms-auto" data-people></div>
    <span class="m4w-doc-pen badge d-none" data-pen></span>
    <button type="button" class="btn btn-light btn-sm text-nowrap d-none" data-take><i class="bi bi-pencil"></i><span class="d-none d-sm-inline ms-1"><?= te('doc.take') ?></span></button>
    <button type="button" class="btn btn-light btn-sm text-nowrap d-none" data-release><i class="bi bi-check2"></i><span class="d-none d-sm-inline ms-1"><?= te('doc.release') ?></span></button>
    <button type="button" class="btn btn-primary btn-sm text-nowrap" data-save disabled><i class="bi bi-cloud-check"></i><span class="d-none d-sm-inline ms-1"><?= te('sheet.save') ?></span></button>
  </header>
  <div class="m4w-sheet-notice d-none" data-notice role="note"></div>
  <div class="m4w-doc-desk" data-desk>
    <div class="m4w-doc-page"><textarea id="m4w-doc-editor" aria-label="<?= te('doc.content') ?>"></textarea></div>
  </div>
  <div class="m4w-sheet-loading" data-loading><span class="spinner-border text-primary"></span></div>
</div>
<script src="<?= e(asset('vendor/jodit/jodit.min.js')) ?>" defer></script>
<script src="<?= e(asset('js/doc.js')) ?>" defer></script>
