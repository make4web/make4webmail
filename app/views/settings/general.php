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
    <div class="m4w-setting-row"><div class="label" id="zoom-label"><?= te('settings.zoom') ?><small><?= te('settings.zoom_desc') ?></small></div><div class="btn-group flex-wrap" role="group" aria-labelledby="zoom-label">
      <?php foreach ([80, 90, 100, 110, 120] as $z): ?>
      <input type="radio" class="btn-check" name="zoom" id="zo-<?= $z ?>" value="<?= $z ?>" <?= (int) ($p['zoom'] ?? 100) === $z ? 'checked' : '' ?>><label class="btn btn-light" for="zo-<?= $z ?>"><?= $z ?> %</label>
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
    <?php foreach (['swipe_left' => 'bi-arrow-left', 'swipe_right' => 'bi-arrow-right'] as $sk => $sicon): ?>
    <div class="m4w-setting-row"><div class="label"><label for="set-<?= $sk ?>"><i class="bi <?= $sicon ?> me-1" aria-hidden="true"></i><?= te('settings.' . $sk) ?></label><small><?= te('settings.swipe_desc') ?></small></div><div><select class="form-select" id="set-<?= $sk ?>" name="<?= $sk ?>" style="max-width:280px">
      <?php foreach (M4W\Service\Users::SWIPE_ACTIONS as $sa): ?><option value="<?= $sa ?>" <?= ($p[$sk] ?? '') === $sa ? 'selected' : '' ?>><?= te('settings.swipe_' . $sa) ?></option><?php endforeach; ?>
    </select></div></div>
    <?php endforeach; ?>
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

