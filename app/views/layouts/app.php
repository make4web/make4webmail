<?php
use M4W\Core\Settings;
$path = $req->path;
$area = str_starts_with($path, '/admin') ? 'admin' : (str_starts_with($path, '/contacts') ? 'contacts' : (str_starts_with($path, '/files') ? 'files' : 'settings'));
?>
<!DOCTYPE html>
<html lang="<?= e(M4W\Core\I18n::language()) ?>">
<head>
<?php include __DIR__ . '/../partials/head.php'; ?>
</head>
<body data-sidebar="<?= e(Settings::get('brand.sidebar', 'light')) ?>" class="m4w-area-<?= $area ?>">
<div class="m4w-loading-bar" id="m4w-loading"></div>
<?php $topbarMode = 'page'; include __DIR__ . '/../partials/topbar.php'; ?>
<div class="m4w-page">
  <nav class="m4w-page-nav m4w-sidebar" aria-label="<?= te('nav.section') ?>">
    <?php if ($area !== 'files'): ?><a href="<?= e(url('mail')) ?>" class="m4w-compose-btn text-decoration-none"><i class="bi bi-arrow-left"></i><?= te('nav.back_mail') ?></a><?php endif; ?>
    <?php include __DIR__ . '/../partials/nav_' . $area . '.php'; ?>
  </nav>
  <main class="m4w-page-content" id="main">
    <div class="m4w-page-inner" data-aos="fade-up" data-aos-duration="350">
      <?php include __DIR__ . '/../partials/flash.php'; ?>
      <?= $content ?>
    </div>
  </main>
</div>
<div class="m4w-toasts" id="m4w-toasts" aria-live="polite"></div>
<?php include __DIR__ . '/../partials/scripts.php'; ?>
<script src="<?= e(asset('vendor/qrcode/qrcode.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('vendor/jodit/jodit.min.css')) ?>">
<script src="<?= e(asset('vendor/jodit/jodit.min.js')) ?>"></script>
<script src="<?= e(asset('js/editor.js')) ?>"></script>
<script src="<?= e(asset('js/pages.js')) ?>"></script>
<?php if ($area === 'files'): ?><script src="<?= e(asset('js/files-picker.js')) ?>"></script><script src="<?= e(asset('js/files.js')) ?>"></script><?php endif; ?>
</body>
</html>
