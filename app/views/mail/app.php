<?php
/** @var array $user */
$prefs = $user['prefs'];
$pane = in_array($prefs['reading_pane'], ['right', 'bottom', 'off'], true) ? $prefs['reading_pane'] : 'right';
?>
<div class="m4w-app" id="m4w-app">
  <aside class="m4w-sidebar" id="m4w-sidebar" aria-label="<?= te('nav.folders') ?>">
    <button class="m4w-compose-btn" type="button" data-action="compose" title="<?= te('mail.compose') ?> (C)">
      <i class="bi bi-pencil-square"></i><span><?= te('mail.compose') ?></span>
    </button>
    <div id="m4w-vacation-flag"></div>
    <div class="m4w-sidebar-scroll">
      <ul class="m4w-nav" id="m4w-folders"></ul>
      <div class="m4w-nav-title">
        <span><?= te('mail.my_folders') ?></span>
        <button class="btn btn-ghost btn-icon btn-sm" type="button" data-action="new-folder" title="<?= te('mail.new_folder') ?>"><i class="bi bi-plus-lg"></i></button>
      </div>
      <ul class="m4w-nav" id="m4w-user-folders"></ul>
      <ul class="m4w-nav mt-2">
        <li><a class="m4w-nav-item" href="<?= e(url('files')) ?>"><i class="bi bi-folder2-open"></i><span class="m4w-nav-label"><?= te('nav.files') ?></span></a></li>
        <li><a class="m4w-nav-item" href="<?= e(url('contacts')) ?>"><i class="bi bi-people"></i><span class="m4w-nav-label"><?= te('nav.contacts') ?></span></a></li>
        <li><a class="m4w-nav-item" href="<?= e(url('settings/rules')) ?>"><i class="bi bi-funnel"></i><span class="m4w-nav-label"><?= te('nav.rules') ?></span></a></li>
      </ul>
    </div>
    <div class="m4w-quota" id="m4w-quota"></div>
  </aside>

  <div class="m4w-main pane-<?= e($pane) ?>" id="m4w-main">
    <section class="m4w-panel m4w-list" id="m4w-list" aria-label="<?= te('mail.list') ?>">
      <div class="m4w-list-toolbar">
        <input class="form-check-input m-0 me-1" type="checkbox" id="m4w-select-all" aria-label="<?= te('mail.select_all') ?>">
        <div class="dropdown">
          <button class="btn btn-ghost btn-icon btn-sm" data-bs-toggle="dropdown" aria-label="<?= te('mail.select') ?>"><i class="bi bi-chevron-down" style="font-size:.7rem"></i></button>
          <div class="dropdown-menu">
            <button class="dropdown-item" data-select="all"><?= te('mail.sel_all') ?></button>
            <button class="dropdown-item" data-select="none"><?= te('mail.sel_none') ?></button>
            <button class="dropdown-item" data-select="read"><?= te('mail.sel_read') ?></button>
            <button class="dropdown-item" data-select="unread"><?= te('mail.sel_unread') ?></button>
            <button class="dropdown-item" data-select="flagged"><?= te('mail.sel_flagged') ?></button>
          </div>
        </div>
        <h2 class="m4w-folder-title" id="m4w-folder-title"></h2>
        <div class="m4w-sel-actions" id="m4w-sel-actions">
          <button class="btn btn-ghost btn-icon btn-sm" data-bulk="archive" title="<?= te('mail.archive') ?> (E)"><i class="bi bi-archive"></i></button>
          <button class="btn btn-ghost btn-icon btn-sm" data-bulk="spam" title="<?= te('mail.spam') ?> (!)"><i class="bi bi-exclamation-octagon"></i></button>
          <button class="btn btn-ghost btn-icon btn-sm" data-bulk="delete" title="<?= te('mail.delete') ?> (#)"><i class="bi bi-trash3"></i></button>
          <span class="vr mx-1 opacity-25"></span>
          <button class="btn btn-ghost btn-icon btn-sm" data-bulk="read" title="<?= te('mail.mark_read') ?>"><i class="bi bi-envelope-open"></i></button>
          <button class="btn btn-ghost btn-icon btn-sm" data-bulk="unread" title="<?= te('mail.mark_unread') ?>"><i class="bi bi-envelope"></i></button>
          <button class="btn btn-ghost btn-icon btn-sm" data-bulk="flag" title="<?= te('mail.flag') ?>"><i class="bi bi-star"></i></button>
          <div class="dropdown">
            <button class="btn btn-ghost btn-icon btn-sm" data-bs-toggle="dropdown" title="<?= te('mail.move') ?> (V)"><i class="bi bi-folder-symlink"></i></button>
            <div class="dropdown-menu m4w-move-menu" data-move-menu></div>
          </div>
          <span class="small text-muted ms-2 text-nowrap" id="m4w-sel-count"></span>
        </div>
        <div class="ms-auto d-flex align-items-center gap-1">
          <button class="btn btn-ghost btn-icon btn-sm" data-action="refresh" title="<?= te('mail.refresh') ?>"><i class="bi bi-arrow-clockwise"></i></button>
          <div class="dropdown">
            <button class="btn btn-ghost btn-icon btn-sm" data-bs-toggle="dropdown" title="<?= te('mail.more') ?>"><i class="bi bi-three-dots-vertical"></i></button>
            <div class="dropdown-menu dropdown-menu-end">
              <h6 class="dropdown-header"><?= te('mail.sort') ?></h6>
              <button class="dropdown-item" data-sort="date"><i class="bi bi-calendar3"></i><?= te('mail.sort_date') ?></button>
              <button class="dropdown-item" data-sort="from"><i class="bi bi-person"></i><?= te('mail.sort_from') ?></button>
              <button class="dropdown-item" data-sort="subject"><i class="bi bi-fonts"></i><?= te('mail.sort_subject') ?></button>
              <div class="dropdown-divider"></div>
              <button class="dropdown-item" data-action="mark-all-read"><i class="bi bi-check2-all"></i><?= te('mail.mark_all_read') ?></button>
              <button class="dropdown-item d-none" data-action="empty-folder"><i class="bi bi-trash3"></i><?= te('mail.empty_folder') ?></button>
            </div>
          </div>
        </div>
      </div>
      <div class="m4w-filter-row" id="m4w-filters" role="toolbar" aria-label="<?= te('mail.filters') ?>">
        <button class="m4w-chip active" data-filter=""><?= te('mail.f_all') ?></button>
        <button class="m4w-chip" data-filter="unread"><i class="bi bi-envelope"></i><?= te('mail.f_unread') ?></button>
        <button class="m4w-chip" data-filter="flagged"><i class="bi bi-star"></i><?= te('mail.f_flagged') ?></button>
        <button class="m4w-chip" data-filter="attachments"><i class="bi bi-paperclip"></i><?= te('mail.f_attachments') ?></button>
      </div>
      <div class="m4w-list-scroll" id="m4w-list-scroll" tabindex="-1" aria-label="<?= te('mail.list') ?>"></div>
      <div class="d-flex align-items-center justify-content-between px-3 py-2 border-top m4w-pager" id="m4w-pager"></div>
    </section>

    <section class="m4w-panel m4w-reader" id="m4w-reader" aria-label="<?= te('mail.reader') ?>">
      <div class="m4w-empty m4w-reader-empty" id="m4w-reader-empty">
        <div class="m4w-empty-icon"><i class="bi bi-envelope-open"></i></div>
        <h3><?= te('mail.no_selection') ?></h3>
        <p class="small mb-0"><?= te('mail.no_selection_hint') ?></p>
      </div>
      <div class="d-none flex-column h-100" id="m4w-reader-content"></div>
    </section>
  </div>
