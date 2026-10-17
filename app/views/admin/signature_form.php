<?php
/** @var array $tpl @var array $opts (Signatures::options) @var array $variables @var array $users */
$isNew = !(int) $tpl['id'];
$custom = trim((string) $tpl['html']) !== '';
$brandLogo = (string) M4W\Core\Settings::get('brand.logo', '');
$primary = (string) M4W\Core\Settings::get('brand.primary', '#2563eb');
$nets = M4W\Service\Signatures::NETWORKS;
?>
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
      <!-- Notice above the signature -->
      <div class="card mb-3"><div class="card-body">
        <div class="fw-semibold mb-1"><i class="bi bi-megaphone me-1 text-primary"></i><?= te('sig.notice_title') ?></div>
        <div class="small text-muted mb-2"><?= te('sig.notice_admin_desc') ?></div>
        <textarea class="form-control" name="opt[notice]" rows="2" maxlength="<?= M4W\Service\Signatures::NOTICE_MAX ?>" placeholder="<?= te('sig.notice_placeholder') ?>"><?= e($opts['notice']) ?></textarea>
        <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="opt[notice_user]" value="1" id="o-nu" <?= $opts['notice_user'] ? 'checked' : '' ?>><label class="form-check-label" for="o-nu"><?= te('sig.notice_user_allowed') ?></label></div>
      </div></div>

      <!-- Layout -->
      <div class="card mb-3"><div class="card-body">
        <div class="fw-semibold mb-2"><i class="bi bi-layout-text-sidebar me-1 text-primary"></i><?= te('sig.layout_title') ?></div>
        <div class="mb-3">
          <label class="form-label small fw-semibold"><?= te('sig.logo') ?></label>
          <div class="d-flex flex-wrap gap-3 align-items-center">
            <?php foreach (['brand', 'custom', 'none'] as $l): ?>
            <div class="form-check"><input class="form-check-input" type="radio" name="opt[logo]" value="<?= $l ?>" id="o-logo-<?= $l ?>" <?= $opts['logo'] === $l ? 'checked' : '' ?>><label class="form-check-label" for="o-logo-<?= $l ?>"><?= te('sig.logo_' . $l) ?></label></div>
            <?php endforeach; ?>
          </div>
          <?php if ($brandLogo === ''): ?><div class="form-text"><?= te('sig.logo_brand_none') ?> <a href="<?= e(url('admin/branding')) ?>"><?= te('admin.brand_title') ?></a></div><?php endif; ?>
          <div class="d-flex align-items-center gap-2 mt-2<?= $opts['logo'] === 'custom' ? '' : ' d-none' ?>" data-sig-custom-logo>
            <input type="hidden" name="opt[logo_file]" value="<?= e($opts['logo_file']) ?>" id="o-logo-file">
            <img id="o-logo-img" src="<?= $opts['logo_file'] !== '' ? e(url('brand/' . $opts['logo_file'])) : '' ?>" alt="" style="max-height:40px;max-width:160px" <?= $opts['logo_file'] === '' ? 'hidden' : '' ?>>
            <label class="btn btn-light btn-sm mb-0"><i class="bi bi-upload me-1"></i><?= te('sig.logo_upload') ?><input type="file" accept="image/png,image/jpeg,image/gif,image/webp" id="o-logo-upload" hidden></label>
          </div>
          <div class="row g-2 mt-1">
            <div class="col-sm-6"><label class="form-label small" for="o-lh"><?= te('sig.logo_height') ?> <span class="text-muted" data-sig-lh><?= (int) $opts['logo_height'] ?> px</span></label><input type="range" class="form-range" min="24" max="120" step="4" name="opt[logo_height]" id="o-lh" value="<?= (int) $opts['logo_height'] ?>"></div>
            <div class="col-sm-6"><label class="form-label small d-block"><?= te('sig.logo_position') ?></label>
              <div class="btn-group btn-group-sm" role="group">
                <?php foreach (['left' => 'align-start', 'right' => 'align-end'] as $pos => $ic): ?>
                <input type="radio" class="btn-check" name="opt[logo_position]" value="<?= $pos ?>" id="o-pos-<?= $pos ?>" <?= $opts['logo_position'] === $pos ? 'checked' : '' ?>><label class="btn btn-outline-secondary" for="o-pos-<?= $pos ?>"><i class="bi bi-<?= $ic ?> me-1"></i><?= te('sig.pos_' . $pos) ?></label>
                <?php endforeach; ?>
              </div></div>
          </div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
          <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" name="opt[separator]" value="1" id="o-sep" <?= $opts['separator'] ? 'checked' : '' ?>><label class="form-check-label" for="o-sep"><?= te('sig.separator') ?></label></div>
          <div class="d-flex align-items-center gap-2<?= $opts['separator'] ? '' : ' d-none' ?>" data-sig-sep-color>
            <input type="color" class="form-control form-control-color form-control-sm" id="o-sepc-pick" value="<?= e($opts['separator_color'] ?: $primary) ?>" title="<?= te('sig.separator_color') ?>">
            <input type="hidden" name="opt[separator_color]" id="o-sepc" value="<?= e($opts['separator_color']) ?>">
            <button type="button" class="btn btn-link btn-sm p-0" data-sig-sep-reset <?= $opts['separator_color'] === '' ? 'hidden' : '' ?>><?= te('sig.separator_color_reset') ?></button>
            <span class="small text-muted" data-sig-sep-main <?= $opts['separator_color'] === '' ? '' : 'hidden' ?>><?= te('sig.separator_color_main') ?></span>
          </div>
        </div>
        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="opt[website_below]" value="1" id="o-web" <?= $opts['website_below'] ? 'checked' : '' ?>><label class="form-check-label" for="o-web"><?= te('sig.website_below') ?></label></div>
      </div></div>

      <!-- Social networks -->
      <div class="card mb-3"><div class="card-body">
        <div class="d-flex align-items-center mb-1"><div class="fw-semibold flex-grow-1"><i class="bi bi-share me-1 text-primary"></i><?= te('sig.socials') ?></div>
          <div class="btn-group btn-group-sm" role="group">
            <?php foreach (['color', 'gray'] as $st): ?>
            <input type="radio" class="btn-check" name="opt[social_style]" value="<?= $st ?>" id="o-ss-<?= $st ?>" <?= $opts['social_style'] === $st ? 'checked' : '' ?>><label class="btn btn-outline-secondary" for="o-ss-<?= $st ?>"><?= te('sig.social_' . $st) ?></label>
            <?php endforeach; ?>
          </div></div>
        <div class="small text-muted mb-2"><?= te('sig.socials_desc') ?></div>
        <div id="sig-socials">
          <?php foreach (array_merge($opts['socials'], []) as $soc): ?>
          <div class="input-group input-group-sm mb-2" data-soc-row>
            <select class="form-select" name="soc_net[]" style="max-width:150px"><?php foreach ($nets as $k => $label): ?><option value="<?= $k ?>" <?= $soc['net'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
            <input class="form-control" name="soc_url[]" value="<?= e($soc['url']) ?>" placeholder="https://…">
            <button type="button" class="btn btn-light" data-soc-remove title="<?= te('common.delete') ?>"><i class="bi bi-x-lg"></i></button>
          </div>
          <?php endforeach; ?>
        </div>
        <template id="sig-soc-row"><div class="input-group input-group-sm mb-2" data-soc-row>
          <select class="form-select" name="soc_net[]" style="max-width:150px"><?php foreach ($nets as $k => $label): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?></select>
          <input class="form-control" name="soc_url[]" placeholder="https://…">
          <button type="button" class="btn btn-light" data-soc-remove title="<?= te('common.delete') ?>"><i class="bi bi-x-lg"></i></button>
        </div></template>
        <button type="button" class="btn btn-light btn-sm" data-soc-add><i class="bi bi-plus-lg me-1"></i><?= te('sig.social_add') ?></button>
      </div></div>

      <!-- Own HTML -->
      <div class="card"><div class="card-body">
        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="custom_html" value="1" id="o-custom" <?= $custom ? 'checked' : '' ?>><label class="form-check-label fw-semibold" for="o-custom"><?= te('sig.custom_html') ?></label></div>
        <div class="small text-muted"><?= te('sig.custom_html_desc') ?></div>
        <div data-sig-html-box class="mt-2" <?= $custom ? '' : 'hidden' ?>>
          <textarea class="form-control m4w-code" name="html" id="sig-html" spellcheck="false" rows="16"><?= e($tpl['html']) ?></textarea>
          <div class="mt-2 small text-muted mb-1"><?= te('admin.sig_vars') ?></div>
          <div class="d-flex flex-wrap gap-1">
            <?php foreach ($variables as $v): ?><button type="button" class="m4w-var-chip" data-insert="{{<?= e($v) ?>}}">{{<?= e($v) ?>}}</button><?php endforeach; ?>
          </div>
          <div class="small text-muted mt-2"><i class="bi bi-lightbulb"></i> <?= te('admin.sig_sections') ?> <code>{{#phone}}…{{/phone}}</code></div>
        </div>
      </div></div>
    </div>
    <div class="col-xl-6">
      <div class="m4w-sig-preview-sticky">
        <div class="d-flex align-items-center mb-2 gap-2"><label class="form-label mb-0 flex-grow-1"><?= te('admin.sig_preview') ?></label>
          <select class="form-select form-select-sm w-auto" id="sig-preview-user"><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === (int) $currentUser['id'] ? 'selected' : '' ?>><?= e($u['display_name'] ?: $u['email']) ?></option><?php endforeach; ?></select></div>
        <div class="m4w-preview-box" id="sig-preview" style="min-height:240px"></div>
        <div class="card mt-3"><div class="card-body small">
          <?php if ($tpl['is_default']): ?>
          <div class="d-flex gap-2"><i class="bi bi-check2-circle text-success"></i><span><?= te('admin.sig_active_desc') ?></span></div>
          <?php else: ?>
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_default" value="1" id="s-def"><label class="form-check-label" for="s-def"><?= te('admin.sig_make_active') ?></label></div>
          <?php endif; ?>
          <hr><div class="text-muted"><i class="bi bi-shield-check"></i> <?= te('admin.sig_security') ?></div>
        </div></div>
      </div>
    </div>
  </div>
  <div class="m4w-sticky-actions">
    <?php if (!$isNew): ?><button type="button" class="btn btn-outline-danger me-auto" data-submit-form="#sig-delete"><i class="bi bi-trash3 me-1"></i><?= te('common.delete') ?></button><?php endif; ?>
    <a class="btn btn-light" href="<?= e(url('admin/signatures')) ?>"><?= te('common.cancel') ?></a><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= te('common.save') ?></button>
  </div>
</form>
<?php if (!$isNew): ?><form method="post" action="<?= e(url('admin/signatures/delete')) ?>" id="sig-delete" data-confirm="<?= te('admin.sig_confirm_delete') ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $tpl['id'] ?>"></form><?php endif; ?>
