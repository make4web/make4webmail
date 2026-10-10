<?php
use M4W\Core\Settings as S;
use M4W\Service\Retention;
$pages = max(1, (int) ceil($total / 50));
$qs = static fn(array $over) => url('admin/deleted', array_filter(array_merge(array_intersect_key($raw, array_flip(['q', 'user', 'reason', 'state', 'from', 'to'])), $over), static fn($v) => $v !== '' && $v !== null && $v !== 0));
?>
<div class="m4w-page-header">
  <div><h1><?= te('ret.title') ?></h1><p><?= te('ret.desc') ?></p></div>
  <button class="btn btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#ret-settings" aria-expanded="false" aria-controls="ret-settings"><i class="bi bi-gear me-1"></i><?= te('ret.settings') ?></button>
</div>

<div class="collapse<?= S::get('retention.enabled') ? '' : ' show' ?>" id="ret-settings">
  <form method="post" action="<?= e(url('admin/deleted/settings')) ?>" class="m4w-section mb-4">
    <?= csrf_field() ?>
    <div class="m4w-section-title"><i class="bi bi-archive me-2 text-primary"></i><?= te('ret.settings') ?></div>
    <div class="m4w-setting-row"><div class="label"><?= te('ret.s_enabled') ?><small><?= te('ret.s_enabled_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled" value="1" <?= S::get('retention.enabled') ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('ret.s_days') ?><small><?= te('ret.s_days_desc') ?></small></div><div class="input-group" style="max-width:200px"><input class="form-control" type="number" min="0" max="3650" name="days" value="<?= (int) S::get('retention.days') ?>"><span class="input-group-text"><?= te('admin.files_days') ?></span></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('ret.s_account') ?><small><?= te('ret.s_account_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="on_account_delete" value="1" <?= S::get('retention.on_account_delete') ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('ret.s_spam') ?><small><?= te('ret.s_spam_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="keep_spam" value="1" <?= S::get('retention.keep_spam') ? 'checked' : '' ?>></div></div>
    <div class="d-flex justify-content-end mt-3"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= te('common.save') ?></button></div>
  </form>
</div>

<?php if (!S::get('retention.enabled')): ?>
<div class="alert alert-warning d-flex gap-2 align-items-start"><i class="bi bi-exclamation-triangle-fill"></i><div><?= te('ret.disabled_warning') ?></div></div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <?php foreach ([
      ['trash3', number_format($stats['count'], 0, ',', ' '), 'ret.stat_count', 'background:rgba(220,38,38,.1);color:#dc2626'],
      ['calendar-week', number_format($stats['last30'], 0, ',', ' '), 'ret.stat_30', ''],
      ['arrow-counterclockwise', number_format($stats['restored'], 0, ',', ' '), 'ret.stat_restored', 'background:rgba(22,163,74,.12);color:#16a34a'],
      ['hdd', format_bytes($stats['size']), 'ret.stat_size', 'background:rgba(124,58,237,.12);color:#7c3aed'],
  ] as [$icon, $val, $label, $style]): ?>
  <div class="col-sm-6 col-xl-3"><div class="m4w-stat"><span class="icon" style="<?= $style ?>"><i class="bi bi-<?= $icon ?>"></i></span><div><div class="value"><?= e($val) ?></div><div class="label"><?= te($label) ?></div></div></div></div>
  <?php endforeach; ?>
</div>

<form class="m4w-filterbar" method="get" action="<?= e(url('admin/deleted')) ?>">
  <div class="position-relative flex-grow-1" style="max-width:320px">
    <i class="bi bi-search position-absolute text-muted" style="left:.8rem;top:50%;transform:translateY(-50%)"></i>
    <input class="form-control ps-5" type="search" name="q" value="<?= e($raw['q'] ?? '') ?>" placeholder="<?= te('ret.search') ?>" aria-label="<?= te('ret.search') ?>">
  </div>
  <select class="form-select w-auto" name="user" aria-label="<?= te('ret.mailbox') ?>">
    <option value=""><?= te('ret.all_mailboxes') ?></option>
    <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $filters['user'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['display_name'] ?: $u['email']) ?><?= $u['status'] !== 'active' ? ' (' . te('admin.st_disabled') . ')' : '' ?></option><?php endforeach; ?>
  </select>
  <select class="form-select w-auto" name="reason" aria-label="<?= te('ret.reason') ?>">
    <option value=""><?= te('ret.all_reasons') ?></option>
    <?php foreach (Retention::REASONS as $r): ?><option value="<?= $r ?>" <?= $filters['reason'] === $r ? 'selected' : '' ?>><?= te('ret.reason_' . $r) ?></option><?php endforeach; ?>
  </select>
  <select class="form-select w-auto" name="state" aria-label="<?= te('ret.state') ?>">
    <option value=""><?= te('ret.all_states') ?></option>
    <option value="pending" <?= $filters['state'] === 'pending' ? 'selected' : '' ?>><?= te('ret.state_pending') ?></option>
    <option value="restored" <?= $filters['state'] === 'restored' ? 'selected' : '' ?>><?= te('ret.state_restored') ?></option>
  </select>
  <input class="form-control w-auto" type="date" name="from" value="<?= e($raw['from'] ?? '') ?>" aria-label="<?= te('ret.deleted_after') ?>" title="<?= te('ret.deleted_after') ?>">
  <input class="form-control w-auto" type="date" name="to" value="<?= e($raw['to'] ?? '') ?>" aria-label="<?= te('ret.deleted_before') ?>" title="<?= te('ret.deleted_before') ?>">
  <button class="btn btn-soft"><i class="bi bi-funnel me-1"></i><?= te('ret.filter') ?></button>
  <?php if (array_filter(array_intersect_key($raw, array_flip(['q', 'user', 'reason', 'state', 'from', 'to'])))): ?><a class="btn btn-ghost" href="<?= e(url('admin/deleted')) ?>"><?= te('ret.reset') ?></a><?php endif; ?>
</form>

<?php if (!$items): ?>
<div class="m4w-empty py-5"><div class="m4w-empty-icon"><i class="bi bi-trash3"></i></div><h3><?= te('ret.empty') ?></h3><p class="small"><?= te('ret.empty_hint') ?></p></div>
<?php else: ?>
<form method="post" action="<?= e(url('admin/deleted/restore')) ?>">
  <?= csrf_field() ?>
  <div class="m4w-bulk align-items-center gap-2 mb-2 d-none" data-bulk-bar>
    <span class="small fw-semibold me-2" data-bulk-count></span>
    <button class="btn btn-primary btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i><?= te('ret.restore_selected') ?></button>
    <button class="btn btn-outline-danger btn-sm" formaction="<?= e(url('admin/deleted/purge')) ?>" data-confirm-bulk="<?= te('ret.confirm_purge') ?>"><i class="bi bi-x-octagon me-1"></i><?= te('ret.purge_selected') ?></button>
  </div>
  <div class="m4w-table-card"><div class="table-responsive"><table class="table table-hover small mb-0">
    <thead><tr>
      <th style="width:36px"><input type="checkbox" class="form-check-input" data-check-all="ids[]" aria-label="<?= te('ret.select_all') ?>"></th>
      <th><?= te('ret.deleted_on') ?></th><th><?= te('ret.mailbox') ?></th><th><?= te('ret.message') ?></th><th class="d-none d-lg-table-cell"><?= te('ret.origin') ?></th><th><?= te('ret.reason') ?></th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($items as $it): $masked = Retention::masked($it); ?>
      <tr>
        <td><input type="checkbox" class="form-check-input" name="ids[]" value="<?= (int) $it['id'] ?>" aria-label="<?= te('ret.select') ?>"></td>
        <td class="text-nowrap"><?= e(format_datetime((int) $it['deleted_at'])) ?><div class="text-muted"><?= $it['deleted_by_name'] !== '' ? te('ret.by', ['name' => $it['deleted_by_name']]) : te('ret.by_system') ?></div></td>
        <td class="text-truncate" style="max-width:200px"><?= e($it['user_email']) ?></td>
        <td style="max-width:360px">
          <a class="fw-medium text-reset d-block text-truncate" href="<?= e(url('admin/deleted/' . $it['id'])) ?>"><?= $masked ? '<i class="bi bi-lock me-1"></i>' . te('ret.personal_subject') : e($it['subject'] ?: t('mail.no_subject')) ?></a>
          <div class="text-muted text-truncate"><?= e($it['from_name'] ?: $it['from_email']) ?> · <?= e(format_datetime((int) $it['date_sent'])) ?> · <?= e(format_bytes((int) $it['size'])) ?><?= $it['has_attachments'] ? ' · <i class="bi bi-paperclip"></i>' : '' ?></div>
        </td>
        <td class="d-none d-lg-table-cell text-truncate" style="max-width:160px"><?= e($it['folder_name']) ?></td>
        <td><span class="badge badge-soft-secondary"><?= te('ret.reason_' . $it['reason']) ?></span><?php if ((int) $it['restored_at']): ?><div class="mt-1"><span class="badge badge-soft-success" title="<?= e($it['restored_to']) ?>"><i class="bi bi-check2 me-1"></i><?= te('ret.restored_on', ['date' => format_datetime((int) $it['restored_at'], false)]) ?></span></div><?php endif; ?></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('admin/deleted/' . $it['id'])) ?>" title="<?= te('ret.open') ?>" aria-label="<?= te('ret.open') ?>"><i class="bi bi-eye"></i></a>
          <button class="btn btn-ghost btn-icon btn-sm" name="id" value="<?= (int) $it['id'] ?>" formaction="<?= e(url('admin/deleted/restore')) ?>" title="<?= te('ret.restore') ?>" aria-label="<?= te('ret.restore') ?>"><i class="bi bi-arrow-counterclockwise"></i></button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>
</form>
<?php if ($pages > 1): ?>
<nav class="d-flex justify-content-between align-items-center mt-3 small" aria-label="<?= te('ret.pages') ?>">
  <span class="text-muted"><?= te('ret.page_of', ['page' => $page, 'pages' => $pages, 'total' => $total]) ?></span>
  <div class="btn-group">
    <a class="btn btn-light btn-sm<?= $page <= 1 ? ' disabled' : '' ?>" href="<?= e($qs(['page' => $page - 1])) ?>"><i class="bi bi-chevron-left"></i><span class="visually-hidden"><?= te('ret.prev') ?></span></a>
    <a class="btn btn-light btn-sm<?= $page >= $pages ? ' disabled' : '' ?>" href="<?= e($qs(['page' => $page + 1])) ?>"><i class="bi bi-chevron-right"></i><span class="visually-hidden"><?= te('ret.next') ?></span></a>
  </div>
</nav>
<?php endif; ?>
<?php endif; ?>
