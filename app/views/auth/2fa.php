<div class="m4w-auth-card" data-aos="fade-up">
  <div class="mb-3" style="font-size:2.4rem;color:var(--m4w-primary)"><i class="bi bi-shield-lock"></i></div>
  <h1><?= te('auth.2fa_title') ?></h1>
  <p class="sub"><?= te('auth.2fa_sub') ?></p>
  <?php include __DIR__ . '/../partials/flash.php'; ?>
  <form method="post" action="<?= e(url('login/2fa')) ?>" data-m4w-otp>
    <?= csrf_field() ?>
    <input type="hidden" name="code" id="otp-code">
    <div class="m4w-code-inputs mb-3" data-otp-inputs>
      <?php for ($i = 0; $i < 6; $i++): ?><input class="form-control" inputmode="numeric" maxlength="1" autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>" aria-label="<?= te('auth.digit') ?> <?= $i + 1 ?>" <?= $i === 0 ? 'autofocus' : '' ?>><?php endfor; ?>
    </div>
    <button class="btn btn-primary btn-lg w-100" type="submit"><?= te('auth.verify') ?></button>
    <details class="mt-4">
      <summary class="small text-muted"><?= te('auth.use_recovery') ?></summary>
      <div class="input-group mt-2">
        <input class="form-control" name="recovery" placeholder="XXXXX-XXXXX" data-recovery>
        <button class="btn btn-light" type="submit"><?= te('auth.verify') ?></button>
      </div>
    </details>
  </form>
  <a href="<?= e(url('login')) ?>" class="d-inline-block mt-4 small"><i class="bi bi-arrow-left"></i> <?= te('auth.back_login') ?></a>
</div>
