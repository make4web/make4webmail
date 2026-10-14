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
    private static function connect()
    {
        $addr = self::address();
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

    /** Daemon answers (administration status, installer check). */
    public static function ping(): bool
    {
        $s = self::connect();
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
