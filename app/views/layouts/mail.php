<?php use M4W\Core\Settings; ?>
<!DOCTYPE html>
<html lang="<?= e(M4W\Core\I18n::language()) ?>">
<head>
<?php include __DIR__ . '/../partials/head.php'; ?>
</head>
<body data-sidebar="<?= e(Settings::get('brand.sidebar', 'light')) ?>" data-density="<?= e($currentUser['prefs']['density'] ?? 'comfortable') ?>" class="m4w-mail-page">
<a class="visually-hidden-focusable" href="#m4w-list"><?= te('nav.skip') ?></a>
<div class="m4w-loading-bar" id="m4w-loading"></div>
<?php $topbarMode = 'mail'; include __DIR__ . '/../partials/topbar.php'; ?>
<?= $content ?>
<div class="m4w-toasts" id="m4w-toasts" aria-live="polite"></div>
<?php include __DIR__ . '/../partials/scripts.php'; ?>
<script src="<?= e(asset('vendor/qrcode/qrcode.js')) ?>"></script>
<script src="<?= e(asset('vendor/tinymce/tinymce.min.js')) ?>"></script>
<script src="<?= e(asset('js/editor.js')) ?>"></script>
<script src="<?= e(asset('js/mail.js')) ?>"></script>
<script src="<?= e(asset('js/compose.js')) ?>"></script>
</body>
</html>
