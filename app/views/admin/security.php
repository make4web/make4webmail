<?php use M4W\Core\Settings as S; ?>
<div class="m4w-page-header"><div><h1><?= te('admin.security_title') ?></h1><p><?= te('admin.security_desc') ?></p></div></div>
<form method="post" action="<?= e(url('admin/security')) ?>">
  <?= csrf_field() ?>
  <div class="m4w-section">
    <div class="m4w-section-title"><i class="bi bi-key me-2 text-primary"></i><?= te('admin.pw_policy') ?></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.pw_min') ?></div><div><input class="form-control" type="number" name="password_min" min="8" max="64" value="<?= (int) S::get('security.password_min') ?>" style="max-width:120px"></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.pw_classes') ?><small><?= te('admin.pw_classes_desc') ?></small></div><div><select class="form-select" name="password_classes" style="max-width:120px"><?php for ($i = 1; $i <= 4; $i++): ?><option <?= (int) S::get('security.password_classes') === $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></div></div>
  </div>
  <div class="m4w-section">
    <div class="m4w-section-title"><i class="bi bi-door-closed me-2 text-primary"></i><?= te('admin.login_protection') ?></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.max_attempts') ?><small><?= te('admin.max_attempts_desc') ?></small></div><div class="d-flex gap-2 align-items-center flex-wrap"><input class="form-control" type="number" name="max_attempts" value="<?= (int) S::get('security.max_attempts') ?>" style="max-width:100px"><span class="small text-muted"><?= te('admin.attempts_in') ?></span><input class="form-control" type="number" name="lockout_minutes" value="<?= (int) S::get('security.lockout_minutes') ?>" style="max-width:100px"><span class="small text-muted">min</span></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.session_idle') ?></div><div class="input-group" style="max-width:180px"><input class="form-control" type="number" name="session_idle" value="<?= (int) S::get('security.session_idle') ?>"><span class="input-group-text">min</span></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.session_abs') ?></div><div class="input-group" style="max-width:180px"><input class="form-control" type="number" name="session_absolute" value="<?= (int) S::get('security.session_absolute') ?>"><span class="input-group-text">min</span></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.enforce_2fa') ?><small><?= te('admin.enforce_2fa_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enforce_2fa_admin" value="1" <?= S::get('security.enforce_2fa_admin') ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.admin_ips') ?><small><?= te('admin.admin_ips_desc', ['ip' => $req->ip()]) ?></small></div><div><input class="form-control m4w-code" name="allowed_ips_admin" value="<?= e(S::get('security.allowed_ips_admin')) ?>" placeholder="192.168.1.0/24, 203.0.113.4"></div></div>
  </div>
  <div class="m4w-section">
    <div class="m4w-section-title"><i class="bi bi-envelope-exclamation me-2 text-primary"></i><?= te('admin.mail_policy') ?></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.block_images') ?><small><?= te('admin.block_images_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="block_remote_images" value="1" <?= S::get('security.block_remote_images') ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.ext_forward') ?><small><?= te('admin.ext_forward_desc') ?></small></div><div><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="allow_external_forward" value="1" <?= S::get('security.allow_external_forward') ? 'checked' : '' ?>></div><input class="form-control" name="forward_whitelist" value="<?= e(S::get('security.forward_whitelist')) ?>" placeholder="<?= te('admin.fwd_wl_ph') ?>"></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.max_attach') ?></div><div class="input-group" style="max-width:180px"><input class="form-control" type="number" name="max_attachment_mb" value="<?= (int) S::get('security.max_attachment_mb') ?>"><span class="input-group-text">Mo</span></div></div>
  </div>
  <div class="m4w-section">
    <div class="m4w-section-title"><i class="bi bi-toggles me-2 text-primary"></i><?= te('admin.features') ?></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.f_user_sig') ?><small><?= te('admin.f_user_sig_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="user_signature" value="1" <?= S::get('features.user_signature') ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.f_fetch') ?><small><?= te('admin.f_fetch_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="fetch_accounts" value="1" <?= S::get('features.fetch_accounts') ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.f_undo') ?></div><div class="input-group" style="max-width:180px"><input class="form-control" type="number" min="0" max="30" name="undo_send_seconds" value="<?= (int) S::get('features.undo_send_seconds') ?>"><span class="input-group-text">s</span></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.f_lang') ?></div><div><select class="form-select" name="default_language" style="max-width:200px"><?php foreach (M4W\Core\I18n::LANGUAGES as $c => $l): ?><option value="<?= $c ?>" <?= S::get('features.default_language') === $c ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div></div>
  </div>
  <div class="m4w-sticky-actions"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= te('common.save') ?></button></div>
</form>
<div class="card mt-5">
  <div class="card-header d-flex align-items-center"><i class="bi bi-shield-x me-2 text-danger"></i><?= te('admin.failed_ips') ?>
    <form method="post" action="<?= e(url('admin/security')) ?>" class="ms-auto"><?= csrf_field() ?><input type="hidden" name="unlock_all" value="1"><button class="btn btn-light btn-sm"><i class="bi bi-unlock me-1"></i><?= te('admin.unlock_all') ?></button></form></div>
  <div class="table-responsive"><table class="table"><thead><tr><th>IP</th><th><?= te('admin.attempts') ?></th><th><?= te('admin.last_attempt') ?></th></tr></thead><tbody>
  <?php foreach ($blocked as $b): ?><tr><td class="m4w-code"><?= e($b['ip']) ?></td><td><span class="badge <?= $b['n'] >= S::get('security.max_attempts') ? 'badge-soft-danger' : 'badge-soft-warning' ?>"><?= (int) $b['n'] ?></span></td><td class="small"><?= e(format_datetime((int) $b['last'])) ?></td></tr><?php endforeach; ?>
  <?php if (!$blocked): ?><tr><td colspan="3" class="text-muted small"><?= te('admin.no_failed') ?></td></tr><?php endif; ?>
  </tbody></table></div>
</div>
