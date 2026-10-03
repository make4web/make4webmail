<?php
use M4W\Core\Settings as S;
$img = static function (string $key, string $label, string $hint) {
    $f = (string) S::get('brand.' . $key, '');
    $u = $f !== '' ? url('brand/' . $f) : '';
    ob_start(); ?>
    <div class="mb-3">
      <label class="form-label"><?= te($label) ?></label>
      <div class="m4w-image-drop">
        <div class="thumb" data-thumb="<?= $key ?>"<?= $key === 'logo_dark' ? ' style="background-color:#0f172a"' : '' ?>><?= $u ? '<img src="' . e($u) . '" alt="">' : '<i class="bi bi-image fs-4"></i>' ?></div>
        <div class="flex-grow-1 min-w-0">
          <input type="file" class="form-control form-control-sm" name="<?= $key ?>" accept="image/png,image/jpeg,image/webp,image/gif<?= $key === 'favicon' ? ',image/x-icon' : '' ?>" data-preview-img="<?= $key ?>">
          <div class="form-text"><?= te($hint) ?></div>
          <?php if ($u): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="remove_<?= $key ?>" value="1" id="rm-<?= $key ?>"><label class="form-check-label small" for="rm-<?= $key ?>"><?= te('common.remove') ?></label></div><?php endif; ?>
        </div>
      </div>
    </div>
    <?php return ob_get_clean();
};
$presets = ['#2563eb', '#4f46e5', '#7c3aed', '#db2777', '#dc2626', '#ea580c', '#d97706', '#16a34a', '#0d9488', '#0891b2', '#0f172a'];
?>
<div class="m4w-page-header"><div><h1><?= te('admin.brand_title') ?></h1><p><?= te('admin.brand_desc') ?></p></div></div>
<form method="post" action="<?= e(url('admin/branding')) ?>" enctype="multipart/form-data" id="brand-form">
  <?= csrf_field() ?>
  <div class="row g-4">
    <div class="col-xl-7">
      <div class="card mb-4"><div class="card-header"><i class="bi bi-type me-2 text-primary"></i><?= te('admin.brand_identity') ?></div><div class="card-body"><div class="row g-3">
        <div class="col-md-6"><label class="form-label"><?= te('admin.brand_name') ?></label><input class="form-control" name="name" value="<?= e(S::get('brand.name')) ?>" data-live="name" required></div>
        <div class="col-md-6"><label class="form-label"><?= te('admin.brand_company') ?></label><input class="form-control" name="company" value="<?= e(S::get('brand.company')) ?>"></div>
        <div class="col-12"><label class="form-label"><?= te('admin.brand_tagline') ?></label><input class="form-control" name="tagline" value="<?= e(S::get('brand.tagline')) ?>"></div>
        <div class="col-md-6"><label class="form-label"><?= te('admin.brand_website') ?></label><input class="form-control" name="website" type="url" value="<?= e(S::get('brand.website')) ?>" placeholder="https://"></div>
        <div class="col-md-6"><label class="form-label"><?= te('admin.brand_address') ?></label><input class="form-control" name="address" value="<?= e(S::get('brand.address')) ?>"></div>
      </div></div></div>

      <div class="card mb-4"><div class="card-header"><i class="bi bi-images me-2 text-primary"></i><?= te('admin.brand_images') ?></div><div class="card-body">
        <?= $img('logo', 'admin.brand_logo', 'admin.brand_logo_hint') ?>
        <?= $img('logo_dark', 'admin.brand_logo_dark', 'admin.brand_logo_dark_hint') ?>
        <?= $img('favicon', 'admin.brand_favicon', 'admin.brand_favicon_hint') ?>
        <?= $img('login_bg', 'admin.brand_login_bg', 'admin.brand_login_bg_hint') ?>
      </div></div>

      <div class="card mb-4"><div class="card-header"><i class="bi bi-palette me-2 text-primary"></i><?= te('admin.brand_colors') ?></div><div class="card-body">
        <?php foreach (['primary' => 'admin.brand_primary', 'accent' => 'admin.brand_accent'] as $k => $l): $val = (string) S::get('brand.' . $k); ?>
        <div class="mb-3"><label class="form-label"><?= te($l) ?></label>
          <div class="d-flex gap-3 flex-wrap align-items-center">
            <div class="m4w-swatch-input"><input type="color" name="<?= $k ?>" value="<?= e($val) ?>" data-live="<?= $k ?>"><input class="form-control m4w-code" style="width:110px" value="<?= e($val) ?>" data-hex-for="<?= $k ?>" maxlength="7"></div>
            <div class="d-flex gap-2 m4w-presets"><?php foreach ($presets as $c): ?><button type="button" style="background:<?= $c ?>" data-preset="<?= $k ?>" data-color="<?= $c ?>" title="<?= $c ?>"></button><?php endforeach; ?></div>
          </div></div>
        <?php endforeach; ?>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label"><?= te('admin.brand_sidebar') ?></label><select class="form-select" name="sidebar" data-live="sidebar"><?php foreach (['light', 'dark', 'brand'] as $s): ?><option value="<?= $s ?>" <?= S::get('brand.sidebar') === $s ? 'selected' : '' ?>><?= te('admin.sidebar_' . $s) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label"><?= te('admin.brand_font') ?></label><select class="form-select" name="font"><option value="inter" <?= S::get('brand.font') === 'inter' ? 'selected' : '' ?>>Inter</option><option value="system" <?= S::get('brand.font') === 'system' ? 'selected' : '' ?>><?= te('admin.font_system') ?></option></select></div>
          <div class="col-12"><label class="form-label d-flex justify-content-between"><span><?= te('admin.brand_radius') ?></span><span class="text-muted" data-radius-val><?= (int) S::get('brand.radius') ?>px</span></label><input type="range" class="form-range" min="0" max="20" name="radius" value="<?= (int) S::get('brand.radius') ?>" data-live="radius"></div>
        </div>
      </div></div>

      <div class="card mb-4"><div class="card-header"><i class="bi bi-box-arrow-in-right me-2 text-primary"></i><?= te('admin.brand_login') ?></div><div class="card-body">
        <div class="mb-3"><label class="form-label"><?= te('admin.brand_login_message') ?></label><textarea class="form-control" name="login_message" rows="2" placeholder="<?= te('admin.brand_login_message_ph') ?>"><?= e(S::get('brand.login_message')) ?></textarea></div>
        <div><label class="form-label"><?= te('admin.brand_footer') ?></label><input class="form-control" name="footer" value="<?= e(S::get('brand.footer')) ?>"></div>
      </div></div>

      <div class="card"><div class="card-header"><i class="bi bi-code-slash me-2 text-primary"></i><?= te('admin.brand_css') ?></div><div class="card-body">
        <textarea class="form-control m4w-code" name="custom_css" rows="8" spellcheck="false" placeholder=".m4w-compose-btn { border-radius: 8px; }"><?= e(S::get('brand.custom_css')) ?></textarea>
        <div class="form-text"><?= te('admin.brand_css_hint') ?></div>
      </div></div>
    </div>
    <div class="col-xl-5">
      <div class="m4w-brand-preview" id="brand-preview">
        <div class="pv-top"><span class="m4w-brand-mark" data-pv-mark style="width:28px;height:28px;font-size:.85rem"><i class="bi bi-envelope-paper-heart"></i></span><span data-pv-name><?= e(S::get('brand.name')) ?></span><span class="ms-auto rounded-pill px-3 py-1 small" style="background:var(--m4w-surface-3);color:var(--m4w-muted)"><i class="bi bi-search"></i> <?= te('search.placeholder') ?></span></div>
        <div class="pv-body">
          <div class="pv-side" data-pv-side>
            <div class="rounded-4 px-3 py-2 mb-2 small fw-semibold d-inline-flex gap-2 align-items-center" style="background:var(--m4w-surface);box-shadow:var(--m4w-shadow-sm);color:var(--m4w-text)"><i class="bi bi-pencil-square" data-pv-primary-text></i><?= te('mail.compose') ?></div>
            <div class="small px-2 py-1 rounded-end-pill fw-semibold" data-pv-active><i class="bi bi-inbox-fill me-1"></i><?= te('folder.inbox') ?></div>
            <div class="small px-2 py-1" data-pv-item><i class="bi bi-send me-1"></i><?= te('folder.sent') ?></div>
            <div class="small px-2 py-1" data-pv-item><i class="bi bi-archive me-1"></i><?= te('folder.archive') ?></div>
          </div>
          <div class="pv-main">
            <div class="d-flex align-items-center gap-2 mb-2"><span class="m4w-avatar sm" data-pv-avatar>JD</span><div class="flex-grow-1"><div class="pv-line" style="width:50%"></div><div class="pv-line" style="width:80%"></div></div></div>
            <div class="pv-line" style="width:90%"></div><div class="pv-line" style="width:75%"></div><div class="pv-line" style="width:60%"></div>
            <div class="d-flex gap-2 mt-3"><span class="btn btn-primary btn-sm" data-pv-btn><?= te('mail.reply') ?></span><span class="btn btn-soft btn-sm" data-pv-soft><?= te('mail.forward') ?></span></div>
          </div>
        </div>
      </div>
      <p class="small text-muted mt-2 text-center"><i class="bi bi-eye"></i> <?= te('admin.brand_preview_hint') ?></p>
    </div>
  </div>
  <div class="m4w-sticky-actions"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= te('common.save') ?></button></div>
</form>
