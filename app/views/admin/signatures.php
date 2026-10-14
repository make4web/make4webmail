<div class="m4w-page-header">
  <div><h1><?= te('admin.sig_title') ?></h1><p><?= te('admin.sig_desc') ?></p></div>
  <a class="btn btn-primary" href="<?= e(url('admin/signatures/new')) ?>"><i class="bi bi-plus-lg me-1"></i><?= te('admin.sig_new') ?></a>
</div>
<div class="row g-4">
  <?php foreach ($templates as $i => $tp): ?>
  <div class="col-xl-6" data-aos="fade-up" data-aos-delay="<?= $i * 50 ?>">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center gap-2">
        <span class="flex-grow-1"><?= e($tp['name']) ?> <?= $tp['is_default'] ? '<span class="badge badge-soft-success ms-1"><i class="bi bi-check2-circle me-1"></i>' . te('admin.sig_active') . '</span>' : '<span class="badge badge-soft-secondary ms-1">' . te('admin.sig_inactive') . '</span>' ?></span>
        <?php if (!$tp['is_default']): ?><form method="post" action="<?= e(url('admin/signatures/' . (int) $tp['id'] . '/activate')) ?>" data-confirm="<?= te('admin.sig_activate_confirm') ?>"><?= csrf_field() ?><button class="btn btn-soft btn-sm"><i class="bi bi-check2-circle me-1"></i><?= te('admin.sig_activate') ?></button></form><?php endif; ?>
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
