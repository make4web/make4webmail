<?php use M4W\Core\Settings as S; ?>
<div class="m4w-page-header"><div><h1><?= te('admin.files_title') ?></h1><p><?= te('admin.files_desc') ?></p></div>
  <a class="btn btn-light" href="<?= e(url('files')) ?>"><i class="bi bi-folder2-open me-1"></i><?= te('admin.files_open') ?></a></div>

<div class="row g-3 mb-4">
  <?php
  $tiles = [
      ['hdd', 'admin.files_used', format_bytes($usage['used']) . ($usage['quota'] ? ' / ' . format_bytes($usage['quota']) : '')],
      ['files', 'admin.files_count', number_format($stats['files'], 0, ',', ' ')],
      ['clock-history', 'admin.files_versions_count', number_format($stats['versions'], 0, ',', ' ')],
      ['database', 'admin.files_storage', t('admin.files_driver_' . $driver)],
  ];
  foreach ($tiles as [$icon, $label, $value]): ?>
  <div class="col-sm-6 col-xl-3"><div class="m4w-stat"><span class="icon"><i class="bi bi-<?= $icon ?>"></i></span><div><div class="value"><?= e($value) ?></div><div class="label"><?= te($label) ?></div></div></div></div>
  <?php endforeach; ?>
</div>

<form method="post" action="<?= e(url('admin/files')) ?>">
  <?= csrf_field() ?>
  <div class="m4w-section">
    <div class="m4w-section-title"><i class="bi bi-sliders me-2 text-primary"></i><?= te('admin.files_limits') ?></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.files_max_file') ?><small><?= te('admin.files_max_file_desc', ['php' => format_bytes($phpMax)]) ?></small></div><div class="input-group" style="max-width:180px"><input class="form-control" type="number" min="1" max="10240" name="max_file_mb" value="<?= (int) S::get('files.max_file_mb') ?>"><span class="input-group-text">Mo</span></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.files_quota') ?><small><?= te('admin.files_quota_desc') ?></small></div><div class="input-group" style="max-width:180px"><input class="form-control" type="number" min="0" name="quota_gb" value="<?= (int) S::get('files.quota_gb') ?>"><span class="input-group-text">Go</span></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.files_versions') ?><small><?= te('admin.files_versions_desc') ?></small></div><div><input class="form-control" type="number" min="0" max="100" name="versions" value="<?= (int) S::get('files.versions') ?>" style="max-width:120px"></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.files_trash') ?></div><div class="input-group" style="max-width:180px"><input class="form-control" type="number" min="1" max="365" name="trash_days" value="<?= (int) S::get('files.trash_days') ?>"><span class="input-group-text"><?= te('admin.files_days') ?></span></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.files_user_spaces') ?><small><?= te('admin.files_user_spaces_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="users_create_spaces" value="1" <?= S::get('files.users_create_spaces') ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.files_transfer') ?><small><?= te('admin.files_transfer_hint') ?></small></div><div><textarea class="form-control m4w-code m4w-code-short" name="transfer_services" aria-label="<?= te('admin.files_transfer') ?>" rows="4" style="min-width:min(380px,70vw)"><?= e(S::get('files.transfer_services')) ?></textarea></div></div>
  </div>
  <div class="m4w-section">
    <div class="m4w-section-title"><i class="bi bi-hourglass-split me-2 text-primary"></i><?= te('admin.files_expiry') ?></div>
    <p class="small text-muted"><?= te('admin.files_expiry_desc') ?></p>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.files_expiry_default') ?></div><div class="input-group" style="max-width:200px"><input class="form-control" type="number" min="1" max="3650" name="expiry_default_days" aria-label="<?= te('admin.files_expiry_default') ?>" value="<?= (int) S::get('files.expiry_default_days') ?>"><span class="input-group-text"><?= te('admin.days') ?></span></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.files_expiry_max') ?><small><?= te('admin.files_expiry_max_desc') ?></small></div><div class="input-group" style="max-width:200px"><input class="form-control" type="number" min="30" max="3650" name="expiry_max_days" aria-label="<?= te('admin.files_expiry_max') ?>" value="<?= (int) S::get('files.expiry_max_days') ?>"><span class="input-group-text"><?= te('admin.days') ?></span></div></div>
  </div>
  <div class="m4w-section" id="office">
    <div class="m4w-section-title"><i class="bi bi-file-earmark-richtext me-2 text-primary"></i><?= te('admin.office_title') ?></div>
    <p class="small text-muted"><?= te('admin.office_desc') ?></p>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.office_url') ?><small><?= te('admin.office_url_hint') ?></small></div><div class="d-flex gap-2 flex-wrap"><input class="form-control m4w-code" style="max-width:340px" name="office_url" aria-label="<?= te('admin.office_url') ?>" type="url" value="<?= e(S::get('office.url')) ?>" placeholder="https://office.exemple.fr"><button type="button" class="btn btn-light" data-remote-action="<?= e(url('admin/files/office-test')) ?>"><i class="bi bi-plug me-1"></i><?= te('admin.office_test') ?></button><span class="small align-self-center" data-remote-result role="status"></span></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.office_wopi') ?><small><?= te('admin.office_wopi_hint') ?></small></div><div><input class="form-control m4w-code" style="max-width:340px" name="office_wopi_base" aria-label="<?= te('admin.office_wopi') ?>" type="url" value="<?= e(S::get('office.wopi_base')) ?>" placeholder="<?= e(public_url('')) ?>"></div></div>
  </div>
  <div class="m4w-sticky-actions"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= te('common.save') ?></button></div>
