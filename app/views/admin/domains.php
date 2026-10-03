<div class="m4w-page-header"><div><h1><?= te('admin.domains_title') ?></h1><p><?= te('admin.domains_desc') ?></p></div></div>
<div class="row g-4">
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center"><i class="bi bi-globe2 me-2 text-primary"></i><?= te('admin.domains') ?></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($domains as $d): ?>
        <li class="list-group-item d-flex align-items-center gap-3">
          <div class="flex-grow-1"><div class="fw-medium"><?= e($d['name']) ?></div><div class="small text-muted"><?= te('admin.n_users', ['n' => (int) $d['users']]) ?></div></div>
          <form method="post" action="<?= e(url('admin/domains/save')) ?>" class="form-check form-switch m-0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="name" value="<?= e($d['name']) ?>"><input class="form-check-input" type="checkbox" name="active" value="1" <?= $d['active'] ? 'checked' : '' ?> data-autosubmit title="<?= te('common.enabled') ?>"></form>
          <form method="post" action="<?= e(url('admin/domains/delete')) ?>" data-confirm="<?= te('admin.confirm_delete_domain', ['name' => $d['name']]) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="btn btn-ghost btn-icon btn-sm text-danger" <?= $d['users'] ? 'disabled' : '' ?>><i class="bi bi-trash3"></i></button></form>
        </li>
        <?php endforeach; ?>
      </ul>
      <form method="post" action="<?= e(url('admin/domains/save')) ?>" class="card-footer d-flex gap-2"><?= csrf_field() ?><input class="form-control" name="name" placeholder="exemple.fr" required><button class="btn btn-primary text-nowrap"><i class="bi bi-plus-lg me-1"></i><?= te('admin.add_domain') ?></button></form>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-at me-2 text-primary"></i><?= te('admin.aliases') ?></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($aliases as $a): ?>
        <li class="list-group-item d-flex align-items-center gap-2"><code><?= e($a['address']) ?></code><i class="bi bi-arrow-right text-muted"></i><span class="flex-grow-1 small"><?= e($a['target']) ?></span>
          <form method="post" action="<?= e(url('admin/aliases/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="btn btn-ghost btn-icon btn-sm text-danger"><i class="bi bi-x-lg"></i></button></form></li>
        <?php endforeach; ?>
        <?php if (!$aliases): ?><li class="list-group-item small text-muted"><?= te('admin.no_aliases') ?></li><?php endif; ?>
      </ul>
      <form method="post" action="<?= e(url('admin/aliases/save')) ?>" class="card-footer d-flex gap-2 flex-wrap"><?= csrf_field() ?>
        <input class="form-control" name="address" type="email" placeholder="contact@exemple.fr" required style="flex:1 1 200px">
        <select class="form-select" name="user_id" style="flex:1 1 200px"><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['email']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-primary"><i class="bi bi-plus-lg"></i></button></form>
    </div>
  </div>
  <div class="col-12">
    <div class="alert alert-info mb-0"><i class="bi bi-hdd-network"></i><div><?= te('admin.dns_help') ?></div></div>
  </div>
</div>
