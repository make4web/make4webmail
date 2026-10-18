<?php
/** @var array $checks @var string $overall @var array $dmarc @var int $days @var array $domains */
use M4W\Core\Settings as S;
$icon = ['ok' => 'check-circle-fill text-success', 'warn' => 'exclamation-triangle-fill text-warning', 'fail' => 'x-octagon-fill text-danger', 'off' => 'dash-circle text-muted'];
$groups = ['server' => ['cron', 'queue', 'disk', 'backup', 'antivirus', 'remote', 'alerts', 'cert'], 'dns' => ['mx', 'spf', 'dkim', 'dmarc']];
?>
<div class="m4w-page-header"><div><h1><?= te('health.title') ?></h1><p><?= te('health.desc') ?></p></div>
  <span class="badge fs-6 <?= ['ok' => 'badge-soft-success', 'warn' => 'badge-soft-warning', 'fail' => 'badge-soft-danger'][$overall] ?>"><i class="bi bi-<?= explode(' ', $icon[$overall])[0] ?> me-1"></i><?= te('health.overall_' . $overall) ?></span></div>

<div class="row g-4">
<?php foreach ($groups as $g => $kinds): ?>
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-<?= $g === 'dns' ? 'globe2' : 'hdd-stack' ?> me-2 text-primary"></i><?= te('health.group_' . $g) ?></div>
      <ul class="list-group list-group-flush">
      <?php $n = 0; foreach ($checks as $key => $c): $kind = explode(':', $key)[0];
        // Server: every check that is not about the domains' DNS (a new one is never left out).
        if ($g === 'server' ? in_array($kind, $groups['dns'], true) : !in_array($kind, $kinds, true)) { continue; } $n++; ?>
        <li class="list-group-item d-flex gap-3 align-items-start">
          <i class="bi bi-<?= $icon[$c['state']] ?> mt-1" role="img" aria-label="<?= te('health.state_' . $c['state']) ?>"></i>
          <div class="min-w-0 flex-grow-1"><div class="fw-medium"><?= e($c['label']) ?></div><div class="small text-muted text-break"><?= e($c['detail']) ?></div></div>
          <?php if ($c['link'] !== '' && $c['state'] !== 'ok'): ?><a class="btn btn-light btn-sm text-nowrap" href="<?= e(url($c['link'])) ?>"><?= te('health.fix') ?></a><?php endif; ?>
        </li>
      <?php endforeach; ?>
      <?php if (!$n): ?><li class="list-group-item small text-muted"><?= te('health.no_domains') ?></li><?php endif; ?>
      </ul>
    </div>
  </div>
<?php endforeach; ?>
</div>

<form method="post" action="<?= e(url('admin/health')) ?>" class="card mt-4">
  <?= csrf_field() ?>
  <div class="card-header"><i class="bi bi-bell me-2 text-primary"></i><?= te('health.alerts_title') ?></div>
  <div class="card-body">
    <p class="small text-muted"><?= te('health.alerts_desc') ?></p>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <label class="visually-hidden" for="health-email"><?= te('health.alert_email') ?></label>
      <input class="form-control" style="max-width:340px" type="email" id="health-email" name="alert_email" value="<?= e(S::get('health.alert_email', '')) ?>" placeholder="<?= te('health.alert_email_ph') ?>">
      <button class="btn btn-primary"><?= te('common.save') ?></button>
      <button class="btn btn-light" name="refresh" value="1"><i class="bi bi-arrow-repeat me-1"></i><?= te('health.refresh_dns') ?></button>
    </div>
    <div class="form-text"><?= te('health.last_run', ['date' => format_datetime((int) S::get('health.last_run', 0))]) ?></div>
  </div>
</form>

