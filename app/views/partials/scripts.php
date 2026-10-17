<?php
$jsI18n = M4W\Core\I18n::jsDictionary();
?>
<?php if (!empty($currentUser)): ?><script type="application/json" id="m4w-transfer"><?= json_encode(M4W\Service\TooBigForMail::services(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script><?php endif; ?>
<script type="application/json" id="m4w-i18n"><?= json_encode($jsI18n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('vendor/jquery/jquery.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/aos/aos.js')) ?>"></script>
<script src="<?= e(asset('js/core.js')) ?>"></script>
<script src="<?= e(asset('js/offline.js')) ?>"></script>
<?php if (!empty($currentUser)): $np = $currentUser['prefs'];
$notifyCfg = [
    'on' => (int) $np['notifications'], 'notify_new' => (int) $np['notify_new'], 'notify_reminder' => (int) $np['notify_reminder'],
    'reminder_minutes' => (int) $np['reminder_minutes'], 'period' => $np['notify_period'], 'hours' => $np['notify_hours'],
    'sound_new' => $np['sound_new'], 'sound_reminder' => $np['sound_reminder'], 'volume' => (int) $np['sound_volume'], 'vibrate' => $np['vibrate'],
];
foreach (M4W\Service\Notify::KINDS as $k) {
    $notifyCfg['custom_' . $k] = $np['sound_file_' . $k] !== '' ? url('settings/sound/' . $k) . '?v=' . substr(md5($np['sound_file_' . $k]), 0, 8) : '';
}
?>
<script type="application/json" id="m4w-notify-cfg"><?= json_encode($notifyCfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= e(asset('js/notify.js')) ?>"></script>
<?php endif; ?>
