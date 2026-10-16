<?php
declare(strict_types=1);

namespace M4W\Mail;

final class HtmlToText
{
    public static function convert(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $html = preg_replace('#<(script|style|head|title)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        // Line breaks of the HTML source are not line breaks (the indentation between two <div>s
        // would double every line), except where the text keeps them: <pre>, white-space:pre…
        $html = preg_replace_callback('#<(pre|div|span|p|td)\b([^>]*)>(.*?)</\1>#is', static function ($m) {
            if ($m[1] !== 'pre' && !preg_match('#white-space\s*:\s*pre#i', $m[2])) {
                return $m[0];
            }
            return '<' . $m[1] . $m[2] . '>' . str_replace(["\r\n", "\n"], '<br>', $m[3]) . '</' . $m[1] . '>';
        }, $html) ?? $html;
        $html = preg_replace('/\s+/u', ' ', $html) ?? $html;
        // The signature stands apart from the text, as it does in the HTML (margin above it).
        $html = preg_replace('#<div\b[^>]*\bdata-m4w-signature\b#i', "\n\n$0", $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        // A paragraph is followed by a blank line; other blocks just end their line.
        $html = preg_replace('#</p>#i', "\n\n", $html) ?? $html;
        $html = preg_replace('#</(div|tr|h[1-6]|li|blockquote|table|section|article|header|footer)>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<li\b[^>]*>#i', "• ", $html) ?? $html;
        $html = preg_replace('#<(td|th)\b[^>]*>#i', ' ', $html) ?? $html;
        $html = preg_replace_callback(
            '#<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            static function ($m) {
                $label = trim(strip_tags($m[3]));
                $href = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($label === '' || $label === $href || str_starts_with($href, 'mailto:') || !preg_match('#^https?://#i', $href)) {
                    return $m[3];
                }
                return $m[3] . ' <' . $href . '>';
            },
            $html
        ) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/ *\n */", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }
}