</form>

<h2 class="h5 mt-5 mb-3"><?= te('admin.files_spaces') ?></h2>
<?php if (!$spaces): ?>
<div class="m4w-empty py-4"><div class="m4w-empty-icon"><i class="bi bi-collection"></i></div><h3><?= te('admin.files_no_spaces') ?></h3><p class="small"><?= te('admin.files_no_spaces_hint') ?></p></div>
<?php else: ?>
<div class="m4w-table-card"><table class="table table-hover">
  <thead><tr><th><?= te('files.name') ?></th><th class="d-none d-md-table-cell"><?= te('admin.files_created_by') ?></th><th><?= te('admin.files_members') ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($spaces as $s): ?>
    <tr>
      <td><div class="fw-medium"><?= e($s['name']) ?><?= $s['deleted_at'] ? ' <span class="badge badge-soft-danger ms-1">' . te('files.trash') . '</span>' : '' ?></div><?php if ($s['description']): ?><div class="small text-muted"><?= e($s['description']) ?></div><?php endif; ?></td>
      <td class="d-none d-md-table-cell small"><?= e($s['display_name'] ?: $s['email'] ?: '—') ?></td>
      <td><span class="badge badge-soft-secondary"><?= (int) $s['members'] ?></span></td>
      <td class="text-end"><a class="btn btn-soft btn-sm" href="<?= e(url('files')) ?>#<?= $s['deleted_at'] ? 'trash' : 'f/' . (int) $s['id'] ?>"><?= te('admin.files_manage') ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<?php
