<?php
$items = [
    ['settings', 'person-circle', 'nav.profile', 'general'],
    ['settings/rules', 'funnel', 'nav.rules', 'rules'],
    ['settings/vacation', 'airplane', 'nav.vacation', 'vacation'],
    ['settings/forwarding', 'forward', 'nav.forwarding', 'forwarding'],
    ['settings/signature', 'pen', 'nav.signature', 'signature'],
    ['settings/folders', 'folder2-open', 'nav.folders', 'folders'],
    ['settings/security', 'shield-lock', 'nav.security', 'security'],
];
if ((int) M4W\Core\Settings::get('features.fetch_accounts', 1)) {
    $items[] = ['settings/accounts', 'cloud-download', 'nav.accounts', 'accounts'];
}
?>
<div class="m4w-nav-title"><?= te('nav.settings') ?></div>
<ul class="m4w-nav">
<?php foreach ($items as [$p, $icon, $label, $key]): ?>
  <li><a class="m4w-nav-item<?= ($section ?? '') === $key ? ' active' : '' ?>" href="<?= e(url($p)) ?>"><i class="bi bi-<?= $icon ?>"></i><span class="m4w-nav-label"><?= te($label) ?></span></a></li>
<?php endforeach; ?>
</ul>
<?php if (($currentUser['role'] ?? '') === 'admin'): ?>
<div class="m4w-nav-title"><?= te('nav.admin') ?></div>
<ul class="m4w-nav"><li><a class="m4w-nav-item" href="<?= e(url('admin')) ?>"><i class="bi bi-speedometer2"></i><span class="m4w-nav-label"><?= te('nav.admin_console') ?></span></a></li></ul>
<?php endif; ?>
