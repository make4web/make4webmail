<?php
declare(strict_types=1);

namespace M4W\Core;

final class I18n
{
    public const LANGUAGES = ['fr' => 'Français', 'en' => 'English'];
    private static string $lang = 'fr';
    private static array $dict = [];
    private static array $fallback = [];

    public static function setLanguage(string $lang): void
    {
        if (!isset(self::LANGUAGES[$lang])) {
            $lang = 'fr';
        }
        self::$lang = $lang;
        self::$dict = require M4W_APP . '/lang/' . $lang . '.php';
        if (!self::$fallback) {
            self::$fallback = $lang === 'fr' ? self::$dict : require M4W_APP . '/lang/fr.php';
        }
    }

    public static function language(): string
    {
        return self::$lang;
    }

    public static function t(string $key, array $vars = []): string
    {
        if (!self::$dict) {
            self::setLanguage(self::$lang);
        }
        $s = self::$dict[$key] ?? self::$fallback[$key] ?? $key;
        if ($vars) {
            // Longest keys first so ":total" is not clobbered by ":to".
            uksort($vars, static fn($a, $b) => strlen((string) $b) <=> strlen((string) $a));
            foreach ($vars as $k => $v) {
                $s = str_replace(':' . $k, (string) $v, $s);
            }
        }
        return $s;
    }

    /** Keys exported to the JavaScript runtime (prefix "js."). */
    public static function jsDictionary(): array
    {
        if (!self::$dict) {
            self::setLanguage(self::$lang);
        }
        $out = [];
        foreach (self::$fallback + self::$dict as $k => $_) {
            if (str_starts_with($k, 'js.')) {
                $out[substr($k, 3)] = self::$dict[$k] ?? self::$fallback[$k];
            }
        }
        return $out;
    }

    public static function detect(string $acceptLanguage): string
    {
        foreach (explode(',', $acceptLanguage) as $part) {
            $code = strtolower(substr(trim($part), 0, 2));
            if (isset(self::LANGUAGES[$code])) {
                return $code;
            }
        }
        return (string) Settings::get('features.default_language', 'fr');
    }
}
