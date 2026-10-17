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
// Where each sound is: the classic ones, and the person's own.
$notifyCfg['sounds'] = [];
foreach (M4W\Service\Notify::SOUNDS as $snd) {
    $notifyCfg['sounds'][$snd] = asset('sounds/' . $snd . '.wav');
}
foreach (M4W\Service\Notify::ownSounds((int) $currentUser['id']) as $snd) {
    $notifyCfg['sounds']['own:' . $snd['id']] = url('settings/sound/' . $snd['id']);
}
?>
<script type="application/json" id="m4w-notify-cfg"><?= json_encode($notifyCfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= e(asset('js/notify.js')) ?>"></script>
<?php endif; ?>
