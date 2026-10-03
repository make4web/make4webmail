<?php $active = M4W\Service\Vacation::isActive($v); ?>
<div class="m4w-page-header">
  <div><h1><?= te('vac.title') ?></h1><p><?= te('vac.desc') ?></p></div>
  <?php if ($v['enabled']): ?><span class="badge <?= $active ? 'badge-soft-success' : 'badge-soft-warning' ?> fs-6 fw-semibold px-3 py-2"><i class="bi bi-<?= $active ? 'broadcast' : 'clock' ?> me-1"></i><?= te($active ? 'vac.status_on' : 'vac.status_scheduled') ?></span><?php endif; ?>
</div>
<form method="post" action="<?= e(url('settings/vacation')) ?>" data-vacation>
  <?= csrf_field() ?>
  <div class="m4w-setting-row pt-0">
    <div class="label"><?= te('vac.enable') ?><small><?= te('vac.enable_desc') ?></small></div>
    <div class="form-check form-switch fs-5"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="vac-en" <?= $v['enabled'] ? 'checked' : '' ?>></div>
  </div>
  <div data-vac-body>
    <div class="m4w-setting-row">
      <div class="label"><?= te('vac.period') ?><small><?= te('vac.period_desc') ?></small></div>
      <div class="row g-2" style="max-width:640px">
        <div class="col-sm-6"><label class="form-label small"><?= te('vac.start') ?></label><div class="input-group"><input type="date" class="form-control" name="start_date" value="<?= $v['start_at'] ? date('Y-m-d', $v['start_at']) : '' ?>"><input type="time" class="form-control" name="start_time" value="<?= $v['start_at'] ? date('H:i', $v['start_at']) : '' ?>" style="max-width:120px"></div></div>
        <div class="col-sm-6"><label class="form-label small"><?= te('vac.end') ?></label><div class="input-group"><input type="date" class="form-control" name="end_date" value="<?= $v['end_at'] ? date('Y-m-d', $v['end_at']) : '' ?>"><input type="time" class="form-control" name="end_time" value="<?= $v['end_at'] ? date('H:i', $v['end_at']) : '' ?>" style="max-width:120px"></div></div>
      </div>
    </div>
    <div class="m4w-setting-row">
      <div class="label"><?= te('vac.subject') ?><small><?= te('vac.subject_desc') ?></small></div>
      <div style="max-width:640px"><input class="form-control" name="subject" value="<?= e($v['subject']) ?>" placeholder="<?= te('vac.default_subject') ?>"></div>
    </div>
    <div class="m4w-setting-row">
      <div class="label"><?= te('vac.message') ?><small><?= te('vac.message_desc') ?></small>
        <div class="mt-3 d-flex flex-column gap-1">
          <button type="button" class="btn btn-light btn-sm text-start" data-vac-template="1"><i class="bi bi-magic me-1"></i><?= te('vac.tpl1') ?></button>
          <button type="button" class="btn btn-light btn-sm text-start" data-vac-template="2"><i class="bi bi-magic me-1"></i><?= te('vac.tpl2') ?></button>
        </div>
      </div>
      <div style="max-width:760px"><textarea name="body_html" data-editor placeholder="<?= te('vac.message_ph') ?>"><?= e($v['body_html']) ?></textarea></div>
    </div>
    <div class="m4w-setting-row">
      <div class="label"><?= te('vac.options') ?></div>
      <div>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="internal_only" value="1" id="vac-int" <?= $v['internal_only'] ? 'checked' : '' ?>><label class="form-check-label" for="vac-int"><?= te('vac.internal_only') ?></label></div>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="only_contacts" value="1" id="vac-ct" <?= $v['only_contacts'] ? 'checked' : '' ?>><label class="form-check-label" for="vac-ct"><?= te('vac.only_contacts') ?></label></div>
        <label class="form-label"><?= te('vac.interval') ?></label>
        <select class="form-select" name="interval_days" style="max-width:280px"><?php foreach ([0 => 'vac.every', 1 => 'vac.d1', 4 => 'vac.d4', 7 => 'vac.d7'] as $d => $l): ?><option value="<?= $d ?>" <?= $v['interval_days'] === $d ? 'selected' : '' ?>><?= te($l) ?></option><?php endforeach; ?></select>
        <div class="form-text"><?= te('vac.rfc') ?></div>
      </div>
    </div>
  </div>
  <div class="m4w-sticky-actions"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= te('common.save') ?></button></div>
</form>
<script type="application/json" id="vac-templates"><?= json_encode([
    '1' => t('vac.tpl1_body', ['name' => e($currentUser['name'])]),
    '2' => t('vac.tpl2_body', ['name' => e($currentUser['name'])]),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
