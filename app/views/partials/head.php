<?php
/** @var string|null $title */
use M4W\Core\Settings;
$brandName = (string) Settings::get('brand.name', 'Make4WebMail');
$favicon = (string) Settings::get('brand.favicon', '');
$themePref = $currentUser['prefs']['theme'] ?? 'auto';
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= e(Settings::get('brand.primary', '#2563eb')) ?>">
<meta name="csrf-token" content="<?= e(M4W\Core\Csrf::token()) ?>">
<meta name="base-url" content="<?= e(url('/')) ?>">
<meta name="m4w-pw-policy" content="<?= max(8, (int) Settings::get('security.password_min', 10)) ?>,<?= (int) Settings::get('security.password_classes', 3) ?>">
<?php if (!empty($currentUser) && (int) Settings::get('security.session_keepalive', 1) === 1): ?>
<meta name="m4w-keepalive" content="<?= (int) min(300, max(60, (int) Settings::get('security.session_idle', 30) * 60 / 3)) ?>">
<?php endif; ?>
<title><?= e(isset($title) && $title !== '' ? $title . ' · ' . $brandName : $brandName) ?></title>
<link rel="icon" href="<?= $favicon !== '' ? e(url('brand/' . $favicon)) : e(asset('img/icon.svg')) ?>">
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<link rel="apple-touch-icon" href="<?= e(M4W\Service\AppIcon::url('apple')) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= e(mb_substr($brandName, 0, 12)) ?>">
<script src="<?= e(asset('js/theme-init.js')) ?>" data-theme-pref="<?= e($themePref) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('vendor/inter/inter.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/aos/aos.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/m4w.css')) ?>">
<link rel="stylesheet" href="<?= e(url('theme.css')) ?>?v=<?= e(substr(md5(json_encode([Settings::get('brand.primary'), Settings::get('brand.accent'), Settings::get('brand.radius'), Settings::get('brand.sidebar'), Settings::get('brand.sidebar_color'), Settings::get('brand.sidebar_bg'), Settings::get('brand.sidebar_bg_fit'), Settings::get('brand.sidebar_main'), Settings::get('brand.font'), md5((string) Settings::get('brand.custom_css'))])), 0, 8)) ?>">
