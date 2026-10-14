<?php $isAdmin = ($currentUser['role'] ?? '') === 'admin'; ?>
<div class="m4w-page-header"><div><h1><?= te('shared.title') ?></h1><p><?= te('shared.desc') ?></p></div></div>

<div class="m4w-section">
  <?php if (!$boxes): ?>
  <div class="m4w-empty py-4"><div class="m4w-empty-icon"><i class="bi bi-inboxes"></i></div><h3><?= te('shared.empty') ?></h3><p class="small"><?= te('shared.empty_hint') ?></p></div>
  <?php endif; ?>
  <?php foreach ($boxes as $b): $bn = $b['display_name'] ?: trim($b['first_name'] . ' ' . $b['last_name']) ?: $b['email']; ?>
  <div class="m4w-rule">
    <span class="m4w-avatar" style="background:<?= e(avatar_color($b['email'])) ?>"><?= e(initials($bn)) ?></span>
    <div class="flex-grow-1 min-w-0">
      <div class="fw-semibold text-truncate"><?= e($bn) ?> <?php if ((int) $b['unread']): ?><span class="badge badge-soft ms-1"><?= te('shared.unread', ['n' => (int) $b['unread']]) ?></span><?php endif; ?></div>
      <div class="m4w-rule-summary text-truncate"><?= e($b['email']) ?> · <?= te($b['method'] === 'admin' ? 'shared.by_admin' : 'shared.by_password', ['date' => date('d/m/Y', (int) $b['created_at'])]) ?></div>
    </div>
    <form method="post" action="<?= e(url('mailbox/switch')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="btn btn-soft btn-sm text-nowrap"><i class="bi bi-box-arrow-in-right me-1"></i><?= te('shared.open') ?></button></form>
    <form method="post" action="<?= e(url('settings/mailboxes/remove')) ?>" data-confirm="<?= te('shared.remove_confirm', ['email' => $b['email']]) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="btn btn-ghost btn-icon btn-sm" title="<?= te('shared.remove') ?>" aria-label="<?= te('shared.remove') ?>"><i class="bi bi-x-lg"></i></button></form>
  </div>
  <?php endforeach; ?>
</div>

<form method="post" action="<?= e(url('settings/mailboxes/add')) ?>" class="m4w-section" id="add" autocomplete="off">
  <?= csrf_field() ?>
  <div class="m4w-section-title"><i class="bi bi-plus-circle me-2 text-primary"></i><?= te('shared.add_title') ?></div>
  <div class="m4w-section-desc"><?= te($isAdmin ? 'shared.add_desc_admin' : 'shared.add_desc') ?></div>
  <div class="row g-3" style="max-width:760px">
    <div class="col-md-<?= $isAdmin ? '8' : '6' ?>"><label class="form-label" for="sh-email"><?= te('shared.address') ?></label><input class="form-control" type="email" id="sh-email" name="email" required placeholder="contact@…" autocomplete="off"></div>
    <?php if (!$isAdmin): ?>
    <div class="col-md-6"><label class="form-label" for="sh-pw"><?= te('shared.password') ?></label><input class="form-control" type="password" id="sh-pw" name="password" required autocomplete="new-password"></div>
    <div class="col-md-6"><label class="form-label" for="sh-code"><?= te('shared.code') ?> <small class="text-muted fw-normal">(<?= te('shared.code_if') ?>)</small></label><input class="form-control" id="sh-code" name="code" inputmode="numeric" maxlength="8" autocomplete="one-time-code"></div>
    <?php endif; ?>
    <div class="col-12"><button class="btn btn-primary"><i class="bi bi-link-45deg me-1"></i><?= te('shared.add') ?></button></div>
  </div>
  <p class="small text-muted mt-3 mb-0"><i class="bi bi-info-circle me-1"></i><?= te('shared.note') ?></p>
</form>
