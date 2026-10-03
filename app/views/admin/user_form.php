<?php
$isNew = $user === null;
$u = $user ?? ['id' => 0, 'email' => '', 'display_name' => '', 'first_name' => '', 'last_name' => '', 'job_title' => '', 'department' => '', 'phone' => '', 'mobile' => '', 'role' => 'user', 'status' => 'active', 'quota_mb' => 2048, 'signature_template_id' => null, 'language' => M4W\Core\Settings::get('features.default_language', 'fr'), 'must_change_password' => 1, 'totp_enabled' => 0, 'used_bytes' => 0, 'last_login_at' => 0, 'created_at' => 0];
?>
<div class="m4w-page-header">
  <div class="d-flex align-items-center gap-3">
    <a class="btn btn-ghost btn-icon" href="<?= e(url('admin/users')) ?>" aria-label="<?= te('common.back') ?>"><i class="bi bi-arrow-left"></i></a>
    <?php if (!$isNew): ?><span class="m4w-avatar lg" style="background:<?= e(avatar_color($u['email'])) ?>"><?= e(initials($u['name'])) ?></span><?php endif; ?>
    <div><h1><?= $isNew ? te('admin.new_user') : e($u['name']) ?></h1><p class="mb-0"><?= $isNew ? te('admin.new_user_desc') : e($u['email']) . ' · ' . te('admin.created_on', ['date' => format_datetime((int) $u['created_at'], false)]) ?></p></div>
  </div>
