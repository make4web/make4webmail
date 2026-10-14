<?php
use M4W\Core\Settings as S;
use M4W\Service\SpamFilter;

$th = SpamFilter::thresholds();
$levelBadge = ['ok' => 'success', 'suspect' => 'warning', 'quarantine' => 'danger', 'delete' => 'dark'];
?>
<div class="m4w-page-header"><div><h1><?= te('admin.spam_title') ?></h1><p><?= te('admin.spam_desc') ?></p></div>
  <a class="btn btn-light" href="<?= e(url('admin/deleted', ['reason' => 'quarantine', 'state' => 'pending'])) ?>"><i class="bi bi-inboxes me-1"></i><?= te('admin.spam_open_quarantine', ['n' => $stats['held']]) ?></a></div>

<div class="row g-3 mb-4">
  <?php foreach ([['envelope', 'admin.spam_received', $stats['received']], ['exclamation-triangle', 'admin.spam_l_suspect', $stats['suspect']], ['inboxes', 'admin.spam_l_quarantine', $stats['quarantine']], ['x-octagon', 'admin.spam_l_delete', $stats['delete']]] as [$icon, $label, $n]): ?>
  <div class="col-sm-6 col-xl-3"><div class="m4w-stat"><span class="icon"><i class="bi bi-<?= $icon ?>"></i></span><div><div class="value"><?= number_format($n, 0, ',', ' ') ?></div><div class="label"><?= te($label) ?> <span class="text-muted">· <?= te('admin.spam_7days') ?></span></div></div></div></div>
  <?php endforeach; ?>
</div>

<form method="post" action="<?= e(url('admin/spam')) ?>">
  <?= csrf_field() ?>
  <div class="m4w-section">
    <div class="m4w-section-title"><i class="bi bi-funnel me-2 text-primary"></i><?= te('admin.spam_levels') ?></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.spam_enable') ?><small><?= te('admin.spam_enable_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled" value="1" aria-label="<?= te('admin.spam_enable') ?>" <?= S::get('spam.enabled') ? 'checked' : '' ?>></div></div>

    <?php
    // Score ranges actually applied (a level at 0 is off).
    $fmt = static fn(float $v) => rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    $bounds = array_filter(['suspect' => $th['suspect'], 'quarantine' => $th['quarantine'], 'delete' => $th['delete']], static fn($v) => $v > 0);
    $keys = array_keys($bounds);
    $ranges = ['ok' => '< ' . $fmt((float) reset($bounds))];
    foreach ($keys as $i => $k) {
        $ranges[$k] = isset($keys[$i + 1]) ? $fmt($bounds[$k]) . ' – ' . $fmt($bounds[$keys[$i + 1]]) : '≥ ' . $fmt($bounds[$k]);
    }
    ?>
    <div class="m4w-spam-scale" aria-hidden="true">
      <?php foreach (['ok' => 'inbox', 'suspect' => 'exclamation-triangle', 'quarantine' => 'inboxes', 'delete' => 'x-octagon'] as $lvl => $icon): ?>
      <div class="seg <?= $lvl ?><?= isset($ranges[$lvl]) ? '' : ' off' ?>"><i class="bi bi-<?= $icon ?>"></i><?= te('admin.spam_l_' . $lvl) ?><span class="range"><?= e($ranges[$lvl] ?? t('admin.spam_off')) ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php foreach (['suspect' => 'exclamation-triangle', 'quarantine' => 'inboxes', 'delete' => 'x-octagon'] as $lvl => $icon): ?>
    <div class="m4w-setting-row"><div class="label"><i class="bi bi-<?= $icon ?> me-1"></i><?= te('admin.spam_l_' . $lvl) ?><small><?= te('admin.spam_d_' . $lvl) ?></small></div>
      <div class="input-group align-self-center" style="max-width:220px"><span class="input-group-text"><?= te('admin.spam_from_score') ?></span><input class="form-control" type="number" step="0.5" min="<?= $lvl === 'suspect' ? 1 : 0 ?>" max="500" name="<?= $lvl ?>" aria-label="<?= te('admin.spam_l_' . $lvl) ?>" value="<?= e((string) $th[$lvl]) ?>"></div></div>
    <?php endforeach; ?>
    <p class="small text-muted mt-2 mb-0"><?= te('admin.spam_levels_hint') ?></p>
  </div>

  <div class="m4w-section">
    <div class="m4w-section-title"><i class="bi bi-list-check me-2 text-primary"></i><?= te('admin.spam_lists') ?></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.spam_allow') ?><small><?= te('admin.spam_allow_desc') ?></small></div><div><textarea class="form-control m4w-code m4w-code-short" rows="4" style="min-width:min(380px,70vw)" name="allow" aria-label="<?= te('admin.spam_allow') ?>" placeholder="partenaire.fr&#10;comptable@cabinet.fr"><?= e(S::get('spam.allow')) ?></textarea></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.spam_block') ?><small><?= te('admin.spam_block_desc') ?></small></div><div><textarea class="form-control m4w-code m4w-code-short" rows="4" style="min-width:min(380px,70vw)" name="block" aria-label="<?= te('admin.spam_block') ?>"><?= e(S::get('spam.block')) ?></textarea></div></div>
  </div>

  <div class="m4w-section">
    <div class="m4w-section-title"><i class="bi bi-gear me-2 text-primary"></i><?= te('admin.spam_sources') ?></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.spam_upstream') ?><small><?= te('admin.spam_upstream_desc') ?></small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="upstream" value="1" aria-label="<?= te('admin.spam_upstream') ?>" <?= S::get('inbound.spam_header') ? 'checked' : '' ?>></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.spam_dnsbl') ?><small><?= te('admin.spam_dnsbl_desc') ?></small></div><div><input class="form-control m4w-code" style="max-width:380px" name="dnsbl" aria-label="<?= te('admin.spam_dnsbl') ?>" placeholder="zen.spamhaus.org" value="<?= e(str_replace("\n", ' ', (string) S::get('spam.dnsbl'))) ?>"></div></div>
    <div class="m4w-setting-row"><div class="label"><?= te('admin.spam_keep') ?><small><?= te('admin.spam_keep_desc') ?></small></div><div class="input-group" style="max-width:180px"><input class="form-control" type="number" min="1" max="365" name="keep_days" aria-label="<?= te('admin.spam_keep') ?>" value="<?= (int) S::get('spam.keep_days') ?>"><span class="input-group-text"><?= te('admin.files_days') ?></span></div></div>
  </div>
  <div class="m4w-sticky-actions"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= te('common.save') ?></button></div>
