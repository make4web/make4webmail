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
  <div class="m4w-section" id="guard">
    <div class="m4w-section-title"><i class="bi bi-person-exclamation me-2 text-primary"></i><?= te('guard.title') ?></div>
    <p class="small text-muted"><?= te('guard.desc') ?></p>
    <div class="m4w-setting-row"><div class="label"><?= te('guard.enable') ?></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="guard_enabled" value="1" aria-label="<?= te('guard.enable') ?>" <?= S::get('guard.enabled', 1) ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('guard.limits') ?><small><?= te('guard.limits_desc') ?></small></div><div class="d-flex gap-2 align-items-center flex-wrap">
      <input class="form-control" type="number" min="1" name="guard_hour" value="<?= (int) S::get('guard.hour_limit', 200) ?>" style="max-width:110px" aria-label="<?= te('guard.per_hour') ?>"><span class="small text-muted"><?= te('guard.per_hour') ?></span>
      <input class="form-control" type="number" min="1" name="guard_day" value="<?= (int) S::get('guard.day_limit', 1000) ?>" style="max-width:110px" aria-label="<?= te('guard.per_day') ?>"><span class="small text-muted"><?= te('guard.per_day') ?></span></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('guard.burst') ?><small><?= te('guard.burst_desc') ?></small></div><div class="d-flex gap-2 align-items-center flex-wrap">
      <input class="form-control" type="number" min="5" name="guard_burst" value="<?= (int) S::get('guard.burst', 80) ?>" style="max-width:110px" aria-label="<?= te('guard.burst') ?>"><span class="small text-muted"><?= te('guard.recipients_in') ?></span>
      <input class="form-control" type="number" min="1" max="120" name="guard_burst_minutes" value="<?= (int) S::get('guard.burst_minutes', 10) ?>" style="max-width:90px" aria-label="<?= te('guard.minutes') ?>"><span class="small text-muted">min</span></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('guard.on_burst') ?></div><div><select class="form-select" name="guard_on_burst" style="max-width:420px" aria-label="<?= te('guard.on_burst') ?>">
      <?php foreach (['block_send', 'lock'] as $o): ?><option value="<?= $o ?>" <?= S::get('guard.on_burst', 'block_send') === $o ? 'selected' : '' ?>><?= te('guard.on_' . $o) ?></option><?php endforeach; ?>
    </select></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('guard.login_mail') ?><small><?= te('guard.login_mail_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="guard_login_mail" value="1" aria-label="<?= te('guard.login_mail') ?>" <?= S::get('guard.new_login_mail', 1) ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('guard.admins_all') ?><small><?= te('guard.admins_all_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="guard_admins_all" value="1" aria-label="<?= te('guard.admins_all') ?>" <?= S::get('guard.alert_admins_all', 0) ? 'checked' : '' ?>></div></div>
  </div>
  <div class="m4w-section">
    <div class="m4w-section-title"><i class="bi bi-person-badge me-2 text-primary"></i><?= te('deleg.admin_title') ?></div>
    <div class="m4w-setting-row"><div class="label"><?= te('deleg.admin_default') ?><small><?= te('deleg.admin_default_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="deleg_admins" value="1" <?= S::get('delegation.admins') ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('deleg.admin_reason') ?><small><?= te('deleg.admin_reason_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="deleg_reason" value="1" <?= S::get('delegation.require_reason') ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('deleg.admin_notify') ?><small><?= te('deleg.admin_notify_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="deleg_notify" value="1" <?= S::get('delegation.notify_owner') ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('deleg.admin_private') ?><small><?= te('deleg.admin_private_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="deleg_private" value="1" <?= S::get('delegation.hide_personal') ? 'checked' : '' ?>></div></div>
  </div>
  <div class="m4w-section">
    <div class="m4w-section-title"><i class="bi bi-envelope-exclamation me-2 text-primary"></i><?= te('admin.mail_policy') ?></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.block_images') ?><small><?= te('admin.block_images_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="block_remote_images" value="1" <?= S::get('security.block_remote_images') ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.image_proxy') ?><small><?= te('admin.image_proxy_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="image_proxy" value="1" aria-label="<?= te('admin.image_proxy') ?>" <?= S::get('security.image_proxy') ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.image_max_px') ?></div><div class="input-group" style="max-width:180px"><input class="form-control" type="number" min="200" max="4000" name="image_max_px" aria-label="<?= te('admin.image_max_px') ?>" value="<?= (int) S::get('security.image_max_px') ?>"><span class="input-group-text">px</span></div></div>
  </div>
  <div class="m4w-section" id="antivirus">
    <div class="m4w-section-title"><i class="bi bi-virus me-2 text-primary"></i><?= te('admin.av_title') ?>
      <?php $avUp = M4W\Service\Antivirus::ping(); ?><span class="badge ms-2 <?= $avUp ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= $avUp ? e(M4W\Service\Antivirus::version() ?: t('admin.av_up')) : te('admin.av_down') ?></span></div>
    <p class="small text-muted"><?= te('admin.av_desc') ?></p>
    <?php if (!$avUp && S::get('security.antivirus')): $avd = M4W\Service\Antivirus::diagnose(); ?>
    <div class="alert alert-warning small" role="status"><div class="w-100">
      <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i><?= te('av.diag_title') ?></div>
      <p class="mb-2"><?= e($avd['reason']) ?></p>
      <?php if ($avd['found']): ?><p class="mb-2"><?= te('av.found') ?>
        <?php foreach ($avd['found'] as $fa): ?><button type="button" class="btn btn-sm btn-light ms-1 m4w-code" data-av-use="<?= e($fa) ?>"><?= e($fa) ?></button><?php endforeach; ?></p><?php endif; ?>
      <details><summary><?= te('av.commands') ?></summary>
        <pre class="m4w-code small mt-2 mb-0">sudo apt install clamav-daemon clamav-freshclam
sudo systemctl stop clamav-freshclam &amp;&amp; sudo freshclam &amp;&amp; sudo systemctl start clamav-freshclam
sudo systemctl enable --now clamav-daemon
sudo systemctl status clamav-daemon
ls -l /var/run/clamav/</pre></details>
      <a class="btn btn-sm btn-warning mt-2" href="<?= e(url('admin/security', ['retest' => time()])) ?>#antivirus" data-av-retest><i class="bi bi-arrow-repeat me-1"></i><?= te('av.retest') ?></a>
    </div></div>
    <?php endif; ?>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.av_enable') ?></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="antivirus" value="1" aria-label="<?= te('admin.av_enable') ?>" <?= S::get('security.antivirus') ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.av_socket') ?><small><?= te('admin.av_socket_hint') ?></small></div><div><input class="form-control m4w-code" style="max-width:340px" name="clamd" data-av-input aria-label="<?= te('admin.av_socket') ?>" value="<?= e(S::get('security.clamd')) ?>"></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.block_exec') ?><small><?= te('admin.block_exec_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="block_executables" value="1" aria-label="<?= te('admin.block_exec') ?>" <?= S::get('security.block_executables') ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.ext_forward') ?><small><?= te('admin.ext_forward_desc') ?></small></div><div><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="allow_external_forward" value="1" <?= S::get('security.allow_external_forward') ? 'checked' : '' ?>></div><input class="form-control" name="forward_whitelist" value="<?= e(S::get('security.forward_whitelist')) ?>" placeholder="<?= te('admin.fwd_wl_ph') ?>"></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.max_attach') ?></div><div class="input-group" style="max-width:180px"><input class="form-control" type="number" name="max_attachment_mb" value="<?= (int) S::get('security.max_attachment_mb') ?>"><span class="input-group-text">Mo</span></div></div>
  </div>
  <div class="m4w-section" id="links">
    <div class="m4w-section-title"><i class="bi bi-link-45deg me-2 text-primary"></i><?= te('admin.links_title') ?></div>
    <p class="small text-muted"><?= te('admin.links_desc') ?></p>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.links_check') ?><small><?= te('admin.links_check_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="link_check" value="1" aria-label="<?= te('admin.links_check') ?>" <?= S::get('security.link_check') ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><label for="sec-idn"><?= te('admin.idn_links') ?></label><small><?= te('admin.idn_links_desc') ?></small></div><div>
      <select class="form-select" style="max-width:420px" id="sec-idn" name="idn_links">
        <?php foreach (['block', 'suspicious', 'warn'] as $o): ?><option value="<?= $o ?>" <?= S::get('security.idn_links', 'block') === $o ? 'selected' : '' ?>><?= te('admin.idn_' . $o) ?></option><?php endforeach; ?>
      </select></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.links_sb') ?><small><?= te('admin.links_sb_desc') ?></small></div>
      <div class="d-flex flex-column gap-2" style="max-width:420px">
        <select class="form-select" name="safebrowsing_api" aria-label="<?= te('admin.links_sb_api') ?>">
          <?php foreach (['safebrowsing', 'webrisk'] as $api): ?><option value="<?= $api ?>" <?= S::get('security.safebrowsing_api') === $api ? 'selected' : '' ?>><?= te('admin.links_api_' . $api) ?></option><?php endforeach; ?>
        </select>
        <input class="form-control m4w-code" type="password" name="safebrowsing_key" autocomplete="off" aria-label="<?= te('admin.links_sb_key') ?>" placeholder="<?= M4W\Service\LinkGuard::hasApiKey() ? te('admin.links_sb_key_set') : te('admin.links_sb_key_ph') ?>">
        <?php if (M4W\Service\LinkGuard::hasApiKey()): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="safebrowsing_clear" value="1" id="sb-clear"><label class="form-check-label small" for="sb-clear"><?= te('admin.links_sb_clear') ?></label></div><?php endif; ?>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="safebrowsing_delivery" value="1" id="sb-deliv" <?= S::get('security.safebrowsing_delivery') ? 'checked' : '' ?>><label class="form-check-label small" for="sb-deliv"><?= te('admin.links_sb_delivery') ?></label></div>
      </div></div>
    <?php $uu = (int) S::get('security.urlhaus_updated', 0); ?>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.links_urlhaus') ?><small><?= $uu ? te('admin.links_urlhaus_state', ['n' => number_format(M4W\Service\LinkGuard::feedSize(), 0, ',', ' '), 'date' => format_datetime($uu, false)]) : te('admin.links_urlhaus_never') ?></small></div><div class="d-flex align-items-center gap-3"><div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="urlhaus" value="1" aria-label="<?= te('admin.links_urlhaus') ?>" <?= S::get('security.urlhaus') ? 'checked' : '' ?>></div><button class="btn btn-light btn-sm" name="urlhaus_update" value="1"><i class="bi bi-arrow-repeat me-1"></i><?= te('admin.disp_update_now') ?></button></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.links_block') ?><small><?= te('admin.links_block_desc') ?></small></div><div><textarea class="form-control m4w-code m4w-code-short" style="min-width:min(380px,70vw)" rows="3" name="link_block" aria-label="<?= te('admin.links_block') ?>"><?= e(S::get('security.link_block')) ?></textarea></div></div>
  </div>
  <div class="m4w-section" id="disposable">
    <div class="m4w-section-title"><i class="bi bi-trash3 me-2 text-primary"></i><?= te('admin.disp_title') ?>
      <span class="badge badge-soft-primary ms-2"><?= te('admin.disp_count', ['n' => number_format(M4W\Service\Senders::listSize(), 0, ',', ' ')]) ?></span></div>
    <p class="small text-muted"><?= te('admin.disp_desc') ?></p>
    <?php $da = M4W\Service\Senders::inboundAction(); ?>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.disp_action') ?></div><div><select class="form-select" name="disposable_action" style="max-width:420px" aria-label="<?= te('admin.disp_action') ?>">
      <?php foreach (['reject', 'spam', 'off'] as $o): ?><option value="<?= $o ?>" <?= $da === $o ? 'selected' : '' ?>><?= te('admin.disp_' . $o) ?></option><?php endforeach; ?>
    </select></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.disp_extra') ?><small><?= te('admin.disp_extra_hint') ?></small></div><div><textarea class="form-control m4w-code m4w-code-short" style="min-width:min(380px,70vw)" rows="3" name="disposable_extra" aria-label="<?= te('admin.disp_extra') ?>" placeholder="exemple-jetable.com"><?= e(S::get('security.disposable_extra')) ?></textarea></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.disp_allow') ?><small><?= te('admin.disp_allow_hint') ?></small></div><div><textarea class="form-control m4w-code m4w-code-short" style="min-width:min(380px,70vw)" rows="3" name="disposable_allow" aria-label="<?= te('admin.disp_allow') ?>"><?= e(S::get('security.disposable_allow')) ?></textarea></div></div>
    <?php $du = (int) S::get('security.disposable_updated', 0); ?>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.disp_auto') ?><small><?= $du ? te('admin.disp_updated', ['date' => format_datetime($du, false)]) : te('admin.disp_bundled') ?></small></div><div class="d-flex align-items-center gap-3"><div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="disposable_autoupdate" value="1" aria-label="<?= te('admin.disp_auto') ?>" <?= S::get('security.disposable_autoupdate') ? 'checked' : '' ?>></div><button class="btn btn-light btn-sm" name="disposable_update" value="1"><i class="bi bi-arrow-repeat me-1"></i><?= te('admin.disp_update_now') ?></button></div></div>
  </div>
  <div class="m4w-section">
    <div class="m4w-section-title"><i class="bi bi-toggles me-2 text-primary"></i><?= te('admin.features') ?></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.f_fetch') ?><small><?= te('admin.f_fetch_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="fetch_accounts" value="1" <?= S::get('features.fetch_accounts') ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.f_push') ?><small><?= te('admin.f_push_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="push_enabled" value="1" <?= S::get('push.enabled') ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.f_push_preview') ?><small><?= te('admin.f_push_preview_desc') ?></small></div><div><select class="form-select" name="push_preview" style="max-width:280px"><option value="1" <?= (int) S::get('push.preview') === 1 ? 'selected' : '' ?>><?= te('admin.push_preview_full') ?></option><option value="0" <?= (int) S::get('push.preview') === 0 ? 'selected' : '' ?>><?= te('admin.push_preview_discreet') ?></option></select></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.f_undo') ?></div><div class="input-group" style="max-width:180px"><input class="form-control" type="number" min="0" max="30" name="undo_send_seconds" value="<?= (int) S::get('features.undo_send_seconds') ?>"><span class="input-group-text">s</span></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.f_lang') ?></div><div><select class="form-select" name="default_language" style="max-width:200px"><?php foreach (M4W\Core\I18n::LANGUAGES as $c => $l): ?><option value="<?= $c ?>" <?= S::get('features.default_language') === $c ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div></div>
  </div>
  <div class="m4w-sticky-actions"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= te('common.save') ?></button></div>
