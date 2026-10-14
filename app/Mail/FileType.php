<?php
declare(strict_types=1);

namespace M4W\Mail;

/**
 * What a file really is, from its bytes (magic signatures), whatever its name or the
 * type the sender declared — and how risky it is to open.
 *
 *   block  programs and scripts (Windows/Linux/macOS executables, installers, shortcuts,
 *          HTA, scripts, disk images…), even renamed or without extension
 *   warn   content that can carry active code or does not match its name: Office files
 *          with macros, archives holding programs or protected by a password, HTML/SVG,
 *          a name that lies about the content, an unknown binary without extension
 */
final class FileType
{
    /** Extensions Windows (or a desktop) would run or open as a program. */
    private const EXEC_EXT = ['exe', 'com', 'scr', 'pif', 'cpl', 'msi', 'msp', 'mst', 'dll', 'sys', 'drv', 'ocx', 'bat', 'cmd', 'ps1', 'psm1', 'vbs', 'vbe',
        'js', 'jse', 'wsf', 'wsh', 'hta', 'lnk', 'url', 'scf', 'reg', 'inf', 'jar', 'app', 'command', 'sh', 'run', 'bin', 'elf', 'appimage', 'deb', 'rpm',
        'apk', 'dmg', 'iso', 'img', 'vhd', 'vhdx', 'msix', 'appx', 'appxbundle', 'xll', 'gadget', 'application', 'chm', 'library-ms', 'settingcontent-ms', 'ws', 'mht', 'xbap'];
    private const MACRO_EXT = ['docm', 'dotm', 'xlsm', 'xltm', 'xlam', 'pptm', 'potm', 'ppam', 'ppsm', 'sldm'];

