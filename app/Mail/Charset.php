<?php
declare(strict_types=1);

namespace M4W\Mail;

final class Charset
{
    private const ALIASES = [
        'utf8' => 'UTF-8', 'utf-8' => 'UTF-8', 'us-ascii' => 'UTF-8', 'ascii' => 'UTF-8', 'ansi_x3.4-1968' => 'UTF-8',
        'latin1' => 'ISO-8859-1', 'latin-1' => 'ISO-8859-1', 'iso8859-1' => 'ISO-8859-1',
        'cp1252' => 'Windows-1252', 'windows-1252' => 'Windows-1252', 'win-1252' => 'Windows-1252',
        'iso-8859-8-i' => 'ISO-8859-8', 'ks_c_5601-1987' => 'CP949', 'ks_c_5601' => 'CP949',
        'gb2312' => 'GB18030', 'gbk' => 'GB18030', 'x-gbk' => 'GB18030', 'cp936' => 'GB18030',
        'shift-jis' => 'SJIS', 'shift_jis' => 'SJIS', 'x-sjis' => 'SJIS', 'unicode-1-1-utf-7' => 'UTF-7',
        'x-mac-roman' => 'MACINTOSH', 'macintosh' => 'MACINTOSH',
    ];

    public static function normalize(string $charset): string
    {
        $c = strtolower(trim($charset, " \t\"'"));
        if ($c === '') {
            return 'UTF-8';
        }
        return self::ALIASES[$c] ?? strtoupper($c);
    }

    public static function toUtf8(string $s, string $charset = ''): string
    {
        if ($s === '') {
            return '';
        }
        $cs = self::normalize($charset);
        if ($cs === 'UTF-8') {
            if (mb_check_encoding($s, 'UTF-8')) {
                return $s;
            }
            $cs = 'Windows-1252';
        }
        if ($cs === 'ISO-8859-1') {
            // Most "latin1" mail is in fact cp1252 (curly quotes, euro sign).
            $cs = 'Windows-1252';
        }
        $out = null;
        static $mbEncodings = null;
        $mbEncodings ??= array_map('strtoupper', mb_list_encodings());
        if (in_array(strtoupper($cs), $mbEncodings, true)) {
            try {
                $out = mb_convert_encoding($s, 'UTF-8', $cs);
            } catch (\ValueError) {
                $out = null;
            }
        }
        if ($out === null || $out === false) {
            $r = @iconv($cs, 'UTF-8//IGNORE', $s);
            $out = $r === false ? null : $r;
        }
        if ($out === null || !mb_check_encoding($out, 'UTF-8')) {
            $out = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        return $out;
    }

    /** Decode RFC 2047 encoded-words in a header value. */
    public static function decodeHeader(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return self::toUtf8($value);
        }
        // Remove whitespace between adjacent encoded words.
        $value = preg_replace('/(=\?[^?]+\?[bqBQ]\?[^?]*\?=)\s+(?==\?[^?]+\?[bqBQ]\?[^?]*\?=)/', '$1', $value) ?? $value;
        $parts = preg_split('/(=\?[^?]+\?[bqBQ]\?[^?]*\?=)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        $pendingCharset = null;
        $pendingBytes = '';
        $flush = static function () use (&$out, &$pendingCharset, &$pendingBytes) {
            if ($pendingCharset !== null) {
                $out .= self::toUtf8($pendingBytes, $pendingCharset);
                $pendingCharset = null;
                $pendingBytes = '';
            }
        };
        foreach ($parts as $part) {
            if (preg_match('/^=\?([^?*]+)(?:\*[^?]*)?\?([bqBQ])\?([^?]*)\?=$/', $part, $m)) {
                $charset = $m[1];
                $bytes = strtoupper($m[2]) === 'B'
                    ? (string) base64_decode($m[3])
                    : quoted_printable_decode(str_replace('_', ' ', $m[3]));
                // Concatenate adjacent words of the same charset before converting (multi-byte splits).
                if ($pendingCharset !== null && strcasecmp($pendingCharset, $charset) !== 0) {
                    $flush();
                }
                $pendingCharset = $charset;
                $pendingBytes .= $bytes;
            } else {
                $flush();
                $out .= self::toUtf8($part);
            }
        }
        $flush();
        return $out;
    }

    /** Encode a header value as RFC 2047 if needed (UTF-8, base64, word length <= 75). */
    public static function encodeHeader(string $value, int $firstLineOffset = 0): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        if (!preg_match('/[^\x20-\x7e]/', $value)) {
            return $value;
        }
        $words = [];
        $max = 45; // bytes of raw text per encoded word (base64 -> 60 chars + 12 overhead)
        $current = '';
        foreach (mb_str_split($value) as $ch) {
            if (strlen($current . $ch) > $max) {
                $words[] = '=?UTF-8?B?' . base64_encode($current) . '?=';
                $current = '';
            }
            $current .= $ch;
        }
        if ($current !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($current) . '?=';
        }
        return implode("\r\n ", $words);
    }
}
