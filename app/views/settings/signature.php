<div class="m4w-page-header"><div><h1><?= te('sig.title') ?></h1><p><?= te('sig.desc') ?></p></div></div>
<div class="m4w-section">
  <div class="m4w-section-title d-flex align-items-center gap-2"><i class="bi bi-building text-primary"></i><?= te('sig.corporate') ?>
    <?php if ($template): ?><span class="badge badge-soft"><?= e($template['name']) ?></span><?php endif; ?></div>
  <div class="m4w-section-desc"><?= te('sig.corporate_desc') ?></div>
  <?php if (trim($preview) !== ''): ?>
    <div class="m4w-preview-box" style="max-width:760px"><?= $preview ?></div>
  <?php else: ?>
    <div class="alert alert-warning" style="max-width:760px"><i class="bi bi-exclamation-triangle"></i><div><?= te('sig.none') ?></div></div>
  <?php endif; ?>
  <p class="small text-muted mt-2"><i class="bi bi-info-circle"></i> <?= te('sig.fields_hint') ?> <a href="<?= e(url('settings')) ?>"><?= te('nav.profile') ?></a>.</p>
</div>
<?php if ($allowPersonal): ?>
<form method="post" action="<?= e(url('settings/signature')) ?>" class="m4w-section">
  <?= csrf_field() ?>
  <div class="m4w-section-title"><?= te('sig.personal') ?></div>
  <div class="m4w-section-desc"><?= te('sig.personal_desc') ?></div>
  <div style="max-width:760px"><textarea name="personal_signature" data-editor="minimal" placeholder="<?= te('sig.personal_ph') ?>"><?= e($currentUser['personal_signature']) ?></textarea></div>
  <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= te('common.save') ?></button></div>
</form>
<?php endif; ?>
