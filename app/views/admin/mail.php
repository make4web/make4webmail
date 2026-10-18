<?php
use M4W\Core\Settings as S;
use M4W\Service\RemoteMailbox as RM;
$mode = RM::mode();
$show = static fn(string $modes): string => ' data-modes="' . $modes . '"' . (in_array($mode, explode(' ', $modes), true) ? '' : ' hidden');
?>
<div class="m4w-page-header"><div><h1><?= te('admin.mail_title') ?></h1><p><?= te('admin.mail_desc') ?></p></div>
  <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#test-modal"><i class="bi bi-send-check me-1"></i><?= te('admin.test_send') ?></button></div>
<form method="post" action="<?= e(url('admin/mail')) ?>">
  <?= csrf_field() ?>
  <fieldset class="m4w-modes mb-4">
    <legend class="h5 mb-1"><?= te('wm.mode_title') ?></legend>
    <p class="small text-muted mb-3"><?= te('wm.mode_desc') ?></p>
    <div class="row g-3">
      <?php foreach ([
          'server' => ['hdd-rack', ['wm.server_need1', 'wm.server_need2', 'wm.server_need3', 'wm.server_need4']],
          'relay' => ['send-check', ['wm.relay_need1', 'wm.relay_need2', 'wm.relay_need3']],
          'webmail' => ['window-stack', ['wm.webmail_need1', 'wm.webmail_need2', 'wm.webmail_need3', 'wm.webmail_need4']],
          'collector' => ['inboxes', ['col.need1', 'col.need2', 'col.need3', 'col.need4']],
      ] as $k => [$icon, $needs]): ?>
      <div class="col-md-6 col-xxl-3">
        <input class="btn-check" type="radio" name="mode" id="mode-<?= $k ?>" value="<?= $k ?>" data-mail-mode <?= $mode === $k ? 'checked' : '' ?>>
        <label class="m4w-mode-card h-100" for="mode-<?= $k ?>">
          <span class="m4w-mode-head"><span class="m4w-mode-icon"><i class="bi bi-<?= $icon ?>"></i></span><span><span class="fw-semibold d-block"><?= te('wm.mode_' . $k) ?></span><span class="small text-muted"><?= te('wm.mode_' . $k . '_short') ?></span></span><i class="bi bi-check-circle-fill m4w-mode-check"></i></span>
          <span class="m4w-mode-body small"><?= te('wm.mode_' . $k . '_desc') ?></span>
          <span class="m4w-mode-needs small"><span class="fw-semibold d-block mb-1"><?= te('wm.needs') ?></span>
            <?php foreach ($needs as $n): ?><span class="d-flex gap-2"><i class="bi bi-dot"></i><span><?= te($n) ?></span></span><?php endforeach; ?></span>
        </label>
      </div>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <?php $ca = $collector['account']; $cst = RM::status($ca); $cs = $collector['stats']; ?>
  <div class="row g-4 mb-4"<?= $show('collector') ?>>
    <div class="col-xl-6">
      <div class="card h-100"><div class="card-header"><i class="bi bi-inboxes me-2 text-primary"></i><?= te('col.card_title') ?>
        <span class="badge ms-2 <?= ['ok' => 'badge-soft-success', 'error' => 'badge-soft-danger', 'pending' => 'badge-soft-warning'][$cst] ?? 'badge-soft-secondary' ?>"><?= te('wm.st_' . $cst) ?></span></div>
      <div class="card-body">
        <p class="small text-muted"><?= te('col.card_desc') ?></p>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label" for="col-address"><?= te('col.address') ?></label><input class="form-control" id="col-address" type="email" name="col_address" value="<?= e($ca['login'] ?? '') ?>" placeholder="mail@<?= e($domains[0] ?? 'exemple.fr') ?>" autocomplete="off"></div>
          <div class="col-md-6"><label class="form-label" for="col-password"><?= te('col.password') ?></label><input class="form-control" id="col-password" type="password" name="col_password" autocomplete="new-password" placeholder="<?= $ca && $ca['password_enc'] !== '' ? '••••••••  (' . te('admin.kept') . ')' : '' ?>"></div>
          <div class="col-md-6"><label class="form-label" for="col-limit"><?= te('col.limit') ?></label><div class="input-group"><input class="form-control" id="col-limit" type="number" min="1" max="100000" name="col_limit" value="<?= (int) S::get('collector.hourly_limit') ?>"><span class="input-group-text"><?= te('col.per_hour') ?></span></div><div class="form-text"><?= te('col.limit_hint') ?></div></div>
          <div class="col-md-6"><label class="form-label" for="col-fallback"><?= te('col.fallback') ?></label><select class="form-select" id="col-fallback" name="col_fallback">
            <option value="0"><?= te('col.fallback_auto') ?></option>
            <?php foreach ($collector['users'] as $cu): ?><option value="<?= (int) $cu['id'] ?>" <?= (int) S::get('collector.fallback_user') === (int) $cu['id'] ? 'selected' : '' ?>><?= e($cu['email']) ?><?= is_admin($cu) ? ' (admin)' : '' ?></option><?php endforeach; ?>
          </select><div class="form-text"><?= te('col.fallback_hint') ?></div></div>
        </div>
        <?php if ($ca): ?>
        <div class="small text-muted mt-3"><?= te('wm.last_sync', ['date' => $ca['last_ok_at'] ? format_datetime((int) $ca['last_ok_at']) : '—']) ?></div>
        <?php if ($ca['last_error'] !== ''): ?><div class="alert alert-danger small py-2 mt-2 mb-0"><i class="bi bi-exclamation-triangle me-1"></i><?= e($ca['last_error']) ?></div><?php endif; ?>
        <div class="d-flex gap-2 mt-3 flex-wrap">
          <button type="button" class="btn btn-light btn-sm" data-remote-action="<?= e(url('admin/mail/collector/test')) ?>"><i class="bi bi-plug me-1"></i><?= te('wm.test') ?></button>
          <button type="button" class="btn btn-light btn-sm" data-remote-action="<?= e(url('admin/mail/collector/sync')) ?>"><i class="bi bi-arrow-repeat me-1"></i><?= te('wm.sync_now') ?></button>
          <span class="small align-self-center" data-remote-result role="status"></span>
        </div>
        <?php endif; ?>
      </div></div>
    </div>
    <div class="col-xl-6"><?php include __DIR__ . '/_send_gauge.php'; ?></div>
    <div class="col-12">
      <div class="card"><div class="card-header"><i class="bi bi-signpost-split me-2 text-primary"></i><?= te('col.redirect_title') ?></div><div class="card-body">
        <p class="small mb-2"><?= te('col.redirect_desc', ['collector' => $ca['login'] ?? 'mail@…']) ?></p>
        <ol class="small mb-3">
          <li><?= te('col.redirect_step1') ?></li>
          <li><?= te('col.redirect_step2', ['collector' => $ca['login'] ?? 'mail@…']) ?></li>
          <li><?= te('col.redirect_step3') ?></li>
        </ol>
        <?php $redir = implode("\n", $collector['redirect']); ?>
        <div class="d-flex align-items-center gap-2 mb-1"><span class="small fw-semibold"><?= te('col.redirect_list', ['n' => count($collector['redirect'])]) ?></span>
          <button type="button" class="btn btn-light btn-sm ms-auto" data-copy="<?= e($redir) ?>"><i class="bi bi-clipboard me-1"></i><?= te('common.copy') ?></button></div>
        <div class="m4w-redirect-list m4w-code small"><?php foreach ($collector['redirect'] as $ad): ?><div><?= e($ad) ?> <i class="bi bi-arrow-right text-muted mx-1"></i> <?= e($ca['login'] ?? 'mail@…') ?></div><?php endforeach; ?></div>
        <div class="m4w-deleg-notice mt-3 small"><i class="bi bi-exclamation-triangle"></i><span><?= te('col.redirect_warn') ?></span></div>
      </div></div>
    </div>
  </div>

  <div class="card mb-4"><div class="card-header"><i class="bi bi-arrow-repeat me-2 text-primary"></i><?= te('wm.check_title') ?></div><div class="card-body">
    <p class="small text-muted"><?= te('wm.check_desc') ?></p>
    <div class="row g-3"><div class="col-md-6"><label class="form-label" for="wm-interval"><?= te('wm.interval') ?></label><div class="input-group"><input class="form-control" type="number" min="1" max="15" id="wm-interval" name="wm_interval" value="<?= M4W\Service\RemoteMailbox::interval() ?>"><span class="input-group-text">min</span></div><div class="form-text"><?= te('wm.interval_hint') ?></div></div></div>
    <div class="fw-semibold small mt-4 mb-1"><?= te('wm.limits_title') ?></div>
    <p class="small text-muted mb-2"><?= te('wm.limits_hint') ?></p>
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label" for="wm-per-second"><?= te('wm.max_per_second') ?></label><input class="form-control" type="number" min="1" max="50" id="wm-per-second" name="wm_per_second" value="<?= M4W\Service\ImapLimits::perSecond() ?>"></div>
      <div class="col-md-4"><label class="form-label" for="wm-per-minute"><?= te('wm.max_per_minute') ?></label><input class="form-control" type="number" min="5" max="1000" id="wm-per-minute" name="wm_per_minute" value="<?= M4W\Service\ImapLimits::perMinute() ?>"></div>
      <div class="col-md-4"><label class="form-label" for="wm-per-box"><?= te('wm.max_per_box') ?></label><input class="form-control" type="number" min="1" max="50" id="wm-per-box" name="wm_per_box" value="<?= M4W\Service\ImapLimits::perBox() ?>"></div>
    </div>
  </div></div>

  <div class="card mb-4"<?= $show('webmail collector') ?>><div class="card-header"><i class="bi bi-window-stack me-2 text-primary"></i><?= te('wm.provider_title') ?></div><div class="card-body">
    <p class="small text-muted"><?= te('wm.provider_desc') ?></p>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label"><?= te('wm.preset') ?></label><select class="form-select" name="wm_preset" data-wm-preset>
        <?php foreach (RM::PRESETS as $pk => $p): ?><option value="<?= e($pk) ?>" data-imap="<?= e($p[1]) ?>" data-imap-port="<?= (int) $p[2] ?>" data-imap-sec="<?= e($p[3]) ?>" data-smtp="<?= e($p[4]) ?>" data-smtp-port="<?= (int) $p[5] ?>" data-smtp-sec="<?= e($p[6]) ?>" <?= S::get('webmail.preset') === $pk ? 'selected' : '' ?>><?= e($p[0]) ?></option><?php endforeach; ?>
      </select></div>
      <div class="col-md-6"><label class="form-label"><?= te('wm.imap_server') ?></label><div class="input-group"><input class="form-control m4w-code" name="wm_imap_host" value="<?= e(S::get('webmail.imap_host')) ?>" placeholder="imap.fournisseur.fr" data-wm="imap"><input class="form-control" style="max-width:90px" type="number" name="wm_imap_port" value="<?= (int) S::get('webmail.imap_port') ?>" aria-label="Port IMAP" data-wm="imap-port"><select class="form-select" style="max-width:120px" name="wm_imap_security" aria-label="<?= te('admin.smtp_security') ?>" data-wm="imap-sec"><?php foreach (['ssl' => 'SSL', 'tls' => 'STARTTLS', 'none' => te('admin.smtp_none')] as $sk => $sl): ?><option value="<?= $sk ?>" <?= S::get('webmail.imap_security') === $sk ? 'selected' : '' ?>><?= $sl ?></option><?php endforeach; ?></select></div></div>
      <div class="col-md-6"><label class="form-label"><?= te('wm.smtp_server') ?></label><div class="input-group"><input class="form-control m4w-code" name="wm_smtp_host" value="<?= e(S::get('webmail.smtp_host')) ?>" placeholder="smtp.fournisseur.fr" data-wm="smtp"><input class="form-control" style="max-width:90px" type="number" name="wm_smtp_port" value="<?= (int) S::get('webmail.smtp_port') ?>" aria-label="Port SMTP" data-wm="smtp-port"><select class="form-select" style="max-width:120px" name="wm_smtp_security" aria-label="<?= te('admin.smtp_security') ?>" data-wm="smtp-sec"><?php foreach (['ssl' => 'SSL', 'tls' => 'STARTTLS', 'none' => te('admin.smtp_none')] as $sk => $sl): ?><option value="<?= $sk ?>" <?= S::get('webmail.smtp_security') === $sk ? 'selected' : '' ?>><?= $sl ?></option><?php endforeach; ?></select></div></div>
      <div class="col-md-6"><label class="form-label"><?= te('wm.leave') ?></label><select class="form-select" name="wm_leave_days">
        <?php foreach ([-1 => 'wm.leave_never', 0 => 'wm.leave_now', 7 => 'wm.leave_7', 30 => 'wm.leave_30', 90 => 'wm.leave_90'] as $lv => $ll): ?><option value="<?= $lv ?>" <?= (int) S::get('webmail.leave_days') === $lv ? 'selected' : '' ?>><?= te($ll) ?></option><?php endforeach; ?>
      </select><div class="form-text"><?= te('wm.leave_hint') ?></div></div>
      <div class="col-md-6"><label class="form-label"><?= te('wm.spam_folder') ?></label><input class="form-control m4w-code" name="wm_spam_folder" value="<?= e(S::get('webmail.spam_folder')) ?>" placeholder="Junk, SPAM, INBOX.Spam…"><div class="form-text"><?= te('wm.spam_folder_hint') ?></div></div>
      <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="wm_verify_peer" value="1" id="wm-vp" <?= S::get('webmail.verify_peer') ? 'checked' : '' ?>><label class="form-check-label" for="wm-vp"><?= te('admin.smtp_verify') ?></label></div></div>
    </div>
    <div class="m4w-deleg-notice mt-3 small"<?= $show('webmail') ?>><i class="bi bi-info-circle"></i><span><?= te('wm.provider_note', ['n' => $linked['ok'] ?? 0, 'total' => $linked['total'] ?? 0]) ?> <a href="<?= e(url('admin/users')) ?>"><?= te('wm.goto_users') ?></a></span></div>
  </div></div>

  <div class="row g-4"<?= $show('server relay') ?>>
    <div class="col-xl-7">
      <div class="card mb-4"><div class="card-header"><i class="bi bi-send me-2 text-primary"></i><?= te('admin.smtp_out') ?></div><div class="card-body">
        <p class="small text-muted"<?= $show('relay') ?>><?= te('wm.relay_card_desc') ?></p>
        <p class="small text-muted"<?= $show('server') ?>><?= te('wm.server_card_desc') ?></p>
        <div class="row g-3">
          <div class="col-md-8"<?= $show('relay') ?>><label class="form-label"><?= te('admin.smtp_host') ?></label><input class="form-control" name="host" value="<?= e(S::get('smtp.host')) ?>" placeholder="smtp.exemple.fr — <?= te('admin.smtp_empty_mx') ?>"></div>
          <div class="col-md-4"<?= $show('relay') ?>><label class="form-label">Port</label><input class="form-control" name="port" type="number" value="<?= (int) S::get('smtp.port') ?>"></div>
          <div class="col-md-6"<?= $show('relay') ?>><label class="form-label"><?= te('admin.smtp_security') ?></label><select class="form-select" name="security"><?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => te('admin.smtp_none')] as $k => $l): ?><option value="<?= $k ?>" <?= S::get('smtp.security') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label"><?= te('admin.smtp_helo') ?></label><input class="form-control" name="helo" value="<?= e(S::get('smtp.helo')) ?>" placeholder="mail.exemple.fr"></div>
          <div class="col-12"<?= $show('relay') ?>><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="auth" value="1" id="m-auth" <?= S::get('smtp.auth') ? 'checked' : '' ?>><label class="form-check-label" for="m-auth"><?= te('admin.smtp_auth') ?></label></div></div>
          <div class="col-md-6"<?= $show('relay') ?>><label class="form-label"><?= te('fetch.username') ?></label><input class="form-control" name="username" value="<?= e(S::get('smtp.username')) ?>" autocomplete="off"></div>
          <div class="col-md-6"<?= $show('relay') ?>><label class="form-label"><?= te('auth.password') ?></label><input class="form-control" type="password" name="password" autocomplete="new-password" placeholder="<?= $hasPassword ? '••••••••  (' . te('admin.kept') . ')' : '' ?>">
            <?php if ($hasPassword): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="clear_password" value="1" id="m-clr"><label class="form-check-label small" for="m-clr"><?= te('admin.clear_password') ?></label></div><?php endif; ?></div>
          <div class="col-md-6"><label class="form-label"><?= te('admin.smtp_timeout') ?></label><div class="input-group"><input class="form-control" type="number" name="timeout" value="<?= (int) S::get('smtp.timeout') ?>"><span class="input-group-text">s</span></div></div>
          <div class="col-md-6 d-flex flex-column justify-content-end">
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="verify_peer" value="1" id="m-vp" <?= S::get('smtp.verify_peer') ? 'checked' : '' ?>><label class="form-check-label" for="m-vp"><?= te('admin.smtp_verify') ?></label></div>
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="local_delivery" value="1" id="m-ld" <?= S::get('smtp.local_delivery') ? 'checked' : '' ?>><label class="form-check-label" for="m-ld"><?= te('admin.smtp_local') ?></label></div>
          </div>
        </div>
      </div></div>
      <div class="card mb-4"><div class="card-header"><i class="bi bi-inbox me-2 text-primary"></i><?= te('admin.inbound') ?></div><div class="card-body">
        <p class="small text-muted"><?= te('admin.inbound_desc') ?></p>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label"><?= te('admin.inbound_listen') ?></label><input class="form-control m4w-code" name="inbound_listen" value="<?= e(S::get('inbound.listen')) ?>"></div>
          <div class="col-md-6"><label class="form-label"><?= te('admin.inbound_max') ?></label><div class="input-group"><input class="form-control" type="number" name="inbound_max" value="<?= (int) S::get('inbound.max_size_mb') ?>"><span class="input-group-text">Mo</span></div></div>
          <div class="col-12"><label class="form-label"><?= te('admin.inbound_ips') ?></label><input class="form-control m4w-code" name="inbound_ips" value="<?= e(S::get('inbound.allowed_ips')) ?>" placeholder="127.0.0.1, 10.0.0.0/8"><div class="form-text"><?= te('admin.inbound_ips_hint') ?></div></div>
          <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="spam_header" value="1" id="m-sp" <?= S::get('inbound.spam_header') ? 'checked' : '' ?>><label class="form-check-label" for="m-sp"><?= te('admin.inbound_spam') ?></label></div></div>
        </div>
        <div class="mt-3 small"><div class="fw-semibold mb-1"><?= te('admin.inbound_howto') ?></div>
