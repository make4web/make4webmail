<?php $u = $currentUser; $p = $u['prefs']; $fonts = M4W\Controller\SettingsController::fonts(); ?>
<div class="m4w-page-header">
  <div><h1><?= te('settings.general_title') ?></h1><p><?= te('settings.general_desc') ?></p></div>
</div>
<form method="post" action="<?= e(url('settings')) ?>">
  <?= csrf_field() ?>
  <div class="m4w-section">
    <div class="d-flex align-items-center gap-3 mb-4">
      <span class="m4w-avatar xl" style="background:<?= e(avatar_color($u['email'])) ?>"><?= e(initials($u['name'])) ?></span>
      <div>
        <div class="fw-semibold fs-5"><?= e($u['name']) ?></div>
        <div class="text-muted"><?= e($u['email']) ?></div>
        <div class="small text-muted mt-1"><?= e($u['job_title']) ?><?= $u['department'] ? ' · ' . e($u['department']) : '' ?> <i class="bi bi-info-circle ms-1" role="img" tabindex="0" data-bs-toggle="tooltip" title="<?= te('settings.admin_managed') ?>" aria-label="<?= te('settings.admin_managed') ?>"></i></div>
      </div>
    </div>
    <div class="m4w-section-title"><?= te('settings.profile') ?></div>
    <div class="m4w-section-desc"><?= te('settings.profile_desc') ?></div>
    <div class="row g-3" style="max-width:760px">
      <div class="col-md-6"><label class="form-label"><?= te('user.first_name') ?></label><input class="form-control" name="first_name" value="<?= e($u['first_name']) ?>"></div>
      <div class="col-md-6"><label class="form-label"><?= te('user.last_name') ?></label><input class="form-control" name="last_name" value="<?= e($u['last_name']) ?>"></div>
      <div class="col-md-6"><label class="form-label"><?= te('user.display_name') ?></label><input class="form-control" name="display_name" value="<?= e($u['display_name']) ?>"></div>
      <div class="col-md-6"><label class="form-label"><?= te('user.language') ?></label><select class="form-select" name="language"><?php foreach ($languages as $c => $l): ?><option value="<?= $c ?>" <?= $u['language'] === $c ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-6"><label class="form-label"><?= te('user.phone') ?></label><input class="form-control" name="phone" value="<?= e($u['phone']) ?>"></div>
      <div class="col-md-6"><label class="form-label"><?= te('user.mobile') ?></label><input class="form-control" name="mobile" value="<?= e($u['mobile']) ?>"></div>
    </div>
  </div>

  <div class="m4w-section">
    <div class="m4w-section-title"><?= te('settings.appearance') ?></div>
    <div class="m4w-section-desc"><?= te('settings.appearance_desc') ?></div>
    <div class="m4w-setting-row"><div class="label"><?= te('settings.theme') ?></div><div class="btn-group" role="group">
      <?php foreach (['light' => 'sun', 'dark' => 'moon-stars', 'auto' => 'circle-half'] as $k => $i): ?>
      <input type="radio" class="btn-check" name="theme" id="th-<?= $k ?>" value="<?= $k ?>" <?= $p['theme'] === $k ? 'checked' : '' ?>><label class="btn btn-light" for="th-<?= $k ?>"><i class="bi bi-<?= $i ?> me-1"></i><?= te('settings.theme_' . $k) ?></label>
      <?php endforeach; ?></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('settings.density') ?></div><div class="btn-group" role="group">
      <?php foreach (['comfortable', 'compact'] as $k): ?>
      <input type="radio" class="btn-check" name="density" id="de-<?= $k ?>" value="<?= $k ?>" <?= $p['density'] === $k ? 'checked' : '' ?>><label class="btn btn-light" for="de-<?= $k ?>"><?= te('settings.density_' . $k) ?></label>
      <?php endforeach; ?></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('settings.reading_pane') ?></div><div class="btn-group" role="group">
      <?php foreach (['right' => 'layout-split', 'bottom' => 'layout-text-window-reverse', 'off' => 'list-ul'] as $k => $i): ?>
      <input type="radio" class="btn-check" name="reading_pane" id="rp-<?= $k ?>" value="<?= $k ?>" <?= $p['reading_pane'] === $k ? 'checked' : '' ?>><label class="btn btn-light" for="rp-<?= $k ?>"><i class="bi bi-<?= $i ?> me-1"></i><?= te('settings.pane_' . $k) ?></label>
      <?php endforeach; ?></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('settings.page_size') ?></div><div><select class="form-select" name="page_size" style="max-width:160px"><?php foreach ([25, 50, 100, 200] as $n): ?><option <?= (int) $p['page_size'] === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></div></div>
  </div>

  <div class="m4w-section">
    <div class="m4w-section-title"><?= te('settings.reading') ?></div>
    <div class="m4w-setting-row"><div class="label"><?= te('settings.conversations') ?><small><?= te('settings.conversations_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="conversations" value="1" <?= $p['conversations'] ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('settings.show_images') ?><small><?= te('settings.show_images_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="show_images" value="1" <?= $p['show_images'] ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('settings.mark_read') ?></div><div><select class="form-select" name="mark_read_delay" style="max-width:280px">
      <?php foreach ([0 => 'settings.mr_immediate', 1 => 'settings.mr_1', 3 => 'settings.mr_3', -1 => 'settings.mr_manual'] as $v => $l): ?><option value="<?= $v ?>" <?= (int) $p['mark_read_delay'] === $v ? 'selected' : '' ?>><?= te($l) ?></option><?php endforeach; ?>
    </select></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('settings.notifications') ?><small><?= te('settings.notifications_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="notifications" value="1" <?= $p['notifications'] ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('settings.shortcuts') ?><small><?= te('settings.shortcuts_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="shortcuts" value="1" <?= $p['shortcuts'] ? 'checked' : '' ?>></div></div>
  </div>

  <div class="m4w-section">
    <div class="m4w-section-title"><?= te('settings.writing') ?></div>
    <div class="m4w-setting-row"><div class="label"><?= te('settings.compose_font') ?></div><div class="d-flex gap-2 flex-wrap">
      <select class="form-select" name="compose_font" style="max-width:220px"><?php foreach ($fonts as $f => $l): ?><option value="<?= e($f) ?>" <?= $p['compose_font'] === $f ? 'selected' : '' ?> style="font-family:<?= e($f) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
      <select class="form-select" name="compose_size" style="max-width:120px"><?php foreach (['12px', '13px', '14px', '16px', '18px'] as $s): ?><option <?= $p['compose_size'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
    </div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('settings.undo_send') ?><small><?= te('settings.undo_send_desc', ['n' => (int) M4W\Core\Settings::get('features.undo_send_seconds', 5)]) ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="undo_send" value="1" <?= $p['undo_send'] ? 'checked' : '' ?>></div></div>
  </div>
  <div class="m4w-sticky-actions"><a href="<?= e(url('mail')) ?>" class="btn btn-light"><?= te('common.cancel') ?></a><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= te('common.save') ?></button></div>
</form>
