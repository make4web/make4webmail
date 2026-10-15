<?php
/** @var array $backlog @var array $rooms @var array $people @var bool $isAdmin */
$days = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
$when = static fn(array $b) => M4W\Service\Calendar::when((int) $b['start_at'], (int) $b['end_at'], false) . ((int) $b['recurring'] ? ' · ' . t('rooms.recurring') : '');
?>
<div class="m4w-page-header"><div><h1><?= te('rooms.title') ?></h1><p><?= te('rooms.desc') ?></p></div>
  <a class="btn btn-light" href="<?= e(url('calendar')) ?>"><i class="bi bi-calendar3 me-1"></i><?= te('rooms.back') ?></a></div>

<?php if ($backlog['rooms']): ?>
<div class="card mb-4" id="pending">
  <div class="card-header"><i class="bi bi-hourglass-split me-2 text-primary"></i><?= te('rooms.pending') ?> <span class="badge badge-soft-warning ms-1"><?= count($backlog['pending']) ?></span></div>
  <?php if (!$backlog['pending']): ?><div class="card-body small text-muted"><?= te('rooms.no_pending') ?></div><?php else: ?>
  <ul class="list-group list-group-flush">
    <?php foreach ($backlog['pending'] as $b): ?>
    <li class="list-group-item">
      <div class="d-flex flex-wrap gap-3 align-items-start">
        <div class="flex-grow-1 min-w-0">
          <div class="fw-semibold"><?= e($b['title'] ?: t('cal.untitled')) ?></div>
          <div class="small"><i class="bi bi-door-open me-1"></i><?= e($b['room_name']) ?> · <?= e($when($b)) ?></div>
          <div class="small text-muted"><?= te('rooms.asked_by', ['who' => $b['requester_name'] ?: $b['requester_email'], 'date' => format_datetime((int) $b['updated_at'])]) ?></div>
        </div>
        <form method="post" action="<?= e(url('calendar/rooms/booking/' . (int) $b['id'])) ?>" class="d-flex flex-wrap gap-2 align-items-center">
          <?= csrf_field() ?>
          <label class="visually-hidden" for="cm-<?= (int) $b['id'] ?>"><?= te('rooms.comment') ?></label>
          <input class="form-control form-control-sm" style="width:min(240px,60vw)" id="cm-<?= (int) $b['id'] ?>" name="comment" maxlength="500" placeholder="<?= te('rooms.comment_ph') ?>">
          <button class="btn btn-primary btn-sm" name="decision" value="approve"><i class="bi bi-check-lg me-1"></i><?= te('rooms.approve') ?></button>
          <button class="btn btn-outline-danger btn-sm" name="decision" value="decline"><i class="bi bi-x-lg me-1"></i><?= te('rooms.decline') ?></button>
        </form>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>

<div class="card mb-4">
  <div class="card-header"><i class="bi bi-calendar-check me-2 text-primary"></i><?= te('rooms.upcoming') ?></div>
  <?php if (!$backlog['upcoming']): ?><div class="card-body small text-muted"><?= te('rooms.no_upcoming') ?></div><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th><?= te('rooms.room') ?></th><th><?= te('rooms.event') ?></th><th><?= te('rooms.when') ?></th><th><?= te('rooms.requester') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($backlog['upcoming'] as $b): ?><tr>
      <td><?= e($b['room_name']) ?></td><td><?= e($b['title'] ?: t('cal.untitled')) ?></td><td class="small"><?= e($when($b)) ?></td><td class="small"><?= e($b['requester_name'] ?: $b['requester_email']) ?></td>
      <td class="text-end"><form method="post" action="<?= e(url('calendar/rooms/booking/' . (int) $b['id'])) ?>" data-confirm="<?= te('rooms.cancel_confirm') ?>"><?= csrf_field() ?><input type="hidden" name="decision" value="decline"><button class="btn btn-ghost btn-sm text-danger"><?= te('rooms.cancel_booking') ?></button></form></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<h2 class="h5 mt-4 mb-3"><?= te('rooms.settings') ?></h2>
