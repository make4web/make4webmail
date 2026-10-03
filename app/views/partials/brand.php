<?php
use M4W\Service\Branding;
$logo = Branding::logoUrl();
$logoDark = (string) M4W\Core\Settings::get('brand.logo_dark', '');
$name = (string) M4W\Core\Settings::get('brand.name', 'Make4Web Mail');
?>
<a class="m4w-brand" href="<?= e(url('mail')) ?>" aria-label="<?= e($name) ?>">
<?php if ($logo !== ''): ?>
  <img class="m4w-logo-light<?= $logoDark !== '' ? ' has-dark' : '' ?>" src="<?= e($logo) ?>" alt="<?= e($name) ?>">
  <?php if ($logoDark !== ''): ?><img class="m4w-logo-dark" src="<?= e(Branding::logoUrl(true)) ?>" alt="<?= e($name) ?>"><?php endif; ?>
<?php else: ?>
  <span class="m4w-brand-mark"><i class="bi bi-envelope-paper-heart"></i></span>
  <span class="m4w-brand-name"><?= e($name) ?></span>
<?php endif; ?>
</a>
