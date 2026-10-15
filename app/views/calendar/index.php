<?php
/** @var array $calendars @var array $people @var array $user */
$boot = ['calendars' => $calendars, 'me' => ['email' => mb_strtolower($user['email']), 'name' => $user['name']],
    'people' => array_map(static fn($p) => ['id' => (int) $p['id'], 'email' => $p['email'], 'name' => $p['display_name'] ?: trim($p['first_name'] . ' ' . $p['last_name']) ?: $p['email']], $people),
    'meetings' => M4W\Service\Meetings::available($user),
    'colors' => M4W\Service\Calendar::COLORS, 'week_start' => 1, 'lang' => M4W\Core\I18n::language()];
?>
<script type="application/json" id="cal-boot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<div class="m4w-cal" data-cal>
  <div class="m4w-cal-bar">
    <button type="button" class="btn btn-light btn-sm" data-cal-today><?= te('cal.today') ?></button>
    <div class="btn-group">
      <button type="button" class="btn btn-ghost btn-icon btn-sm" data-cal-prev aria-label="<?= te('cal.previous') ?>" title="<?= te('cal.previous') ?>"><i class="bi bi-chevron-left"></i></button>
      <button type="button" class="btn btn-ghost btn-icon btn-sm" data-cal-next aria-label="<?= te('cal.next') ?>" title="<?= te('cal.next') ?>"><i class="bi bi-chevron-right"></i></button>
    </div>
    <h1 class="m4w-cal-title" data-cal-title aria-live="polite"></h1>
    <div class="btn-group ms-auto" role="group" aria-label="<?= te('cal.view') ?>">
      <?php foreach (['day', 'week', 'month', 'list'] as $v): ?>
      <button type="button" class="btn btn-light btn-sm" data-cal-view="<?= $v ?>" aria-pressed="false"><?= te('cal.view_' . $v) ?></button>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="m4w-cal-body" data-cal-body></div>
</div>