</form>
<?php if ($sendBlocked): ?>
<div class="card mt-5 border-danger-subtle">
  <div class="card-header"><i class="bi bi-send-x me-2 text-danger"></i><?= te('guard.blocked_title') ?></div>
  <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th><?= te('guard.account') ?></th><th><?= te('guard.why') ?></th><th><?= te('guard.held') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($sendBlocked as $b): ?><tr>
    <td><div class="fw-medium"><?= e($b['email']) ?></div><?= $b['status'] !== 'active' ? '<span class="badge badge-soft-danger">' . te('guard.account_locked') . '</span>' : '' ?></td>
    <td class="small"><?= e($b['send_block_reason']) ?></td>
    <td><?= (int) $held[(int) $b['id']] ?></td>
    <td class="text-end text-nowrap">
      <?php if ($held[(int) $b['id']]): ?><form method="post" action="<?= e(url('admin/security/guard')) ?>" class="d-inline" data-confirm="<?= te('guard.discard_confirm') ?>"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="action" value="discard"><button class="btn btn-light btn-sm"><i class="bi bi-trash me-1"></i><?= te('guard.discard') ?></button></form><?php endif; ?>
      <form method="post" action="<?= e(url('admin/security/guard')) ?>" class="d-inline" data-confirm="<?= te('guard.unblock_confirm') ?>"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="action" value="unblock"><button class="btn btn-primary btn-sm"><i class="bi bi-unlock me-1"></i><?= te('guard.unblock') ?></button></form>
    </td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>
