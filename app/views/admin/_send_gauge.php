<?php
/**
 * Collector mode: sending volume of the shared provider account over the last hour.
 * @var array $cs Collector::stats()
 */
$bmax = max(1, ...$cs['buckets']);
$labels = ['low' => 'col.level_low', 'mid' => 'col.level_low', 'high' => 'col.level_high', 'full' => 'col.level_full'];
?>
<div class="card m4w-gauge-card level-<?= e($cs['level']) ?>" id="col-gauge" data-col-gauge data-src="<?= e(url('admin/mail/collector/stats')) ?>">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-speedometer2 text-primary"></i><span><?= te('col.gauge_title') ?></span>
    <span class="ms-auto m4w-gauge-pill" data-g="pill"><?= te($labels[$cs['level']]) ?></span>
  </div>
  <div class="card-body">
    <div class="d-flex align-items-end gap-3 flex-wrap mb-2">
      <div class="m4w-gauge-value"><span data-g="used"><?= (int) $cs['used'] ?></span><span class="m4w-gauge-limit"> / <?= (int) $cs['limit'] ?></span></div>
      <div class="small text-muted pb-1"><?= te('col.gauge_unit') ?> · <span data-g="left"><?= te('col.gauge_left', ['n' => $cs['left']]) ?></span></div>
    </div>
    <div class="m4w-gauge" role="meter" aria-valuemin="0" aria-valuemax="<?= (int) $cs['limit'] ?>" aria-valuenow="<?= (int) $cs['used'] ?>" aria-label="<?= te('col.gauge_title') ?>" data-g="meter">
      <div class="m4w-gauge-fill" style="width:<?= (int) $cs['pct'] ?>%" data-g="fill"></div>
      <span class="m4w-gauge-tick" style="left:80%" aria-hidden="true"></span>
    </div>
    <div class="d-flex justify-content-between small text-muted mt-1"><span>0</span><span>80 %</span><span><?= te('col.gauge_cap', ['n' => $cs['limit']]) ?></span></div>

    <div class="alert alert-danger d-flex gap-2 mt-3 mb-0<?= $cs['level'] === 'full' || $cs['held'] ? '' : ' d-none' ?>" data-g="alert" role="alert">
      <i class="bi bi-sign-stop-fill fs-5"></i>
      <div><strong><?= te('col.full_title', ['limit' => $cs['limit']]) ?></strong><br>
        <span data-g="held"><?= te('col.full_held', ['n' => $cs['held'], 'r' => $cs['held_rcpts']]) ?></span>
        <span data-g="next"><?= $cs['next'] ? te('col.full_next', ['time' => date('H:i', $cs['next'])]) : '' ?></span>
        <?php if ($cs['block']): ?><div class="small mt-1"><?= te('col.provider_said', ['time' => date('H:i', (int) $cs['block']['at'])]) ?> <code><?= e($cs['block']['message']) ?></code></div><?php endif; ?>
      </div>
    </div>

    <div class="row g-3 mt-1">
      <div class="col-md-7">
        <div class="small fw-semibold mb-1"><?= te('col.last_hour') ?></div>
        <div class="m4w-gauge-spark" role="img" aria-label="<?= te('col.last_hour') ?>">
          <?php foreach ($cs['buckets'] as $i => $n): ?><span style="height:<?= max(3, (int) round($n / $bmax * 100)) ?>%" title="<?= e(t('col.bucket', ['from' => 60 - $i * 5, 'to' => 55 - $i * 5, 'n' => $n])) ?>"<?= $n ? '' : ' class="empty"' ?>></span><?php endforeach; ?>
        </div>
        <div class="d-flex justify-content-between small text-muted"><span><?= te('col.minus_60') ?></span><span><?= te('col.now') ?></span></div>
      </div>
      <div class="col-md-5">
        <div class="small fw-semibold mb-1"><?= te('col.top') ?></div>
        <?php if (!$cs['top']): ?><div class="small text-muted"><?= te('col.top_none') ?></div><?php endif; ?>
        <ul class="list-unstyled small mb-0">
          <?php foreach ($cs['top'] as $t): ?><li class="d-flex gap-2"><span class="text-truncate"><?= e($t['email'] ?: t('col.system')) ?></span><span class="ms-auto fw-semibold"><?= (int) $t['n'] ?></span></li><?php endforeach; ?>
        </ul>
      </div>
    </div>
    <p class="small text-muted mt-3 mb-0"><i class="bi bi-info-circle me-1"></i><?= te('col.gauge_help', ['limit' => $cs['limit']]) ?></p>
  </div>
</div>
