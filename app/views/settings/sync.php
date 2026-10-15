<?php
/** @var array $passwords @var ?array $created @var string $dav @var string $host @var array $user */
$section = 'sync';
$login = mb_strtolower($user['email']);
?>
<div class="m4w-page-header"><div><h1><?= te('dav.title') ?></h1><p><?= te('dav.desc') ?></p></div></div>

<?php if ($created): ?>
<div class="alert alert-success" role="status"><i class="bi bi-key"></i><div class="w-100">
  <div class="fw-semibold mb-1"><?= te('dav.created', ['name' => $created['name']]) ?></div>
  <div class="d-flex flex-wrap align-items-center gap-2"><code class="m4w-app-password fs-5" data-app-password><?= e($created['password']) ?></code>
    <button type="button" class="btn btn-light btn-sm" data-copy="<?= e($created['password']) ?>"><i class="bi bi-clipboard me-1"></i><?= te('common.copy') ?></button></div>
  <div class="small mt-1"><?= te('dav.created_once') ?></div>
</div></div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-gear me-2 text-primary"></i><?= te('dav.settings') ?></div>
      <div class="card-body">
        <dl class="m4w-dav-settings mb-3">
          <dt><?= te('dav.server') ?></dt><dd><code><?= e($host ?: $dav) ?></code> <button type="button" class="btn btn-ghost btn-icon btn-sm" data-copy="<?= e($host ?: $dav) ?>" aria-label="<?= te('common.copy') ?>" title="<?= te('common.copy') ?>"><i class="bi bi-clipboard"></i></button></dd>
          <dt><?= te('dav.url') ?></dt><dd><code class="text-break"><?= e($dav) ?></code> <button type="button" class="btn btn-ghost btn-icon btn-sm" data-copy="<?= e($dav) ?>" aria-label="<?= te('common.copy') ?>" title="<?= te('common.copy') ?>"><i class="bi bi-clipboard"></i></button></dd>
          <dt><?= te('dav.login') ?></dt><dd><code><?= e($login) ?></code></dd>
          <dt><?= te('dav.password') ?></dt><dd><?= te('dav.password_hint') ?></dd>
        </dl>
        <h3 class="h6"><i class="bi bi-apple me-1"></i>iPhone, iPad, Mac</h3>
        <p class="small text-muted"><?= te('dav.ios') ?></p>
        <a class="btn btn-light btn-sm mb-3" href="<?= e(url('settings/sync/profile')) ?>"><i class="bi bi-download me-1"></i><?= te('dav.profile_download') ?></a>
        <h3 class="h6"><i class="bi bi-android2 me-1"></i>Android</h3>
        <p class="small text-muted"><?= te('dav.android') ?></p>
        <h3 class="h6"><i class="bi bi-window me-1"></i>Thunderbird, Outlook</h3>
        <p class="small text-muted mb-0"><?= te('dav.desktop') ?></p>
      </div>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-key me-2 text-primary"></i><?= te('dav.passwords') ?></div>
      <div class="card-body">
        <form method="post" action="<?= e(url('settings/sync/create')) ?>" class="d-flex gap-2 mb-3">
          <?= csrf_field() ?>
          <label class="visually-hidden" for="dav-name"><?= te('dav.device_name') ?></label>
          <input class="form-control" id="dav-name" name="name" maxlength="100" placeholder="<?= te('dav.device_ph') ?>" required>
          <button class="btn btn-primary text-nowrap"><i class="bi bi-plus-lg me-1"></i><?= te('dav.create') ?></button>
        </form>
        <?php if (!$passwords): ?>
          <p class="text-muted small mb-0"><?= te('dav.none') ?></p>
        <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($passwords as $p): ?>
          <li class="list-group-item d-flex align-items-center gap-2 px-0">
            <i class="bi bi-phone text-muted"></i>
            <div class="min-w-0 flex-grow-1"><div class="fw-medium text-truncate"><?= e($p['name']) ?></div>
              <div class="small text-muted"><?= te('dav.created_on', ['date' => format_datetime((int) $p['created_at'], false)]) ?> · <?= $p['last_used_at'] ? te('dav.used_on', ['date' => format_datetime((int) $p['last_used_at']), 'ip' => $p['last_ip']]) : te('dav.never_used') ?></div></div>
            <form method="post" action="<?= e(url('settings/sync/revoke')) ?>" data-confirm="<?= te('dav.revoke_confirm') ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn btn-outline-danger btn-sm"><?= te('dav.revoke') ?></button></form>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php $personal = array_filter(['teams', 'meet', 'zoom'], static fn($p) => M4W\Service\Meetings::mode($p) === 'personal'); ?>
<?php if ($personal): ?>
<form method="post" action="<?= e(url('settings/sync/meetings')) ?>" class="card mt-4" id="meetings">
  <?= csrf_field() ?>
  <div class="card-header"><i class="bi bi-camera-video me-2 text-primary"></i><?= te('meet.personal_title') ?></div>
  <div class="card-body">
    <p class="small text-muted"><?= te('meet.personal_desc') ?></p>
    <?php foreach ($personal as $pv): ?>
    <div class="mb-3"><label class="form-label" for="ml-<?= $pv ?>"><?= e(M4W\Service\Meetings::LABELS[$pv]) ?></label>
      <input class="form-control m4w-code" type="url" id="ml-<?= $pv ?>" name="meet_<?= $pv ?>" value="<?= e(M4W\Service\Meetings::personalLink($user, $pv)) ?>" placeholder="<?= te('meet.ph_' . $pv) ?>"></div>
    <?php endforeach; ?>
    <button class="btn btn-primary"><?= te('common.save') ?></button>
  </div>
</form>
<?php endif; ?>
