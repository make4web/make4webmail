<?php
use M4W\Core\Settings;
use M4W\Service\Branding;
$brand = (string) Settings::get('brand.name', 'Make4Web Mail');
$bg = (string) Settings::get('brand.login_bg', '');
$logo = Branding::logoUrl();
?>
<!DOCTYPE html>
<html lang="<?= e(M4W\Core\I18n::language()) ?>">
<head>
<?php include __DIR__ . '/../partials/head.php'; ?>
</head>
<body>
<main class="m4w-auth">
  <section class="m4w-auth-hero<?= $bg !== '' ? ' has-image' : '' ?>"<?= $bg !== '' ? ' style="background-image:url(\'' . e(url('brand/' . $bg)) . '\')"' : '' ?>>
    <span class="orb o1"></span><span class="orb o2"></span>
    <div class="m4w-auth-logo" data-aos="fade-down">
      <?php if ($logo !== ''): ?><img src="<?= e(Branding::logoUrl(true)) ?>" alt="<?= e($brand) ?>"><?php else: ?><span class="m4w-brand-mark" style="background:rgba(255,255,255,.18);box-shadow:none"><i class="bi bi-envelope-paper-heart"></i></span><span><?= e($brand) ?></span><?php endif; ?>
    </div>
    <div>
      <h2 data-aos="fade-up"><?= e(Settings::get('brand.tagline', '') ?: t('auth.hero_title')) ?></h2>
      <p class="lead mb-4" data-aos="fade-up" data-aos-delay="80"><?= te('auth.hero_lead') ?></p>
      <div data-aos="fade-up" data-aos-delay="160">
        <div class="m4w-auth-feature"><i class="bi bi-shield-check"></i><div><b><?= te('auth.f1_title') ?></b><span><?= te('auth.f1_text') ?></span></div></div>
        <div class="m4w-auth-feature"><i class="bi bi-lightning-charge"></i><div><b><?= te('auth.f2_title') ?></b><span><?= te('auth.f2_text') ?></span></div></div>
        <div class="m4w-auth-feature"><i class="bi bi-funnel"></i><div><b><?= te('auth.f3_title') ?></b><span><?= te('auth.f3_text') ?></span></div></div>
      </div>
    </div>
    <div class="small opacity-75">© <?= date('Y') ?> <?= e(Settings::get('brand.company', '') ?: $brand) ?><?= Settings::get('brand.footer', '') !== '' ? ' · ' . e(Settings::get('brand.footer')) : '' ?></div>
  </section>
  <section class="m4w-auth-form">
    <?= $content ?>
    <div class="m4w-auth-footer">
      <?php foreach (M4W\Core\I18n::LANGUAGES as $code => $label): ?>
        <a href="<?= e(url('lang/' . $code, ['back' => $req->path])) ?>" class="<?= M4W\Core\I18n::language() === $code ? 'fw-semibold' : 'text-muted' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <span><i class="bi bi-lock-fill"></i> <?= te('auth.secure_conn') ?></span>
    </div>
  </section>
</main>
<?php include __DIR__ . '/../partials/scripts.php'; ?>
<script src="<?= e(asset('js/auth.js')) ?>"></script>
</body>
</html>
