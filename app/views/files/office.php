<?php
/** @var array $file @var string $action @var string $token @var int $ttl @var string $mode @var string $origin @var bool $embedded */
$back = url('files') . '#f/' . (int) $file['folder_id'];
?>
<div class="m4w-office<?= $embedded ? ' is-embedded' : '' ?>" data-office data-origin="<?= e($origin) ?>" data-back="<?= e($back) ?>">
  <?php if (!$embedded): ?>
  <header class="m4w-office-bar">
    <a class="btn btn-ghost btn-sm" href="<?= e($back) ?>" data-office-close><i class="bi bi-arrow-left me-1"></i><?= te('office.back') ?></a>
    <span class="fw-semibold text-truncate"><?= e($file['name']) ?></span>
    <span class="badge <?= $mode === 'edit' ? 'badge-soft-primary' : 'badge-soft-secondary' ?>"><?= te($mode === 'edit' ? 'office.mode_edit' : 'office.mode_view') ?></span>
    <span class="small text-muted ms-auto d-none d-md-inline"><i class="bi bi-people me-1"></i><?= te('office.collab_hint') ?></span>
  </header>
  <?php endif; ?>
  <form id="m4w-office-form" method="post" action="<?= e($action) ?>" target="m4w-office-frame">
    <input type="hidden" name="access_token" value="<?= e($token) ?>">
    <input type="hidden" name="access_token_ttl" value="<?= (int) $ttl ?>">
  </form>
  <iframe name="m4w-office-frame" id="m4w-office-frame" class="m4w-office-frame" title="<?= e($file['name']) ?>" allow="clipboard-read *; clipboard-write *; fullscreen *"></iframe>
</div>
<script src="<?= e(asset('js/office.js')) ?>"></script>
