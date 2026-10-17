<?php
/** @var array $domain @var array $r (DomainHealth::report) */
$icon = ['ok' => 'check-circle-fill text-success', 'warn' => 'exclamation-triangle-fill text-warning', 'fail' => 'x-octagon-fill text-danger', 'unknown' => 'question-circle text-muted', 'clean' => 'check-circle-fill text-success', 'listed' => 'x-octagon-fill text-danger'];
$badge = ['ok' => 'badge-soft-success', 'warn' => 'badge-soft-warning', 'fail' => 'badge-soft-danger'];
$del = $r['delivery'];
$spfBadge = ['pass' => 'badge-soft-success', 'fail' => 'badge-soft-danger', 'softfail' => 'badge-soft-warning', 'neutral' => 'badge-soft-warning', 'none' => 'badge-soft-danger', 'permerror' => 'badge-soft-danger'];
?>
<div class="m4w-page-header">
  <div><a class="small text-muted text-decoration-none" href="<?= e(url('admin/domains')) ?>"><i class="bi bi-arrow-left me-1"></i><?= te('admin.domains_title') ?></a>
    <h1 class="mt-1"><?= te('dh.title', ['domain' => $domain['name']]) ?></h1><p><?= te('dh.desc') ?></p></div>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <span class="badge fs-6 <?= $badge[$r['state']] ?? 'badge-soft' ?>"><i class="bi bi-<?= explode(' ', $icon[$r['state']] ?? 'question-circle')[0] ?> me-1"></i><?= te('dh.state_' . $r['state']) ?></span>
    <form method="post" action="<?= e(url('admin/domains/' . (int) $domain['id'] . '/health')) ?>"><?= csrf_field() ?><button class="btn btn-light"><i class="bi bi-arrow-repeat me-1"></i><?= te('dh.refresh') ?></button></form>
  </div>
</div>
<p class="small text-muted"><?= te('dh.checked_at', ['date' => format_datetime((int) $r['at'])]) ?></p>

