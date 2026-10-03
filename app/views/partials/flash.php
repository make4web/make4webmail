<?php if (!empty($flash)): ?>
<div class="m4w-flash" aria-live="polite">
<?php foreach ($flash as $f): $icon = ['success' => 'check-circle-fill', 'danger' => 'exclamation-octagon-fill', 'warning' => 'exclamation-triangle-fill', 'info' => 'info-circle-fill'][$f['type']] ?? 'info-circle-fill'; ?>
  <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
    <i class="bi bi-<?= $icon ?>"></i>
    <div><?= e($f['message']) ?></div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= te('common.close') ?>"></button>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
