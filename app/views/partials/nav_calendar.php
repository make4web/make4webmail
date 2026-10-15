<?php $onCal = ($req->path ?? '') === '/calendar'; ?>
<?php if ($onCal): ?><button type="button" class="m4w-compose-btn mt-2" data-cal-new><i class="bi bi-plus-lg"></i><?= te('cal.new_event') ?></button>
<?php else: ?><a class="m4w-compose-btn mt-2 text-decoration-none" href="<?= e(url('calendar')) ?>"><i class="bi bi-calendar3"></i><?= te('nav.calendar') ?></a><?php endif; ?>
<?php if ($onCal): ?><div class="m4w-cal-mini" data-cal-mini role="group" aria-label="<?= te('cal.mini') ?>"></div><?php endif; ?>
<div class="m4w-cal-lists" data-cal-lists></div>
<ul class="m4w-nav mt-1">
  <?php if ($onCal): ?><li><button type="button" class="m4w-nav-item w-100 border-0 bg-transparent text-start" data-cal-add><i class="bi bi-plus-circle"></i><span class="m4w-nav-label"><?= te('cal.add_calendar') ?></span></button></li>
  <?php if (($currentUser['role'] ?? '') === 'admin'): ?><li><button type="button" class="m4w-nav-item w-100 border-0 bg-transparent text-start" data-cal-add-room><i class="bi bi-door-open"></i><span class="m4w-nav-label"><?= te('cal.add_room') ?></span></button></li><?php endif; ?><?php endif; ?>
  <?php $rp = M4W\Service\Rooms::managedBy($currentUser) ? M4W\Service\Rooms::pendingCount($currentUser) : -1; ?>
  <?php if ($rp >= 0 || ($currentUser['role'] ?? '') === 'admin'): ?><li><a class="m4w-nav-item<?= ($req->path ?? '') === '/calendar/rooms' ? ' active' : '' ?>" href="<?= e(url('calendar/rooms')) ?>"><i class="bi bi-door-open"></i><span class="m4w-nav-label"><?= te('rooms.nav') ?></span><?= $rp > 0 ? '<span class="m4w-count ms-auto">' . $rp . '</span>' : '' ?></a></li><?php endif; ?>
  <li><a class="m4w-nav-item" href="<?= e(url('settings/sync')) ?>"><i class="bi bi-phone"></i><span class="m4w-nav-label"><?= te('cal.sync_phone') ?></span></a></li>
</ul>