<div class="modal fade" id="cal-event" tabindex="-1" aria-labelledby="cal-event-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <form class="modal-content" data-cal-form novalidate>
      <div class="modal-header">
        <h2 class="modal-title fs-5" id="cal-event-title"><?= te('cal.event') ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= te('common.close') ?>"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info py-2 d-none" data-cal-invite role="status"></div>
        <div class="mb-3"><label class="form-label" for="cal-f-title"><?= te('cal.f_title') ?></label><input class="form-control form-control-lg" id="cal-f-title" name="title" maxlength="250" required></div>
        <div class="row g-2 align-items-end mb-2">
          <div class="col-sm-6"><label class="form-label" for="cal-f-start"><?= te('cal.f_start') ?></label>
            <div class="d-flex gap-2"><input type="date" class="form-control" id="cal-f-start" name="start_date" required><input type="time" class="form-control" name="start_time" aria-label="<?= te('cal.f_start_time') ?>" step="300"></div></div>
          <div class="col-sm-6"><label class="form-label" for="cal-f-end"><?= te('cal.f_end') ?></label>
            <div class="d-flex gap-2"><input type="date" class="form-control" id="cal-f-end" name="end_date" required><input type="time" class="form-control" name="end_time" aria-label="<?= te('cal.f_end_time') ?>" step="300"></div></div>
        </div>
        <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="cal-f-allday" name="all_day"><label class="form-check-label" for="cal-f-allday"><?= te('cal.f_all_day') ?></label></div>
        <div class="row g-2 mb-3">
          <div class="col-sm-6"><label class="form-label" for="cal-f-repeat"><?= te('cal.f_repeat') ?></label>
            <select class="form-select" id="cal-f-repeat" name="repeat">
              <?php foreach (['none', 'daily', 'weekdays', 'weekly', 'biweekly', 'monthly', 'yearly', 'custom'] as $r): ?><option value="<?= $r ?>"<?= $r === 'custom' ? ' hidden' : '' ?>><?= te('cal.repeat_' . $r) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-sm-6" data-cal-until><label class="form-label" for="cal-f-until"><?= te('cal.f_until') ?></label><input type="date" class="form-control" id="cal-f-until" name="repeat_until"></div>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-sm-6"><label class="form-label" for="cal-f-cal"><?= te('cal.f_calendar') ?></label><select class="form-select" id="cal-f-cal" name="calendar_id"></select></div>
          <div class="col-sm-6"><label class="form-label" for="cal-f-reminder"><?= te('cal.f_reminder') ?></label>
            <select class="form-select" id="cal-f-reminder" name="reminder">
              <?php foreach ([-1, 0, 5, 10, 15, 30, 60, 120, 1440, 2880, 10080] as $m): ?><option value="<?= $m ?>"><?= te('cal.reminder_' . ($m < 0 ? 'none' : $m)) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="mb-3" data-cal-meetrow>
          <label class="form-label" for="cal-f-meeting"><?= te('cal.f_meeting') ?></label>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <select class="form-select" style="max-width:20rem" id="cal-f-meeting" name="meeting"><option value=""><?= te('cal.no_meeting') ?></option></select>
            <a class="btn btn-soft d-none" data-cal-join target="_blank" rel="noopener noreferrer"><i class="bi bi-camera-video me-1"></i><?= te('cal.join') ?></a>
            <button type="button" class="btn btn-ghost btn-sm d-none" data-cal-copyjoin><i class="bi bi-clipboard me-1"></i><?= te('cal.copy_link') ?></button>
          </div>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-sm-6" data-cal-roomrow><label class="form-label" for="cal-f-room"><?= te('cal.f_room') ?></label>
            <select class="form-select" id="cal-f-room" name="room_id" aria-describedby="cal-f-room-help"><option value="0"><?= te('cal.no_room') ?></option></select>
            <div class="form-text" id="cal-f-room-help" data-cal-roomhelp role="status"></div></div>
          <div class="col-sm-6"><label class="form-label" for="cal-f-location"><?= te('cal.f_location') ?></label><input class="form-control" id="cal-f-location" name="location" maxlength="500"></div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="cal-f-attendee"><?= te('cal.f_attendees') ?></label>
          <div class="m4w-cal-attendees" data-cal-attendees></div>
          <div class="position-relative"><input class="form-control" id="cal-f-attendee" data-cal-attendee-input autocomplete="off" placeholder="<?= te('cal.f_attendee_ph') ?>" aria-describedby="cal-f-attendee-help">
            <div class="dropdown-menu w-100" data-cal-suggest></div></div>
          <div class="form-text" id="cal-f-attendee-help"><?= te('cal.f_attendees_help') ?></div>
          <div class="m4w-cal-busy d-none" data-cal-busy></div>
        </div>
        <div class="mb-1"><label class="form-label" for="cal-f-desc"><?= te('cal.f_description') ?></label><textarea class="form-control" id="cal-f-desc" name="description" rows="3" maxlength="20000"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto d-none" data-cal-delete><i class="bi bi-trash me-1"></i><?= te('common.delete') ?></button>
        <div class="btn-group d-none" data-cal-answer role="group" aria-label="<?= te('cal.your_answer') ?>">
          <button type="button" class="btn btn-light" data-partstat="ACCEPTED"><i class="bi bi-check-lg me-1 text-success"></i><?= te('cal.accept') ?></button>
          <button type="button" class="btn btn-light" data-partstat="TENTATIVE"><i class="bi bi-question-lg me-1 text-warning"></i><?= te('cal.tentative') ?></button>
          <button type="button" class="btn btn-light" data-partstat="DECLINED"><i class="bi bi-x-lg me-1 text-danger"></i><?= te('cal.decline') ?></button>
        </div>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= te('common.cancel') ?></button>
        <button type="submit" class="btn btn-primary" data-cal-save><?= te('common.save') ?></button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="cal-calendar" tabindex="-1" aria-labelledby="cal-calendar-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" data-cal-calform novalidate>
      <div class="modal-header"><h2 class="modal-title fs-5" id="cal-calendar-title"><?= te('cal.calendar') ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= te('common.close') ?>"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label" for="cal-c-name"><?= te('cal.c_name') ?></label><input class="form-control" id="cal-c-name" name="name" maxlength="190" required></div>
        <fieldset class="mb-3"><legend class="form-label m4w-legend"><?= te('cal.c_color') ?></legend><div class="m4w-cal-colors" data-cal-colors></div></fieldset>
        <div data-cal-share>
          <label class="form-label" for="cal-c-person"><?= te('cal.c_share') ?></label>
          <div class="m4w-cal-acl" data-cal-acl></div>
          <div class="d-flex gap-2 mt-2">
            <select class="form-select form-select-sm" id="cal-c-person" data-cal-acl-person></select>
            <select class="form-select form-select-sm" style="max-width:10rem" data-cal-acl-level aria-label="<?= te('cal.c_level') ?>"><option value="read"><?= te('cal.level_read') ?></option><option value="write"><?= te('cal.level_write') ?></option></select>
            <button type="button" class="btn btn-light btn-sm text-nowrap" data-cal-acl-add><?= te('cal.add') ?></button>
          </div>
          <div class="form-text"><?= te('cal.c_share_help') ?></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto d-none" data-cal-c-delete><i class="bi bi-trash me-1"></i><?= te('cal.c_delete') ?></button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= te('common.cancel') ?></button>
        <button type="submit" class="btn btn-primary"><?= te('common.save') ?></button>
      </div>
    </form>
  </div>
</div>
<form class="d-none" data-cal-importform><input type="file" name="file" accept=".ics,text/calendar" data-cal-importfile aria-label="<?= te('cal.import') ?>"></form>
