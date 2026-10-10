<?php $o = $old ?? []; $v = static fn($k, $d = '') => e($o[$k] ?? $d); ?>
<div class="m4w-install" data-aos="fade-up">
  <div class="text-center mb-4">
    <span class="m4w-brand-mark mb-3" style="width:56px;height:56px;font-size:1.6rem;border-radius:16px"><i class="bi bi-envelope-paper-heart"></i></span>
    <h1 class="fw-bold" style="letter-spacing:-.03em"><?= te('install.title') ?></h1>
    <p class="text-muted"><?= te('install.subtitle') ?></p>
  </div>
  <?php if ($errors): ?>
  <div class="alert alert-danger"><i class="bi bi-exclamation-octagon-fill"></i><div><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div></div>
  <?php endif; ?>
  <div class="row g-4">
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-clipboard-check me-2"></i><?= te('install.requirements') ?></div>
        <ul class="list-unstyled m4w-checklist px-3 py-1 mb-0">
          <?php foreach ($checks as $name => $ok): ?>
            <li><i class="bi bi-<?= $ok ? 'check-circle-fill ok' : 'x-circle-fill text-danger' ?>"></i><?= e($name) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <div class="col-lg-8">
      <form method="post" class="card" action="<?= e(url('install')) ?>">
        <?= csrf_field() ?>
        <div class="card-body">
          <h2 class="h6 fw-semibold mb-3"><i class="bi bi-building me-2 text-primary"></i><?= te('install.org') ?></h2>
          <div class="row g-3 mb-4">
            <div class="col-md-6"><label class="form-label"><?= te('install.brand') ?></label><input class="form-control" name="brand" value="<?= $v('brand', 'Make4WebMail') ?>" required></div>
            <div class="col-md-6"><label class="form-label"><?= te('install.domain') ?></label><input class="form-control" name="domain" value="<?= $v('domain') ?>" placeholder="entreprise.fr" required></div>
            <div class="col-md-6"><label class="form-label"><?= te('install.language') ?></label>
              <select class="form-select" name="lang"><?php foreach (M4W\Core\I18n::LANGUAGES as $c => $l): ?><option value="<?= $c ?>" <?= ($o['lang'] ?? 'fr') === $c ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label"><?= te('install.timezone') ?></label><input class="form-control" name="timezone" value="<?= $v('timezone', 'Europe/Paris') ?>"></div>
          </div>
          <h2 class="h6 fw-semibold mb-3"><i class="bi bi-person-badge me-2 text-primary"></i><?= te('install.admin') ?></h2>
          <div class="row g-3 mb-4">
            <div class="col-md-6"><label class="form-label"><?= te('install.admin_name') ?></label><input class="form-control" name="admin_name" value="<?= $v('admin_name') ?>" required></div>
            <div class="col-md-6"><label class="form-label"><?= te('install.admin_email') ?></label><input type="email" class="form-control" name="admin_email" value="<?= $v('admin_email') ?>" placeholder="admin@entreprise.fr" required></div>
            <div class="col-md-6"><label class="form-label"><?= te('auth.password') ?></label><input type="password" class="form-control" name="admin_password" minlength="10" required autocomplete="new-password"></div>
            <div class="col-md-6"><label class="form-label"><?= te('pw.confirm') ?></label><input type="password" class="form-control" name="admin_password2" minlength="10" required autocomplete="new-password"></div>
          </div>
          <h2 class="h6 fw-semibold mb-3"><i class="bi bi-database me-2 text-primary"></i><?= te('install.database') ?></h2>
          <div class="row g-3">
            <div class="col-12">
              <div class="btn-group" role="group">
                <input type="radio" class="btn-check" name="db_driver" id="db_sqlite" value="sqlite" <?= ($o['db_driver'] ?? 'sqlite') === 'sqlite' ? 'checked' : '' ?>>
                <label class="btn btn-light" for="db_sqlite"><i class="bi bi-file-earmark-binary me-1"></i>SQLite (<?= te('install.zero_conf') ?>)</label>
                <input type="radio" class="btn-check" name="db_driver" id="db_mysql" value="mysql" <?= ($o['db_driver'] ?? '') === 'mysql' ? 'checked' : '' ?>>
                <label class="btn btn-light" for="db_mysql"><i class="bi bi-hdd-stack me-1"></i>MySQL / MariaDB</label>
              </div>
            </div>
            <div class="col-md-4 m4w-mysql"><label class="form-label">Host</label><input class="form-control" name="db_host" value="<?= $v('db_host', '127.0.0.1') ?>"></div>
            <div class="col-md-2 m4w-mysql"><label class="form-label">Port</label><input class="form-control" name="db_port" value="<?= $v('db_port', '3306') ?>"></div>
            <div class="col-md-6 m4w-mysql"><label class="form-label"><?= te('install.db_name') ?></label><input class="form-control" name="db_name" value="<?= $v('db_name', 'm4w') ?>"></div>
            <div class="col-md-6 m4w-mysql"><label class="form-label"><?= te('install.db_user') ?></label><input class="form-control" name="db_user" value="<?= $v('db_user') ?>"></div>
            <div class="col-md-6 m4w-mysql"><label class="form-label"><?= te('auth.password') ?></label><input type="password" class="form-control" name="db_pass"></div>
          </div>
        </div>
        <div class="card-footer d-flex justify-content-end"><button class="btn btn-primary btn-lg"><i class="bi bi-rocket-takeoff me-2"></i><?= te('install.go') ?></button></div>
      </form>
    </div>
  </div>
</div>
