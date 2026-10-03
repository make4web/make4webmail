<div class="d-flex align-items-center justify-content-center" style="min-height:100vh">
  <div class="text-center p-4" style="max-width:480px" data-aos="zoom-in">
    <div class="m4w-empty-icon mx-auto mb-4" style="width:96px;height:96px;border-radius:50%;background:var(--m4w-primary-soft);color:var(--m4w-primary);display:flex;align-items:center;justify-content:center;font-size:2.6rem">
      <i class="bi bi-<?= $status === 404 ? 'signpost-split' : ($status === 403 ? 'shield-exclamation' : 'cone-striped') ?>"></i>
    </div>
    <div class="display-5 fw-bold mb-2" style="letter-spacing:-.04em"><?= (int) $status ?></div>
    <p class="text-muted mb-4"><?= e($message) ?></p>
    <a href="<?= e(url('/')) ?>" class="btn btn-primary"><i class="bi bi-house me-1"></i><?= te('error.home') ?></a>
  </div>
</div>
