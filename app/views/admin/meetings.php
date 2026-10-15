<?php
use M4W\Core\Settings as S;
use M4W\Service\Meetings;
$modeSel = static function (string $p) {
    $m = Meetings::mode($p);
    $h = '<select class="form-select" name="' . $p . '" style="max-width:360px" aria-label="' . te('meet.mode') . ' — ' . e(Meetings::LABELS[$p]) . '">';
    foreach (['off', 'api', 'personal'] as $o) {
        $h .= '<option value="' . $o . '"' . ($m === $o ? ' selected' : '') . '>' . te('meet.mode_' . $o) . '</option>';
    }
    return $h . '</select>';
};
$badge = static fn(string $p) => Meetings::mode($p) === 'api' ? (Meetings::configured($p) ? '<span class="badge badge-soft-success ms-2">' . te('meet.ready') . '</span>' : '<span class="badge badge-soft-warning ms-2">' . te('meet.incomplete') . '</span>') : '';
$secret = static fn(string $k) => S::secret('meet.' . $k) !== '' ? te('meet.secret_set') : '';
?>
<div class="m4w-page-header"><div><h1><?= te('meet.admin_title') ?></h1><p><?= te('meet.admin_desc') ?></p></div></div>
<form method="post" action="<?= e(url('admin/meetings')) ?>" autocomplete="off">
  <?= csrf_field() ?>
  <div class="m4w-section">
    <div class="m4w-section-title"><i class="bi bi-camera-video me-2 text-primary"></i>Jitsi Meet</div>
    <p class="small text-muted"><?= te('meet.jitsi_desc') ?></p>
    <div class="m4w-setting-row"><div class="label"><?= te('meet.jitsi_on') ?></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="jitsi" value="1" aria-label="<?= te('meet.jitsi_on') ?>" <?= Meetings::mode('jitsi') === 'on' ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('meet.jitsi_url') ?><small><?= te('meet.jitsi_url_desc') ?></small></div><div><input class="form-control m4w-code" style="max-width:360px" name="jitsi_url" aria-label="<?= te('meet.jitsi_url') ?>" value="<?= e(S::get('meet.jitsi_url', 'https://meet.jit.si')) ?>"></div></div>
  </div>

  <div class="m4w-section">
    <div class="m4w-section-title"><i class="bi bi-microsoft-teams me-2 text-primary"></i>Microsoft Teams<?= $badge('teams') ?></div>
    <p class="small text-muted"><?= te('meet.teams_desc') ?></p>
    <div class="m4w-setting-row"><div class="label"><?= te('meet.mode') ?></div><div><?= $modeSel('teams') ?></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('meet.teams_tenant') ?></div><div><input class="form-control m4w-code" style="max-width:360px" name="teams_tenant" aria-label="<?= te('meet.teams_tenant') ?>" value="<?= e(S::get('meet.teams_tenant', '')) ?>" placeholder="contoso.onmicrosoft.com"></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('meet.client_id') ?></div><div><input class="form-control m4w-code" style="max-width:360px" name="teams_client" aria-label="Teams <?= te('meet.client_id') ?>" value="<?= e(S::get('meet.teams_client', '')) ?>"></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('meet.client_secret') ?><small><?= $secret('teams_secret') ?></small></div><div><input class="form-control m4w-code" style="max-width:360px" type="password" name="teams_secret" aria-label="Teams <?= te('meet.client_secret') ?>" autocomplete="new-password"></div></div>
  </div>

  <div class="m4w-section">
    <div class="m4w-section-title"><i class="bi bi-google me-2 text-primary"></i>Google Meet<?= $badge('meet') ?></div>
    <p class="small text-muted"><?= te('meet.google_desc') ?></p>
    <div class="m4w-setting-row"><div class="label"><?= te('meet.mode') ?></div><div><?= $modeSel('meet') ?></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('meet.google_key') ?><small><?= $secret('google_key') ?: te('meet.google_key_desc') ?></small></div><div><textarea class="form-control m4w-code m4w-code-short" rows="3" style="min-width:min(380px,70vw)" name="google_key" aria-label="<?= te('meet.google_key') ?>" placeholder="{ &quot;type&quot;: &quot;service_account&quot;, … }"></textarea></div></div>
  </div>

  <div class="m4w-section">
    <div class="m4w-section-title"><i class="bi bi-camera-reels me-2 text-primary"></i>Zoom<?= $badge('zoom') ?></div>
    <p class="small text-muted"><?= te('meet.zoom_desc') ?></p>
    <div class="m4w-setting-row"><div class="label"><?= te('meet.mode') ?></div><div><?= $modeSel('zoom') ?></div></div>
    <div class="m4w-setting-row"><div class="label">Account ID</div><div><input class="form-control m4w-code" style="max-width:360px" name="zoom_account" aria-label="Zoom Account ID" value="<?= e(S::get('meet.zoom_account', '')) ?>"></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('meet.client_id') ?></div><div><input class="form-control m4w-code" style="max-width:360px" name="zoom_client" aria-label="Zoom <?= te('meet.client_id') ?>" value="<?= e(S::get('meet.zoom_client', '')) ?>"></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('meet.client_secret') ?><small><?= $secret('zoom_secret') ?></small></div><div><input class="form-control m4w-code" style="max-width:360px" type="password" name="zoom_secret" aria-label="Zoom <?= te('meet.client_secret') ?>" autocomplete="new-password"></div></div>
  </div>
  <div class="m4w-sticky-actions"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= te('common.save') ?></button></div>
</form>

<form method="post" action="<?= e(url('admin/meetings/test')) ?>" class="card mt-4">
  <?= csrf_field() ?>
  <div class="card-body d-flex flex-wrap align-items-center gap-2">
    <span class="me-2"><?= te('meet.test') ?></span>
    <?php foreach (Meetings::LABELS as $k => $l): ?><button class="btn btn-light btn-sm" name="provider" value="<?= $k ?>"><?= e($l) ?></button><?php endforeach; ?>
    <div class="form-text w-100"><?= te('meet.test_desc') ?></div>
  </div>
</form>
