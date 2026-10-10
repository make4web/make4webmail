<div class="m4w-page-header"><div><h1><?= te('folders.title') ?></h1><p><?= te('folders.desc') ?></p></div><a class="btn btn-primary" href="<?= e(url('mail')) ?>"><i class="bi bi-folder-plus me-1"></i><?= te('folders.manage_in_mail') ?></a></div>
<div class="m4w-table-card"><table class="table">
  <thead><tr><th><?= te('folder.name') ?></th><th class="text-end"><?= te('folders.messages') ?></th><th class="text-end"><?= te('folders.unread') ?></th></tr></thead>
  <tbody>
  <?php foreach ($folders as $f): ?>
    <tr><td><i class="bi bi-<?= e($f['icon']) ?> me-2 text-muted"></i><?php if ($f['color']): ?><span class="m4w-dot me-1" style="display:inline-block;width:10px;height:10px;border-radius:3px;background:<?= e($f['color']) ?>"></span><?php endif; ?><?= e($f['name']) ?><?= $f['role'] ? ' <span class="badge badge-soft-secondary ms-1">' . te('folders.system') . '</span>' : '' ?></td>
    <td class="text-end"><?= (int) $f['total'] ?></td><td class="text-end"><?= (int) $f['unread'] ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
