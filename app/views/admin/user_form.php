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
<form method="post" action="<?= e(url('admin/users/save')) ?>" autocomplete="off" data-keep-input>
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
      <?php $wm = M4W\Service\RemoteMailbox::active(); $rm = $remote ?? null; $rmStatus = M4W\Service\RemoteMailbox::status($rm); $forced = !$wm && $rm && (int) $rm['forced']; ?>
      <div class="card mb-4" id="remote">
        <div class="card-header d-flex align-items-center"><i class="bi bi-window-stack me-2 text-primary"></i><?= te($wm ? 'wm.user_card' : 'own.card') ?>
          <?php if ($wm || $forced): ?><span class="badge ms-auto <?= ['ok' => 'badge-soft-success', 'error' => 'badge-soft-danger', 'pending' => 'badge-soft-warning'][$rmStatus] ?? 'badge-soft-secondary' ?>"><?= te('wm.st_' . $rmStatus) ?></span><?php endif; ?></div>
        <div class="card-body">
          <p class="small text-muted"><?= te($wm ? 'wm.user_card_desc' : (M4W\Service\Collector::active() ? 'own.desc_collector' : 'own.desc')) ?></p>
          <input type="hidden" name="remote_present" value="1">
          <?php if (!$wm): ?>
          <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="remote_forced" value="1" id="rm-forced" data-own-toggle <?= $forced ? 'checked' : '' ?>><label class="form-check-label fw-semibold" for="rm-forced"><?= te('own.force') ?></label></div>
          <?php endif; ?>
          <div<?= !$wm ? ' data-own-fields' . ($forced ? '' : ' class="d-none"') : '' ?>>
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label"><?= te('wm.login') ?></label><input class="form-control" name="remote_login" value="<?= e($rm['login'] ?? '') ?>" placeholder="<?= e($isNew ? t('wm.login_ph') : $u['email']) ?>" autocomplete="off"></div>
            <div class="col-md-6"><label class="form-label"><?= te('wm.password') ?></label><input class="form-control" type="password" name="remote_password" autocomplete="new-password" placeholder="<?= $rm && $rm['password_enc'] !== '' ? '••••••••  (' . te('admin.kept') . ')' : te('wm.password_ph') ?>"></div>
          </div>
          <div class="form-text"><?= te('wm.password_hint') ?></div>
          <?php if ($rm): ?>
          <div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" name="remote_enabled" value="1" id="rm-en" <?= (int) $rm['enabled'] ? 'checked' : '' ?>><label class="form-check-label" for="rm-en"><?= te('wm.enabled') ?></label></div>
          <?php endif; ?>
          <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="remote_two_way" value="1" id="rm-two" <?= !$rm || (int) ($rm['two_way'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label" for="rm-two"><?= te('wm.two_way') ?></label><div class="form-text"><?= te('wm.two_way_hint') ?></div></div>
          <details class="mt-3"<?= !$wm && M4W\Core\Settings::get('webmail.imap_host', '') === '' ? ' open' : '' ?>><summary class="small fw-semibold"><?= te('wm.override') ?></summary>
            <p class="small text-muted mt-2"><?= te($wm || M4W\Core\Settings::get('webmail.imap_host', '') !== '' ? 'wm.override_hint' : 'own.servers_hint') ?></p>
            <div class="row g-2">
              <div class="col-md-6"><div class="input-group input-group-sm"><span class="input-group-text">IMAP</span><input class="form-control m4w-code" name="remote_imap_host" value="<?= e($rm['imap_host'] ?? '') ?>" placeholder="<?= e(M4W\Core\Settings::get('webmail.imap_host')) ?>" aria-label="<?= te('wm.imap_server') ?>"><input class="form-control" style="max-width:80px" type="number" name="remote_imap_port" value="<?= (int) ($rm['imap_port'] ?? 0) ?: '' ?>" placeholder="<?= (int) M4W\Core\Settings::get('webmail.imap_port') ?>" aria-label="Port IMAP"></div></div>
              <div class="col-md-6"><div class="input-group input-group-sm"><span class="input-group-text">SMTP</span><input class="form-control m4w-code" name="remote_smtp_host" value="<?= e($rm['smtp_host'] ?? '') ?>" placeholder="<?= e(M4W\Core\Settings::get('webmail.smtp_host')) ?>" aria-label="<?= te('wm.smtp_server') ?>"><input class="form-control" style="max-width:80px" type="number" name="remote_smtp_port" value="<?= (int) ($rm['smtp_port'] ?? 0) ?: '' ?>" placeholder="<?= (int) M4W\Core\Settings::get('webmail.smtp_port') ?>" aria-label="Port SMTP"></div></div>
            </div>
          </details>
          <?php if (!$wm): ?>
          <div class="row g-3 mt-1">
            <div class="col-md-6"><label class="form-label" for="rm-limit"><?= te('own.limit') ?></label><div class="input-group"><input class="form-control" type="number" min="0" id="rm-limit" name="remote_hourly_limit" value="<?= (int) ($rm['hourly_limit'] ?? 0) ?: '' ?>" placeholder="200"><span class="input-group-text"><?= te('own.per_hour') ?></span></div><div class="form-text"><?= te('own.limit_hint') ?></div></div>
            <?php if (M4W\Service\Collector::active()): ?>
            <div class="col-md-6 d-flex align-items-start pt-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="remote_overflow" value="1" id="rm-over" <?= !$rm || (int) $rm['overflow'] ? 'checked' : '' ?>><label class="form-check-label" for="rm-over"><?= te('own.overflow') ?></label><div class="form-text"><?= te('own.overflow_hint', ['box' => M4W\Service\Collector::address()]) ?></div></div></div>
            <?php endif; ?>
            <div class="col-md-6"><label class="form-label" for="rm-leave"><?= te('own.leave') ?></label>
              <select class="form-select" id="rm-leave" name="remote_leave_days">
                <?php foreach ([-1 => 'own.leave_never', 90 => 'wm.leave_90', 30 => 'wm.leave_30', 7 => 'wm.leave_7', 0 => 'wm.leave_now'] as $lv => $ll): ?><option value="<?= $lv ?>" <?= (int) ($rm['leave_days'] ?? -1) === $lv ? 'selected' : '' ?>><?= te($ll) ?></option><?php endforeach; ?>
              </select><div class="form-text"><?= te('own.leave_hint') ?></div></div>
            <div class="col-md-6 d-flex align-items-start pt-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="remote_save_sent" value="1" id="rm-sent" <?= !$rm || (int) ($rm['save_sent'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label" for="rm-sent"><?= te('own.save_sent') ?></label><div class="form-text"><?= te('own.save_sent_hint') ?></div></div></div>
          </div>
          <?php if ($rm): $eff = M4W\Service\RemoteMailbox::effective($rm); ?>
          <div class="small mt-3 p-2 rounded-3 bg-body-tertiary"><i class="bi bi-hdd-network me-1"></i><?= te('own.effective', ['login' => $eff['login'], 'imap' => $eff['imap_host'] . ':' . $eff['imap_port'], 'smtp' => $eff['smtp_host'] . ':' . $eff['smtp_port']]) ?>
            <?php if ((string) $rm['imap_host'] === '' || (string) $rm['smtp_host'] === ''): ?><div class="text-warning-emphasis mt-1"><i class="bi bi-info-circle me-1"></i><?= te('own.inherited') ?></div><?php endif; ?>
            <?php $del = M4W\Service\RemoteMailbox::deletedStats($rm); ?>
            <div class="mt-1"><i class="bi bi-trash3 me-1"></i><?= $del ? te('own.deleted_n', ['n' => $del['n'], 'date' => date('d/m/Y H:i', (int) $del['last'])]) : te('own.deleted_none') ?></div>
            <?php $tw = (int) ($rm['two_way'] ?? 1) ? M4W\Service\RemoteMailbox::twoWayReport($rm) : null; if ($tw): ?>
            <div class="mt-1"><i class="bi bi-arrow-left-right me-1"></i><?= te('wm.tw_report', ['time' => date('d/m/Y H:i', (int) $tw['at']), 'followed' => $tw['followed'], 'provider' => $tw['provider'], 'trashed' => $tw['trashed'], 'archived' => $tw['archived'], 'moved' => $tw['moved'], 'flags' => $tw['flags']]) ?>
              <?php if ($tw['other_way'] > 0): ?><div class="text-muted"><?= te('wm.tw_other_way', ['n' => $tw['other_way']]) ?></div><?php endif; ?>
              <?php if (!empty($tw['failed'])): ?><div class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= te('wm.tw_failed', ['n' => (int) $tw['failed'], 'error' => (string) ($tw['error'] ?? '')]) ?></div><?php endif; ?>
              <?php if (!empty($tw['waiting_empty'])): ?><div class="text-warning-emphasis"><?= te('wm.tw_waiting_empty') ?></div><?php endif; ?></div>
            <?php endif; ?>
            <?php $col = M4W\Service\Collector::account(); if ($col && M4W\Service\RemoteMailbox::sharedWithOwn($col) && mb_strtolower((string) M4W\Service\RemoteMailbox::effective($col)['login']) === mb_strtolower((string) $eff['login'])): ?>
            <div class="text-danger mt-1"><i class="bi bi-exclamation-triangle me-1"></i><?= te('own.same_as_collector') ?></div><?php endif; ?></div>
          <?php endif; ?>
          <?php endif; ?>
          <?php if ($rm): ?>
          <div class="small text-muted mt-3"><?= te('wm.last_sync', ['date' => $rm['last_ok_at'] ? format_datetime((int) $rm['last_ok_at']) : '—']) ?></div>
          <?php $rs = M4W\Service\RemoteMailbox::restoreState($rm); if ($rs): ?><div class="small mt-1 <?= $rs['finished'] ? 'text-muted' : 'text-primary' ?>"><i class="bi bi-cloud-upload me-1"></i><?= te($rs['finished'] ? 'own.restore_done' : 'own.restore_running', ['done' => $rs['done'], 'skipped' => $rs['skipped'], 'failed' => $rs['failed'], 'total' => $rs['total']]) ?><?= $rs['error'] !== '' ? ' — ' . e($rs['error']) : '' ?></div><?php endif; ?>
          <?php if ($rm['last_error'] !== ''): ?><div class="alert alert-danger small py-2 mt-2 mb-0"><i class="bi bi-exclamation-triangle me-1"></i><?= e($rm['last_error']) ?></div><?php endif; ?>
          <div class="d-flex gap-2 mt-3 flex-wrap">
            <button type="button" class="btn btn-light btn-sm" data-remote-action="<?= e(url('admin/users/' . (int) $u['id'] . '/remote/test')) ?>"><i class="bi bi-plug me-1"></i><?= te('wm.test') ?></button>
            <button type="button" class="btn btn-light btn-sm" data-remote-action="<?= e(url('admin/users/' . (int) $u['id'] . '/remote/sync')) ?>"><i class="bi bi-arrow-repeat me-1"></i><?= te('wm.sync_now') ?></button>
            <?php if (!$wm && $forced): ?><button type="button" class="btn btn-light btn-sm" data-remote-action="<?= e(url('admin/users/' . (int) $u['id'] . '/remote/restore')) ?>" data-remote-confirm="<?= te('own.restore_confirm') ?>"><i class="bi bi-cloud-upload me-1"></i><?= te('own.restore') ?></button><?php endif; ?>
            <span class="small align-self-center" data-remote-result role="status"></span>
          </div>
          <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="card mb-4">
        <div class="card-header"><i class="bi bi-briefcase me-2 text-primary"></i><?= te('admin.signature_fields') ?><small class="text-muted fw-normal ms-2"><?= te('admin.signature_fields_desc') ?></small></div>
        <div class="card-body"><div class="row g-3">
          <div class="col-md-6"><label class="form-label"><?= te('user.job_title') ?></label><input class="form-control" name="job_title" value="<?= e($u['job_title']) ?>"></div>
          <div class="col-md-6"><label class="form-label"><?= te('user.department') ?></label><input class="form-control" name="department" value="<?= e($u['department']) ?>" list="departments"><datalist id="departments"><?php foreach ($departments as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist></div>
          <div class="col-md-6"><label class="form-label"><?= te('user.phone') ?></label><input class="form-control" name="phone" value="<?= e($u['phone']) ?>"></div>
          <div class="col-md-6"><label class="form-label"><?= te('user.mobile') ?></label><input class="form-control" name="mobile" value="<?= e($u['mobile']) ?>"></div>
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
      <div class="card mb-4">
        <div class="card-header"><i class="bi bi-clock-history me-2 text-primary"></i><?= te('deleg.journal') ?><small class="text-muted fw-normal ms-2"><?= te('deleg.journal_desc') ?></small></div>
        <?php include __DIR__ . '/../partials/delegation_journal.php'; ?>
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
        <div class="card-header d-flex align-items-center"><i class="bi bi-person-badge me-2 text-primary"></i><?= te('deleg.card_title') ?></div>
        <div class="card-body">
          <p class="small text-muted"><?= te(M4W\Service\Delegation::adminsByDefault() ? 'deleg.card_admins_on' : 'deleg.card_admins_off') ?></p>
          <?php if ((int) $u['id'] !== (int) $currentUser['id'] && M4W\Service\Delegation::via($currentUser, (int) $u['id'])): ?>
          <button type="button" class="btn btn-soft w-100 mb-3" data-open-mailbox="<?= (int) $u['id'] ?>" data-name="<?= e($u['name']) ?>" data-email="<?= e($u['email']) ?>" data-reason="<?= M4W\Service\Delegation::reasonRequired($currentUser, (int) $u['id']) ? 1 : 0 ?>"><i class="bi bi-box-arrow-in-right me-1"></i><?= te('deleg.open_this') ?></button>
          <?php endif; ?>
          <div class="fw-semibold small mb-1"><?= te('deleg.delegates') ?></div>
          <div class="form-text mt-0 mb-2"><?= te('deleg.delegates_desc') ?></div>
          <input type="hidden" name="delegates_present" value="1">
          <input class="form-control form-control-sm mb-2" type="search" placeholder="<?= te('deleg.filter_users') ?>" aria-label="<?= te('deleg.filter_users') ?>" data-filter-list="#deleg-list">
          <div class="m4w-check-list" id="deleg-list">
            <?php foreach ($others as $o): $on = $o['display_name'] ?: $o['email']; ?>
            <label class="m4w-check-item" data-filter-text="<?= e(mb_strtolower($on . ' ' . $o['email'])) ?>"><input class="form-check-input" type="checkbox" name="delegates[]" value="<?= (int) $o['id'] ?>" <?= in_array((int) $o['id'], $delegates, true) ? 'checked' : '' ?>><span class="min-w-0"><span class="d-block text-truncate"><?= e($on) ?><?= $o['role'] === 'admin' ? ' <span class="badge badge-soft ms-1">Admin</span>' : '' ?></span><small class="text-muted d-block text-truncate"><?= e($o['email']) ?></small></span></label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="card mb-4">
        <div class="card-header d-flex align-items-center"><i class="bi bi-inboxes me-2 text-primary"></i><?= te('shared.card_title') ?></div>
        <div class="card-body">
          <p class="small text-muted"><?= te('shared.card_desc') ?></p>
          <input type="hidden" name="members_present" value="1">
          <input class="form-control form-control-sm mb-2" type="search" placeholder="<?= te('deleg.filter_users') ?>" aria-label="<?= te('deleg.filter_users') ?>" data-filter-list="#members-list">
          <div class="m4w-check-list" id="members-list">
            <?php foreach ($others as $o): $on = $o['display_name'] ?: $o['email']; ?>
            <label class="m4w-check-item" data-filter-text="<?= e(mb_strtolower($on . ' ' . $o['email'])) ?>"><input class="form-check-input" type="checkbox" name="members[]" value="<?= (int) $o['id'] ?>" <?= in_array((int) $o['id'], $members, true) ? 'checked' : '' ?>><span class="min-w-0"><span class="d-block text-truncate"><?= e($on) ?></span><small class="text-muted d-block text-truncate"><?= e($o['email']) ?></small></span></label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
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
