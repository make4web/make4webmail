<?php use M4W\Core\Settings as S; ?>
<div class="m4w-page-header"><div><h1><?= te('admin.mail_title') ?></h1><p><?= te('admin.mail_desc') ?></p></div>
  <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#test-modal"><i class="bi bi-send-check me-1"></i><?= te('admin.test_send') ?></button></div>
<form method="post" action="<?= e(url('admin/mail')) ?>">
  <?= csrf_field() ?>
  <div class="row g-4">
    <div class="col-xl-7">
      <div class="card mb-4"><div class="card-header"><i class="bi bi-send me-2 text-primary"></i><?= te('admin.smtp_out') ?></div><div class="card-body">
        <p class="small text-muted"><?= te('admin.smtp_out_desc') ?></p>
        <div class="row g-3">
          <div class="col-md-8"><label class="form-label"><?= te('admin.smtp_host') ?></label><input class="form-control" name="host" value="<?= e(S::get('smtp.host')) ?>" placeholder="smtp.exemple.fr — <?= te('admin.smtp_empty_mx') ?>"></div>
          <div class="col-md-4"><label class="form-label">Port</label><input class="form-control" name="port" type="number" value="<?= (int) S::get('smtp.port') ?>"></div>
          <div class="col-md-6"><label class="form-label"><?= te('admin.smtp_security') ?></label><select class="form-select" name="security"><?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => te('admin.smtp_none')] as $k => $l): ?><option value="<?= $k ?>" <?= S::get('smtp.security') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label"><?= te('admin.smtp_helo') ?></label><input class="form-control" name="helo" value="<?= e(S::get('smtp.helo')) ?>" placeholder="mail.exemple.fr"></div>
          <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="auth" value="1" id="m-auth" <?= S::get('smtp.auth') ? 'checked' : '' ?>><label class="form-check-label" for="m-auth"><?= te('admin.smtp_auth') ?></label></div></div>
          <div class="col-md-6"><label class="form-label"><?= te('fetch.username') ?></label><input class="form-control" name="username" value="<?= e(S::get('smtp.username')) ?>" autocomplete="off"></div>
          <div class="col-md-6"><label class="form-label"><?= te('auth.password') ?></label><input class="form-control" type="password" name="password" autocomplete="new-password" placeholder="<?= $hasPassword ? '••••••••  (' . te('admin.kept') . ')' : '' ?>">
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