</div>

<button class="btn btn-primary m4w-fab" data-action="compose" aria-label="<?= te('mail.compose') ?>"><i class="bi bi-pencil"></i></button>

<!-- Shortcuts -->
<div class="modal fade" id="m4w-shortcuts" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-keyboard me-2"></i><?= te('sc.title') ?></h5><a class="btn btn-link btn-sm ms-auto me-2" href="<?= e(url('help')) ?>"><i class="bi bi-book me-1"></i><?= te('nav.guide') ?></a><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row">
          <?php $groups = [
              'sc.g_nav' => [['J / K', 'sc.next_prev'], ['O / ↵', 'sc.open'], ['U', 'sc.back'], ['/', 'sc.search'], ['G I', 'sc.goto_inbox'], ['G S', 'sc.goto_sent'], ['G D', 'sc.goto_drafts'], ['?', 'sc.help']],
              'sc.g_actions' => [['C', 'sc.compose'], ['R', 'sc.reply'], ['A', 'sc.reply_all'], ['F', 'sc.forward'], ['E', 'sc.archive'], ['#', 'sc.delete'], ['!', 'sc.spam'], ['V', 'sc.move'], ['S', 'sc.star'], ['Shift + I / U', 'sc.read_unread'], ['X', 'sc.select'], ['Ctrl + ↵', 'sc.send'], ['Esc', 'sc.close']],
          ];
          foreach ($groups as $title => $rows): ?>
          <div class="col-md-6">
            <h6 class="fw-semibold mb-2"><?= te($title) ?></h6>
            <table class="m4w-shortcuts w-100 mb-3"><?php foreach ($rows as [$k, $l]): ?><tr><td><?php foreach (explode(' ', $k) as $part): ?><?= in_array($part, ['/', '+'], true) && strlen($k) > 1 ? '<span class="text-muted mx-1">' . e($part) . '</span>' : '<kbd>' . e($part) . '</kbd> ' ?><?php endforeach; ?></td><td class="text-muted"><?= te($l) ?></td></tr><?php endforeach; ?></table>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Folder dialog -->
