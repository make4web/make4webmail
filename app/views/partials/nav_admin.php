<?php
// The customer's administrator sees what is theirs; the super administrator, everything (see is_admin()).
$super = is_superadmin($currentUser ?? null);
// 5th value: hidden unless super administrator.
$items = [
    ['admin', 'speedometer2', 'admin.nav_dashboard', 'dashboard'],
    ['admin/users', 'people', 'admin.nav_users', 'users', !can_manage_users($currentUser ?? null)],
    ['admin/domains', 'globe2', 'admin.nav_domains', 'domains', true],
    ['admin/signatures', 'vector-pen', 'admin.nav_signatures', 'signatures'],
    ['admin/branding', 'palette', 'admin.nav_branding', 'branding', true],
    ['admin/files', 'folder2-open', 'admin.nav_files', 'files', true],
    ['admin/deleted', 'trash3', 'admin.nav_deleted', 'deleted'],
    ['admin/mail', 'hdd-network', 'admin.nav_mail', 'mail', true],
    ['admin/spam', 'funnel', 'admin.nav_spam', 'spam', true],
    ['admin/meetings', 'camera-video', 'admin.nav_meetings', 'meetings', true],
    ['admin/security', 'shield-check', 'admin.nav_security', 'security', true],
    ['admin/health', 'heart-pulse', 'admin.nav_health', 'health'],
    ['admin/logs', 'journal-text', 'admin.nav_logs', 'logs'],
    ['admin/update', 'cloud-arrow-down', 'admin.nav_update', 'update', true],
];
?>
<div class="m4w-nav-title"><?= te('nav.admin_console') ?></div>
<ul class="m4w-nav">
<?php foreach ($items as $it): [$p, $icon, $label, $key] = $it; if (!empty($it[4]) && !$super) { continue; } ?>
  <li><a class="m4w-nav-item<?= ($section ?? '') === $key ? ' active' : '' ?>" href="<?= e(url($p)) ?>"><i class="bi bi-<?= $icon ?>"></i><span class="m4w-nav-label"><?= te($label) ?></span></a></li>
<?php endforeach; ?>
</ul>
<div class="m4w-nav-title"><?= te('nav.my_account') ?></div>
<ul class="m4w-nav"><li><a class="m4w-nav-item" href="<?= e(url('settings')) ?>"><i class="bi bi-person-circle"></i><span class="m4w-nav-label"><?= te('nav.settings') ?></span></a></li>
  <li><a class="m4w-nav-item" href="<?= e(url('help')) ?>#a-overview"><i class="bi bi-book"></i><span class="m4w-nav-label"><?= te('nav.admin_guide') ?></span></a></li></ul>
<div class="m4w-sidebar-footer px-3 mt-3">Make4WebMail v<?= e(M4W_VERSION) ?></div>
