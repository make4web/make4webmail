<?php
/** @var array $currentUser */
$u = $currentUser;
$isMail = ($topbarMode ?? '') === 'mail';
$prefs = $u['prefs'] ?? [];
?>
<header class="m4w-topbar" role="banner">
  <button class="btn btn-ghost btn-icon m4w-mobile-only" type="button" data-action="toggle-sidebar" aria-label="<?= te('nav.menu') ?>"><i class="bi bi-list"></i></button>
  <?php include __DIR__ . '/brand.php'; ?>
  <form class="m4w-search" role="search" action="<?= e(url('mail')) ?>" method="get" id="m4w-search-form" autocomplete="off">
    <i class="bi bi-search"></i>
    <input type="search" class="form-control" name="q" id="m4w-search" placeholder="<?= te('search.placeholder') ?>" aria-label="<?= te('search.placeholder') ?>" value="<?= e($_GET['q'] ?? '') ?>">
    <div class="m4w-search-tools">
      <button type="button" class="btn btn-ghost btn-icon btn-sm d-none" data-action="clear-search" title="<?= te('search.clear') ?>"><i class="bi bi-x-lg"></i></button>
      <?php if ($isMail): ?>
      <button type="button" class="btn btn-ghost btn-icon btn-sm" data-action="adv" data-bs-toggle="dropdown" data-bs-auto-close="outside" title="<?= te('search.advanced') ?>"><i class="bi bi-sliders2"></i></button>
      <div class="dropdown-menu dropdown-menu-end m4w-search-advanced" id="m4w-adv-search">
        <div class="row g-2">
          <div class="col-sm-6"><label class="form-label"><?= te('search.from') ?></label><input class="form-control" name="adv_from"></div>
          <div class="col-sm-6"><label class="form-label"><?= te('search.to') ?></label><input class="form-control" name="adv_to"></div>
          <div class="col-12"><label class="form-label"><?= te('search.subject') ?></label><input class="form-control" name="adv_subject"></div>
          <div class="col-12"><label class="form-label"><?= te('search.words') ?></label><input class="form-control" name="adv_words"></div>
          <div class="col-sm-6"><label class="form-label"><?= te('search.after') ?></label><input type="date" class="form-control" name="adv_after"></div>
          <div class="col-sm-6"><label class="form-label"><?= te('search.before') ?></label><input type="date" class="form-control" name="adv_before"></div>
          <div class="col-12 d-flex gap-3 flex-wrap mt-2">
            <div class="form-check"><input class="form-check-input" type="checkbox" id="adv_att" name="adv_att"><label class="form-check-label" for="adv_att"><?= te('search.has_att') ?></label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="adv_unread" name="adv_unread"><label class="form-check-label" for="adv_unread"><?= te('search.unread') ?></label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="adv_flag" name="adv_flag"><label class="form-check-label" for="adv_flag"><?= te('search.starred') ?></label></div>
          </div>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-3">
          <small class="text-muted"><?= te('search.tip') ?></small>
          <button type="button" class="btn btn-primary" data-action="adv-search"><i class="bi bi-search me-1"></i><?= te('search.go') ?></button>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </form>
  <div class="m4w-top-actions">
    <?php if ($isMail): ?>
    <button class="btn btn-ghost btn-icon m4w-hide-xs" type="button" data-action="shortcuts" title="<?= te('nav.help') ?>" aria-label="<?= te('nav.help') ?>"><i class="bi bi-question-circle"></i></button>
    <div class="dropdown m4w-hide-xs">
      <button class="btn btn-ghost btn-icon" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" title="<?= te('nav.quick_settings') ?>" aria-label="<?= te('nav.quick_settings') ?>"><i class="bi bi-gear"></i></button>
      <div class="dropdown-menu dropdown-menu-end p-3" style="width:300px" id="m4w-quick-settings">
        <div class="fw-semibold mb-2"><?= te('nav.quick_settings') ?></div>
        <a class="btn btn-soft w-100 mb-3" href="<?= e(url('settings')) ?>"><?= te('nav.all_settings') ?></a>
        <div class="small text-muted fw-semibold text-uppercase mb-2" style="letter-spacing:.06em;font-size:.7rem"><?= te('settings.theme') ?></div>
        <div class="btn-group w-100 mb-3" role="group">
          <?php foreach (['light' => 'sun', 'dark' => 'moon-stars', 'auto' => 'circle-half'] as $k => $icon): ?>
          <input type="radio" class="btn-check" name="qs_theme" id="qs_theme_<?= $k ?>" value="<?= $k ?>" <?= ($prefs['theme'] ?? 'auto') === $k ? 'checked' : '' ?>>
          <label class="btn btn-light btn-sm" for="qs_theme_<?= $k ?>"><i class="bi bi-<?= $icon ?>"></i> <?= te('settings.theme_' . $k) ?></label>
          <?php endforeach; ?>
        </div>
        <div class="small text-muted fw-semibold text-uppercase mb-2" style="letter-spacing:.06em;font-size:.7rem"><?= te('settings.density') ?></div>
        <div class="btn-group w-100 mb-3" role="group">
          <?php foreach (['comfortable', 'compact'] as $k): ?>
          <input type="radio" class="btn-check" name="qs_density" id="qs_density_<?= $k ?>" value="<?= $k ?>" <?= ($prefs['density'] ?? 'comfortable') === $k ? 'checked' : '' ?>>
          <label class="btn btn-light btn-sm" for="qs_density_<?= $k ?>"><?= te('settings.density_' . $k) ?></label>
          <?php endforeach; ?>
        </div>
        <div class="small text-muted fw-semibold text-uppercase mb-2" style="letter-spacing:.06em;font-size:.7rem"><?= te('settings.reading_pane') ?></div>
        <div class="btn-group w-100 mb-3" role="group">
          <?php foreach (['right' => 'layout-split', 'bottom' => 'layout-text-window-reverse', 'off' => 'list-ul'] as $k => $icon): ?>
          <input type="radio" class="btn-check" name="qs_pane" id="qs_pane_<?= $k ?>" value="<?= $k ?>" <?= ($prefs['reading_pane'] ?? 'right') === $k ? 'checked' : '' ?>>
          <label class="btn btn-light btn-sm" for="qs_pane_<?= $k ?>" title="<?= te('settings.pane_' . $k) ?>"><i class="bi bi-<?= $icon ?>"></i></label>
          <?php endforeach; ?>
        </div>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="qs_threads" <?= !empty($prefs['conversations']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="qs_threads"><?= te('settings.conversations') ?></label>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <div class="dropdown">
      <button class="btn btn-ghost btn-icon" type="button" data-bs-toggle="dropdown" title="<?= te('nav.apps') ?>" aria-label="<?= te('nav.apps') ?>"><i class="bi bi-grid-3x3-gap"></i></button>
      <div class="dropdown-menu dropdown-menu-end p-2" style="width:290px">
        <div class="row g-1 text-center">
          <?php
          $apps = [['mail', 'envelope', 'nav.mail'], ['files', 'folder2-open', 'nav.files'], ['contacts', 'people', 'nav.contacts'], ['settings', 'sliders', 'nav.settings'], ['settings/rules', 'funnel', 'nav.rules']];
          if (($u['role'] ?? '') === 'admin') {
              $apps[] = ['admin', 'speedometer2', 'nav.admin'];
              $apps[] = ['admin/users', 'person-gear', 'nav.users'];
          }
          foreach ($apps as [$path, $icon, $label]): ?>
          <div class="col-4"><a class="d-flex flex-column align-items-center gap-1 p-2 rounded-3 text-body m4w-app-tile" href="<?= e(url($path)) ?>"><span class="m4w-stat-icon d-inline-flex align-items-center justify-content-center rounded-3" style="width:42px;height:42px;background:var(--m4w-primary-soft);color:var(--m4w-primary);font-size:1.15rem"><i class="bi bi-<?= $icon ?>"></i></span><small class="fw-medium"><?= te($label) ?></small></a></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="dropdown">
      <button class="m4w-avatar-btn ms-1" type="button" data-bs-toggle="dropdown" aria-label="<?= te('nav.account') ?>">
        <span class="m4w-avatar" style="background:<?= e(avatar_color($u['email'])) ?>"><?= e(initials($u['name'])) ?></span>
      </button>
      <div class="dropdown-menu dropdown-menu-end" style="width:300px">
        <div class="d-flex align-items-center gap-3 p-2 pb-3">
          <span class="m4w-avatar lg" style="background:<?= e(avatar_color($u['email'])) ?>"><?= e(initials($u['name'])) ?></span>
          <div class="min-w-0"><div class="fw-semibold text-truncate"><?= e($u['name']) ?></div><div class="small text-muted text-truncate"><?= e($u['email']) ?></div></div>
        </div>
        <div class="dropdown-divider"></div>
        <a class="dropdown-item" href="<?= e(url('settings')) ?>"><i class="bi bi-person-circle"></i><?= te('nav.profile') ?></a>
        <a class="dropdown-item" href="<?= e(url('settings/vacation')) ?>"><i class="bi bi-airplane"></i><?= te('nav.vacation') ?></a>
        <a class="dropdown-item" href="<?= e(url('settings/security')) ?>"><i class="bi bi-shield-lock"></i><?= te('nav.security') ?></a>
        <?php if (($u['role'] ?? '') === 'admin'): ?><a class="dropdown-item" href="<?= e(url('admin')) ?>"><i class="bi bi-speedometer2"></i><?= te('nav.admin') ?></a><?php endif; ?>
        <div class="dropdown-divider"></div>
        <form method="post" action="<?= e(url('logout')) ?>"><?= csrf_field() ?><button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-right text-danger"></i><?= te('nav.logout') ?></button></form>
      </div>
    </div>
  </div>
</header>