</form>

<div class="m4w-section mt-4" id="spam-test">
  <div class="m4w-section-title"><i class="bi bi-clipboard-check me-2 text-primary"></i><?= te('admin.spam_test') ?></div>
  <p class="small text-muted"><?= te('admin.spam_test_desc') ?></p>
  <form method="post" action="<?= e(url('admin/spam/test')) ?>">
    <?= csrf_field() ?>
    <textarea class="form-control m4w-code m4w-code-short mb-2" rows="6" name="source" aria-label="<?= te('admin.spam_test') ?>" placeholder="From: …&#10;Subject: …"><?= e($test['source'] ?? '') ?></textarea>
    <button class="btn btn-light"><i class="bi bi-calculator me-1"></i><?= te('admin.spam_test_run') ?></button>
  </form>
  <?php if ($test): ?>
  <div class="m4w-spam-result mt-3">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
      <span class="fs-4 fw-semibold"><?= e((string) $test['score']) ?></span>
      <span class="badge badge-soft-<?= $levelBadge[$test['level']] ?>"><?= te('admin.spam_l_' . $test['level']) ?></span>
      <span class="text-muted small text-truncate"><?= e($test['from']) ?> — <?= e($test['subject']) ?></span>
    </div>
    <?php if ($test['tests']): ?>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody>
      <?php arsort($test['tests']); foreach ($test['tests'] as $code => $w): ?>
      <tr><td><?= e(SpamFilter::label($code)) ?></td><td class="text-end m4w-code <?= $w > 0 ? 'text-danger' : 'text-success' ?>"><?= $w > 0 ? '+' : '' ?><?= e((string) $w) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php else: ?><p class="text-muted small mb-0"><?= te('admin.spam_test_clean') ?></p><?php endif; ?>
    <p class="small text-muted mt-2 mb-0"><?= te('admin.spam_test_note') ?></p>
  </div>
  <?php endif; ?>
</div>
