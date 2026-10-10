<?php
/** @var array $row */ /** @var ?M4W\Mail\ParsedMessage $parsed */
$to = json_decode((string) $row['to_list'], true) ?: [];
$fmt = static fn(array $list) => implode(', ', array_map(static fn($a) => ($a['name'] ?? '') !== '' ? $a['name'] . ' <' . $a['email'] . '>' : ($a['email'] ?? ''), $list));
?>
<div class="m4w-page-header">
  <div class="d-flex align-items-start gap-3 min-w-0">
    <a class="btn btn-ghost btn-icon" href="<?= e(url('admin/deleted')) ?>" aria-label="<?= te('common.back') ?>"><i class="bi bi-arrow-left"></i></a>
    <div class="min-w-0"><h1 class="text-break"><?= $masked ? '<i class="bi bi-lock me-1"></i>' . te('ret.personal_subject') : e($row['subject'] ?: t('mail.no_subject')) ?></h1>
    <p class="mb-0"><?= te('ret.deleted_from', ['mailbox' => $row['user_email'], 'date' => format_datetime((int) $row['deleted_at'])]) ?></p></div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if (!$masked): ?><a class="btn btn-light" href="<?= e(url('admin/deleted/' . $row['id'] . '/eml')) ?>"><i class="bi bi-download me-1"></i><?= te('ret.download') ?></a><?php endif; ?>
    <form method="post" action="<?= e(url('admin/deleted/purge')) ?>" data-confirm="<?= te('ret.confirm_purge') ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-outline-danger"><i class="bi bi-x-octagon me-1"></i><?= te('ret.purge') ?></button></form>
  </div>
</div>

<div class="row g-4">
  <div class="col-xl-8">
    <?php if ($masked): ?>
    <div class="m4w-empty py-5 border rounded-3"><div class="m4w-empty-icon"><i class="bi bi-lock"></i></div><h3><?= te('ret.personal') ?></h3><p class="small"><?= te('ret.personal_hint') ?></p></div>
    <?php else: ?>
    <div class="card">
      <div class="card-body small">
        <dl class="row mb-0">
          <dt class="col-sm-2 text-muted fw-normal"><?= te('mail.from') ?></dt><dd class="col-sm-10"><?= e(($row['from_name'] ? $row['from_name'] . ' ' : '') . '<' . $row['from_email'] . '>') ?></dd>
          <dt class="col-sm-2 text-muted fw-normal"><?= te('mail.to') ?></dt><dd class="col-sm-10 text-break"><?= e($fmt($to)) ?: '—' ?></dd>
          <?php if ($cc = $parsed->cc()): ?><dt class="col-sm-2 text-muted fw-normal">Cc</dt><dd class="col-sm-10 text-break"><?= e($fmt($cc)) ?></dd><?php endif; ?>
          <dt class="col-sm-2 text-muted fw-normal"><?= te('mail.date') ?></dt><dd class="col-sm-10"><?= e(format_datetime((int) $row['date_sent'])) ?></dd>
          <?php if ($row['message_id'] !== ''): ?><dt class="col-sm-2 text-muted fw-normal">Message-ID</dt><dd class="col-sm-10 m4w-code text-break"><?= e($row['message_id']) ?></dd><?php endif; ?>
        </dl>
        <?php if ($atts = $parsed->attachmentList()): ?>
        <div class="d-flex flex-wrap gap-2 mt-3"><?php foreach ($atts as $a): if (!empty($a['inline'])) { continue; } ?><span class="m4w-chip"><i class="bi bi-paperclip"></i><?= e($a['name']) ?> <span class="text-muted"><?= e(format_bytes((int) $a['size'])) ?></span></span><?php endforeach; ?></div>
        <?php endif; ?>
      </div>
      <iframe class="m4w-ret-frame" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" title="<?= te('ret.content') ?>" src="<?= e(url('admin/deleted/' . $row['id'] . '/body')) ?>"></iframe>
    </div>
    <p class="small text-muted mt-2"><i class="bi bi-shield-lock me-1"></i><?= te('ret.view_logged') ?></p>
    <?php endif; ?>
  </div>
  <div class="col-xl-4">
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-arrow-counterclockwise me-2 text-primary"></i><?= te('ret.restore') ?></div>
      <form class="card-body" method="post" action="<?= e(url('admin/deleted/restore')) ?>">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="back" value="show">
        <?php if ((int) $row['restored_at']): ?><div class="alert alert-success small py-2"><?= te('ret.already_restored', ['date' => format_datetime((int) $row['restored_at']), 'to' => $row['restored_to']]) ?></div><?php endif; ?>
        <label class="form-label" for="ret-target"><?= te('ret.restore_to') ?></label>
        <select class="form-select mb-2" id="ret-target" name="target" <?= $masked ? 'disabled' : '' ?>>
          <?php if ($owner): ?><option value="<?= (int) $owner['id'] ?>"><?= te('ret.original_mailbox', ['email' => $owner['email']]) ?></option><?php endif; ?>
          <?php if (!$masked): foreach ($users as $u): if ($owner && (int) $u['id'] === (int) $owner['id']) { continue; } ?><option value="<?= (int) $u['id'] ?>"><?= e($u['display_name'] ?: $u['email']) ?> — <?= e($u['email']) ?></option><?php endforeach; endif; ?>
        </select>
        <?php if ($masked && $owner): ?><input type="hidden" name="target" value="<?= (int) $owner['id'] ?>"><?php endif; ?>
        <div class="form-text mb-3"><?= te($owner ? 'ret.restore_hint' : 'ret.restore_hint_gone') ?></div>
        <button class="btn btn-primary w-100" <?= !$owner && !$users ? 'disabled' : '' ?>><i class="bi bi-arrow-counterclockwise me-1"></i><?= te('ret.restore') ?></button>
      </form>
    </div>
    <div class="card">
      <div class="card-header"><i class="bi bi-info-circle me-2 text-primary"></i><?= te('ret.deletion') ?></div>
      <ul class="list-group list-group-flush small">
        <li class="list-group-item d-flex justify-content-between gap-3"><span class="text-muted"><?= te('ret.reason') ?></span><span class="text-end"><?= te('ret.reason_' . $row['reason']) ?></span></li>
        <li class="list-group-item d-flex justify-content-between gap-3"><span class="text-muted"><?= te('ret.deleted_by') ?></span><span class="text-end"><?= $row['deleted_by_name'] !== '' ? e($row['deleted_by_name']) : te('ret.system') ?></span></li>
        <li class="list-group-item d-flex justify-content-between gap-3"><span class="text-muted"><?= te('ret.origin') ?></span><span class="text-end"><?= e($row['folder_name'] ?: '—') ?></span></li>
        <?php if ($row['ip'] !== ''): ?><li class="list-group-item d-flex justify-content-between gap-3"><span class="text-muted">IP</span><span class="m4w-code"><?= e($row['ip']) ?></span></li><?php endif; ?>
        <li class="list-group-item d-flex justify-content-between gap-3"><span class="text-muted"><?= te('ret.size') ?></span><span><?= e(format_bytes((int) $row['size'])) ?></span></li>
        <li class="list-group-item d-flex justify-content-between gap-3"><span class="text-muted"><?= te('ret.expires') ?></span><span><?= (int) M4W\Core\Settings::get('retention.days', 365) > 0 ? e(format_datetime((int) $row['deleted_at'] + (int) M4W\Core\Settings::get('retention.days', 365) * 86400, false)) : te('ret.never') ?></span></li>
      </ul>
    </div>
  </div>
</div>
