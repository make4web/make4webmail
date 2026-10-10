<?php /** @var int $maxFile */ ?>
<div id="fs-app" data-max-file="<?= (int) $maxFile ?>">
  <div class="fs-header">
    <nav aria-label="<?= te('files.path') ?>" class="fs-crumbs-wrap"><ol class="fs-crumbs" id="fs-crumbs"></ol></nav>
    <div class="fs-header-tools">
      <div class="fs-search">
        <i class="bi bi-search"></i>
        <input type="search" class="form-control" id="fs-search" placeholder="<?= te('files.search') ?>" aria-label="<?= te('files.search') ?>" autocomplete="off">
      </div>
      <div class="btn-group" role="group" aria-label="<?= te('files.view') ?>">
        <button type="button" class="btn btn-light btn-icon" data-fs-view="list" title="<?= te('files.view_list') ?>" aria-label="<?= te('files.view_list') ?>"><i class="bi bi-list-ul"></i></button>
        <button type="button" class="btn btn-light btn-icon" data-fs-view="grid" title="<?= te('files.view_grid') ?>" aria-label="<?= te('files.view_grid') ?>"><i class="bi bi-grid-3x3-gap"></i></button>
      </div>
    </div>
  </div>
  <div class="fs-subheader" id="fs-subheader"></div>

  <div class="fs-toolbar" id="fs-toolbar">
    <div class="fs-toolbar-default">
      <button class="btn btn-soft btn-sm" type="button" data-fs="new-folder" data-need="write"><i class="bi bi-folder-plus me-1"></i><?= te('files.new_folder') ?></button>
      <button class="btn btn-soft btn-sm" type="button" data-fs="upload" data-need="write"><i class="bi bi-upload me-1"></i><?= te('files.upload') ?></button>
      <button class="btn btn-light btn-sm" type="button" data-fs="share" data-need="manage"><i class="bi bi-person-plus me-1"></i><?= te('files.share') ?></button>
      <button class="btn btn-light btn-sm" type="button" data-fs="zip-folder" data-need="read"><i class="bi bi-file-earmark-zip me-1"></i><?= te('files.download_folder') ?></button>
      <button class="btn btn-light btn-sm" type="button" data-fs="edit-space" data-need="manage-space"><i class="bi bi-pencil me-1"></i><?= te('files.edit_space') ?></button>
      <button class="btn btn-light btn-sm" type="button" data-fs="empty-trash" data-need="trash"><i class="bi bi-trash3 me-1"></i><?= te('files.empty_trash') ?></button>
    </div>
    <div class="fs-toolbar-selection">
      <button class="btn btn-ghost btn-icon btn-sm" type="button" data-fs="clear-selection" title="<?= te('files.clear_selection') ?>" aria-label="<?= te('files.clear_selection') ?>"><i class="bi bi-x-lg"></i></button>
      <span class="fw-semibold me-2" id="fs-sel-count"></span>
      <button class="btn btn-light btn-sm" type="button" data-fs="download" data-sel="files"><i class="bi bi-download me-1"></i><?= te('files.download') ?></button>
      <button class="btn btn-light btn-sm" type="button" data-fs="attach-mail" data-sel="files"><i class="bi bi-envelope me-1"></i><?= te('files.send_mail') ?></button>
      <button class="btn btn-light btn-sm" type="button" data-fs="move" data-sel="write"><i class="bi bi-folder-symlink me-1"></i><?= te('files.move') ?></button>
      <button class="btn btn-light btn-sm" type="button" data-fs="copy" data-sel="any"><i class="bi bi-copy me-1"></i><?= te('files.copy') ?></button>
      <button class="btn btn-light btn-sm" type="button" data-fs="rename" data-sel="one-write"><i class="bi bi-input-cursor-text me-1"></i><?= te('files.rename') ?></button>
      <button class="btn btn-light btn-sm text-danger" type="button" data-fs="delete" data-sel="write"><i class="bi bi-trash3 me-1"></i><?= te('common.delete') ?></button>
      <button class="btn btn-light btn-sm" type="button" data-fs="restore" data-sel="trash"><i class="bi bi-arrow-counterclockwise me-1"></i><?= te('files.restore') ?></button>
      <button class="btn btn-light btn-sm text-danger" type="button" data-fs="purge" data-sel="trash"><i class="bi bi-x-octagon me-1"></i><?= te('files.purge') ?></button>
    </div>
  </div>

  <div class="fs-body" id="fs-body" tabindex="-1"></div>
  <div class="fs-drop" id="fs-drop" aria-hidden="true"><div><i class="bi bi-cloud-arrow-up"></i><div class="fw-semibold mt-2" id="fs-drop-label"><?= te('files.drop_here') ?></div></div></div>
  <input type="file" id="fs-input" multiple hidden>
  <input type="file" id="fs-input-dir" multiple webkitdirectory hidden>
</div>

