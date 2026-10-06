<div class="m4w-page-header">
  <div><h1><?= te('admin.users_title') ?></h1><p><?= te('admin.users_desc') ?></p></div>
  <div class="d-flex gap-2">
    <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#import-modal"><i class="bi bi-filetype-csv me-1"></i><?= te('admin.import_csv') ?></button>
    <a class="btn btn-primary" href="<?= e(url('admin/users/new')) ?>"><i class="bi bi-person-plus me-1"></i><?= te('admin.new_user') ?></a>
  </div>
</div>
<form class="m4w-filterbar" method="get">
  <div class="position-relative flex-grow-1" style="max-width:380px"><i class="bi bi-search position-absolute text-muted" style="left:.8rem;top:50%;transform:translateY(-50%)"></i><input class="form-control ps-5" name="q" value="<?= e($q) ?>" placeholder="<?= te('admin.search_users') ?>"></div>
  <div class="btn-group">
    <?php foreach (['' => 'admin.f_all', 'active' => 'admin.f_active', 'disabled' => 'admin.f_disabled', 'admin' => 'admin.f_admins'] as $k => $l): ?>
    <a class="btn btn-light <?= $status === $k ? 'active' : '' ?>" href="<?= e(url('admin/users', array_filter(['status' => $k, 'q' => $q]))) ?>"><?= te($l) ?></a>
    <?php endforeach; ?>
  </div>
  <span class="ms-auto small text-muted"><?= te('admin.n_users', ['n' => count($users)]) ?></span>
</form>
<form method="post" action="<?= e(url('admin/users/bulk')) ?>" id="users-bulk">
  <?= csrf_field() ?>
  <div class="d-none align-items-center gap-2 mb-3 p-2 rounded-3" style="background:var(--m4w-primary-softer)" data-bulk-bar>
    <span class="small fw-semibold ms-2" data-bulk-count></span>
    <select name="bulk_action" class="form-select form-select-sm w-auto">
      <option value="enable"><?= te('admin.b_enable') ?></option><option value="disable"><?= te('admin.b_disable') ?></option>
      <option value="force_pw"><?= te('admin.b_force_pw') ?></option><option value="template"><?= te('admin.b_template') ?></option><option value="delete"><?= te('admin.b_delete') ?></option>
    </select>
    <select name="template_id" class="form-select form-select-sm w-auto"><option value=""><?= te('admin.default_template') ?></option><?php foreach ($templates as $tp): ?><option value="<?= (int) $tp['id'] ?>"><?= e($tp['name']) ?></option><?php endforeach; ?></select>
    <button class="btn btn-primary btn-sm" data-confirm-bulk="<?= te('admin.bulk_confirm') ?>"><?= te('admin.apply') ?></button>
  </div>
  <div class="m4w-table-card"><div class="table-responsive"><table class="table table-hover">
    <thead><tr><th style="width:36px"><input type="checkbox" class="form-check-input" data-check-all="ids[]"></th><th><?= te('admin.user') ?></th><th class="d-none d-lg-table-cell"><?= te('admin.function') ?></th><th class="d-none d-md-table-cell"><?= te('admin.storage') ?></th><th class="d-none d-xl-table-cell"><?= te('admin.last_login') ?></th><th><?= te('admin.status') ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $name = $u['display_name'] ?: trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['email']; $pct = $u['quota_mb'] > 0 ? min(100, round($u['used_bytes'] / ($u['quota_mb'] * 1048576) * 100)) : 0; ?>
      <tr>
        <td><input type="checkbox" class="form-check-input" name="ids[]" value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === (int) $currentUser['id'] ? 'disabled' : '' ?>></td>
        <td><a class="m4w-user-cell text-reset" href="<?= e(url('admin/users/' . $u['id'])) ?>"><span class="m4w-avatar" style="background:<?= e(avatar_color($u['email'])) ?>"><?= e(initials($name)) ?></span><div class="min-w-0"><div class="fw-medium"><?= e($name) ?> <?= $u['role'] === 'admin' ? '<span class="badge badge-soft ms-1">Admin</span>' : '' ?><?= $u['totp_enabled'] ? ' <i class="bi bi-shield-check text-success" title="2FA"></i>' : '' ?></div><div class="small text-muted"><?= e($u['email']) ?></div></div></a></td>
        <td class="d-none d-lg-table-cell"><div class="small"><?= e($u['job_title']) ?></div><div class="small text-muted"><?= e($u['department']) ?></div></td>
        <td class="d-none d-md-table-cell" style="min-width:150px"><div class="small mb-1"><?= e(format_bytes((int) $u['used_bytes'])) ?> <span class="text-muted">/ <?= $u['quota_mb'] > 0 ? e(format_bytes($u['quota_mb'] * 1048576)) : '∞' ?></span></div><div class="progress" style="height:4px"><div class="progress-bar <?= $pct > 90 ? 'bg-danger' : '' ?>" style="width:<?= max(1, $pct) ?>%"></div></div></td>
        <td class="d-none d-xl-table-cell small text-muted"><?= e(format_datetime((int) $u['last_login_at'])) ?></td>
        <td><span class="badge <?= $u['status'] === 'active' ? 'badge-soft-success' : 'badge-soft-secondary' ?>"><?= te('admin.st_' . $u['status']) ?></span><?= $u['must_change_password'] ? ' <i class="bi bi-key text-warning" title="' . te('admin.must_change') . '"></i>' : '' ?></td>
        <td class="text-end text-nowrap">
          <?php if ((int) $u['id'] !== (int) $currentUser['id'] && M4W\Service\Delegation::adminsByDefault()): ?>
          <button type="button" class="btn btn-ghost btn-icon btn-sm" data-open-mailbox="<?= (int) $u['id'] ?>" data-name="<?= e($name) ?>" data-email="<?= e($u['email']) ?>" data-reason="<?= (int) M4W\Core\Settings::get('delegation.require_reason', 1) ?>" title="<?= te('deleg.open_this') ?>" aria-label="<?= te('deleg.open_for', ['name' => $name]) ?>"><i class="bi bi-box-arrow-in-right"></i></button>
          <?php endif; ?>
          <a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('admin/users/' . $u['id'])) ?>" aria-label="<?= te('common.edit') ?>"><i class="bi bi-pencil"></i></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$users): ?><tr><td colspan="7" class="text-center text-muted py-5"><?= te('admin.no_users') ?></td></tr><?php endif; ?>
    </tbody>
  </table></div></div>
</form>
<div class="modal fade" id="import-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post" action="<?= e(url('admin/users/import')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="modal-header"><h5 class="modal-title"><?= te('admin.import_csv') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <p class="small text-muted"><?= te('admin.import_help') ?></p>
      <pre class="m4w-code p-2 rounded-3" style="background:var(--m4w-surface-2)">email;first_name;last_name;job_title;department;phone;password
jean.dupont@<?= e(explode('@', $currentUser['email'])[1]) ?>;Jean;Dupont;Commercial;Ventes;+33 1 23 45 67 89;</pre>
      <input type="file" name="file" accept=".csv,text/csv" class="form-control" required>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= te('common.cancel') ?></button><button class="btn btn-primary"><?= te('contacts.import') ?></button></div>
  </form></div>
</div>
