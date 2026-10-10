<?php
$jsI18n = M4W\Core\I18n::jsDictionary();
?>
<script type="application/json" id="m4w-i18n"><?= json_encode($jsI18n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('vendor/jquery/jquery.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/aos/aos.js')) ?>"></script>
<script src="<?= e(asset('js/core.js')) ?>"></script>
