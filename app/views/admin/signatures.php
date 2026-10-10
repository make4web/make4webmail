<div class="m4w-page-header">
  <div><h1><?= te('admin.sig_title') ?></h1><p><?= te('admin.sig_desc') ?></p></div>
  <a class="btn btn-primary" href="<?= e(url('admin/signatures/new')) ?>"><i class="bi bi-plus-lg me-1"></i><?= te('admin.sig_new') ?></a>
</div>
<div class="row g-4">
  <?php foreach ($templates as $i => $tp): ?>
  <div class="col-xl-6" data-aos="fade-up" data-aos-delay="<?= $i * 50 ?>">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center gap-2">
        <span class="flex-grow-1"><?= e($tp['name']) ?> <?= $tp['is_default'] ? '<span class="badge badge-soft ms-1">' . te('admin.default') . '</span>' : '' ?></span>
        <span class="small text-muted fw-normal"><i class="bi bi-people"></i> <?= (int) $tp['users_count'] ?></span>
        <a class="btn btn-light btn-sm" href="<?= e(url('admin/signatures/' . $tp['id'])) ?>"><i class="bi bi-pencil me-1"></i><?= te('common.edit') ?></a>
      </div>
      <div class="card-body">
        <?php if ($tp['description']): ?><p class="small text-muted"><?= e($tp['description']) ?></p><?php endif; ?>
        <div class="m4w-preview-box"><?= $tp['preview'] ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$templates): ?>
  <div class="col-12"><div class="m4w-empty py-5"><div class="m4w-empty-icon"><i class="bi bi-vector-pen"></i></div><h3><?= te('admin.sig_empty') ?></h3><a class="btn btn-primary mt-2" href="<?= e(url('admin/signatures/new')) ?>"><?= te('admin.sig_new') ?></a></div></div>
  <?php endif; ?>
</div>
<?php if ($templates): ?>
<div class="card mt-4">
  <div class="card-header"><i class="bi bi-diagram-3 me-2 text-primary"></i><?= te('admin.sig_assign') ?></div>
  <form class="card-body d-flex gap-2 flex-wrap align-items-end" method="post" action="<?= e(url('admin/signatures/assign')) ?>" data-confirm="<?= te('admin.sig_assign_confirm') ?>">
    <?= csrf_field() ?>
    <div><label class="form-label"><?= te('admin.sig_assign_to') ?></label><select class="form-select" name="department"><option value="*"><?= te('admin.all_users') ?></option><?php foreach ($departments as $d): ?><option value="<?= e($d) ?>"><?= te('user.department') ?> : <?= e($d) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label"><?= te('admin.signature_template') ?></label><select class="form-select" name="template_id"><option value=""><?= te('admin.default_template') ?></option><?php foreach ($templates as $tp): ?><option value="<?= (int) $tp['id'] ?>"><?= e($tp['name']) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary"><?= te('admin.apply') ?></button>
  </form>
</div>
<?php endif; ?>
