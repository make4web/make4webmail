<?php
/**
 * Phone and computer set-up with the mail apps service: Exchange (ActiveSync) or IMAP first,
 * then an app password, the steps of each app, and what the server sees of the attempts.
 * @var array $passwords @var ?array $created @var string $dav @var string $login @var string $mode
 */
$mg = M4W\Service\MailGateway::config();
$field = static function (string $label, string $value, bool $copy = true): string {
    return '<dt>' . e($label) . '</dt><dd><code class="text-break">' . e($value) . '</code>'
        . ($copy ? ' <button type="button" class="btn btn-ghost btn-icon btn-sm" data-copy="' . e($value) . '" aria-label="' . te('common.copy') . '" title="' . te('common.copy') . '"><i class="bi bi-clipboard"></i></button>' : '') . '</dd>';
};
$pwNote = '<dt>' . te('dav.password') . '</dt><dd>' . te('sync.pw_field') . '</dd>';
?>
<div data-sync-wizard data-mode="<?= e($mode) ?>">

  <div class="card mb-4">
    <div class="card-header"><span class="m4w-step-num">1</span><?= te('sync.choose_title') ?></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6"><button type="button" class="m4w-choice w-100 h-100 text-start" data-sync-mode="eas" aria-pressed="<?= $mode === 'eas' ? 'true' : 'false' ?>">
          <div class="d-flex align-items-center gap-2 mb-1"><i class="bi bi-lightning-charge-fill text-primary fs-5"></i><span class="fw-semibold"><?= te('sync.eas_title') ?></span><span class="badge badge-soft-primary ms-auto"><?= te('sync.eas_badge') ?></span></div>
          <div class="small text-muted"><?= te('sync.eas_desc') ?></div></button></div>
        <div class="col-md-6"><button type="button" class="m4w-choice w-100 h-100 text-start" data-sync-mode="imap" aria-pressed="<?= $mode === 'imap' ? 'true' : 'false' ?>">
          <div class="d-flex align-items-center gap-2 mb-1"><i class="bi bi-envelope-at text-primary fs-5"></i><span class="fw-semibold"><?= te('sync.imap_title') ?></span></div>
          <div class="small text-muted"><?= te('sync.imap_desc') ?></div></button></div>
      </div>
    </div>
  </div>

  <div class="card mb-4" id="step-password" data-sync-after-choice>
    <div class="card-header"><span class="m4w-step-num">2</span><?= te('sync.step_pw') ?></div>
    <div class="card-body">
      <p class="small text-muted"><?= te('sync.step_pw_desc') ?></p>
      <?php if ($created): ?>
      <div class="alert alert-success" role="status"><i class="bi bi-key"></i><div class="w-100">
        <div class="fw-semibold mb-1"><?= te('dav.created', ['name' => $created['name']]) ?></div>
        <div class="d-flex flex-wrap align-items-center gap-2"><code class="m4w-app-password fs-5" data-app-password><?= e($created['password']) ?></code>
          <button type="button" class="btn btn-light btn-sm" data-copy="<?= e($created['password']) ?>"><i class="bi bi-clipboard me-1"></i><?= te('common.copy') ?></button></div>
        <div class="small mt-1"><?= te('sync.created_next') ?></div>
      </div></div>
      <?php endif; ?>
      <form method="post" action="<?= e(url('settings/sync/create')) ?>" class="d-flex gap-2 mb-3" style="max-width:560px">
        <?= csrf_field() ?><input type="hidden" name="mode" value="<?= e($mode) ?>" data-sync-mode-field>
        <label class="visually-hidden" for="dav-name"><?= te('dav.device_name') ?></label>
        <input class="form-control" id="dav-name" name="name" maxlength="100" placeholder="<?= te('dav.device_ph') ?>" required>
        <button class="btn btn-primary text-nowrap"><i class="bi bi-plus-lg me-1"></i><?= te('dav.create') ?></button>
      </form>
      <?php if ($passwords): ?>
      <details<?= $created ? '' : ' open' ?>><summary class="small fw-semibold"><?= te('dav.passwords') ?> (<?= count($passwords) ?>)</summary>
      <ul class="list-group list-group-flush mt-1">
        <?php foreach ($passwords as $p): ?>
        <li class="list-group-item d-flex align-items-center gap-2 px-0">
          <i class="bi bi-phone text-muted"></i>
          <div class="min-w-0 flex-grow-1"><div class="fw-medium text-truncate"><?= e($p['name']) ?></div>
            <div class="small text-muted"><?= te('dav.created_on', ['date' => format_datetime((int) $p['created_at'], false)]) ?> · <?= $p['last_used_at'] ? te('dav.used_on', ['date' => format_datetime((int) $p['last_used_at']), 'ip' => preg_replace('/^::ffff:/', '', (string) $p['last_ip'])]) : te('dav.never_used') ?></div></div>
          <form method="post" action="<?= e(url('settings/sync/revoke')) ?>" data-confirm="<?= te('dav.revoke_confirm') ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn btn-outline-danger btn-sm"><?= te('dav.revoke') ?></button></form>
        </li>
        <?php endforeach; ?>
      </ul></details>
      <?php endif; ?>
    </div>
  </div>

  <div class="card mb-4" id="step-setup" data-sync-after-choice>
    <div class="card-header"><span class="m4w-step-num">3</span><?= te('sync.step_setup') ?></div>
    <div class="card-body">
      <div data-for-mode="eas">
        <ul class="nav nav-pills nav-sm mb-3" role="tablist">
          <?php foreach (['gmail' => 'bi-google', 'samsung' => 'bi-phone', 'iphone' => 'bi-apple', 'outlook' => 'bi-microsoft'] as $app => $icon): ?>
          <li class="nav-item"><button type="button" class="nav-link<?= $app === 'gmail' ? ' active' : '' ?>" data-bs-toggle="pill" data-bs-target="#eas-<?= $app ?>"><i class="bi <?= $icon ?> me-1"></i><?= te('sync.app_' . $app) ?></button></li>
          <?php endforeach; ?>
        </ul>
        <div class="tab-content mb-3">
          <?php foreach (['gmail', 'samsung', 'iphone', 'outlook'] as $app): ?>
          <div class="tab-pane fade<?= $app === 'gmail' ? ' show active' : '' ?>" id="eas-<?= $app ?>"><ol class="small mb-0 m4w-steps"><?= t('sync.eas_' . $app) ?></ol></div>
          <?php endforeach; ?>
        </div>
        <div class="fw-semibold small mb-1"><?= te('sync.if_asked') ?></div>
        <dl class="m4w-dav-settings mb-2">
          <?= $field(t('sync.f_email'), $login) ?>
          <?= $field(t('sync.f_user'), $login) ?>
          <?= $pwNote ?>
          <?= $field(t('sync.f_server'), $mg['host']) ?>
          <?= $field(t('sync.f_port'), (string) $mg['imap_port']) ?>
          <?= $field(t('sync.f_security'), 'SSL/TLS', false) ?>
        </dl>
        <p class="small text-muted mb-0"><i class="bi bi-calendar3 me-1"></i><?= te('sync.eas_pim') ?></p>
      </div>
      <div data-for-mode="imap">
        <div class="fw-semibold small mb-1"><i class="bi bi-envelope me-1"></i><?= te('sync.imap_mail') ?></div>
        <dl class="m4w-dav-settings mb-2">
          <?= $field(t('dav.login'), $login) ?>
          <?= $pwNote ?>
          <?= $field(t('maild.user_in'), $mg['host']) ?>
          <dt><?= te('sync.f_port') ?></dt><dd><code><?= (int) $mg['imap_port'] ?></code> · SSL/TLS</dd>
          <?= $field(t('maild.user_out'), $mg['host']) ?>
          <dt><?= te('sync.f_port') ?></dt><dd><code><?= (int) $mg['smtp_port'] ?></code> · SSL/TLS · <?= te('maild.user_auth') ?></dd>
        </dl>
        <p class="small text-muted"><?= te('sync.imap_note') ?></p>
        <div class="fw-semibold small mb-1"><i class="bi bi-calendar3 me-1"></i><?= te('sync.imap_pim') ?></div>
        <dl class="m4w-dav-settings mb-2">
          <?= $field(t('dav.url'), $dav) ?>
          <?= $field(t('dav.login'), $login) ?>
        </dl>
        <ul class="small mb-0 m4w-steps">
          <li><i class="bi bi-android2 me-1"></i><?= te('dav.android') ?></li>
          <li><i class="bi bi-apple me-1"></i><?= te('dav.ios') ?> <a href="<?= e(url('settings/sync/profile')) ?>"><?= te('dav.profile_download') ?></a></li>
          <li><i class="bi bi-window me-1"></i><?= te('dav.desktop') ?></li>
        </ul>
      </div>
    </div>
  </div>

  <div class="card mb-4" id="step-check" data-sync-after-choice data-sync-diagnose="<?= e(url('settings/sync/diagnose')) ?>">
    <div class="card-header d-flex align-items-center"><span class="m4w-step-num">4</span><?= te('sync.step_check') ?><span class="ms-auto small text-muted" data-sync-live><span class="spinner-grow spinner-grow-sm text-success me-1" aria-hidden="true"></span><?= te('sync.live') ?></span></div>
    <div class="card-body">
      <p class="small text-muted"><?= te('sync.step_check_desc') ?></p>
      <ul class="list-unstyled small mb-3 m4w-sync-events" data-sync-events><li class="text-muted"><?= te('sync.ev_waiting') ?></li></ul>
      <div class="fw-semibold small mb-2"><?= te('sync.q_title') ?></div>
      <div class="d-flex flex-wrap gap-2 mb-2">
        <?php foreach (['password', 'server', 'error', 'nothing'] as $q): ?>
        <button type="button" class="btn btn-light btn-sm" data-sync-q="<?= $q ?>"><?= te('sync.q_' . $q) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="alert alert-info small d-none mb-0" data-sync-answer role="status"></div>
    </div>
  </div>
</div>
