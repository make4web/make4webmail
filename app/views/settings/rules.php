<div class="m4w-page-header">
  <div><h1><?= te('rules.title') ?></h1><p><?= te('rules.desc') ?></p></div>
  <button class="btn btn-primary" data-rule-new><i class="bi bi-plus-lg me-1"></i><?= te('rules.new') ?></button>
</div>
<div id="rules-list" data-rules="<?= e(json_encode($rules, JSON_UNESCAPED_UNICODE)) ?>" data-folders="<?= e(json_encode(array_map(static fn($f) => ['id' => $f['id'], 'name' => $f['name'], 'role' => $f['role']], $folders), JSON_UNESCAPED_UNICODE)) ?>" data-prefill="<?= e(json_encode(['from' => (string) ($_GET['from'] ?? ''), 'subject' => (string) ($_GET['subject'] ?? '')], JSON_UNESCAPED_UNICODE)) ?>"></div>
<div class="alert alert-info mt-4"><i class="bi bi-lightbulb"></i><div><?= te('rules.tip') ?></div></div>

<div class="modal fade" id="rule-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <form class="modal-content" id="rule-form">
      <div class="modal-header"><h5 class="modal-title" data-title><?= te('rules.new') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3 mb-4">
          <div class="col-md-8"><label class="form-label"><?= te('rules.name') ?></label><input class="form-control" name="name" required maxlength="190" placeholder="<?= te('rules.name_ph') ?>"></div>
          <div class="col-md-4 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="enabled" id="r-en" checked><label class="form-check-label" for="r-en"><?= te('rules.enabled') ?></label></div></div>
        </div>
        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
          <span class="fw-semibold"><?= te('rules.when') ?></span>
          <select class="form-select form-select-sm w-auto" name="match_type"><option value="all"><?= te('rules.match_all') ?></option><option value="any"><?= te('rules.match_any') ?></option></select>
          <span class="text-muted small"><?= te('rules.conditions_hint') ?></span>
        </div>
        <div data-conds></div>
        <button type="button" class="btn btn-soft btn-sm mb-4" data-add-cond><i class="bi bi-plus-lg me-1"></i><?= te('rules.add_cond') ?></button>
        <div class="fw-semibold mb-2"><?= te('rules.then') ?></div>
        <div data-acts></div>
        <button type="button" class="btn btn-soft btn-sm mb-4" data-add-act><i class="bi bi-plus-lg me-1"></i><?= te('rules.add_act') ?></button>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="stop_processing" id="r-stop"><label class="form-check-label" for="r-stop"><?= te('rules.stop') ?></label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="apply_now" id="r-now"><label class="form-check-label" for="r-now"><?= te('rules.apply_now') ?></label></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= te('common.cancel') ?></button><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= te('common.save') ?></button></div>
    </form>
  </div>
</div>