<div class="modal fade" id="m4w-folder-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="m4w-folder-form">
      <div class="modal-header"><h5 class="modal-title" data-title></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="id">
        <div class="mb-3"><label class="form-label"><?= te('folder.name') ?></label><input class="form-control" name="name" required maxlength="100"></div>
        <div class="mb-3" data-parent-wrap><label class="form-label"><?= te('folder.parent') ?></label><select class="form-select" name="parent_id"></select></div>
        <label class="form-label"><?= te('folder.color') ?></label>
        <div class="d-flex gap-2 flex-wrap m4w-presets" data-colors>
          <?php foreach (['', '#2563eb', '#7c3aed', '#db2777', '#dc2626', '#ea580c', '#ca8a04', '#16a34a', '#0d9488', '#64748b'] as $c): ?>
          <button type="button" data-color="<?= $c ?>" style="background:<?= $c ?: 'var(--m4w-surface-3)' ?>" title="<?= $c ?: te('folder.no_color') ?>"><?= $c === '' ? '<i class="bi bi-slash-lg small text-muted"></i>' : '' ?></button>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="color">
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= te('common.cancel') ?></button><button class="btn btn-primary"><?= te('common.save') ?></button></div>
    </form>
  </div>
</div>

<!-- Confirm dialog -->
<div class="modal fade" id="m4w-confirm" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-body p-4 text-center">
        <div class="mb-3" style="font-size:2rem;color:var(--m4w-danger)"><i class="bi bi-exclamation-triangle"></i></div>
        <h6 class="fw-semibold" data-title></h6>
        <p class="text-muted small mb-0" data-text></p>
      </div>
      <div class="modal-footer justify-content-center border-0 pt-0 pb-4"><button class="btn btn-light" data-bs-dismiss="modal"><?= te('common.cancel') ?></button><button class="btn btn-danger" data-ok><?= te('common.confirm') ?></button></div>
    </div>
  </div>
</div>

<script type="application/json" id="m4w-boot"><?= json_encode([
    'prefs' => array_diff_key($prefs, ['_recovery' => 1, '_totp_step' => 1]),
    'q' => (string) ($_GET['q'] ?? ''),
    'compose' => isset($_GET['compose']) ? ['to' => (string) ($_GET['to'] ?? ''), 'subject' => (string) ($_GET['subject'] ?? ''), 'files' => array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['files'] ?? '')))))] : null,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
