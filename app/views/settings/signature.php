<?php
/** @var string $preview @var ?array $template @var array $opts @var array $currentUser */
$mode = (string) ($currentUser['prefs']['sig_notice_mode'] ?? '');
$own = (string) ($currentUser['prefs']['sig_notice'] ?? '');
?>
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
  <p class="small text-muted mt-2"><i class="bi bi-info-circle"></i> <?= te('sig.fields_hint') ?></p>
</div>
<?php if ($template && $opts['notice_user']): ?>
<div class="m4w-section">
  <div class="m4w-section-title d-flex align-items-center gap-2"><i class="bi bi-megaphone text-primary"></i><?= te('sig.notice_title') ?></div>
  <div class="m4w-section-desc"><?= te('sig.notice_user_desc') ?></div>
  <form method="post" action="<?= e(url('settings/signature')) ?>" style="max-width:760px" data-sig-notice-form>
    <?= csrf_field() ?>
    <div class="form-check"><input class="form-check-input" type="radio" name="notice_mode" value="" id="n-default" <?= $mode === '' ? 'checked' : '' ?>>
      <label class="form-check-label" for="n-default"><?= te('sig.notice_mode_default') ?></label>
      <div class="small text-muted"><?= $opts['notice'] !== '' ? nl2br(e($opts['notice']), false) : te('sig.notice_company_empty') ?></div></div>
    <div class="form-check mt-2"><input class="form-check-input" type="radio" name="notice_mode" value="custom" id="n-custom" <?= $mode === 'custom' ? 'checked' : '' ?>>
      <label class="form-check-label" for="n-custom"><?= te('sig.notice_mode_custom') ?></label></div>
    <textarea class="form-control mt-1" name="notice" rows="3" maxlength="<?= M4W\Service\Signatures::NOTICE_MAX ?>" placeholder="<?= te('sig.notice_placeholder') ?>" data-sig-own-notice><?= e($own) ?></textarea>
    <div class="form-check mt-2"><input class="form-check-input" type="radio" name="notice_mode" value="none" id="n-none" <?= $mode === 'none' ? 'checked' : '' ?>>
      <label class="form-check-label" for="n-none"><?= te('sig.notice_mode_none') ?></label></div>
    <button class="btn btn-primary mt-3"><i class="bi bi-check2 me-1"></i><?= te('common.save') ?></button>
  </form>
</div>
<?php endif; ?>
