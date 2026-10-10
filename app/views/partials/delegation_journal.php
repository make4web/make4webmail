<?php /** @var array $journal */ ?>
<?php if (!$journal): ?>
<div class="card-body small text-muted"><?= te('deleg.journal_empty') ?></div>
<?php else: ?>
<div class="table-responsive"><table class="table table-sm mb-0 small">
  <thead><tr><th><?= te('deleg.j_who') ?></th><th><?= te('deleg.j_when') ?></th><th><?= te('deleg.reason') ?></th><th class="text-end"><?= te('deleg.j_sent') ?></th></tr></thead>
  <tbody>
  <?php foreach ($journal as $j): ?>
    <tr>
      <td><div class="fw-medium"><?= e($j['actor_name']) ?></div><span class="badge <?= $j['via'] === 'admin' ? 'badge-soft-warning' : 'badge-soft-secondary' ?>"><?= te('deleg.via_' . $j['via']) ?></span></td>
      <td class="text-nowrap"><?= e(format_datetime((int) $j['opened_at'])) ?><div class="text-muted"><?= te('deleg.j_until', ['date' => format_datetime((int) ($j['closed_at'] ?: $j['last_seen_at']))]) ?></div></td>
      <td><?= $j['reason'] !== '' ? e($j['reason']) : '<span class="text-muted">—</span>' ?></td>
      <td class="text-end"><?= (int) $j['sent'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
