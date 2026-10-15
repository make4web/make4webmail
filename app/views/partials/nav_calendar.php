<button type="button" class="m4w-compose-btn mt-2" data-cal-new><i class="bi bi-plus-lg"></i><?= te('cal.new_event') ?></button>
<div class="m4w-cal-mini" data-cal-mini aria-label="<?= te('cal.mini') ?>"></div>
<div class="m4w-cal-lists" data-cal-lists></div>
<ul class="m4w-nav mt-1">
  <li><button type="button" class="m4w-nav-item w-100 border-0 bg-transparent text-start" data-cal-add><i class="bi bi-plus-circle"></i><span class="m4w-nav-label"><?= te('cal.add_calendar') ?></span></button></li>
  <?php if (($currentUser['role'] ?? '') === 'admin'): ?><li><button type="button" class="m4w-nav-item w-100 border-0 bg-transparent text-start" data-cal-add-room><i class="bi bi-door-open"></i><span class="m4w-nav-label"><?= te('cal.add_room') ?></span></button></li><?php endif; ?>
  <li><a class="m4w-nav-item" href="<?= e(url('settings/sync')) ?>"><i class="bi bi-phone"></i><span class="m4w-nav-label"><?= te('cal.sync_phone') ?></span></a></li>
</ul>
