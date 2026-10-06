<?php
$items = [
    ['admin', 'speedometer2', 'admin.nav_dashboard', 'dashboard'],
    ['admin/users', 'people', 'admin.nav_users', 'users'],
    ['admin/domains', 'globe2', 'admin.nav_domains', 'domains'],
    ['admin/signatures', 'vector-pen', 'admin.nav_signatures', 'signatures'],
    ['admin/branding', 'palette', 'admin.nav_branding', 'branding'],
    ['admin/files', 'folder2-open', 'admin.nav_files', 'files'],
    ['admin/deleted', 'trash3', 'admin.nav_deleted', 'deleted'],
    ['admin/mail', 'hdd-network', 'admin.nav_mail', 'mail'],
    ['admin/security', 'shield-check', 'admin.nav_security', 'security'],
    ['admin/logs', 'journal-text', 'admin.nav_logs', 'logs'],
];
?>
<div class="m4w-nav-title"><?= te('nav.admin_console') ?></div>
<ul class="m4w-nav">
<?php foreach ($items as [$p, $icon, $label, $key]): ?>
  <li><a class="m4w-nav-item<?= ($section ?? '') === $key ? ' active' : '' ?>" href="<?= e(url($p)) ?>"><i class="bi bi-<?= $icon ?>"></i><span class="m4w-nav-label"><?= te($label) ?></span></a></li>
<?php endforeach; ?>
</ul>
<div class="m4w-nav-title"><?= te('nav.my_account') ?></div>
<ul class="m4w-nav"><li><a class="m4w-nav-item" href="<?= e(url('settings')) ?>"><i class="bi bi-person-circle"></i><span class="m4w-nav-label"><?= te('nav.settings') ?></span></a></li>
  <li><a class="m4w-nav-item" href="<?= e(url('help')) ?>#a-overview"><i class="bi bi-book"></i><span class="m4w-nav-label"><?= te('nav.admin_guide') ?></span></a></li></ul>
<div class="m4w-sidebar-footer px-3 mt-3">Make4Web Mail v<?= e(M4W_VERSION) ?></div>