$bk = M4W\Service\Backup::config();
$bkNext = M4W\Service\Backup::nextRun();
$bkCron = (int) S::get('backup.cron_seen', 0);
$bkHist = M4W\Service\Backup::history(10);
$bkEst = M4W\Service\Backup::estimate();
$bkFree = (int) @disk_free_space(M4W\Core\Config::storagePath('tmp'));
$bkDays = (string) S::get('backup.days', '1234567');
?>
<form method="post" action="<?= e(url('admin/files/backup')) ?>" class="mt-4">
  <?= csrf_field() ?>
  <div class="m4w-section" id="backup">
    <div class="m4w-section-title"><i class="bi bi-cloud-arrow-up me-2 text-primary"></i><?= te('backup.title') ?>
      <?php if (M4W\Service\Backup::enabled()): ?><span class="badge badge-soft-success ms-2"><?= te('backup.next', ['date' => $bkNext ? (new DateTimeImmutable('@' . $bkNext))->setTimezone(M4W\Service\Backup::timezone())->format('d/m H:i') : '—']) ?></span><?php else: ?><span class="badge badge-soft-secondary ms-2"><?= te('backup.off') ?></span><?php endif; ?></div>
    <p class="small text-muted"><?= te('backup.desc') ?></p>
    <?php if (M4W\Service\Backup::enabled() && $bkCron < time() - 600): ?>
    <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i><?= te('backup.no_cron') ?><code class="d-block mt-1">* * * * * www-data php <?= e(M4W_ROOT) ?>/bin/cron.php</code></div>
    <?php endif; ?>
    <div class="m4w-setting-row"><div class="label"><?= te('backup.enable') ?></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled" value="1" aria-label="<?= te('backup.enable') ?>" <?= M4W\Service\Backup::enabled() ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('backup.server') ?><small><?= te('backup.server_hint') ?></small></div>
      <div class="d-flex flex-column gap-2" style="max-width:520px">
        <div class="input-group">
          <select class="form-select" style="max-width:150px" name="protocol" aria-label="<?= te('backup.protocol') ?>">
            <?php foreach (M4W\Service\Backup::PROTOCOLS as $pr): ?><option value="<?= $pr ?>" <?= $bk['protocol'] === $pr ? 'selected' : '' ?>><?= te('backup.p_' . $pr) ?></option><?php endforeach; ?>
          </select>
          <input class="form-control m4w-code" name="host" value="<?= e($bk['host']) ?>" placeholder="backup.exemple.fr" aria-label="<?= te('backup.host') ?>">
          <input class="form-control" style="max-width:90px" type="number" name="port" value="<?= (int) S::get('backup.port') ?: '' ?>" placeholder="<?= $bk['port'] ?>" aria-label="Port">
        </div>
        <div class="input-group">
          <input class="form-control" name="user" value="<?= e($bk['user']) ?>" placeholder="<?= te('backup.user') ?>" aria-label="<?= te('backup.user') ?>" autocomplete="off">
          <input class="form-control" type="password" name="password" autocomplete="new-password" aria-label="<?= te('backup.password') ?>" placeholder="<?= S::get('backup.password', '') !== '' ? '••••••••  (' . te('admin.kept') . ')' : te('backup.password') ?>">
        </div>
        <input class="form-control m4w-code" name="path" value="<?= e((string) S::get('backup.path')) ?>" aria-label="<?= te('backup.path') ?>" placeholder="/make4webmail">
        <div class="form-check"><input class="form-check-input" type="checkbox" name="tls_verify" value="1" id="bk-tls" <?= $bk['verify'] ? 'checked' : '' ?>><label class="form-check-label small" for="bk-tls"><?= te('backup.tls_verify') ?></label></div>
        <div><button type="button" class="btn btn-light btn-sm" data-remote-form data-remote-action="<?= e(url('admin/files/backup/test')) ?>"><i class="bi bi-plug me-1"></i><?= te('backup.test') ?></button> <span class="small" data-remote-result role="status"></span></div>
      </div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('backup.schedule') ?><small><?= te('backup.schedule_hint') ?></small></div>
      <div class="d-flex flex-column gap-2">
        <div class="d-flex gap-2 flex-wrap">
          <input class="form-control" style="max-width:120px" type="time" name="time" value="<?= e((string) S::get('backup.time')) ?>" aria-label="<?= te('backup.time') ?>">
          <select class="form-select" style="max-width:240px" name="timezone" aria-label="<?= te('backup.timezone') ?>">
            <?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option value="<?= e($tz) ?>" <?= M4W\Service\Backup::timezone()->getName() === $tz ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="d-flex gap-3 flex-wrap" role="group" aria-label="<?= te('backup.days') ?>">
          <?php foreach ([1, 2, 3, 4, 5, 6, 7] as $d): ?><div class="form-check form-check-inline m-0"><input class="form-check-input" type="checkbox" name="days[]" value="<?= $d ?>" id="bk-d<?= $d ?>" <?= str_contains($bkDays, (string) $d) ? 'checked' : '' ?>><label class="form-check-label small" for="bk-d<?= $d ?>"><?= te('backup.day_' . $d) ?></label></div><?php endforeach; ?>
        </div>
      </div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('backup.keep') ?><small><?= te('backup.keep_hint') ?></small></div><div class="input-group" style="max-width:200px"><input class="form-control" type="number" min="1" max="365" name="keep" value="<?= (int) S::get('backup.keep') ?>" aria-label="<?= te('backup.keep') ?>"><span class="input-group-text"><?= te('backup.copies') ?></span></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('backup.zip_password') ?><small><?= te('backup.zip_password_hint') ?></small></div><div style="max-width:320px"><input class="form-control" type="password" name="zip_password" autocomplete="new-password" aria-label="<?= te('backup.zip_password') ?>" placeholder="<?= S::get('backup.zip_password', '') !== '' ? '••••••••  (' . te('admin.kept') . ')' : te('backup.zip_password_none') ?>">
      <?php if (S::get('backup.zip_password', '') !== ''): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="zip_password_clear" value="1" id="bk-zc"><label class="form-check-label small" for="bk-zc"><?= te('backup.zip_password_clear') ?></label></div><?php endif; ?></div></div>
    <p class="small text-muted mt-2 mb-0"><?= te('backup.size', ['size' => format_bytes($bkEst), 'free' => format_bytes($bkFree)]) ?></p>
  </div>
  <div class="d-flex gap-2 flex-wrap mb-3">
    <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= te('common.save') ?></button>
    <button class="btn btn-light" name="now" value="1"><i class="bi bi-cloud-arrow-up me-1"></i><?= te('backup.now') ?></button>
  </div>