<div class="card mt-5">
  <div class="card-header"><i class="bi bi-bell me-2 text-primary"></i><?= te('guard.alerts') ?></div>
  <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th><?= te('guard.when') ?></th><th><?= te('guard.account') ?></th><th><?= te('guard.what') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($alerts as $a): $sev = ['high' => 'danger', 'medium' => 'warning', 'info' => 'primary'][$a['severity']] ?? 'primary'; ?><tr class="<?= $a['resolved_at'] ? 'text-muted' : '' ?>">
    <td class="small text-nowrap"><?= e(format_datetime((int) $a['created_at'])) ?></td>
    <td class="small"><?= e($a['email'] ?? '—') ?></td>
    <td><span class="badge badge-soft-<?= $sev ?> me-1"><?= te('guard.kind_' . $a['kind']) ?></span><span class="small"><?= e($a['details']) ?></span></td>
    <td class="text-end"><?php if (!$a['resolved_at']): ?><form method="post" action="<?= e(url('admin/security/guard')) ?>"><?= csrf_field() ?><input type="hidden" name="alert_id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="action" value="resolve"><button class="btn btn-ghost btn-sm"><i class="bi bi-check2 me-1"></i><?= te('guard.resolve') ?></button></form><?php else: ?><span class="small"><?= te('guard.handled') ?></span><?php endif; ?></td>
  </tr><?php endforeach; ?>
  <?php if (!$alerts): ?><tr><td colspan="4" class="text-muted small"><?= te('guard.no_alerts') ?></td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<div class="card mt-5">
  <div class="card-header d-flex align-items-center"><i class="bi bi-shield-x me-2 text-danger"></i><?= te('admin.failed_ips') ?>
    <form method="post" action="<?= e(url('admin/security')) ?>" class="ms-auto"><?= csrf_field() ?><input type="hidden" name="unlock_all" value="1"><button class="btn btn-light btn-sm"><i class="bi bi-unlock me-1"></i><?= te('admin.unlock_all') ?></button></form></div>
  <div class="table-responsive"><table class="table"><thead><tr><th>IP</th><th><?= te('admin.attempts') ?></th><th><?= te('admin.last_attempt') ?></th></tr></thead><tbody>
  <?php foreach ($blocked as $b): ?><tr><td class="m4w-code"><?= e($b['ip']) ?></td><td><span class="badge <?= $b['n'] >= S::get('security.max_attempts') ? 'badge-soft-danger' : 'badge-soft-warning' ?>"><?= (int) $b['n'] ?></span></td><td class="small"><?= e(format_datetime((int) $b['last'])) ?></td></tr><?php endforeach; ?>
  <?php if (!$blocked): ?><tr><td colspan="3" class="text-muted small"><?= te('admin.no_failed') ?></td></tr><?php endif; ?>
  </tbody></table></div>
</div>