</div>
<form method="post" action="<?= e(url('admin/users/save')) ?>" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
  <div class="row g-4">
    <div class="col-xl-8">
      <div class="card mb-4">
        <div class="card-header"><i class="bi bi-person-vcard me-2 text-primary"></i><?= te('admin.identity') ?></div>
        <div class="card-body"><div class="row g-3">
          <?php if ($isNew): ?>
          <div class="col-12"><label class="form-label"><?= te('admin.email_address') ?></label>
            <div class="input-group"><input class="form-control" name="local_part" required pattern="[a-zA-Z0-9._+\-]{1,64}" placeholder="prenom.nom" data-local-part><span class="input-group-text">@</span>
            <select class="form-select" name="domain" style="max-width:260px"><?php foreach ($domains as $d): ?><option><?= e($d['name']) ?></option><?php endforeach; ?></select></div></div>
          <?php endif; ?>
          <div class="col-md-6"><label class="form-label"><?= te('user.first_name') ?></label><input class="form-control" name="first_name" value="<?= e($u['first_name']) ?>" data-fn></div>
          <div class="col-md-6"><label class="form-label"><?= te('user.last_name') ?></label><input class="form-control" name="last_name" value="<?= e($u['last_name']) ?>" data-ln></div>
          <div class="col-md-6"><label class="form-label"><?= te('user.display_name') ?></label><input class="form-control" name="display_name" value="<?= e($u['display_name']) ?>" placeholder="<?= te('admin.auto_from_names') ?>"></div>
          <div class="col-md-6"><label class="form-label"><?= te('user.language') ?></label><select class="form-select" name="language"><?php foreach ($languages as $c => $l): ?><option value="<?= $c ?>" <?= $u['language'] === $c ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        </div></div>
      </div>
      <div class="card mb-4">
        <div class="card-header"><i class="bi bi-briefcase me-2 text-primary"></i><?= te('admin.signature_fields') ?><small class="text-muted fw-normal ms-2"><?= te('admin.signature_fields_desc') ?></small></div>
        <div class="card-body"><div class="row g-3">
          <div class="col-md-6"><label class="form-label"><?= te('user.job_title') ?></label><input class="form-control" name="job_title" value="<?= e($u['job_title']) ?>"></div>
          <div class="col-md-6"><label class="form-label"><?= te('user.department') ?></label><input class="form-control" name="department" value="<?= e($u['department']) ?>" list="departments"><datalist id="departments"><?php foreach ($departments as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist></div>
          <div class="col-md-6"><label class="form-label"><?= te('user.phone') ?></label><input class="form-control" name="phone" value="<?= e($u['phone']) ?>"></div>
          <div class="col-md-6"><label class="form-label"><?= te('user.mobile') ?></label><input class="form-control" name="mobile" value="<?= e($u['mobile']) ?>"></div>
          <div class="col-12"><label class="form-label"><?= te('admin.signature_template') ?></label><select class="form-select" name="signature_template_id"><option value=""><?= te('admin.default_template') ?></option><?php foreach ($templates as $tp): ?><option value="<?= (int) $tp['id'] ?>" <?= (int) $u['signature_template_id'] === (int) $tp['id'] ? 'selected' : '' ?>><?= e($tp['name']) ?><?= $tp['is_default'] ? ' (' . te('admin.default') . ')' : '' ?></option><?php endforeach; ?></select></div>
        </div></div>
      </div>
      <?php if (!$isNew): ?>
      <div class="card mb-4">
        <div class="card-header"><i class="bi bi-at me-2 text-primary"></i><?= te('admin.aliases') ?><small class="text-muted fw-normal ms-2"><?= te('admin.aliases_desc') ?></small></div>
        <div class="card-body">
          <?php foreach ($aliases as $a): ?>
            <div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-arrow-return-right text-muted"></i><code class="flex-grow-1"><?= e($a['address']) ?></code>
            <button class="btn btn-ghost btn-sm text-danger" form="alias-del-<?= (int) $a['id'] ?>"><i class="bi bi-x-lg"></i></button></div>
          <?php endforeach; ?>
          <div class="input-group mt-2" style="max-width:520px"><input class="form-control" name="address" form="alias-form" placeholder="contact@<?= e(explode('@', $u['email'])[1] ?? '') ?>" type="email"><button class="btn btn-light" form="alias-form"><i class="bi bi-plus-lg me-1"></i><?= te('admin.add_alias') ?></button></div>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <div class="col-xl-4">
      <div class="card mb-4">
        <div class="card-header"><i class="bi bi-shield-lock me-2 text-primary"></i><?= te('admin.access') ?></div>
        <div class="card-body">
          <div class="mb-3"><label class="form-label"><?= te('admin.status') ?></label><select class="form-select" name="status"><option value="active" <?= $u['status'] === 'active' ? 'selected' : '' ?>><?= te('admin.st_active') ?></option><option value="disabled" <?= $u['status'] === 'disabled' ? 'selected' : '' ?>><?= te('admin.st_disabled') ?></option></select></div>
          <div class="mb-3"><label class="form-label"><?= te('admin.role') ?></label><select class="form-select" name="role"><option value="user"><?= te('admin.role_user') ?></option><option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>><?= te('admin.role_admin') ?></option></select></div>
          <div class="mb-3"><label class="form-label"><?= te('admin.quota') ?></label><div class="input-group"><input type="number" class="form-control" name="quota_mb" min="0" step="256" value="<?= (int) $u['quota_mb'] ?>"><span class="input-group-text">Mo</span></div><div class="form-text"><?= te('admin.quota_hint') ?></div></div>
          <div class="mb-2"><label class="form-label"><?= te($isNew ? 'auth.password' : 'admin.new_password') ?></label>
            <div class="input-group"><input type="text" class="form-control m4w-code" name="password" <?= $isNew ? 'required' : '' ?> autocomplete="new-password" data-strength data-pw><button class="btn btn-light" type="button" data-gen-password title="<?= te('admin.generate') ?>"><i class="bi bi-magic"></i></button></div>
            <div class="progress mt-1" style="height:4px"><div class="progress-bar" data-strength-bar style="width:0"></div></div>
            <div class="form-text"><?= te($isNew ? 'pw.policy' : 'admin.pw_keep', ['n' => M4W\Core\Settings::get('security.password_min', 10)]) ?></div></div>
          <div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" name="must_change_password" value="1" id="mcp" <?= $u['must_change_password'] ? 'checked' : '' ?>><label class="form-check-label" for="mcp"><?= te('admin.must_change') ?></label></div>
          <?php if ($isNew): ?><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="send_welcome" value="1" id="sw" checked><label class="form-check-label" for="sw"><?= te('admin.send_welcome') ?></label></div><?php endif; ?>
        </div>
      </div>
      <?php if (!$isNew): ?>
      <div class="card mb-4">
        <div class="card-header"><i class="bi bi-info-circle me-2 text-primary"></i><?= te('admin.info') ?></div>
        <ul class="list-group list-group-flush small">
          <li class="list-group-item d-flex justify-content-between"><span class="text-muted"><?= te('admin.storage') ?></span><span><?= e(format_bytes((int) $u['used_bytes'])) ?></span></li>
          <li class="list-group-item d-flex justify-content-between"><span class="text-muted"><?= te('admin.last_login') ?></span><span><?= e(format_datetime((int) $u['last_login_at'])) ?></span></li>
          <li class="list-group-item d-flex justify-content-between align-items-center"><span class="text-muted">2FA</span><span><?= $u['totp_enabled'] ? '<span class="badge badge-soft-success">' . te('sec.2fa_on') . '</span> <button class="btn btn-link btn-sm p-0 ms-1" form="reset2fa">' . te('admin.reset') . '</button>' : '<span class="badge badge-soft-secondary">' . te('sec.2fa_off') . '</span>' ?></span></li>
        </ul>
        <?php if ((int) $u['id'] !== (int) $currentUser['id']): ?>
        <div class="card-footer"><button class="btn btn-outline-danger btn-sm w-100" form="user-delete"><i class="bi bi-trash3 me-1"></i><?= te('admin.delete_user') ?></button></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="m4w-sticky-actions"><a class="btn btn-light" href="<?= e(url('admin/users')) ?>"><?= te('common.cancel') ?></a><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= te($isNew ? 'admin.create_user' : 'common.save') ?></button></div>
</form>
<?php if (!$isNew): ?>
<form method="post" action="<?= e(url('admin/aliases/save')) ?>" id="alias-form" data-alias-form><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="back" value="user"></form>
<?php foreach ($aliases as $a): ?><form method="post" action="<?= e(url('admin/aliases/delete')) ?>" id="alias-del-<?= (int) $a['id'] ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="back" value="user"></form><?php endforeach; ?>
<form method="post" action="<?= e(url('admin/users/delete')) ?>" id="user-delete" data-confirm="<?= te('admin.confirm_delete_user', ['email' => $u['email']]) ?>" data-confirm-text="<?= te('admin.confirm_delete_user_text') ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"></form>
<form method="post" action="<?= e(url('admin/users/reset2fa')) ?>" id="reset2fa" data-confirm="<?= te('admin.confirm_reset2fa') ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"></form>
<?php endif; ?>
