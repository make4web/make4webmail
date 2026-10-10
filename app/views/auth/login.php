<?php use M4W\Core\Settings; $msg = (string) Settings::get('brand.login_message', ''); ?>
<div class="m4w-auth-card" data-aos="fade-up" data-aos-duration="450">
  <div class="d-lg-none mb-4"><?php include __DIR__ . '/../partials/brand.php'; ?></div>
  <h1><?= te('auth.welcome') ?></h1>
  <p class="sub"><?= te('auth.subtitle', ['brand' => Settings::get('brand.name')]) ?></p>
  <?php include __DIR__ . '/../partials/flash.php'; ?>
  <?php if ($msg !== ''): ?><div class="alert alert-info"><i class="bi bi-megaphone"></i><div><?= nl2br(e($msg)) ?></div></div><?php endif; ?>
  <form method="post" action="<?= e(url('login')) ?>" class="needs-validation" novalidate data-m4w-login>
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="email"><?= te('auth.email') ?></label>
      <input type="email" class="form-control form-control-lg" id="email" name="email" value="<?= e($email ?? '') ?>" autocomplete="username" required <?= ($email ?? '') === '' ? 'autofocus' : '' ?> placeholder="prenom.nom@entreprise.fr">
      <div class="invalid-feedback"><?= te('auth.email_required') ?></div>
    </div>
    <div class="mb-3">
      <div class="d-flex justify-content-between"><label class="form-label" for="password"><?= te('auth.password') ?></label></div>
      <div class="position-relative">
        <input type="password" class="form-control form-control-lg pe-5" id="password" name="password" autocomplete="current-password" required <?= ($email ?? '') !== '' ? 'autofocus' : '' ?>>
        <button type="button" class="btn btn-ghost btn-icon btn-sm m4w-pw-toggle" data-toggle-password="#password" aria-label="<?= te('auth.show_password') ?>"><i class="bi bi-eye"></i></button>
      </div>
      <div class="form-text d-none text-warning" data-capslock><i class="bi bi-capslock-fill"></i> <?= te('auth.capslock') ?></div>
    </div>
    <button class="btn btn-primary btn-lg w-100 mt-2" type="submit"><span class="label"><?= te('auth.login') ?></span><span class="spinner-border spinner-border-sm d-none ms-2"></span></button>
  </form>
  <p class="text-muted small mt-4 mb-0 text-center"><i class="bi bi-info-circle"></i> <?= te('auth.forgot_help') ?></p>
</div>
