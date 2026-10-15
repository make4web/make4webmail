<!DOCTYPE html>
<html lang="<?= e(M4W\Core\I18n::language()) ?>" data-zoom="<?= (int) ($currentUser['prefs']['zoom'] ?? 100) ?>">
<head>
<?php include __DIR__ . '/../partials/head.php'; ?>
</head>
<body>
<?= $content ?>
<?php include __DIR__ . '/../partials/scripts.php'; ?>
</body>
</html>
