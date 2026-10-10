<div class="m4w-page-header"><div><h1><?= te('admin.logs_title') ?></h1><p><?= te('admin.logs_desc') ?></p></div></div>
<ul class="nav nav-tabs mb-3">
  <?php foreach (['mail' => 'admin.logs_mail', 'queue' => 'admin.logs_queue', 'audit' => 'admin.logs_audit'] as $k => $l): ?>
  <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="<?= e(url('admin/logs', ['tab' => $k])) ?>"><?= te($l) ?><?= $k === 'queue' && ($n = count(array_filter($queue, static fn($q) => $q['status'] !== 'sent'))) ? ' <span class="badge badge-soft-warning">' . $n . '</span>' : '' ?></a></li>
  <?php endforeach; ?>
</ul>
<?php if ($tab !== 'queue'): ?>
<form class="m4w-filterbar" method="get"><input type="hidden" name="tab" value="<?= e($tab) ?>"><div class="position-relative flex-grow-1" style="max-width:380px"><i class="bi bi-search position-absolute text-muted" style="left:.8rem;top:50%;transform:translateY(-50%)"></i><input class="form-control ps-5" name="q" value="<?= e($q) ?>" placeholder="<?= te('admin.logs_search') ?>"></div></form>
<?php endif; ?>
<div class="m4w-table-card"><div class="table-responsive">
<?php if ($tab === 'mail'): ?>
  <table class="table table-hover small"><thead><tr><th><?= te('mail.date') ?></th><th></th><th><?= te('mail.from') ?></th><th><?= te('mail.to') ?></th><th><?= te('mail.subject') ?></th><th><?= te('mail.size') ?></th><th><?= te('admin.status') ?></th></tr></thead><tbody>
  <?php foreach ($mail as $l): ?>
    <tr><td class="text-nowrap text-muted"><?= e(format_datetime((int) $l['created_at'])) ?></td>
    <td><i class="bi bi-<?= $l['direction'] === 'in' ? 'arrow-down-left text-success' : 'arrow-up-right text-primary' ?>"></i></td>
    <td class="text-truncate" style="max-width:200px"><?= e($l['sender'] ?: '<>') ?></td><td class="text-truncate" style="max-width:220px"><?= e($l['recipients']) ?></td>
    <td class="text-truncate" style="max-width:260px"><?= e($l['subject']) ?></td><td class="text-nowrap"><?= e(format_bytes((int) $l['size'])) ?></td>
    <td><span class="badge <?= $l['status'] === 'ok' ? 'badge-soft-success' : ($l['status'] === 'partial' ? 'badge-soft-warning' : 'badge-soft-danger') ?>" title="<?= e($l['info']) ?>"><?= e($l['status']) ?></span> <span class="text-muted"><?= e(str_limit((string) $l['info'], 40)) ?></span></td></tr>
  <?php endforeach; ?>
  <?php if (!$mail): ?><tr><td colspan="7" class="text-center text-muted py-4"><?= te('common.none') ?></td></tr><?php endif; ?>
  </tbody></table>
<?php elseif ($tab === 'queue'): ?>
  <table class="table table-hover small"><thead><tr><th>#</th><th><?= te('admin.q_kind') ?></th><th><?= te('mail.to') ?></th><th><?= te('admin.status') ?></th><th><?= te('admin.q_attempts') ?></th><th><?= te('admin.q_error') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($queue as $q2): ?>
    <tr><td><?= (int) $q2['id'] ?></td><td><span class="badge badge-soft-secondary"><?= e($q2['kind']) ?></span></td><td class="text-truncate" style="max-width:240px"><?= e(implode(', ', json_decode((string) $q2['recipients'], true) ?: [])) ?></td>
    <td><span class="badge <?= ['sent' => 'badge-soft-success', 'failed' => 'badge-soft-danger'][$q2['status']] ?? 'badge-soft-warning' ?>"><?= e($q2['status']) ?></span></td><td><?= (int) $q2['attempts'] ?></td>
    <td class="text-truncate text-danger" style="max-width:300px" title="<?= e($q2['last_error']) ?>"><?= e($q2['last_error']) ?></td>
    <td class="text-end text-nowrap"><?php if ($q2['status'] !== 'sent'): ?><form method="post" action="<?= e(url('admin/queue/retry')) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $q2['id'] ?>"><button class="btn btn-ghost btn-sm"><i class="bi bi-arrow-repeat"></i></button></form><?php endif; ?>
      <form method="post" action="<?= e(url('admin/queue/delete')) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $q2['id'] ?>"><button class="btn btn-ghost btn-sm text-danger"><i class="bi bi-trash3"></i></button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$queue): ?><tr><td colspan="7" class="text-center text-muted py-4"><?= te('admin.queue_empty') ?></td></tr><?php endif; ?>
  </tbody></table>
<?php else: ?>
  <table class="table table-hover small"><thead><tr><th><?= te('mail.date') ?></th><th><?= te('admin.user') ?></th><th><?= te('admin.action') ?></th><th><?= te('admin.target') ?></th><th>IP</th></tr></thead><tbody>
  <?php foreach ($audit as $a): ?>
    <tr><td class="text-nowrap text-muted"><?= e(format_datetime((int) $a['created_at'])) ?></td><td><?= e($a['email'] ?: t('admin.system')) ?></td><td><?= e(audit_label($a['action'])) ?> <code class="small text-muted"><?= e($a['action']) ?></code></td><td class="text-truncate" style="max-width:260px"><?= e($a['target']) ?></td><td class="m4w-code"><?= e($a['ip']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$audit): ?><tr><td colspan="5" class="text-center text-muted py-4"><?= te('common.none') ?></td></tr><?php endif; ?>
  </tbody></table>
<?php endif; ?>
</div></div>
