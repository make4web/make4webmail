<?php
/** @var string|null $title */
use M4W\Core\Settings;
$brandName = (string) Settings::get('brand.name', 'Make4Web Mail');
$favicon = (string) Settings::get('brand.favicon', '');
$themePref = $currentUser['prefs']['theme'] ?? 'auto';
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= e(Settings::get('brand.primary', '#2563eb')) ?>">
<meta name="csrf-token" content="<?= e(M4W\Core\Csrf::token()) ?>">
<meta name="base-url" content="<?= e(url('/')) ?>">
<title><?= e(isset($title) && $title !== '' ? $title . ' · ' . $brandName : $brandName) ?></title>
<link rel="icon" href="<?= $favicon !== '' ? e(url('brand/' . $favicon)) : e(asset('img/icon.svg')) ?>">
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<script src="<?= e(asset('js/theme-init.js')) ?>" data-theme-pref="<?= e($themePref) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('vendor/inter/inter.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/aos/aos.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/m4w.css')) ?>">
<link rel="stylesheet" href="<?= e(url('theme.css')) ?>?v=<?= e(substr(md5(json_encode([Settings::get('brand.primary'), Settings::get('brand.accent'), Settings::get('brand.radius'), Settings::get('brand.sidebar'), Settings::get('brand.font'), md5((string) Settings::get('brand.custom_css'))])), 0, 8)) ?>">
