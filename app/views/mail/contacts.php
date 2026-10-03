<?php $isDir = $tab === 'directory'; ?>
<div class="m4w-page-header">
  <div>
    <h1><?= te($isDir ? 'contacts.directory' : 'contacts.title') ?></h1>
    <p><?= te($isDir ? 'contacts.directory_desc' : 'contacts.desc') ?></p>
  </div>
  <?php if (!$isDir): ?>
  <div class="d-flex gap-2 flex-wrap">
    <div class="dropdown">
      <button class="btn btn-light" data-bs-toggle="dropdown"><i class="bi bi-arrow-down-up me-1"></i><?= te('contacts.import_export') ?></button>
      <div class="dropdown-menu dropdown-menu-end p-3" style="width:320px">
        <form method="post" action="<?= e(url('contacts/import')) ?>" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <label class="form-label"><?= te('contacts.import_label') ?></label>
          <input type="file" name="file" accept=".vcf,.csv,text/vcard,text/csv" class="form-control mb-2" required>
          <button class="btn btn-primary btn-sm w-100"><i class="bi bi-upload me-1"></i><?= te('contacts.import') ?></button>
        </form>
        <div class="dropdown-divider"></div>
        <a class="btn btn-light btn-sm w-100" href="<?= e(url('contacts/export')) ?>"><i class="bi bi-download me-1"></i><?= te('contacts.export') ?></a>
      </div>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#contact-modal" data-contact="{}"><i class="bi bi-person-plus me-1"></i><?= te('contacts.add') ?></button>
  </div>
  <?php endif; ?>
</div>

<form class="m4w-filterbar" method="get" action="<?= e(url('contacts')) ?>">
  <?php if ($isDir): ?><input type="hidden" name="tab" value="directory"><?php endif; ?>
  <div class="position-relative flex-grow-1" style="max-width:420px">
    <i class="bi bi-search position-absolute text-muted" style="left:.8rem;top:50%;transform:translateY(-50%)"></i>
    <input class="form-control ps-5" name="q" value="<?= e($q) ?>" placeholder="<?= te('contacts.search') ?>" <?= $isDir ? 'data-live-filter="#dir-table"' : '' ?>>
  </div>
</form>

<?php if ($isDir): ?>
<div class="m4w-table-card"><table class="table table-hover" id="dir-table">
  <thead><tr><th><?= te('contacts.name') ?></th><th><?= te('contacts.job') ?></th><th><?= te('contacts.phone') ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($directory as $d): $name = $d['display_name'] ?: trim($d['first_name'] . ' ' . $d['last_name']) ?: $d['email']; ?>
    <tr data-filter-text="<?= e(mb_strtolower($name . ' ' . $d['email'] . ' ' . $d['job_title'] . ' ' . $d['department'])) ?>">
      <td><div class="m4w-user-cell"><span class="m4w-avatar" style="background:<?= e(avatar_color($d['email'])) ?>"><?= e(initials($name)) ?></span><div><div class="fw-medium"><?= e($name) ?></div><div class="small text-muted"><?= e($d['email']) ?></div></div></div></td>
      <td><div><?= e($d['job_title']) ?></div><div class="small text-muted"><?= e($d['department']) ?></div></td>
      <td class="small"><?= e($d['phone']) ?><?= $d['mobile'] ? '<br>' . e($d['mobile']) : '' ?></td>
      <td class="text-end"><a class="btn btn-soft btn-sm" href="<?= e(url('mail', ['compose' => 1, 'to' => $d['email']])) ?>"><i class="bi bi-envelope me-1"></i><?= te('contacts.write') ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php elseif (!$contacts): ?>
<div class="m4w-empty py-5">
  <div class="m4w-empty-icon"><i class="bi bi-person-lines-fill"></i></div>
  <h3><?= te('contacts.empty') ?></h3>
  <p class="small"><?= te('contacts.empty_hint') ?></p>
  <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#contact-modal" data-contact="{}"><i class="bi bi-person-plus me-1"></i><?= te('contacts.add') ?></button>
