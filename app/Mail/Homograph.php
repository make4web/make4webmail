<?php
declare(strict_types=1);

namespace M4W\Mail;

/**
 * Domain names that pass for others: "amαzon.com" (Greek alpha), "amаzon.com" (Cyrillic a),
 * written in Unicode or in their punycode form ("xn--amzon-…"). Unicode technical standard
 * #39 in short: the letters are mapped to the Latin letter they look like (skeleton), and the
 * scripts mixed inside one label are checked.
 */
final class Homograph
{
    /** Letters of other scripts (and Latin oddities) that look like a plain Latin letter. */
    private const CONFUSABLES = [
        // Cyrillic
        'а' => 'a', 'в' => 'b', 'с' => 'c', 'ԁ' => 'd', 'е' => 'e', 'ё' => 'e', 'һ' => 'h', 'і' => 'i', 'ї' => 'i', 'ј' => 'j', 'к' => 'k', 'ӏ' => 'l',
        'м' => 'm', 'п' => 'n', 'о' => 'o', 'р' => 'p', 'ԛ' => 'q', 'г' => 'r', 'ѕ' => 's', 'т' => 't', 'у' => 'y', 'ү' => 'y', 'х' => 'x', 'ԝ' => 'w',
        'ѡ' => 'w', 'з' => '3', 'ь' => 'b', 'ы' => 'bl', 'ю' => 'io', 'ћ' => 'h', 'ђ' => 'h', 'ԍ' => 'g', 'ɡ' => 'g', 'ԃ' => 'd', 'ꙇ' => 'i',
        // Greek
        'α' => 'a', 'β' => 'b', 'γ' => 'y', 'δ' => 'd', 'ε' => 'e', 'η' => 'n', 'ι' => 'i', 'κ' => 'k', 'ν' => 'v', 'ο' => 'o', 'ρ' => 'p', 'σ' => 'o',
        'τ' => 't', 'υ' => 'u', 'χ' => 'x', 'ω' => 'w', 'ϲ' => 'c', 'ϳ' => 'j', 'ϱ' => 'p', 'ς' => 'c', 'ί' => 'i', 'ό' => 'o', 'ύ' => 'u', 'ά' => 'a', 'έ' => 'e',
        // Armenian, Cherokee, Georgian…
        'օ' => 'o', 'ո' => 'n', 'ս' => 'u', 'հ' => 'h', 'ց' => 'g', 'զ' => 'q', 'ա' => 'w', 'ւ' => 'l', 'Ꭺ' => 'a', 'Ꮯ' => 'c', 'Ꭼ' => 'e', 'Ꮋ' => 'h', 'Ꮃ' => 'w',
        'ᴏ' => 'o', 'ᴄ' => 'c', 'ᴠ' => 'v', 'ᴡ' => 'w', 'ᴢ' => 'z', 'ꮃ' => 'w',
        // Latin look-alikes (not ordinary accented letters)
        'ı' => 'i', 'ɩ' => 'i', 'ɪ' => 'i', 'ɑ' => 'a', 'ɒ' => 'a', 'ƅ' => 'b', 'ɓ' => 'b', 'ƈ' => 'c', 'ɗ' => 'd', 'ɛ' => 'e', 'ƒ' => 'f', 'ɢ' => 'g', 'ɦ' => 'h',
        'ʝ' => 'j', 'ƙ' => 'k', 'ʟ' => 'l', 'ǀ' => 'l', 'ɱ' => 'm', 'ɴ' => 'n', 'ɵ' => 'o', 'ƿ' => 'p', 'ʀ' => 'r', 'ʂ' => 's', 'ƚ' => 'l',
        'ʋ' => 'u', 'ʏ' => 'y', 'ƴ' => 'y', 'ʐ' => 'z', 'ẓ' => 'z', 'ꜱ' => 's', 'ℓ' => 'l', 'ⅰ' => 'i', 'ⅼ' => 'l', 'ⅽ' => 'c', 'ⅾ' => 'd', 'ⅿ' => 'm',
    ];

