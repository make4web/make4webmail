<?php
declare(strict_types=1);

/*
 * Builds the classic notification sounds (public/assets/sounds/*.wav): short tones, so that no
 * sound of unknown origin or licence is shipped. Run again only to change them:
 *   php bin/sounds.php
 */

// Each sound: wave shape and notes of [frequency Hz, start s, length s].
$sounds = [
    'ding'    => ['sine', [[1318.5, 0, 0.9]]],
    'chime'   => ['sine', [[1046.5, 0, 0.5], [1318.5, 0.16, 0.5], [1568, 0.32, 0.8]]],
    'bell'    => ['triangle', [[880, 0, 1.4], [1760, 0, 0.9], [2637, 0, 0.5]]],
    'pop'     => ['sine', [[660, 0, 0.08], [990, 0.06, 0.12]]],
    'marimba' => ['sine', [[784, 0, 0.3], [988, 0.12, 0.3], [1175, 0.24, 0.45]]],
    'beep'    => ['square', [[1000, 0, 0.12], [1000, 0.2, 0.12]]],
];
$rate = 22050;
$dir = dirname(__DIR__) . '/public/assets/sounds';
foreach ($sounds as $name => [$shape, $notes]) {
    $len = 0.0;
    foreach ($notes as $n) {
        $len = max($len, $n[1] + $n[2]);
    }
    $buf = array_fill(0, (int) ceil(($len + 0.05) * $rate), 0.0);
    foreach ($notes as [$freq, $start, $dur]) {
        $from = (int) ($start * $rate);
        $count = (int) ($dur * $rate);
        for ($i = 0; $i < $count; $i++) {
            $t = $i / $rate;
            $phase = fmod($freq * $t, 1.0);
            $wave = match ($shape) {
                'square' => $phase < 0.5 ? 0.6 : -0.6,
                'triangle' => 4 * abs($phase - 0.5) - 1,
                default => sin(2 * M_PI * $phase),
            };
            // Quick rise (15 ms), then an exponential fade to silence: a struck sound.
            $env = $t < 0.015 ? $t / 0.015 : exp(-5.5 * ($t - 0.015) / $dur);
            $buf[$from + $i] += $wave * $env;
        }
    }
    $peak = max(0.0001, max(array_map('abs', $buf)));
    $pcm = '';
    foreach ($buf as $v) {
        $pcm .= pack('v', (int) round($v / $peak * 0.9 * 32767) & 0xFFFF);
    }
    $wav = 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVE'
        . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
        . 'data' . pack('V', strlen($pcm)) . $pcm;
    file_put_contents("$dir/$name.wav", $wav);
    echo "$name.wav ", strlen($wav), " bytes\n";
}
