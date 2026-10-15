<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Settings;

/**
 * ClamAV through its daemon (clamd), INSTREAM protocol: the content is streamed over the
 * socket, nothing is written to disk for the scan. Debian: package clamav-daemon, socket
 * /var/run/clamav/clamd.ctl.
 */
final class Antivirus
{
    private const CHUNK = 65536;

    public static function enabled(): bool
    {
        return (int) Settings::get('security.antivirus', 1) === 1;
    }

    public static function address(): string
    {
        return (string) Settings::get('security.clamd', 'unix:///var/run/clamav/clamd.ctl');
    }

    /** @return resource|null */
    private static function connect(?string $addr = null)
    {
        $addr ??= self::address();
        if (!preg_match('#^(unix://.+|tcp://[a-z0-9.\-\[\]:]+:\d+)$#i', $addr)) {
            return null;
        }
        $s = @stream_socket_client($addr, $errno, $err, 3);
        if (!$s) {
            return null;
        }
        stream_set_timeout($s, 60);
        return $s;
    }

    /** Usual places of the daemon: Debian / Ubuntu, RHEL / Fedora, Arch, Alpine, network. */
    public const CANDIDATES = [
        'unix:///var/run/clamav/clamd.ctl', 'unix:///run/clamav/clamd.ctl', 'unix:///run/clamd.scan/clamd.sock', 'unix:///var/run/clamd.scan/clamd.sock',
        'unix:///run/clamav/clamd.sock', 'unix:///var/run/clamav/clamd.sock', 'unix:///var/lib/clamav/clamd.sock', 'unix:///tmp/clamd.socket', 'tcp://127.0.0.1:3310',
    ];

    /**
     * Why the daemon cannot be reached, in words an administrator can act on, and the
     * addresses where one does answer.
     * @return array{ok:bool,reason:string,found:string[],signatures:bool|null,user:string}
     */
    public static function diagnose(): array
    {
        $addr = self::address();
        $user = function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? '') : (string) get_current_user();
        $out = ['ok' => self::ping(), 'reason' => '', 'found' => [], 'signatures' => null, 'user' => $user];
        $dbDir = '/var/lib/clamav';
        if (@is_dir($dbDir)) {
            $out['signatures'] = (bool) (glob($dbDir . '/{main,daily}.{cvd,cld}', GLOB_BRACE) ?: []);
        }
        if ($out['ok']) {
            return $out;
        }
        if (str_starts_with($addr, 'unix://')) {
            $path = substr($addr, 7);
            $basedir = (string) ini_get('open_basedir');
            if (!@file_exists($path)) {
                $out['reason'] = $basedir !== '' ? t('av.why_basedir', ['path' => $path, 'dirs' => $basedir]) : t('av.why_missing', ['path' => $path]);
            } elseif (!@is_writable($path)) {
                $out['reason'] = t('av.why_denied', ['path' => $path, 'user' => $user ?: '?']);
            } else {
                $out['reason'] = t('av.why_refused', ['path' => $path]);
            }
        } else {
            $out['reason'] = t('av.why_tcp', ['addr' => $addr]);
        }
        if ($out['signatures'] === false) {
            $out['reason'] .= ' ' . t('av.why_nosig', ['dir' => $dbDir]);
        }
        foreach (self::CANDIDATES as $c) {
            if ($c !== $addr && self::ping($c)) {
                $out['found'][] = $c;
            }
        }
        return $out;
    }

    /** Daemon answers (administration status, installer check). */
    public static function ping(?string $addr = null): bool
    {
        $s = self::connect($addr);
        if (!$s) {
            return false;
        }
        fwrite($s, "zPING\0");
        $r = (string) stream_get_contents($s, 64);
        fclose($s);
        return str_starts_with(trim($r, "\0\r\n "), 'PONG');
    }

    public static function version(): string
    {
        $s = self::connect();
        if (!$s) {
            return '';
        }
        fwrite($s, "zVERSION\0");
        $r = trim((string) stream_get_contents($s, 256), "\0\r\n ");
        fclose($s);
        return $r;
    }

    /**
     * @return array{status:string,signature:string} status: clean | infected | error (daemon down,
     *         size limit, unreadable) | disabled
     */
    public static function scanFile(string $path): array
    {
        if (!self::enabled()) {
            return ['status' => 'disabled', 'signature' => ''];
        }
        $s = self::connect();
        $in = @fopen($path, 'rb');
        if (!$s || !$in) {
            return ['status' => 'error', 'signature' => 'clamd unreachable'];
        }
        fwrite($s, "zINSTREAM\0");
        while (!feof($in)) {
            $chunk = (string) fread($in, self::CHUNK);
            if ($chunk !== '' && @fwrite($s, pack('N', strlen($chunk)) . $chunk) === false) {
                break;
            }
        }
        fclose($in);
        @fwrite($s, pack('N', 0));
        return self::answer($s);
    }

    /** @param resource $s */
    private static function answer($s): array
    {
        $r = trim((string) stream_get_contents($s, 4096), "\0\r\n ");
        fclose($s);
        if (preg_match('/:\s*OK$/', $r)) {
            return ['status' => 'clean', 'signature' => ''];
        }
        if (preg_match('/:\s*(.+?)\s+FOUND$/', $r, $m)) {
            return ['status' => 'infected', 'signature' => mb_substr($m[1], 0, 120)];
        }
        return ['status' => 'error', 'signature' => mb_substr($r !== '' ? $r : 'no answer', 0, 120)];
    }

    public static function scan(string $bytes): array
    {
        if (!self::enabled()) {
            return ['status' => 'disabled', 'signature' => ''];
        }
        $s = self::connect();
        if (!$s) {
            return ['status' => 'error', 'signature' => 'clamd unreachable'];
        }
        fwrite($s, "zINSTREAM\0");
        $len = strlen($bytes);
        for ($off = 0; $off < $len; $off += self::CHUNK) {
            $chunk = substr($bytes, $off, self::CHUNK);
            if (@fwrite($s, pack('N', strlen($chunk)) . $chunk) === false) {
                break; // the daemon closed the stream (size limit): its answer says why
            }
        }
        @fwrite($s, pack('N', 0));
        // "stream: OK" | "stream: Eicar-Signature FOUND" | "INSTREAM size limit exceeded. ERROR"
        return self::answer($s);
    }
}
