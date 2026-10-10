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
