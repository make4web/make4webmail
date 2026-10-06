<div class="dropdown mb-2">
  <button class="m4w-compose-btn w-100" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="fs-new-btn"><i class="bi bi-plus-lg"></i><span><?= te('files.new') ?></span></button>
  <div class="dropdown-menu w-100 shadow">
    <button class="dropdown-item" type="button" data-fs="new-folder"><i class="bi bi-folder-plus"></i><?= te('files.new_folder') ?></button>
    <div class="dropdown-divider"></div>
    <button class="dropdown-item" type="button" data-fs="upload"><i class="bi bi-file-earmark-arrow-up"></i><?= te('files.upload_files') ?></button>
    <button class="dropdown-item" type="button" data-fs="upload-dir"><i class="bi bi-folder-symlink"></i><?= te('files.upload_folder') ?></button>
    <div class="dropdown-divider d-none" data-fs-space-only></div>
    <button class="dropdown-item d-none" type="button" data-fs="new-space" data-fs-space-only><i class="bi bi-collection"></i><?= te('files.new_space') ?></button>
  </div>
</div>
<div class="m4w-sidebar-scroll">
  <ul class="m4w-nav" id="fs-nav-main">
    <li><a class="m4w-nav-item" href="#mine" data-fs-nav="mine"><i class="bi bi-person-workspace"></i><span class="m4w-nav-label"><?= te('files.my_files') ?></span></a></li>
    <li><a class="m4w-nav-item" href="#shared" data-fs-nav="shared"><i class="bi bi-people"></i><span class="m4w-nav-label"><?= te('files.shared_with_me') ?></span></a></li>
  </ul>
  <div class="m4w-nav-title">
    <span><?= te('files.spaces') ?></span>
    <button class="btn btn-ghost btn-icon btn-sm d-none" type="button" data-fs="new-space" data-fs-space-only title="<?= te('files.new_space') ?>" aria-label="<?= te('files.new_space') ?>"><i class="bi bi-plus-lg"></i></button>
  </div>
  <ul class="m4w-nav" id="fs-nav-spaces"></ul>
  <ul class="m4w-nav mt-2">
    <li><a class="m4w-nav-item" href="#trash" data-fs-nav="trash"><i class="bi bi-trash3"></i><span class="m4w-nav-label"><?= te('files.trash') ?></span><span class="m4w-count" id="fs-trash-count"></span></a></li>
    <li><a class="m4w-nav-item" href="<?= e(url('mail')) ?>"><i class="bi bi-envelope"></i><span class="m4w-nav-label"><?= te('nav.mail') ?></span></a></li>
  </ul>
</div>
<div class="m4w-quota" id="fs-usage"></div>