    /** @return array{type:string,label:string,risk:string,why:string} */
    public static function classify(string $name, string $bytes): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $real = self::sniff($bytes);
        $out = ['type' => $real['type'], 'label' => t($real['label']), 'risk' => '', 'why' => ''];
        // Double extension like "facture.pdf.exe", or spaces/RTL characters hiding the real one.
        $hidden = preg_match('/\.(pdf|docx?|xlsx?|jpe?g|png|txt|zip)\s*\.[a-z0-9]{2,5}$/i', $name) || preg_match('/[\x{202E}\x{202D}\x{200E}\x{200F}]/u', $name);
        if ($real['exec'] || in_array($ext, self::EXEC_EXT, true)) {
            $out['risk'] = 'block';
            $out['why'] = $real['exec'] ? ($ext !== '' && !in_array($ext, self::EXEC_EXT, true) ? 'disguised' : 'program') : 'program';
            return $out;
        }
        if ($hidden) {
            return ['risk' => 'warn', 'why' => 'double_ext'] + $out;
        }
        if (in_array($ext, self::MACRO_EXT, true) || $real['type'] === 'ole' && self::oleHasMacros($bytes)) {
            return ['risk' => 'warn', 'why' => 'macros'] + $out;
        }
        if ($real['type'] === 'zip') {
            $z = self::zipRisk($bytes, $ext);
            if ($z !== '') {
                return ['risk' => $z === 'exec_inside' ? 'block' : 'warn', 'why' => $z] + $out;
            }
        }
        if (in_array($real['type'], ['html', 'svg'], true) || in_array($ext, ['html', 'htm', 'svg', 'xhtml', 'shtml'], true)) {
            return ['risk' => 'warn', 'why' => 'active_content'] + $out;
        }
        if (in_array($real['type'], ['rar', '7z'], true)) {
            return ['risk' => 'warn', 'why' => 'archive'] + $out;
        }
        if ($ext === '' && $real['type'] === 'binary') {
            return ['risk' => 'warn', 'why' => 'no_ext'] + $out;
        }
        if ($ext !== '' && !self::extMatches($ext, $real['type'])) {
            return ['risk' => 'warn', 'why' => 'mismatch'] + $out;
        }
        return $out;
    }

    /** @return array{type:string,label:string,exec:bool} */
    public static function sniff(string $b): array
    {
        $h = substr($b, 0, 16);
        $t = static fn(string $type, string $label, bool $exec = false) => ['type' => $type, 'label' => $label, 'exec' => $exec];
        return match (true) {
            str_starts_with($h, 'MZ') => $t('pe', 'ft.pe', true),
            str_starts_with($h, "\x7FELF") => $t('elf', 'ft.elf', true),
            in_array(substr($h, 0, 4), ["\xFE\xED\xFA\xCE", "\xFE\xED\xFA\xCF", "\xCE\xFA\xED\xFE", "\xCF\xFA\xED\xFE", "\xCA\xFE\xBA\xBE"], true) => $t('macho', 'ft.macho', true),
            str_starts_with($h, '#!') => $t('script', 'ft.script', true),
            str_starts_with($h, "\x4C\x00\x00\x00\x01\x14\x02\x00") => $t('lnk', 'ft.lnk', true),
            str_starts_with($h, 'MSCF') => $t('cab', 'ft.cab', true),
            strlen($b) > 0x8006 && substr($b, 0x8001, 5) === 'CD001' => $t('iso', 'ft.iso', true),
            str_starts_with($h, 'koly') || str_starts_with($h, "\x78\x01\x73\x0D\x62\x62\x60") => $t('dmg', 'ft.dmg', true),
            str_starts_with($h, '%PDF') => $t('pdf', 'ft.pdf'),
            str_starts_with($h, "\x89PNG") => $t('png', 'ft.png'),
            str_starts_with($h, "\xFF\xD8\xFF") => $t('jpeg', 'ft.jpeg'),
            str_starts_with($h, 'GIF8') => $t('gif', 'ft.gif'),
            str_starts_with($h, 'RIFF') && substr($h, 8, 4) === 'WEBP' => $t('webp', 'ft.webp'),
            str_starts_with($h, 'BM') => $t('bmp', 'ft.bmp'),
            str_starts_with($h, "PK\x03\x04") || str_starts_with($h, "PK\x05\x06") => $t('zip', self::zipLabel($b)),
            str_starts_with($h, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") => $t('ole', 'ft.ole'),
            str_starts_with($h, 'Rar!') => $t('rar', 'ft.rar'),
            str_starts_with($h, "7z\xBC\xAF\x27\x1C") => $t('7z', 'ft.7z'),
            str_starts_with($h, "\x1F\x8B") => $t('gzip', 'ft.gzip'),
            str_starts_with($h, '{\\rtf') => $t('rtf', 'ft.rtf'),
            str_starts_with($h, 'BEGIN:VCALENDAR') => $t('ics', 'ft.ics'),
            str_starts_with($h, 'BEGIN:VCARD') => $t('vcf', 'ft.vcf'),
            (bool) preg_match('/^\s*(<\?xml[^>]*>\s*)?<svg\b/i', substr($b, 0, 512)) => $t('svg', 'ft.svg'),
            (bool) preg_match('/^\s*(<!doctype html|<html|<head|<body|<script)/i', substr($b, 0, 512)) => $t('html', 'ft.html'),
            self::looksText(substr($b, 0, 4096)) => $t('text', 'ft.text'),
            default => $t('binary', 'ft.binary'),
        };
    }

    private static function looksText(string $s): bool
    {
        return $s === '' || (!str_contains($s, "\0") && (mb_check_encoding($s, 'UTF-8') || preg_match('/^[\x09\x0A\x0D\x20-\x7E\x80-\xFF]*$/', $s)));
    }

    private static function zipLabel(string $b): string
    {
        return match (true) {
            str_contains(substr($b, 0, 2000), 'word/') => 'ft.zip_word',
            str_contains(substr($b, 0, 2000), 'xl/') => 'ft.zip_excel',
            str_contains(substr($b, 0, 2000), 'ppt/') => 'ft.zip_ppt',
            str_contains(substr($b, 0, 200), 'mimetypeapplication/vnd.oasis') => 'ft.zip_odf',
            default => 'ft.zip',
        };
    }

    /** Programs inside an archive, encrypted entries, or an Office file with macros. */
    private static function zipRisk(string $bytes, string $ext): string
    {
        if (!class_exists(\ZipArchive::class)) {
            return '';
        }
        $tmp = (string) tempnam(sys_get_temp_dir(), 'ft');
        file_put_contents($tmp, $bytes);
        $z = new \ZipArchive();
        $risk = '';
        if ($z->open($tmp, \ZipArchive::RDONLY) === true) {
            for ($i = 0; $i < min($z->numFiles, 5000); $i++) {
                $st = $z->statIndex($i);
                $n = strtolower((string) ($st['name'] ?? ''));
                if (($st['encryption_method'] ?? 0) !== 0) {
                    $risk = 'encrypted'; // the antivirus cannot look inside
                }
                if (str_ends_with($n, 'vbaproject.bin')) {
                    $risk = $risk ?: 'macros';
                }
                if (in_array(pathinfo($n, PATHINFO_EXTENSION), self::EXEC_EXT, true) && !in_array($ext, ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'jar', 'apk', 'epub'], true)) {
                    $risk = 'exec_inside';
                    break;
                }
            }
            $z->close();
        }
        @unlink($tmp);
        return $risk;
    }

    private static function oleHasMacros(string $b): bool
    {
        // VBA storage names in the compound file directory (UTF-16LE).
        return str_contains($b, "V\0B\0A\0") || str_contains($b, "_\0V\0B\0A\0_\0P\0R\0O\0J\0E\0C\0T");
    }

    private static function extMatches(string $ext, string $type): bool
    {
        $map = [
            'pdf' => ['pdf'], 'png' => ['png'], 'jpg' => ['jpeg'], 'jpeg' => ['jpeg'], 'gif' => ['gif'], 'webp' => ['webp'], 'bmp' => ['bmp'],
            'docx' => ['zip'], 'xlsx' => ['zip'], 'pptx' => ['zip'], 'odt' => ['zip'], 'ods' => ['zip'], 'odp' => ['zip'], 'zip' => ['zip'],
            'doc' => ['ole', 'rtf'], 'xls' => ['ole', 'text', 'html'], 'ppt' => ['ole'], 'msg' => ['ole'], 'rtf' => ['rtf'],
            'rar' => ['rar'], '7z' => ['7z'], 'gz' => ['gzip'], 'tgz' => ['gzip'],
            'txt' => ['text'], 'csv' => ['text'], 'log' => ['text'], 'xml' => ['text', 'svg'], 'json' => ['text'], 'md' => ['text'], 'eml' => ['text'],
            'ics' => ['ics', 'text'], 'vcf' => ['vcf', 'text'], 'html' => ['html', 'text'], 'htm' => ['html', 'text'], 'svg' => ['svg', 'text'],
        ];
        return !isset($map[$ext]) || in_array($type, $map[$ext], true);
    }
}
