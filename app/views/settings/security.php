<?php $u = $currentUser; ?>
<div class="m4w-page-header"><div><h1><?= te('sec.title') ?></h1><p><?= te('sec.desc') ?></p></div></div>

<div class="row g-4">
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-key me-2 text-primary"></i><?= te('sec.password') ?></div>
      <form class="card-body" method="post" action="<?= e(url('settings/security/password')) ?>">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label"><?= te('pw.current') ?></label><input type="password" name="current" class="form-control" required autocomplete="current-password"></div>
        <div class="mb-2"><label class="form-label"><?= te('pw.new') ?></label><input type="password" name="password" class="form-control" required autocomplete="new-password" data-strength></div>
        <div class="progress mb-1" style="height:4px"><div class="progress-bar" data-strength-bar style="width:0"></div></div>
        <div class="form-text mb-3"><?= te('pw.policy', ['n' => M4W\Core\Settings::get('security.password_min', 10)]) ?></div>
        <div class="mb-3"><label class="form-label"><?= te('pw.confirm') ?></label><input type="password" name="password2" class="form-control" required autocomplete="new-password"></div>
        <div class="d-flex justify-content-between align-items-center"><small class="text-muted"><?= te('sec.pw_changed', ['date' => format_datetime((int) $u['password_changed_at'], false)]) ?></small><button class="btn btn-primary"><?= te('pw.update') ?></button></div>
      </form>
    </div>
  </div>
  <div class="col-xl-6" id="2fa">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center"><i class="bi bi-phone me-2 text-primary"></i><?= te('sec.2fa') ?>
        <span class="ms-auto badge <?= $u['totp_enabled'] ? 'badge-soft-success' : 'badge-soft-warning' ?>"><?= te($u['totp_enabled'] ? 'sec.2fa_on' : 'sec.2fa_off') ?></span></div>
      <div class="card-body">
        <?php if ($recovery): ?>
          <div class="alert alert-success"><i class="bi bi-shield-check"></i><div><?= te('sec.recovery_save') ?></div></div>
          <div class="m4w-recovery mb-3"><?php foreach ($recovery as $c): ?><span><?= e($c) ?></span><?php endforeach; ?></div>
          <button class="btn btn-light btn-sm" data-copy="<?= e(implode("\n", $recovery)) ?>"><i class="bi bi-clipboard me-1"></i><?= te('common.copy') ?></button>
        <?php elseif ($u['totp_enabled']): ?>
          <p class="text-muted"><?= te('sec.2fa_active_desc') ?></p>
          <form method="post" action="<?= e(url('settings/security/2fa/disable')) ?>" class="d-flex gap-2 flex-wrap" data-confirm="<?= te('sec.2fa_disable_confirm') ?>">
            <?= csrf_field() ?>
            <input type="password" name="current" class="form-control" style="max-width:240px" placeholder="<?= te('pw.current') ?>" aria-label="<?= te('pw.current') ?>" required autocomplete="current-password">
            <input type="text" name="code" class="form-control" style="max-width:160px" placeholder="<?= te('sec.2fa_code') ?>" aria-label="<?= te('sec.2fa_code') ?>" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autocomplete="one-time-code">
            <button class="btn btn-outline-danger"><?= te('sec.2fa_disable') ?></button>
          </form>
        <?php elseif ($totpSetup): ?>
          <ol class="small text-muted ps-3">
            <li><?= te('sec.2fa_step1') ?></li>
            <li><?= te('sec.2fa_step2') ?></li>
          </ol>
          <div class="d-flex gap-4 flex-wrap align-items-center mb-3">
            <div class="m4w-qr" data-qr="<?= e($totpSetup['uri']) ?>"></div>
            <div class="small"><div class="text-muted mb-1"><?= te('sec.2fa_manual') ?></div><code class="fs-6 user-select-all" style="word-break:break-all"><?= e(trim(chunk_split($totpSetup['secret'], 4, ' '))) ?></code></div>
          </div>
          <form method="post" action="<?= e(url('settings/security/2fa/enable')) ?>" class="d-flex gap-2">
            <?= csrf_field() ?>
            <input name="code" class="form-control" inputmode="numeric" maxlength="6" placeholder="123456" style="max-width:160px;letter-spacing:.3em;font-weight:600" required autocomplete="one-time-code">
            <button class="btn btn-primary"><?= te('sec.2fa_activate') ?></button>
          </form>
        <?php else: ?>
          <p class="text-muted"><?= te('sec.2fa_desc') ?></p>
          <form method="post" action="<?= e(url('settings/security/2fa/start')) ?>"><?= csrf_field() ?><button class="btn btn-primary"><i class="bi bi-shield-plus me-1"></i><?= te('sec.2fa_setup') ?></button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex align-items-center"><i class="bi bi-laptop me-2 text-primary"></i><?= te('sec.sessions') ?>
        <form method="post" action="<?= e(url('settings/security/sessions/revoke')) ?>" class="ms-auto"><?= csrf_field() ?><button class="btn btn-light btn-sm"><i class="bi bi-box-arrow-right me-1"></i><?= te('sec.revoke_others') ?></button></form></div>
      <div class="table-responsive"><table class="table">
        <thead><tr><th><?= te('sec.device') ?></th><th><?= te('sec.ip') ?></th><th><?= te('sec.last_seen') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($sessions as $s):
            $ua = $s['user_agent'];
            $os = preg_match('/Windows/i', $ua) ? 'windows' : (preg_match('/Mac OS|Macintosh/i', $ua) ? 'apple' : (preg_match('/Android/i', $ua) ? 'android2' : (preg_match('/iPhone|iPad/i', $ua) ? 'phone' : (preg_match('/Linux/i', $ua) ? 'ubuntu' : 'laptop'))));
            $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : t('sec.unknown_browser')))); ?>
          <tr>
            <td><i class="bi bi-<?= $os ?> me-2 text-muted"></i><?= e($br) ?><?= (int) $s['id'] === $currentSession ? ' <span class="badge badge-soft-success ms-1">' . te('sec.this_device') . '</span>' : '' ?><div class="small text-muted text-truncate" style="max-width:420px"><?= e($ua) ?></div></td>
            <td class="small"><?= e($s['ip']) ?></td>
            <td class="small"><?= e(format_datetime((int) $s['last_seen_at'])) ?></td>
            <td class="text-end"><?php if ((int) $s['id'] !== $currentSession): ?><form method="post" action="<?= e(url('settings/security/sessions/revoke')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn btn-ghost btn-sm text-danger"><?= te('sec.revoke') ?></button></form><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>
  <div class="col-12">
    <div class="card">
      <div class="card-header"><i class="bi bi-clock-history me-2 text-primary"></i><?= te('sec.activity') ?></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($logins as $l): $ok = $l['action'] === 'login.success'; ?>
        <li class="list-group-item d-flex align-items-center gap-3 small"><i class="bi bi-<?= $ok ? 'check-circle text-success' : 'x-octagon text-danger' ?>"></i><span class="flex-grow-1"><?= e(audit_label($l['action'])) ?></span><span class="text-muted"><?= e($l['ip']) ?></span><span class="text-muted"><?= e(format_datetime((int) $l['created_at'])) ?></span></li>
        <?php endforeach; ?>
        <?php if (!$logins): ?><li class="list-group-item small text-muted"><?= te('common.none') ?></li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>
