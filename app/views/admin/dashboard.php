<?php $max = max(1, ...array_map(static fn($d) => max($d['in'], $d['out']), $days)); ?>
<div class="m4w-page-header">
  <div><h1><?= te('admin.dash_title') ?></h1><p><?= te('admin.dash_desc', ['brand' => M4W\Core\Settings::get('brand.name')]) ?></p></div>
  <div class="d-flex gap-2"><a class="btn btn-light" href="<?= e(url('admin/logs')) ?>"><i class="bi bi-journal-text me-1"></i><?= te('admin.nav_logs') ?></a><a class="btn btn-primary" href="<?= e(url('admin/users/new')) ?>"><i class="bi bi-person-plus me-1"></i><?= te('admin.new_user') ?></a></div>
</div>
<div class="row g-3 mb-4">
  <?php foreach ([
      ['people', $stats['active'] . ' / ' . $stats['users'], 'admin.stat_users', ''],
      ['envelope-arrow-down', $stats['in24'], 'admin.stat_in', 'background:rgba(22,163,74,.12);color:#16a34a'],
      ['envelope-arrow-up', $stats['out24'], 'admin.stat_out', 'background:rgba(14,165,233,.12);color:#0284c7'],
      ['hdd-stack', format_bytes($stats['storage']), 'admin.stat_storage', 'background:rgba(124,58,237,.12);color:#7c3aed'],
  ] as $i => [$icon, $val, $label, $style]): ?>
  <div class="col-sm-6 col-xl-3" data-aos="fade-up" data-aos-delay="<?= $i * 60 ?>">
    <div class="m4w-stat"><span class="icon" style="<?= $style ?>"><i class="bi bi-<?= $icon ?>"></i></span><div><div class="value"><?= e($val) ?></div><div class="label"><?= te($label) ?></div></div></div>
  </div>
  <?php endforeach; ?>
</div>
<div class="row g-4">
  <div class="col-xl-8">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center"><?= te('admin.traffic') ?><span class="ms-auto small fw-normal text-muted d-flex gap-3"><span><span class="d-inline-block rounded-1 me-1" style="width:10px;height:10px;background:var(--m4w-primary)"></span><?= te('admin.incoming') ?></span><span><span class="d-inline-block rounded-1 me-1" style="width:10px;height:10px;background:var(--m4w-accent);opacity:.65"></span><?= te('admin.outgoing') ?></span></span></div>
      <div class="card-body">
        <div class="m4w-chart" role="img" aria-label="<?= te('admin.traffic') ?>">
          <?php foreach ($days as $d): ?>
          <div class="bar-group" title="<?= e($d['label'] . ' — ' . t('admin.incoming') . ': ' . $d['in'] . ', ' . t('admin.outgoing') . ': ' . $d['out']) ?>">
            <div class="bars"><div class="bar in" style="height:<?= round($d['in'] / $max * 100) ?>%"></div><div class="bar out" style="height:<?= round($d['out'] / $max * 100) ?>%"></div></div>
            <div class="lbl"><?= e($d['label']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="row text-center mt-4 g-2">
          <div class="col-4"><div class="fw-bold fs-5"><?= (int) $stats['messages'] ?></div><div class="small text-muted"><?= te('admin.stat_messages') ?></div></div>
          <div class="col-4"><div class="fw-bold fs-5 <?= $stats['queue'] ? 'text-warning' : '' ?>"><?= (int) $stats['queue'] ?></div><div class="small text-muted"><?= te('admin.stat_queue') ?></div></div>
          <div class="col-4"><div class="fw-bold fs-5 <?= $stats['failed'] ? 'text-danger' : '' ?>"><?= (int) $stats['failed'] ?></div><div class="small text-muted"><?= te('admin.stat_failed') ?></div></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card h-100">
      <div class="card-header"><?= te('admin.checklist') ?></div>
      <ul class="list-unstyled m4w-checklist px-3 py-1 mb-0">
        <?php foreach ([
            'smtp' => ['admin.chk_smtp', 'admin/mail'], 'dkim' => ['admin.chk_dkim', 'admin/mail#dkim'], 'https' => ['admin.chk_https', null],
            'logo' => ['admin.chk_logo', 'admin/branding'], 'twofa' => ['admin.chk_2fa', 'settings/security'], 'cron' => ['admin.chk_cron', null],
        ] as $k => [$label, $link]): ?>
        <li><i class="bi bi-<?= $checks[$k] ? 'check-circle-fill ok' : 'exclamation-circle-fill ko' ?>"></i><span class="flex-grow-1"><?= te($label) ?></span><?php if (!$checks[$k] && $link): ?><a class="small" href="<?= e(url($link)) ?>"><?= te('admin.fix') ?></a><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
      <div class="card-footer small text-muted"><i class="bi bi-shield-lock me-1"></i><?= te('admin.sec_summary', ['ko' => $stats['logins_ko'], 'twofa' => $stats['twofa'], 'vac' => $stats['vacations']]) ?></div>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header"><?= te('admin.top_storage') ?></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($top as $u): $pct = $u['quota_mb'] > 0 ? min(100, round($u['used_bytes'] / ($u['quota_mb'] * 1048576) * 100)) : 0; ?>
        <li class="list-group-item"><div class="d-flex justify-content-between small mb-1"><a href="<?= e(url('admin/users/' . $u['id'])) ?>" class="text-reset fw-medium"><?= e($u['display_name'] ?: $u['email']) ?></a><span class="text-muted"><?= e(format_bytes((int) $u['used_bytes'])) ?><?= $u['quota_mb'] > 0 ? ' / ' . e(format_bytes($u['quota_mb'] * 1048576)) : '' ?></span></div>
        <div class="progress" style="height:5px"><div class="progress-bar <?= $pct > 90 ? 'bg-danger' : '' ?>" style="width:<?= max(1, $pct) ?>%"></div></div></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header d-flex"><?= te('admin.recent_activity') ?><a class="ms-auto small fw-normal" href="<?= e(url('admin/logs', ['tab' => 'audit'])) ?>"><?= te('admin.see_all') ?></a></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($recent as $a): ?>
        <li class="list-group-item d-flex gap-3 small align-items-center"><span class="m4w-avatar sm" style="background:<?= e(avatar_color((string) $a['email'])) ?>"><?= e(initials((string) ($a['email'] ?: '?'))) ?></span><div class="flex-grow-1 min-w-0"><div class="text-truncate"><b><?= e($a['email'] ?: t('admin.system')) ?></b> · <?= e(audit_label($a['action'])) ?> <span class="text-muted"><?= e(str_limit((string) $a['target'], 40)) ?></span></div></div><span class="text-muted text-nowrap"><?= e(format_datetime((int) $a['created_at'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>
