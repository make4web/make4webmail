<?php $isNew = !(int) $tpl['id']; ?>
<div class="m4w-page-header">
  <div class="d-flex align-items-center gap-3"><a class="btn btn-ghost btn-icon" href="<?= e(url('admin/signatures')) ?>"><i class="bi bi-arrow-left"></i></a>
  <div><h1><?= $isNew ? te('admin.sig_new') : e($tpl['name']) ?></h1><p class="mb-0"><?= te('admin.sig_form_desc') ?></p></div></div>
</div>
<form method="post" action="<?= e(url('admin/signatures/save')) ?>" id="sig-form">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $tpl['id'] ?>">
  <div class="row g-3 mb-3">
    <div class="col-md-5"><label class="form-label"><?= te('admin.sig_name') ?></label><input class="form-control" name="name" value="<?= e($tpl['name']) ?>" required></div>
    <div class="col-md-7"><label class="form-label"><?= te('admin.sig_description') ?></label><input class="form-control" name="description" value="<?= e($tpl['description']) ?>"></div>
  </div>
  <div class="row g-4">
    <div class="col-xl-6">
      <div class="d-flex align-items-center mb-2"><label class="form-label mb-0 flex-grow-1"><?= te('admin.sig_html') ?></label>
        <div class="btn-group btn-group-sm"><button type="button" class="btn btn-light" data-sig-reset title="<?= te('admin.sig_reset') ?>"><i class="bi bi-arrow-counterclockwise"></i></button></div></div>
      <textarea class="form-control m4w-code" name="html" id="sig-html" spellcheck="false" rows="18"><?= e($tpl['html']) ?></textarea>
      <div class="mt-2 small text-muted mb-1"><?= te('admin.sig_vars') ?></div>
      <div class="d-flex flex-wrap gap-1">
        <?php foreach ($variables as $v): ?><button type="button" class="m4w-var-chip" data-insert="{{<?= e($v) ?>}}">{{<?= e($v) ?>}}</button><?php endforeach; ?>
      </div>
      <div class="small text-muted mt-2"><i class="bi bi-lightbulb"></i> <?= te('admin.sig_sections') ?> <code>{{#phone}}…{{/phone}}</code></div>
    </div>
    <div class="col-xl-6">
      <div class="d-flex align-items-center mb-2 gap-2"><label class="form-label mb-0 flex-grow-1"><?= te('admin.sig_preview') ?></label>
        <select class="form-select form-select-sm w-auto" id="sig-preview-user"><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === (int) $currentUser['id'] ? 'selected' : '' ?>><?= e($u['display_name'] ?: $u['email']) ?></option><?php endforeach; ?></select></div>
      <div class="m4w-preview-box" id="sig-preview" style="min-height:240px"></div>
      <div class="card mt-3"><div class="card-body small">
        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="is_default" value="1" id="s-def" <?= $tpl['is_default'] ? 'checked' : '' ?>><label class="form-check-label" for="s-def"><?= te('admin.sig_is_default') ?></label></div>
        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="apply_on_reply" value="1" id="s-rep" <?= $tpl['apply_on_reply'] ? 'checked' : '' ?>><label class="form-check-label" for="s-rep"><?= te('admin.sig_on_reply') ?></label></div>
        <hr><div class="text-muted"><i class="bi bi-shield-check"></i> <?= te('admin.sig_security') ?></div>
      </div></div>
    </div>
  </div>
  <div class="m4w-sticky-actions">
    <?php if (!$isNew): ?><button type="button" class="btn btn-outline-danger me-auto" data-submit-form="#sig-delete"><i class="bi bi-trash3 me-1"></i><?= te('common.delete') ?></button><?php endif; ?>
    <a class="btn btn-light" href="<?= e(url('admin/signatures')) ?>"><?= te('common.cancel') ?></a><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= te('common.save') ?></button>
  </div>
</form>
<?php if (!$isNew): ?><form method="post" action="<?= e(url('admin/signatures/delete')) ?>" id="sig-delete" data-confirm="<?= te('admin.sig_confirm_delete') ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $tpl['id'] ?>"></form><?php endif; ?>
<script type="application/json" id="sig-default"><?= json_encode(M4W\Service\Signatures::defaultTemplate(), JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