<div class="card mt-4" id="dmarc">
  <div class="card-header d-flex align-items-center flex-wrap gap-2"><i class="bi bi-envelope-check me-2 text-primary"></i><?= te('health.dmarc_title') ?>
    <form method="get" action="<?= e(url('admin/health')) ?>#dmarc" class="ms-auto d-flex align-items-center gap-2">
      <label class="small text-muted" for="dmarc-days"><?= te('health.period') ?></label>
      <select class="form-select form-select-sm" id="dmarc-days" name="days" data-autosubmit style="width:auto">
        <?php foreach ([7, 30, 90, 365] as $d): ?><option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= te('health.days', ['n' => $d]) ?></option><?php endforeach; ?>
      </select><noscript><button class="btn btn-light btn-sm"><?= te("health.apply") ?></button></noscript>
    </form></div>
  <div class="card-body">
    <?php if (!$dmarc['domains']): ?>
      <p class="mb-2"><?= te('health.dmarc_empty') ?></p>
      <p class="small text-muted mb-0"><?= te('health.dmarc_howto') ?></p>
      <?php foreach ($domains as $d): ?><code class="d-block small mt-1 text-break">_dmarc.<?= e($d) ?> TXT "v=DMARC1; p=quarantine; rua=mailto:postmaster@<?= e($d) ?>"</code><?php endforeach; ?>
    <?php else: ?>
      <div class="row g-3 mb-3">
        <?php foreach ($dmarc['domains'] as $d): $rate = $d['messages'] ? round(100 * ($d['messages'] - $d['failed']) / $d['messages'], 1) : 100; ?>
        <div class="col-md-6 col-xl-4"><div class="m4w-stat-card p-3 border rounded-3 h-100">
          <div class="fw-semibold"><?= e($d['domain']) ?></div>
          <div class="fs-4 fw-bold <?= $rate >= 98 ? 'text-success-emphasis' : ($rate >= 90 ? 'text-warning-emphasis' : 'text-danger-emphasis') ?>"><?= e(str_replace('.', ',', (string) $rate)) ?> %</div>
          <div class="small text-muted"><?= te('health.dmarc_stats', ['n' => number_format((int) $d['messages'], 0, ',', ' '), 'failed' => number_format((int) $d['failed'], 0, ',', ' '), 'reports' => (int) $d['reports'], 'orgs' => (int) $d['orgs']]) ?></div>
        </div></div>
        <?php endforeach; ?>
      </div>
      <h3 class="h6"><?= te('health.dmarc_sources') ?></h3>
      <p class="small text-muted"><?= te('health.dmarc_sources_desc') ?></p>
      <div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th><?= te('health.source_ip') ?></th><th><?= te('health.header_from') ?></th><th class="text-end"><?= te('health.messages') ?></th><th class="text-end"><?= te('health.pass') ?></th><th>SPF</th><th>DKIM</th></tr></thead>
        <tbody>
        <?php foreach ($dmarc['sources'] as $s): $fail = (int) $s['messages'] - (int) $s['passed']; ?>
          <tr class="<?= $fail > 0 ? 'table-warning' : '' ?>"><td class="m4w-code"><?= e($s['source_ip']) ?></td><td class="small"><?= e($s['header_from']) ?></td><td class="text-end"><?= (int) $s['messages'] ?></td>
            <td class="text-end"><?= (int) $s['passed'] ?></td>
            <td><i class="bi bi-<?= $s['spf_ok'] ? 'check-lg text-success' : 'x-lg text-danger' ?>" role="img" aria-label="SPF <?= te($s['spf_ok'] ? 'health.state_ok' : 'health.state_fail') ?>"></i></td>
            <td><i class="bi bi-<?= $s['dkim_ok'] ? 'check-lg text-success' : 'x-lg text-danger' ?>" role="img" aria-label="DKIM <?= te($s['dkim_ok'] ? 'health.state_ok' : 'health.state_fail') ?>"></i></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="small text-muted mb-0"><?= te('health.dmarc_orgs', ['list' => implode(', ', array_map(static fn($o) => $o['org'] . ' (' . $o['reports'] . ')', $dmarc['orgs']))]) ?></p>
    <?php endif; ?>
  </div>
</div>
