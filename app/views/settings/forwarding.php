<?php $policyExt = (int) M4W\Core\Settings::get('security.allow_external_forward', 1); $wl = (string) M4W\Core\Settings::get('security.forward_whitelist', ''); ?>
<div class="m4w-page-header"><div><h1><?= te('fwd.title') ?></h1><p><?= te('fwd.desc') ?></p></div></div>
<form method="post" action="<?= e(url('settings/forwarding')) ?>">
  <?= csrf_field() ?>
  <div class="m4w-setting-row pt-0">
    <div class="label"><?= te('fwd.enable') ?><small><?= te('fwd.enable_desc') ?></small></div>
    <div class="form-check form-switch fs-5"><input class="form-check-input" type="checkbox" name="enabled" value="1" <?= $f['enabled'] ? 'checked' : '' ?>></div>
  </div>
  <div class="m4w-setting-row">
    <div class="label"><?= te('fwd.addresses') ?><small><?= te('fwd.addresses_desc') ?></small></div>
    <div style="max-width:560px">
      <textarea class="form-control" name="addresses" rows="3" placeholder="prenom.nom@exemple.com"><?= e(implode("\n", $f['addresses'])) ?></textarea>
      <?php if (!$policyExt): ?><div class="form-text text-warning"><i class="bi bi-shield-exclamation"></i> <?= te('fwd.policy_internal') ?></div>
      <?php elseif ($wl !== ''): ?><div class="form-text"><i class="bi bi-shield-check"></i> <?= te('fwd.policy_wl', ['list' => $wl]) ?></div><?php endif; ?>
    </div>
  </div>
  <div class="m4w-setting-row">
    <div class="label"><?= te('fwd.copy') ?></div>
    <div>
      <div class="form-check mb-2"><input class="form-check-input" type="radio" name="keep_copy" value="1" id="kc1" <?= $f['keep_copy'] ? 'checked' : '' ?>><label class="form-check-label" for="kc1"><?= te('fwd.keep') ?></label></div>
      <div class="form-check"><input class="form-check-input" type="radio" name="keep_copy" value="0" id="kc0" <?= !$f['keep_copy'] ? 'checked' : '' ?>><label class="form-check-label" for="kc0"><?= te('fwd.nokeep') ?></label></div>
    </div>
  </div>
  <div class="alert alert-info mt-4"><i class="bi bi-info-circle"></i><div><?= te('fwd.tip') ?> <a href="<?= e(url('settings/rules')) ?>"><?= te('nav.rules') ?></a>.</div></div>
  <div class="m4w-sticky-actions"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= te('common.save') ?></button></div>
</form>