<pre class="m4w-code p-3 rounded-3 mb-0" style="background:var(--m4w-surface-2);white-space:pre-wrap"># <?= te('admin.inbound_daemon') ?>

php <?= e(M4W_ROOT) ?>/bin/smtpd.php

# <?= te('admin.inbound_pipe') ?>

m4w unix - n n - - pipe flags=Rq user=www-data argv=/usr/bin/php <?= e(M4W_ROOT) ?>/bin/deliver.php -f ${sender} -- ${recipient}

# <?= te('admin.inbound_cron') ?>

* * * * * php <?= e(M4W_ROOT) ?>/bin/cron.php</pre></div>
      </div></div>
    </div>
    <div class="col-xl-5">
      <div class="card mb-4" id="dkim"><div class="card-header"><i class="bi bi-patch-check me-2 text-primary"></i>DKIM</div><div class="card-body">
        <p class="small text-muted"><?= te('admin.dkim_desc') ?></p>
        <div class="row g-3">
          <div class="col-7"><label class="form-label"><?= te('admin.dkim_domain') ?></label><select class="form-select" name="dkim_domain"><option value=""><?= te('common.none') ?></option><?php foreach ($domains as $d): ?><option <?= S::get('smtp.dkim_domain') === $d ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?></select></div>
          <div class="col-5"><label class="form-label"><?= te('admin.dkim_selector') ?></label><input class="form-control m4w-code" name="dkim_selector" value="<?= e(S::get('smtp.dkim_selector')) ?>"></div>
        </div>
        <?php if ($dkimPublic): ?>
          <div class="mt-3 small"><div class="fw-semibold mb-1"><?= te('admin.dkim_dns') ?></div>
          <div class="mb-1"><code><?= e(S::get('smtp.dkim_selector')) ?>._domainkey.<?= e(S::get('smtp.dkim_domain')) ?></code> TXT</div>
          <textarea class="form-control m4w-code" rows="5" readonly data-select-all><?= e($dkimPublic) ?></textarea>
          <button type="button" class="btn btn-light btn-sm mt-2" data-copy="<?= e($dkimPublic) ?>"><i class="bi bi-clipboard me-1"></i><?= te('common.copy') ?></button></div>
        <?php endif; ?>
        <button type="submit" class="btn btn-soft btn-sm mt-3" form="dkim-gen"><i class="bi bi-key me-1"></i><?= te($dkimPublic ? 'admin.dkim_regen' : 'admin.dkim_gen') ?></button>
      </div></div>
      <div class="card"><div class="card-header"><i class="bi bi-hdd-network me-2 text-primary"></i><?= te('admin.dns_records') ?></div><div class="card-body small">
        <?php $dom = $domains[0] ?? 'exemple.fr'; $host = S::get('smtp.helo') ?: 'mail.' . $dom; ?>
        <div class="table-responsive"><table class="table table-sm m4w-code mb-0" style="word-break:break-all"><tbody>
          <tr><td>MX</td><td><?= e($dom) ?></td><td>10 <?= e($host) ?></td></tr>
          <tr><td>TXT</td><td><?= e($dom) ?></td><td>v=spf1 mx <?= S::get('smtp.host') ? 'include:' . e(S::get('smtp.host')) . ' ' : '' ?>~all</td></tr>
          <tr><td>TXT</td><td>_dmarc.<?= e($dom) ?></td><td>v=DMARC1; p=quarantine; rua=mailto:postmaster@<?= e($dom) ?></td></tr>
        </tbody></table></div>
      </div></div>
    </div>
  </div>
  <div class="m4w-sticky-actions"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= te('common.save') ?></button></div>
</form>
<form method="post" action="<?= e(url('admin/mail/dkim')) ?>" id="dkim-gen" data-confirm="<?= te('admin.dkim_confirm') ?>"><?= csrf_field() ?></form>
<div class="modal fade" id="test-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><form class="modal-content" data-test-mail data-no-busy>
    <div class="modal-header"><h5 class="modal-title"><?= te('admin.test_send') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><label class="form-label"><?= te('admin.test_to') ?></label><input class="form-control" type="email" name="to" value="<?= e($currentUser['email']) ?>" required><div class="small mt-3" data-test-result></div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= te('common.close') ?></button><button class="btn btn-primary"><i class="bi bi-send me-1"></i><?= te('admin.test_go') ?></button></div>
  </form></div>
</div>
