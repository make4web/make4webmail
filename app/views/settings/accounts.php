<div class="m4w-page-header"><div><h1><?= te('fetch.title') ?></h1><p><?= te('fetch.desc') ?></p></div><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#acc-modal" data-account="{}"><i class="bi bi-plus-lg me-1"></i><?= te('fetch.add') ?></button></div>
<?php if (!$accounts): ?>
  <div class="m4w-empty py-5"><div class="m4w-empty-icon"><i class="bi bi-cloud-download"></i></div><h3><?= te('fetch.empty') ?></h3><p class="small"><?= te('fetch.empty_hint') ?></p></div>
<?php else: ?>
  <?php foreach ($accounts as $a): ?>
  <div class="m4w-rule">
    <span class="m4w-avatar" style="background:<?= e(avatar_color($a['host'])) ?>"><i class="bi bi-cloud-download"></i></span>
    <div class="flex-grow-1 min-w-0">
      <div class="fw-semibold"><?= e($a['label'] ?: $a['username']) ?> <?= $a['enabled'] ? '' : '<span class="badge badge-soft-secondary">' . te('common.disabled') . '</span>' ?></div>
      <div class="m4w-rule-summary"><?= e($a['username']) ?> · <?= e($a['host']) ?>:<?= (int) $a['port'] ?> (<?= e(strtoupper($a['security'])) ?>) · <?= te('fetch.last_run') ?> <?= e(format_datetime((int) $a['last_run_at'])) ?></div>
      <?php if ($a['last_error']): ?><div class="small text-danger"><i class="bi bi-exclamation-triangle"></i> <?= e($a['last_error']) ?></div><?php endif; ?>
    </div>
    <form method="post" action="<?= e(url('settings/accounts/run')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="btn btn-light btn-sm"><i class="bi bi-arrow-repeat me-1"></i><?= te('fetch.run') ?></button></form>
    <button class="btn btn-ghost btn-icon btn-sm" data-bs-toggle="modal" data-bs-target="#acc-modal" data-account="<?= e(json_encode(array_intersect_key($a, array_flip(['id', 'label', 'host', 'port', 'security', 'username', 'remote_folder', 'delete_remote', 'apply_rules', 'enabled'])))) ?>"><i class="bi bi-pencil"></i></button>
    <form method="post" action="<?= e(url('settings/accounts/delete')) ?>" data-confirm="<?= te('fetch.confirm_delete') ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="btn btn-ghost btn-icon btn-sm text-danger"><i class="bi bi-trash3"></i></button></form>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
<div class="modal fade" id="acc-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= e(url('settings/accounts/save')) ?>">
      <?= csrf_field() ?><input type="hidden" name="id">
      <div class="modal-header"><h5 class="modal-title"><?= te('fetch.account') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div class="row g-3">
        <div class="col-12"><label class="form-label"><?= te('fetch.label') ?></label><input class="form-control" name="label" placeholder="Gmail perso"></div>
        <div class="col-8"><label class="form-label"><?= te('fetch.host') ?></label><input class="form-control" name="host" placeholder="imap.exemple.com" required></div>
        <div class="col-4"><label class="form-label">Port</label><input class="form-control" name="port" value="993" required></div>
        <div class="col-6"><label class="form-label"><?= te('fetch.security') ?></label><select class="form-select" name="security"><option value="ssl">SSL/TLS</option><option value="tls">STARTTLS</option><option value="none"><?= te('common.none') ?></option></select></div>
        <div class="col-6"><label class="form-label"><?= te('fetch.folder') ?></label><input class="form-control" name="remote_folder" value="INBOX"></div>
        <div class="col-12"><label class="form-label"><?= te('fetch.username') ?></label><input class="form-control" name="username" required autocomplete="off"></div>
        <div class="col-12"><label class="form-label"><?= te('auth.password') ?></label><input class="form-control" type="password" name="password" autocomplete="new-password" placeholder="<?= te('fetch.password_keep') ?>"></div>
        <div class="col-12">
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="a-en" checked><label class="form-check-label" for="a-en"><?= te('fetch.enabled') ?></label></div>
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="apply_rules" value="1" id="a-ru" checked><label class="form-check-label" for="a-ru"><?= te('fetch.apply_rules') ?></label></div>
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="delete_remote" value="1" id="a-de"><label class="form-check-label" for="a-de"><?= te('fetch.delete_remote') ?></label></div>
        </div>
      </div></div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= te('common.cancel') ?></button><button class="btn btn-primary"><?= te('common.save') ?></button></div>
    </form>
  </div>
</div>