    /** Scripts that may legitimately share a label (Japanese, Chinese, Korean names with Latin). */
    private const ALLOWED_MIXES = [['Latin', 'Han', 'Hiragana', 'Katakana'], ['Latin', 'Han', 'Bopomofo'], ['Latin', 'Han', 'Hangul']];
    private const SCRIPTS = ['Latin', 'Greek', 'Cyrillic', 'Armenian', 'Hebrew', 'Arabic', 'Georgian', 'Cherokee', 'Han', 'Hiragana', 'Katakana', 'Hangul',
        'Bopomofo', 'Thai', 'Devanagari', 'Bengali', 'Tamil', 'Ethiopic', 'Khmer', 'Lao', 'Myanmar', 'Sinhala', 'Tibetan', 'Mongolian'];

    /** Readable (Unicode) form of a host, whether it came as Unicode or as punycode. */
    public static function unicode(string $host): string
    {
        $host = strtolower(trim($host, '.'));
        if (!str_contains($host, 'xn--')) {
            return $host;
        }
        if (function_exists('idn_to_utf8')) {
            $u = idn_to_utf8($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($u) && $u !== '') {
                return mb_strtolower($u);
            }
        }
        return $host;
    }

    public static function ascii(string $host): string
    {
        $host = mb_strtolower(trim($host, '.'));
        if (!preg_match('/[^\x00-\x7f]/', $host) || !function_exists('idn_to_ascii')) {
            return $host;
        }
        $a = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        return is_string($a) && $a !== '' ? strtolower($a) : $host;
    }

    /** Internationalised name: other characters than a-z, 0-9 and "-", or their punycode form. */
    public static function isIdn(string $host): bool
    {
        return str_contains(strtolower($host), 'xn--') || (bool) preg_match('/[^\x00-\x7f]/', $host);
    }

    /** @return string[] scripts used by a label (digits, "-" and marks do not count) */
    public static function scripts(string $label): array
    {
        $out = [];
        foreach (self::SCRIPTS as $s) {
            if (preg_match('/\p{' . $s . '}/u', $label)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /** A label written with letters of several scripts that do not go together. */
    public static function mixedScript(string $host): bool
    {
        foreach (explode('.', self::unicode($host)) as $label) {
            $s = self::scripts($label);
            if (count($s) < 2) {
                continue;
            }
            $ok = false;
            foreach (self::ALLOWED_MIXES as $mix) {
                if (!array_diff($s, $mix)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return true;
            }
        }
        return false;
    }

    /** What the name looks like once every look-alike letter is replaced by its Latin twin. */
    public static function skeleton(string $s): string
    {
        $s = mb_strtolower($s);
        if (class_exists(\Normalizer::class)) {
            $s = (string) \Normalizer::normalize($s, \Normalizer::FORM_KC); // fullwidth, ligatures, ℓ…
        }
        return strtr($s, self::CONFUSABLES);
    }

    /**
     * The plain Latin name this host imitates ("amazon.com" for "amαzon.com"), or null. Only
     * look-alike letters count: an ordinary accented name ("élysée.fr") is not an imitation.
     */
    public static function imitates(string $host): ?string
    {
        $u = self::unicode($host);
        if (!preg_match('/[^\x00-\x7f]/', $u)) {
            return null;
        }
        $skel = self::skeleton($u);
        if ($skel === $u) {
            return null; // no look-alike letter at all (accents, other scripts written plainly)
        }
        // Accents left after the replacement are dropped too: "аmázon" still reads "amazon".
        if (class_exists(\Normalizer::class)) {
            $skel = preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($skel, \Normalizer::FORM_D)) ?? $skel;
        }
        return preg_match('/^[a-z0-9.\-]+$/', $skel) ? $skel : null;
    }

    /** The host with every character outside a-z, 0-9, "." and "-" marked (HTML, escaped). */
    public static function highlight(string $host): string
    {
        $out = '';
        foreach (mb_str_split(self::unicode($host)) as $ch) {
            $out .= preg_match('/^[a-z0-9.\-]$/', $ch) ? e($ch) : '<mark title="U+' . sprintf('%04X', mb_ord($ch, 'UTF-8')) . '">' . e($ch) . '</mark>';
        }
        return $out;
    }
}