<div class="row g-4">
  <div class="col-xl-7">
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-shield-check me-2 text-primary"></i><?= te('dh.auth_title') ?></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($r['checks'] as $c): $k = explode(':', $c['key'])[0]; ?>
        <li class="list-group-item d-flex gap-3 align-items-start">
          <i class="bi bi-<?= $icon[$c['state']] ?> mt-1" role="img" aria-label="<?= te('dh.state_' . $c['state']) ?>"></i>
          <div class="min-w-0 flex-grow-1">
            <div class="fw-medium"><?= te('dh.c_' . $k) ?></div>
            <div class="small text-muted text-break m4w-code"><?= e($c['detail']) ?></div>
            <?php if ($c['tip'] !== ''): ?><div class="small mt-1"><i class="bi bi-lightbulb me-1 text-warning"></i><?= e($c['tip']) ?></div><?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-hdd-network me-2 text-primary"></i><?= te('dh.senders_title') ?></div>
      <div class="card-body">
        <p class="small text-muted"><?= te('dh.senders_desc') ?></p>
        <?php if (!$r['senders']): ?><p class="small text-muted mb-0"><?= te('dh.senders_none') ?></p><?php endif; ?>
        <?php foreach ($r['senders'] as $ip => $s): ?>
        <div class="border rounded-3 p-3 mb-3">
          <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <code class="fs-6"><?= e($ip) ?></code>
            <?php foreach ($s['labels'] as $l): [$kind, $what] = array_pad(explode(':', $l, 2), 2, ''); ?>
              <span class="badge badge-soft"><?= te('dh.from_' . $kind, ['what' => $what]) ?></span>
            <?php endforeach; ?>
            <span class="badge <?= $spfBadge[$s['spf']] ?? 'badge-soft' ?>">SPF : <?= e($s['spf']) ?></span>
          </div>
          <div class="small mb-2"><i class="bi bi-arrow-return-left me-1 text-muted"></i><?= te('dh.ptr_label') ?> <?= $s['ptr']['name'] !== '' ? '<code>' . e($s['ptr']['name']) . '</code>' . ($s['ptr']['confirmed'] ? ' <i class="bi bi-check2 text-success"></i>' : ' <span class="text-warning">' . te('dh.ptr_not_back') . '</span>') : '<span class="text-danger">' . te('dh.ptr_missing') . '</span>' ?></div>
          <div class="d-flex flex-wrap gap-1">
            <?php foreach ($s['lists'] as $zone => $l): ?>
            <span class="badge <?= ['clean' => 'badge-soft-success', 'listed' => 'badge-soft-danger', 'unknown' => 'badge-soft'][$l['state']] ?>" title="<?= e($zone . ($l['code'] !== '' ? ' → ' . $l['code'] : '')) ?>"><i class="bi bi-<?= ['clean' => 'check2', 'listed' => 'x-lg', 'unknown' => 'question'][$l['state']] ?> me-1"></i><?= e($l['label']) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <div class="small fw-semibold mt-2 mb-1"><?= te('dh.domain_lists', ['domain' => $domain['name']]) ?></div>
        <div class="d-flex flex-wrap gap-1">
          <?php foreach ($r['domain_lists'] as $zone => $l): ?>
          <span class="badge <?= ['clean' => 'badge-soft-success', 'listed' => 'badge-soft-danger', 'unknown' => 'badge-soft'][$l['state']] ?>" title="<?= e($zone) ?>"><i class="bi bi-<?= ['clean' => 'check2', 'listed' => 'x-lg', 'unknown' => 'question'][$l['state']] ?> me-1"></i><?= e($l['label']) ?></span>
          <?php endforeach; ?>
        </div>
        <p class="small text-muted mt-2 mb-0"><?= te('dh.lists_note') ?></p>
      </div>
    </div>
  </div>

  <div class="col-xl-5">
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-signpost-split me-2 text-primary"></i><?= te('dh.routes_title') ?></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($r['routes'] ?? [] as $rt): ?>
        <li class="list-group-item small d-flex gap-2 align-items-start">
          <i class="bi bi-<?= ['internal' => 'building', 'own' => 'person-badge', 'collector' => 'inboxes', 'webmail' => 'cloud', 'relay' => 'arrow-left-right', 'server' => 'hdd-network'][$rt['kind']] ?? 'send' ?> text-muted mt-1"></i>
          <div class="min-w-0">
            <div class="fw-semibold"><?= te('dh.route_' . $rt['kind'], ['host' => $rt['host']]) ?> <span class="text-muted fw-normal">· <?= te('dh.n_people', ['n' => (int) $rt['users']]) ?></span></div>
            <div class="text-muted"><?= te('dh.route_' . $rt['kind'] . '_note', ['host' => $rt['host']]) ?></div>
          </div>
        </li>
        <?php endforeach; ?>
        <?php if (empty($r['routes'])): ?><li class="list-group-item small text-muted"><?= te('dh.routes_none') ?></li><?php endif; ?>
      </ul>
      <?php if (array_filter($r['routes'] ?? [], static fn($rt) => in_array($rt['kind'], ['own', 'collector', 'webmail'], true)) || !empty($r['selectors'])): ?>
      <div class="card-body border-top">
        <form method="post" action="<?= e(url('admin/domains/' . (int) $domain['id'] . '/health')) ?>"><?= csrf_field() ?>
          <label class="form-label small fw-semibold" for="dh-selectors"><?= te('dh.selectors_label') ?></label>
          <div class="input-group input-group-sm">
            <input id="dh-selectors" class="form-control m4w-code" name="selectors" value="<?= e(implode(' ', $r['selectors'] ?? [])) ?>" placeholder="ovhmo1234567-selector1 ovhmo1234567-selector2">
            <button class="btn btn-light"><?= te('dh.selectors_save') ?></button>
          </div>
          <div class="form-text"><?= te('dh.selectors_hint') ?></div>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-send-check me-2 text-primary"></i><?= te('dh.delivery_title') ?></div>
      <div class="card-body">
        <div class="row g-2 text-center mb-3">
          <?php foreach (['ok' => 'success', 'held' => 'warning', 'partial' => 'warning', 'failed' => 'danger'] as $st => $cls): ?>
          <div class="col-3"><div class="border rounded-3 py-2"><div class="fs-4 fw-semibold text-<?= $cls ?>"><?= (int) ($del['totals'][$st] ?? 0) ?></div><div class="small text-muted"><?= te('dh.sent_' . $st) ?></div></div></div>
          <?php endforeach; ?>
        </div>
        <p class="small text-muted"><?= te('dh.delivery_note') ?></p>
        <?php if (array_sum($del['tracked'] ?? [])): ?>
        <div class="small fw-semibold mb-1"><?= te('dh.track_title') ?></div>
        <div class="d-flex flex-wrap gap-1 mb-2">
          <?php foreach ($del['tracked'] as $st => $n): if (!$n) { continue; } ?>
          <span class="badge badge-soft-<?= ['queued' => 'warning', 'handed' => 'primary', 'delayed' => 'warning', 'relayed' => 'success', 'delivered' => 'success', 'failed' => 'danger'][$st] ?>"><?= (int) $n ?> · <?= e(t('js.dlv_' . $st)) ?></span>
          <?php endforeach; ?>
        </div>
        <p class="small text-muted"><?= te('dh.track_note') ?></p>
        <?php endif; ?>
        <?php if ($del['queue']): ?>
        <div class="small fw-semibold mb-1"><?= te('dh.queue_title') ?></div>
        <ul class="list-unstyled small mb-3">
          <?php foreach ($del['queue'] as $q): $rc = json_decode((string) $q['recipients'], true) ?: []; ?>
          <li class="mb-1"><span class="badge <?= $q['status'] === 'failed' || $q['status'] === 'parked' ? 'badge-soft-danger' : 'badge-soft-warning' ?>"><?= te('dh.q_' . $q['status']) ?></span> <?= e(implode(', ', $rc)) ?> · <?= te('dh.attempts', ['n' => (int) $q['attempts']]) ?><?= $q['last_error'] !== '' ? '<div class="text-muted text-break">' . e($q['last_error']) . '</div>' : '' ?></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <div class="small fw-semibold mb-1"><?= te('dh.bounces_title') ?></div>
        <?php if (!$del['bounces']): ?><p class="small text-muted"><?= te('dh.bounces_none') ?></p><?php endif; ?>
        <ul class="list-unstyled small">
          <?php foreach ($del['bounces'] as $b): ?>
          <li class="mb-2"><span class="badge <?= $b['permanent'] ? 'badge-soft-danger' : 'badge-soft-warning' ?>"><?= $b['status'] !== '' ? e($b['status']) : te('dh.bounce') ?></span>
            <?= e(format_datetime($b['date'])) ?> · <strong><?= e($b['recipient'] ?: '—') ?></strong><div class="text-muted text-break"><?= e($b['reason']) ?></div></li>
          <?php endforeach; ?>
        </ul>
        <div class="small fw-semibold mb-1"><?= te('dh.recent_title') ?></div>
        <div class="table-responsive"><table class="table table-sm small align-middle mb-0"><tbody>
          <?php foreach (array_slice($del['recent'], 0, 12) as $m): ?>
          <tr><td class="text-nowrap text-muted"><?= e(format_datetime((int) $m['created_at'])) ?></td><td class="text-break"><?= e($m['recipients']) ?><div class="text-muted"><?= e(mb_substr((string) $m['subject'], 0, 60)) ?></div></td>
            <td><span class="badge <?= ['ok' => 'badge-soft-success', 'held' => 'badge-soft-warning', 'partial' => 'badge-soft-warning', 'failed' => 'badge-soft-danger'][$m['status']] ?? 'badge-soft' ?>" title="<?= e($m['info']) ?>"><?= te('dh.sent_' . (in_array($m['status'], ['ok', 'held', 'partial', 'failed'], true) ? $m['status'] : 'ok')) ?></span></td></tr>
          <?php endforeach; ?>
          <?php if (!$del['recent']): ?><tr><td class="text-muted"><?= te('dh.recent_none') ?></td></tr><?php endif; ?>
        </tbody></table></div>
      </div>
    </div>

    <?php if ($del['receivers']): ?>
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-inboxes me-2 text-primary"></i><?= te('dh.receivers_title') ?></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($del['receivers'] as $who => $doms): ?>
        <li class="list-group-item small"><div class="fw-semibold"><?= e(implode(', ', array_keys($doms))) ?> <span class="text-muted fw-normal">· <?= te('dh.n_sent', ['n' => array_sum($doms)]) ?></span></div><div class="text-muted"><?= te('dh.rx_' . $who) ?></div></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header"><i class="bi bi-file-earmark-bar-graph me-2 text-primary"></i><?= te('dh.dmarc_title') ?></div>
      <div class="card-body small">
        <?php if (!$r['dmarc_reports']): ?><p class="text-muted mb-0"><?= te('dh.dmarc_reports_none', ['domain' => $domain['name']]) ?></p><?php endif; ?>
        <?php foreach ($r['dmarc_reports'] as $d): ?>
        <p class="mb-1"><?= te('dh.dmarc_reports', ['messages' => (int) $d['messages'], 'failed' => (int) $d['failed'], 'reports' => (int) $d['reports']]) ?></p>
        <?php endforeach; ?>
        <a href="<?= e(url('admin/health')) ?>"><?= te('dh.dmarc_more') ?></a>
      </div>
    </div>
  </div>
</div>
