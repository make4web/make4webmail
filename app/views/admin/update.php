<?php
/** @var bool $connected @var string $login @var string $branch @var ?array $status @var array $history */
$repo = M4W\Service\Updater::REPO;
?>
<div class="m4w-page-header"><div><h1><?= te('upd.title') ?></h1><p><?= te('upd.desc', ['repo' => $repo]) ?></p></div></div>
<div class="row g-4">
  <div class="col-xl-7">
    <div class="card mb-4">
      <div class="card-header d-flex align-items-center"><i class="bi bi-cloud-arrow-down me-2 text-primary"></i><span class="flex-grow-1"><?= te('upd.state_title') ?></span>
        <?php if ($connected): ?><form method="post" action="<?= e(url('admin/update/check')) ?>"><?= csrf_field() ?><button class="btn btn-light btn-sm"><i class="bi bi-arrow-repeat me-1"></i><?= te('upd.check') ?></button></form><?php endif; ?></div>
      <div class="card-body">
        <div class="row g-3 text-center mb-3">
          <div class="col-6"><div class="border rounded-3 py-3"><div class="small text-muted"><?= te('upd.installed') ?></div><div class="fs-5 fw-semibold m4w-code"><?= e(M4W_VERSION) ?></div></div></div>
          <div class="col-6"><div class="border rounded-3 py-3"><div class="small text-muted"><?= te('upd.available', ['branch' => $branch]) ?></div>
            <div class="fs-5 fw-semibold m4w-code <?= $status && $status['newer'] ? 'text-primary' : '' ?>"><?= $status ? e($status['remote'] ?: '?') : '—' ?></div></div></div>
        </div>
        <?php if (!$connected): ?>
          <p class="text-muted mb-0"><?= te('upd.connect_first') ?></p>
        <?php elseif (!$status): ?>
          <p class="text-muted mb-0"><?= te('upd.not_checked') ?></p>
        <?php else: ?>
          <p class="small text-muted"><?= te('upd.checked_at', ['date' => format_datetime((int) $status['at'])]) ?></p>
          <?php if ($status['newer']): ?>
          <form method="post" action="<?= e(url('admin/update/apply')) ?>" data-confirm="<?= te('upd.confirm', ['from' => M4W_VERSION, 'to' => $status['remote']]) ?>">
            <?= csrf_field() ?><input type="hidden" name="sha" value="<?= e($status['sha']) ?>">
            <button class="btn btn-primary"><i class="bi bi-download me-1"></i><?= te('upd.apply', ['version' => $status['remote']]) ?></button>
          </form>
          <?php elseif (M4W\Service\Updater::compare((string) $status['remote'], M4W_VERSION) === 0): ?>
          <div class="alert alert-success mb-0"><i class="bi bi-check2-circle"></i><div><?= te('upd.up_to_date') ?></div></div>
          <?php else: ?>
          <div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle"></i><div><?= te('upd.local_newer') ?></div></div>
          <?php endif; ?>
          <div class="small fw-semibold mt-4 mb-1"><?= te('upd.commits', ['branch' => $branch]) ?></div>
          <ul class="list-unstyled small mb-0">
            <?php foreach ($status['commits'] as $c): ?>
            <li class="d-flex gap-2 py-1 border-bottom"><code class="text-muted"><?= e($c['sha']) ?></code><span class="flex-grow-1 text-break"><?= e($c['message']) ?></span><span class="text-muted text-nowrap"><?= $c['date'] ? e(format_datetime((int) $c['date'])) : '' ?></span></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-clock-history me-2 text-primary"></i><?= te('upd.history') ?></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($history as $i => $h): ?>
        <li class="list-group-item small d-flex align-items-center gap-2">
          <div class="flex-grow-1"><span class="m4w-code"><?= e($h['from']) ?> → <?= e($h['to']) ?></span> · <?= e(format_datetime((int) $h['at'])) ?>
            <div class="text-muted"><?= te('upd.history_line', ['n' => (int) $h['written'], 'removed' => (int) $h['removed'], 'sha' => substr((string) $h['sha'], 0, 7)]) ?><?= !empty($h['rolled_back']) ? ' · ' . te('upd.was_rolled_back', ['date' => format_datetime((int) $h['rolled_back'])]) : '' ?></div></div>
          <?php if ($i === 0 && empty($h['rolled_back'])): ?>
          <form method="post" action="<?= e(url('admin/update/rollback')) ?>" data-confirm="<?= te('upd.rollback_confirm', ['version' => $h['from']]) ?>"><?= csrf_field() ?><button class="btn btn-light btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i><?= te('upd.rollback', ['version' => $h['from']]) ?></button></form>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
        <?php if (!$history): ?><li class="list-group-item small text-muted"><?= te('upd.history_none') ?></li><?php endif; ?>
      </ul>
    </div>
  </div>

  <div class="col-xl-5">
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-github me-2"></i><?= te('upd.account') ?></div>
      <div class="card-body">
        <?php if ($connected): ?>
          <p class="mb-3"><i class="bi bi-check-circle-fill text-success me-1"></i><?= te('upd.connected_as', ['login' => $login, 'repo' => $repo]) ?></p>
          <form method="post" action="<?= e(url('admin/update/disconnect')) ?>" data-confirm="<?= te('upd.disconnect_confirm') ?>"><?= csrf_field() ?><button class="btn btn-outline-danger btn-sm"><?= te('upd.disconnect') ?></button></form>
          <hr>
          <div class="small text-muted"><?= te('upd.replace_token') ?></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('admin/update/connect')) ?>" class="mt-2" autocomplete="off">
          <?= csrf_field() ?>
          <div class="mb-2"><label class="form-label small" for="u-login"><?= te('upd.login') ?></label><input class="form-control" id="u-login" name="login" value="<?= e($login) ?>" autocomplete="username" placeholder="make4web"></div>
          <div class="mb-2"><label class="form-label small" for="u-token"><?= te('upd.token') ?></label><input class="form-control m4w-code" id="u-token" name="token" type="password" autocomplete="new-password" placeholder="github_pat_…" required></div>
          <div class="mb-3"><label class="form-label small" for="u-branch"><?= te('upd.branch') ?></label><input class="form-control m4w-code" id="u-branch" name="branch" value="<?= e($branch) ?>"></div>
          <button class="btn btn-primary"><i class="bi bi-plug me-1"></i><?= te($connected ? 'upd.reconnect' : 'upd.connect') ?></button>
        </form>
        <div class="small text-muted mt-3"><?= te('upd.token_help') ?></div>
        <ol class="small text-muted mt-1 mb-0 ps-3">
          <li><?= te('upd.token_step1') ?></li>
          <li><?= te('upd.token_step2', ['repo' => $repo]) ?></li>
          <li><?= te('upd.token_step3') ?></li>
        </ol>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><i class="bi bi-funnel me-2 text-primary"></i><?= te('upd.what_title') ?></div>
      <div class="card-body small">
        <p class="mb-1"><?= te('upd.what_copied') ?></p><p class="m4w-code mb-2"><?= e(implode('  ', M4W\Service\Updater::INCLUDE)) ?></p>
        <p class="mb-2"><?= te('upd.what_never') ?></p>
        <p class="mb-0 text-muted"><?= te('upd.what_backup') ?></p>
      </div>
    </div>
  </div>
</div>