<div class="fs-uploads shadow" id="fs-uploads" hidden>
  <div class="fs-uploads-head"><span id="fs-uploads-title"></span><button type="button" class="btn btn-ghost btn-icon btn-sm" data-fs="close-uploads" aria-label="<?= te('common.close') ?>"><i class="bi bi-x-lg"></i></button></div>
  <ul class="fs-uploads-list" id="fs-uploads-list"></ul>
</div>

<div class="dropdown-menu shadow fs-ctx" id="fs-ctx" role="menu"></div>

<!-- Name prompt (new folder, rename, space) -->
<div class="modal fade" id="fs-name-modal" tabindex="-1" aria-hidden="true" aria-labelledby="fs-name-title">
  <div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="fs-name-form" autocomplete="off">
    <div class="modal-header"><h2 class="modal-title fs-5" id="fs-name-title"></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= te('common.close') ?>"></button></div>
    <div class="modal-body">
      <label class="form-label" for="fs-name-input"><?= te('files.name') ?></label>
      <input class="form-control" id="fs-name-input" maxlength="200" required>
      <div class="mt-3 d-none" id="fs-desc-wrap"><label class="form-label" for="fs-desc-input"><?= te('files.description') ?></label><textarea class="form-control" id="fs-desc-input" rows="2" maxlength="500"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= te('common.cancel') ?></button><button class="btn btn-primary" type="submit" id="fs-name-ok"><?= te('common.save') ?></button></div>
  </form></div>
</div>

<!-- Share -->
<div class="modal fade" id="fs-share-modal" tabindex="-1" aria-hidden="true" aria-labelledby="fs-share-title">
  <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-5" id="fs-share-title"></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= te('common.close') ?>"></button></div>
    <div class="modal-body">
      <div class="fs-share-add">
        <div class="position-relative flex-grow-1">
          <input class="form-control" id="fs-share-q" placeholder="<?= te('files.share_placeholder') ?>" aria-label="<?= te('files.share_placeholder') ?>" autocomplete="off">
          <div class="dropdown-menu w-100 shadow" id="fs-share-suggest"></div>
        </div>
        <button class="btn btn-light" type="button" data-fs="share-everyone"><i class="bi bi-globe2 me-1"></i><?= te('files.everyone') ?></button>
      </div>
      <p class="small text-muted mt-2 mb-3"><?= te('files.share_help') ?></p>
      <ul class="fs-acl-list" id="fs-acl-list"></ul>
      <div id="fs-acl-inherited"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= te('common.cancel') ?></button><button class="btn btn-primary" type="button" data-fs="share-save"><?= te('common.save') ?></button></div>
  </div></div>
</div>

<!-- Versions -->
<div class="modal fade" id="fs-versions-modal" tabindex="-1" aria-hidden="true" aria-labelledby="fs-versions-title">
  <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-5" id="fs-versions-title"></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= te('common.close') ?>"></button></div>
    <div class="modal-body p-0"><div class="m4w-table-card border-0 rounded-0"><table class="table mb-0"><thead><tr><th><?= te('files.version') ?></th><th><?= te('files.modified') ?></th><th><?= te('files.size') ?></th><th></th></tr></thead><tbody id="fs-versions-body"></tbody></table></div></div>
  </div></div>
</div>

<!-- Preview -->
<div class="modal fade" id="fs-preview-modal" tabindex="-1" aria-hidden="true" aria-labelledby="fs-preview-title">
  <div class="modal-dialog modal-dialog-centered modal-xl"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-6 text-truncate" id="fs-preview-title"></h2>
      <div class="ms-auto d-flex gap-1"><a class="btn btn-light btn-sm" id="fs-preview-dl" href="#"><i class="bi bi-download me-1"></i><?= te('files.download') ?></a><button type="button" class="btn-close ms-2" data-bs-dismiss="modal" aria-label="<?= te('common.close') ?>"></button></div></div>
    <div class="modal-body fs-preview-body" id="fs-preview-body"></div>
  </div></div>
</div>

<!-- Upload conflict -->
<div class="modal fade" id="fs-conflict-modal" tabindex="-1" aria-hidden="true" aria-labelledby="fs-conflict-title">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-5" id="fs-conflict-title"><?= te('files.conflict_title') ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= te('common.close') ?>"></button></div>
    <div class="modal-body"><p id="fs-conflict-text" class="mb-0"></p></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-conflict="keep"><?= te('files.keep_both') ?></button><button type="button" class="btn btn-primary" data-conflict="replace"><?= te('files.replace') ?></button></div>
  </div></div>
</div>

<div class="modal fade" id="m4w-confirm" tabindex="-1" aria-hidden="true" aria-labelledby="m4w-confirm-title">
  <div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content">
    <div class="modal-body"><h2 class="fs-6 fw-semibold" data-title id="m4w-confirm-title"></h2><p class="small text-muted mb-0" data-text></p></div>
    <div class="modal-footer"><button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal"><?= te('common.cancel') ?></button><button type="button" class="btn btn-danger btn-sm" data-ok></button></div>
  </div></div>
</div>