<?php if (!$rooms): ?><p class="text-muted"><?= te('rooms.none') ?></p><?php endif; ?>
<?php foreach ($rooms as $id => $r): $c = $r['config']; ?>
<form method="post" action="<?= e(url('calendar/rooms/' . (int) $id . '/config')) ?>" class="card mb-4" id="room-<?= (int) $id ?>">
  <?= csrf_field() ?>
  <div class="card-header d-flex align-items-center gap-2"><span class="rounded-circle d-inline-block" style="width:.8rem;height:.8rem;background:<?= e($r['color']) ?>" aria-hidden="true"></span><?= e($r['name']) ?></div>
  <div class="card-body">
    <div class="row g-4">
      <div class="col-lg-6">
        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="managed" value="1" id="mg-<?= (int) $id ?>" <?= $c['managed'] ? 'checked' : '' ?>><label class="form-check-label" for="mg-<?= (int) $id ?>"><?= te('rooms.managed') ?></label></div>
        <div class="form-text mb-3"><?= te('rooms.managed_desc') ?></div>
        <label class="form-label" for="mgr-<?= (int) $id ?>"><?= te('rooms.managers') ?></label>
        <select class="form-select mb-1" id="mgr-<?= (int) $id ?>" name="managers[]" multiple size="4">
          <?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>" <?= in_array((int) $p['id'], $c['managers'], true) ? 'selected' : '' ?>><?= e(($p['display_name'] ?: $p['email']) . ' <' . $p['email'] . '>') ?></option><?php endforeach; ?>
        </select>
        <div class="form-text mb-3"><?= te('rooms.managers_desc') ?></div>
        <div class="row g-2">
          <div class="col-4"><label class="form-label" for="cap-<?= (int) $id ?>"><?= te('rooms.capacity') ?></label><input class="form-control" type="number" min="0" id="cap-<?= (int) $id ?>" name="capacity" value="<?= (int) $c['capacity'] ?: '' ?>"></div>
          <div class="col-8"><label class="form-label" for="det-<?= (int) $id ?>"><?= te('rooms.details') ?></label><input class="form-control" id="det-<?= (int) $id ?>" name="details" maxlength="500" value="<?= e($c['details']) ?>" placeholder="<?= te('rooms.details_ph') ?>"></div>
        </div>
      </div>
      <div class="col-lg-6">
        <fieldset><legend class="form-label m4w-legend"><?= te('rooms.hours') ?></legend>
        <table class="table table-sm align-middle mb-0 m4w-room-hours"><tbody>
          <?php foreach ($days as $n => $k): $h = $c['hours'][$n]; ?>
          <tr><td style="width:40%"><div class="form-check m-0"><input class="form-check-input" type="checkbox" name="open_<?= $n ?>" value="1" id="op-<?= (int) $id ?>-<?= $n ?>" <?= $h ? 'checked' : '' ?>><label class="form-check-label" for="op-<?= (int) $id ?>-<?= $n ?>"><?= te('rooms.day_' . $k) ?></label></div></td>
            <td><input class="form-control form-control-sm" type="time" name="from_<?= $n ?>" value="<?= e($h[0] ?? '08:00') ?>" aria-label="<?= te('rooms.from', ['day' => t('rooms.day_' . $k)]) ?>"></td>
            <td><input class="form-control form-control-sm" type="time" name="to_<?= $n ?>" value="<?= e(($h[1] ?? '19:00') === '24:00' ? '23:59' : ($h[1] ?? '19:00')) ?>" aria-label="<?= te('rooms.to', ['day' => t('rooms.day_' . $k)]) ?>"></td></tr>
          <?php endforeach; ?>
        </tbody></table></fieldset>
      </div>
    </div>
  </div>
  <div class="card-footer d-flex justify-content-end"><button class="btn btn-primary"><?= te('common.save') ?></button></div>
</form>
<?php endforeach; ?>
<?php endif; ?>