</div>
<?php else: ?>
<form method="post" action="<?= e(url('contacts/delete')) ?>" id="contacts-bulk" data-confirm="<?= te('contacts.confirm_delete') ?>">
<?= csrf_field() ?>
<div class="m4w-table-card"><table class="table table-hover">
  <thead><tr><th style="width:36px"><input type="checkbox" class="form-check-input" data-check-all="ids[]"></th><th><?= te('contacts.name') ?></th><th class="d-none d-md-table-cell"><?= te('contacts.company') ?></th><th class="d-none d-md-table-cell"><?= te('contacts.phone') ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($contacts as $c): $name = $c['name'] ?: $c['email']; ?>
    <tr>
      <td><input type="checkbox" class="form-check-input" name="ids[]" value="<?= (int) $c['id'] ?>"></td>
      <td><div class="m4w-user-cell"><span class="m4w-avatar" style="background:<?= e(avatar_color($c['email'])) ?>"><?= e(initials($name)) ?></span><div><div class="fw-medium"><?= e($name) ?><?= $c['is_favorite'] ? ' <i class="bi bi-star-fill text-warning small"></i>' : '' ?><?= $c['auto_collected'] ? ' <span class="badge badge-soft-secondary ms-1">' . te('contacts.auto') . '</span>' : '' ?></div><div class="small text-muted"><?= e($c['email']) ?></div></div></div></td>
      <td class="d-none d-md-table-cell"><?= e($c['company']) ?></td>
      <td class="d-none d-md-table-cell small"><?= e($c['phone']) ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('mail', ['compose' => 1, 'to' => $c['email']])) ?>" title="<?= te('contacts.write') ?>"><i class="bi bi-envelope"></i></a>
        <button type="button" class="btn btn-ghost btn-icon btn-sm" data-bs-toggle="modal" data-bs-target="#contact-modal" data-contact="<?= e(json_encode(['id' => (int) $c['id'], 'name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone'], 'company' => $c['company'], 'notes' => $c['notes'], 'is_favorite' => (int) $c['is_favorite']])) ?>" title="<?= te('common.edit') ?>"><i class="bi bi-pencil"></i></button>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<div class="mt-3 d-none" data-bulk-bar><button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash3 me-1"></i><?= te('contacts.delete_selected') ?></button></div>
</form>
<?php endif; ?>

<div class="modal fade" id="contact-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= e(url('contacts/save')) ?>">
      <?= csrf_field() ?><input type="hidden" name="id">
      <div class="modal-header"><h5 class="modal-title"><?= te('contacts.contact') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12"><label class="form-label"><?= te('contacts.name') ?></label><input class="form-control" name="name"></div>
          <div class="col-12"><label class="form-label"><?= te('contacts.email') ?></label><input class="form-control" type="email" name="email" required></div>
          <div class="col-md-6"><label class="form-label"><?= te('contacts.phone') ?></label><input class="form-control" name="phone"></div>
          <div class="col-md-6"><label class="form-label"><?= te('contacts.company') ?></label><input class="form-control" name="company"></div>
          <div class="col-12"><label class="form-label"><?= te('contacts.notes') ?></label><textarea class="form-control" name="notes" rows="2"></textarea></div>
          <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_favorite" value="1" id="c-fav"><label class="form-check-label" for="c-fav"><?= te('contacts.favorite') ?></label></div></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto d-none" data-delete-contact><i class="bi bi-trash3"></i></button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= te('common.cancel') ?></button>
        <button class="btn btn-primary"><?= te('common.save') ?></button>
      </div>
    </form>
  </div>
</div>
<form method="post" action="<?= e(url('contacts/delete')) ?>" id="contact-delete-form" class="d-none"><?= csrf_field() ?><input type="hidden" name="id"></form>
