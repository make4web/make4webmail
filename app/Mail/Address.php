<?php
declare(strict_types=1);

namespace M4W\Mail;

/**
 * RFC 5322 address-list parsing / formatting.
 */
final class Address
{
    /** @return array<int, array{name:string,email:string}> */
    public static function parseList(string $value): array
    {
        $out = [];
        foreach (self::splitList($value) as $item) {
            $a = self::parseOne($item);
            if ($a !== null) {
                $out[] = $a;
            }
        }
        return $out;
    }

    /** Split on commas/semicolons outside quotes, comments and angle brackets. */
    private static function splitList(string $s): array
    {
        $items = [];
        $buf = '';
        $inQuote = false;
        $depthAngle = 0;
        $depthParen = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $buf .= $c . $s[++$i];
                continue;
            }
            if ($c === '"' && $depthParen === 0) {
                $inQuote = !$inQuote;
            } elseif (!$inQuote) {
                if ($c === '(') {
                    $depthParen++;
                } elseif ($c === ')' && $depthParen > 0) {
                    $depthParen--;
                } elseif ($c === '<' && $depthParen === 0) {
                    $depthAngle++;
                } elseif ($c === '>' && $depthAngle > 0) {
                    $depthAngle--;
                } elseif (($c === ',' || $c === ';') && $depthAngle === 0 && $depthParen === 0) {
                    $items[] = $buf;
                    $buf = '';
                    continue;
                } elseif ($c === ':' && $depthAngle === 0 && $depthParen === 0) {
                    // Group syntax "Group: a@b, c@d;" -> drop the group name.
                    $buf = '';
                    continue;
                }
            }
            $buf .= $c;
        }
        $items[] = $buf;
        return array_values(array_filter(array_map('trim', $items), static fn($x) => $x !== ''));
    }

    /** @return array{name:string,email:string}|null */
    public static function parseOne(string $item): ?array
    {
        $item = trim($item);
        if ($item === '') {
            return null;
        }
        if (preg_match('/^(.*)<\s*([^<>]*?)\s*>\s*(?:\(.*\))?$/s', $item, $m)) {
            $name = trim($m[1]);
            $email = trim($m[2]);
        } else {
            $email = trim(preg_replace('/\(.*?\)/', '', $item) ?? $item);
            $name = '';
            if (preg_match('/\((.*?)\)/', $item, $c)) {
                $name = $c[1];
            }
        }
        $name = trim($name);
        if (strlen($name) >= 2 && $name[0] === '"' && str_ends_with($name, '"')) {
            $name = stripcslashes(substr($name, 1, -1));
        }
        $name = Charset::decodeHeader($name);
        $email = trim($email, " \t\"'");
        if ($email === '' || !str_contains($email, '@')) {
            return $name !== '' && str_contains($name, '@') ? ['name' => '', 'email' => mb_strtolower($name)] : null;
        }
        // Lowercase the domain, keep the local part as-is.
        [$local, $domain] = explode('@', $email, 2) + [1 => ''];
        return ['name' => trim($name), 'email' => $local . '@' . mb_strtolower($domain)];
    }

    /** Format for display / header use (not encoded). */
    public static function format(string $email, string $name = ''): string
    {
        if ($name === '' || $name === $email) {
            return $email;
        }
        if (preg_match('/[(),.:;<>@\[\]"\\\\]/', $name)) {
            $name = '"' . addcslashes($name, '"\\') . '"';
        }
        return $name . ' <' . $email . '>';
    }

    /** Header-ready (RFC 2047 encoded name, IDN domain). */
    public static function encode(string $email, string $name = ''): string
    {
        $email = self::asciiEmail($email);
        if ($name === '') {
            return $email;
        }
        if (preg_match('/[^\x20-\x7e]/', $name)) {
            return Charset::encodeHeader($name) . ' <' . $email . '>';
        }
        return self::format($email, $name);
    }

    public static function asciiEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        if (preg_match('/[^\x20-\x7e]/', $domain) && function_exists('idn_to_ascii')) {
            $d = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($d !== false) {
                $domain = $d;
            }
        }
        return $local . '@' . $domain;
    }

    public static function domain(string $email): string
    {
        $p = strrpos($email, '@');
        return $p === false ? '' : mb_strtolower(substr($email, $p + 1));
    }

    /** Parse free user input (compose fields): "a@b.c, Jean <j@x.fr>; …" */
    public static function parseInput(string|array $input): array
    {
        if (is_array($input)) {
            $input = implode(',', array_map('strval', $input));
        }
        $list = [];
        $seen = [];
        foreach (self::parseList($input) as $a) {
            $key = mb_strtolower($a['email']);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $list[] = $a;
            }
        }
        return $list;
    }
}