</form>
<?php $bkStatus = M4W\Service\Backup::status(); $bkActive = $bkStatus['requested'] || array_filter($bkStatus['runs'], static fn($x) => $x['running']); ?>
<div class="mb-4" data-backup-list="admin/files/backup/status" data-active="<?= $bkActive ? 1 : 0 ?>">
  <div class="alert alert-info small py-2<?= $bkStatus['requested'] ? '' : ' d-none' ?>" data-backup-waiting><span class="spinner-border spinner-border-sm me-2"></span><?= te('backup.waiting') ?></div>
  <div class="table-responsive<?= $bkStatus['runs'] ? '' : ' d-none' ?>"><table class="table table-sm align-middle m4w-table">
    <thead><tr><th><?= te('backup.h_date') ?></th><th><?= te('backup.h_status') ?></th><th class="d-none d-md-table-cell"><?= te('backup.h_file') ?></th><th class="text-end"><?= te('backup.h_size') ?></th><th class="d-none d-lg-table-cell"><?= te('backup.h_duration') ?></th></tr></thead>
    <tbody data-backup-rows>
    <?php foreach ($bkStatus['runs'] as $h): ?>
      <tr>
        <td class="small text-nowrap"><?= e($h['date']) ?> <span class="text-muted">· <?= e($h['origin']) ?></span></td>
        <td><span class="badge <?= ['ok' => 'badge-soft-success', 'failed' => 'badge-soft-danger', 'running' => 'badge-soft-warning'][$h['status']] ?? 'badge-soft-secondary' ?>"><?php if ($h['running']): ?><span class="spinner-border spinner-border-sm me-1" style="width:.7rem;height:.7rem"></span><?php endif; ?><?= e($h['status_label']) ?></span>
          <?php if ($h['message'] !== ''): ?><div class="small mt-1 <?= $h['status'] === 'failed' ? 'text-danger' : 'text-muted' ?> text-break"><?= e($h['message']) ?></div><?php endif; ?></td>
        <td class="small m4w-code d-none d-md-table-cell"><?= e($h['file']) ?></td>
        <td class="small text-end text-nowrap"><?= e($h['size']) ?></td>
        <td class="small d-none d-lg-table-cell"><?= e($h['duration']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<h2 class="h5 mt-5 mb-1" id="archive"><?= te('admin.files_archive') ?></h2>
<p class="small text-muted mb-3"><?= te('admin.files_archive_desc') ?></p>
<?php if (!$archived): ?>
<div class="m4w-empty py-4"><div class="m4w-empty-icon"><i class="bi bi-archive"></i></div><h3><?= te('admin.files_archive_empty') ?></h3></div>
<?php else: ?>
<div class="m4w-table-card"><div class="table-responsive"><table class="table table-hover align-middle">
  <thead><tr><th><?= te('files.name') ?></th><th class="d-none d-md-table-cell"><?= te('admin.files_archive_origin') ?></th><th class="d-none d-lg-table-cell"><?= te('admin.files_created_by') ?></th><th><?= te('admin.files_archive_date') ?></th><th class="text-end"><?= te('common.actions') ?></th></tr></thead>
  <tbody>
  <?php foreach ($archived as $a): ?>
    <tr>
      <td class="text-break"><i class="bi bi-file-earmark me-1 text-muted"></i><?= e($a['name']) ?> <small class="text-muted">· <?= e(format_bytes((int) $a['size'])) ?></small></td>
      <td class="d-none d-md-table-cell small text-muted text-break"><?= e($a['origin']) ?></td>
      <td class="d-none d-lg-table-cell small"><?= e($a['creator_email'] ?: '—') ?></td>
      <td class="small text-nowrap"><?= e(format_datetime((int) $a['archived_at'], false)) ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('admin/files/archive/' . (int) $a['id'])) ?>" title="<?= te('files.download') ?>" aria-label="<?= te('files.download') ?>"><i class="bi bi-download"></i></a>
        <form class="d-inline" method="post" action="<?= e(url('admin/files/archive/' . (int) $a['id'])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><button class="btn btn-soft btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i><?= te('files.restore') ?></button></form>
        <form class="d-inline" method="post" action="<?= e(url('admin/files/archive/' . (int) $a['id'])) ?>" data-confirm="<?= te('admin.files_archive_delete_confirm', ['name' => $a['name']]) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn btn-ghost btn-icon btn-sm text-danger" title="<?= te('common.delete') ?>" aria-label="<?= te('common.delete') ?>"><i class="bi bi-trash3"></i></button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php endif; ?>