<?php $days = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun']; $hours = M4W\Service\Notify::parseHours((string) $p['notify_hours']); ?>
<form method="post" action="<?= e(url('settings/notifications')) ?>" id="m4w-notify-form" class="m4w-section mt-4">
  <?= csrf_field() ?>
  <div class="m4w-section-title" id="notifications"><?= te('notify.title') ?></div>
  <p class="text-muted small mb-3"><?= te('notify.desc') ?></p>
  <?php if (!(int) $p['notifications']): ?><div class="alert alert-warning small"><?= te('push.pref_off') ?></div><?php endif; ?>

  <div class="m4w-setting-row"><div class="label"><label for="nt-new"><?= te('notify.new') ?></label><small><?= te('notify.new_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="nt-new" name="notify_new" value="1" <?= (int) $p['notify_new'] ? 'checked' : '' ?>></div></div>
  <div class="m4w-setting-row"><div class="label"><label for="nt-rem"><?= te('notify.reminder') ?></label><small><?= te('notify.reminder_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="nt-rem" name="notify_reminder" value="1" <?= (int) $p['notify_reminder'] ? 'checked' : '' ?>></div></div>
  <div class="m4w-setting-row" data-reminder-opts><div class="label"><label for="nt-every"><?= te('notify.reminder_every') ?></label></div><div><select class="form-select" id="nt-every" name="reminder_minutes" style="max-width:220px">
    <?php foreach (M4W\Service\Notify::REMINDER_MINUTES as $m): ?><option value="<?= $m ?>" <?= (int) $p['reminder_minutes'] === $m ? 'selected' : '' ?>><?= $m >= 60 ? te('notify.every_h', ['n' => intdiv($m, 60)]) : te('notify.every_min', ['n' => $m]) ?></option><?php endforeach; ?>
  </select></div></div>

  <div class="m4w-setting-row"><div class="label"><?= te('notify.period') ?><small><?= te('notify.period_desc') ?></small></div><div class="btn-group flex-wrap" role="group">
    <?php foreach (['always', 'custom'] as $k): ?>
    <input type="radio" class="btn-check" name="notify_period" id="np-<?= $k ?>" value="<?= $k ?>" <?= $p['notify_period'] === $k ? 'checked' : '' ?>><label class="btn btn-light" for="np-<?= $k ?>"><?= te('notify.period_' . $k) ?></label>
    <?php endforeach; ?></div></div>
  <div data-notify-hours class="mb-3" style="max-width:520px">
    <table class="table table-sm align-middle mb-0 m4w-room-hours m4w-notify-hours"><tbody>
      <?php foreach ($days as $n => $k): $h = $hours[$n]; ?>
      <tr><td><div class="form-check m-0"><input class="form-check-input" type="checkbox" name="open_<?= $n ?>" value="1" id="nh-<?= $n ?>" <?= $h ? 'checked' : '' ?>><label class="form-check-label" for="nh-<?= $n ?>"><?= te('rooms.day_' . $k) ?></label></div></td>
        <td><input class="form-control form-control-sm" type="time" name="from_<?= $n ?>" value="<?= e($h[0] ?? '08:00') ?>" aria-label="<?= te('rooms.from', ['day' => t('rooms.day_' . $k)]) ?>"></td>
        <td><input class="form-control form-control-sm" type="time" name="to_<?= $n ?>" value="<?= e($h[1] ?? '19:00') ?>" aria-label="<?= te('rooms.to', ['day' => t('rooms.day_' . $k)]) ?>"></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <div class="form-text"><?= te('notify.hours_desc') ?></div>
  </div>

  <?php $ownSounds = M4W\Service\Notify::ownSounds((int) $u['id']); ?>
  <?php foreach (M4W\Service\Notify::KINDS as $kind): ?>
  <div class="m4w-setting-row"><div class="label"><label for="ns-<?= $kind ?>"><?= te('notify.sound_' . $kind) ?></label></div><div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <select class="form-select" id="ns-<?= $kind ?>" name="sound_<?= $kind ?>" style="max-width:240px" data-sound-select>
        <option value="none" <?= $p['sound_' . $kind] === 'none' ? 'selected' : '' ?>><?= te('notify.s_none') ?></option>
        <optgroup label="<?= te('notify.classic_sounds') ?>">
          <?php foreach (M4W\Service\Notify::SOUNDS as $s): ?><option value="<?= $s ?>" <?= $p['sound_' . $kind] === $s ? 'selected' : '' ?>><?= te('notify.s_' . $s) ?></option><?php endforeach; ?>
        </optgroup>
        <optgroup label="<?= te('notify.my_sounds') ?>" data-own-sounds <?= $ownSounds ? '' : 'hidden' ?>>
          <?php foreach ($ownSounds as $os): ?><option value="own:<?= (int) $os['id'] ?>" <?= $p['sound_' . $kind] === 'own:' . $os['id'] ? 'selected' : '' ?>><?= e($os['label']) ?></option><?php endforeach; ?>
        </optgroup>
      </select>
      <button type="button" class="btn btn-light" data-listen="<?= $kind ?>"><i class="bi bi-play-fill me-1"></i><?= te('notify.listen') ?></button>
      <label class="btn btn-light mb-0" title="<?= te('notify.upload_hint') ?>"><i class="bi bi-upload me-1"></i><?= te('notify.upload') ?><input type="file" class="d-none" accept="audio/mpeg,audio/mp3,audio/ogg,audio/wav,.mp3,.ogg,.wav" data-sound-file="<?= $kind ?>"></label>
    </div>
  </div></div>
  <?php endforeach; ?>
  <div class="m4w-setting-row"><div class="label"><?= te('notify.app_version') ?><small><?= te('notify.app_version_desc') ?></small></div><div>
    <div class="small m4w-code mb-2" data-app-version="<?= e(M4W_VERSION) ?>"><?= te('notify.app_version_line', ['server' => M4W_VERSION, 'page' => '…', 'sw' => '…']) ?></div>
    <button type="button" class="btn btn-light btn-sm" data-app-update><i class="bi bi-arrow-repeat me-1"></i><?= te('notify.app_update') ?></button>
  </div></div>
  <div class="m4w-setting-row"><div class="label"><?= te('notify.android_title') ?><small><?= te('notify.android_desc') ?></small></div><div>
    <a class="btn btn-light" href="#" download data-sound-download><i class="bi bi-download me-1"></i><?= te('notify.android_save') ?></a>
    <ol class="small text-muted mt-2 mb-0 ps-3">
      <li><?= te('notify.android_step1') ?></li>
      <li><?= te('notify.android_step2') ?></li>
      <li><?= te('notify.android_step3') ?></li>
    </ol>
  </div></div>
  <div class="m4w-setting-row" data-own-list-row <?= $ownSounds ? '' : 'hidden' ?>><div class="label"><?= te('notify.my_sounds') ?><small><?= te('notify.my_sounds_desc') ?></small></div><div>
    <ul class="list-unstyled mb-0 d-flex flex-column gap-1" data-own-list>
      <?php foreach ($ownSounds as $os): ?>
      <li class="d-flex align-items-center gap-2" data-own="own:<?= (int) $os['id'] ?>"><i class="bi bi-music-note-beamed text-muted"></i><span class="flex-grow-1 text-truncate" style="max-width:260px"><?= e($os['label']) ?></span>
        <button type="button" class="btn btn-ghost btn-icon btn-sm" data-own-play title="<?= te('notify.listen') ?>" aria-label="<?= te('notify.listen') ?>"><i class="bi bi-play-fill"></i></button>
        <button type="button" class="btn btn-ghost btn-icon btn-sm text-danger" data-own-delete title="<?= te('common.delete') ?>" aria-label="<?= te('common.delete') ?>"><i class="bi bi-trash3"></i></button></li>
      <?php endforeach; ?>
    </ul>
  </div></div>
  <div class="m4w-setting-row"><div class="label"><label for="nt-vol"><?= te('notify.volume') ?></label></div><div><input type="range" class="form-range" id="nt-vol" name="sound_volume" min="0" max="100" step="5" value="<?= (int) $p['sound_volume'] ?>" style="max-width:220px"></div></div>
  <div class="m4w-setting-row"><div class="label"><label for="nt-vib"><?= te('notify.vibrate') ?></label><small><?= te('notify.vibrate_desc') ?></small></div><div class="d-flex gap-2 flex-wrap">
    <select class="form-select" id="nt-vib" name="vibrate" style="max-width:220px"><?php foreach (array_keys(M4W\Service\Notify::VIBRATIONS) as $v): ?><option value="<?= $v ?>" <?= $p['vibrate'] === $v ? 'selected' : '' ?>><?= te('notify.v_' . $v) ?></option><?php endforeach; ?></select>
    <button type="button" class="btn btn-light" data-try-vibrate><i class="bi bi-phone-vibrate me-1"></i><?= te('notify.try') ?></button>
  </div></div>
  <p class="small text-muted mb-3"><i class="bi bi-info-circle me-1"></i><?= te('notify.limits') ?></p>
  <div class="text-end"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= te('common.save') ?></button></div>
</form>

<?php if (M4W\Service\WebPush::enabled()): $devices = M4W\Service\WebPush::devices((int) $u['id']); ?>
<div class="m4w-section mt-4" id="m4w-push" data-state="">
  <div class="m4w-section-title" id="mobile"><?= te('push.title') ?></div>
  <p class="text-muted small mb-3"><?= te('push.desc') ?></p>
  <div class="m4w-push-status">
    <div data-push-state="off" class="d-none">
      <p class="mb-2"><?= te('push.state_off') ?></p>
      <button type="button" class="btn btn-primary" data-push-enable><i class="bi bi-bell me-1"></i><?= te('push.enable') ?></button>
    </div>
    <div data-push-state="on" class="d-none">
      <p class="mb-2"><i class="bi bi-check-circle-fill text-success me-1"></i><?= te('push.state_on') ?></p>
      <button type="button" class="btn btn-light" data-push-test><i class="bi bi-send me-1"></i><?= te('push.test') ?></button>
      <button type="button" class="btn btn-light" data-push-disable><i class="bi bi-bell-slash me-1"></i><?= te('push.disable') ?></button>
    </div>
    <div data-push-state="denied" class="d-none"><div class="alert alert-warning mb-0"><?= te('push.state_denied') ?></div></div>
    <div data-push-state="ios-install" class="d-none"><div class="alert alert-info mb-0"><i class="bi bi-phone me-1"></i><?= te('push.state_ios') ?></div></div>
    <div data-push-state="unsupported" class="d-none"><div class="alert alert-secondary mb-0"><?= te('push.state_unsupported') ?></div></div>
  </div>
  <?php if (!(int) ($p['notifications'] ?? 1)): ?><div class="alert alert-warning mt-3 mb-0 small"><?= te('push.pref_off') ?></div><?php endif; ?>

  <div class="d-flex align-items-center gap-2 mt-4 mb-2"><h3 class="fs-6 fw-semibold mb-0 flex-grow-1"><?= te('push.devices') ?></h3>
    <?php if ($devices): ?><button type="button" class="btn btn-light btn-sm" data-push-test-all><i class="bi bi-broadcast me-1"></i><?= te('push.test_all') ?></button><?php endif; ?></div>
  <?php if ($devices): ?><p class="small text-muted mb-2"><?= te('push.test_all_desc') ?></p>
  <div class="table-responsive mb-3 d-none" data-test-table>
    <table class="table table-sm align-middle small mb-1">
      <thead><tr><th><?= te('push.t_device') ?></th><th><?= te('push.t_state') ?></th><th><?= te('push.t_status') ?></th><th class="text-end"><?= te('push.t_delay') ?></th></tr></thead>
      <tbody></tbody>
    </table>
    <div class="small text-muted" data-test-note></div>
  </div><?php endif; ?>
  <?php if (!$devices): ?>
  <p class="text-muted small mb-0"><?= te('push.no_devices') ?></p>
  <?php else: ?>
  <ul class="list-group m4w-push-devices">
    <?php foreach ($devices as $d): ?>
    <li class="list-group-item d-flex align-items-center gap-3" data-device data-id="<?= (int) $d['id'] ?>" data-hash="<?= e($d['endpoint_hash']) ?>">
      <i class="bi bi-<?= e(M4W\Service\WebPush::deviceIcon($d['user_agent'])) ?> fs-5 text-muted"></i>
      <div class="flex-grow-1 min-w-0">
        <div class="fw-medium"><?= e(M4W\Service\WebPush::deviceLabel($d['user_agent'])) ?> <span class="badge badge-soft-primary ms-1 d-none" data-this-device><?= te('push.this_device') ?></span></div>
        <div class="small text-muted"><?= te('push.added', ['date' => date('d/m/Y', (int) $d['created_at'])]) ?> · <?= te('push.last_used', ['date' => date('d/m/Y H:i', (int) $d['last_used_at'])]) ?></div>

      </div>
      <button type="button" class="btn btn-ghost btn-icon btn-sm" data-device-remove title="<?= te('push.remove') ?>" aria-label="<?= te('push.remove') ?>"><i class="bi bi-x-lg"></i></button>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
<?php endif; ?>
