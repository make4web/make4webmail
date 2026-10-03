<div class="m4w-auth-card" data-aos="fade-up">
  <div class="mb-3" style="font-size:2.4rem;color:var(--m4w-primary)"><i class="bi bi-key"></i></div>
  <h1><?= te('pw.change_title') ?></h1>
  <p class="sub"><?= te('pw.change_sub') ?></p>
  <?php include __DIR__ . '/../partials/flash.php'; ?>
  <form method="post" action="<?= e(url('password/change')) ?>">
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label"><?= te('pw.current') ?></label><input type="password" name="current" class="form-control form-control-lg" required autocomplete="current-password"></div>
    <div class="mb-3"><label class="form-label"><?= te('pw.new') ?></label><input type="password" name="password" class="form-control form-control-lg" required autocomplete="new-password" data-strength></div>
    <div class="progress mb-1" style="height:4px"><div class="progress-bar" data-strength-bar style="width:0"></div></div>
    <div class="form-text mb-3" data-strength-label><?= te('pw.policy', ['n' => M4W\Core\Settings::get('security.password_min', 10)]) ?></div>
    <div class="mb-4"><label class="form-label"><?= te('pw.confirm') ?></label><input type="password" name="password2" class="form-control form-control-lg" required autocomplete="new-password"></div>
    <button class="btn btn-primary btn-lg w-100"><?= te('pw.update') ?></button>
  </form>
  <form method="post" action="<?= e(url('logout')) ?>" class="mt-3 text-center"><?= csrf_field() ?><button class="btn btn-link btn-sm text-muted"><?= te('nav.logout') ?></button></form>
</div>
