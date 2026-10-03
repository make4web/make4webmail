<?php
declare(strict_types=1);

namespace M4W\Mail;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Whitelist-based HTML sanitizer for e-mail content.
 * Output is additionally rendered in a sandboxed iframe with a strict CSP (defence in depth).
 */
final class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'a', 'abbr', 'address', 'article', 'aside', 'b', 'bdi', 'bdo', 'big', 'blockquote', 'br', 'caption',
        'center', 'cite', 'code', 'col', 'colgroup', 'dd', 'del', 'details', 'dfn', 'div', 'dl', 'dt', 'em',
        'figcaption', 'figure', 'font', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'i', 'img',
        'ins', 'kbd', 'li', 'main', 'mark', 'nav', 'ol', 'p', 'pre', 'q', 's', 'samp', 'section', 'small', 'span',
        'strike', 'strong', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'time', 'tr',
        'tt', 'u', 'ul', 'var', 'wbr',
    ];

    private const DROP_WITH_CONTENT = [
        'script', 'style', 'noscript', 'iframe', 'object', 'embed', 'applet', 'title', 'meta', 'link', 'base',
        'svg', 'math', 'frameset', 'frame', 'template', 'audio', 'video', 'canvas', 'input', 'select', 'textarea',
        'option', 'head', 'xml', 'param', 'source', 'track', 'portal',
    ];

    private const ALLOWED_ATTRS = [
        'style', 'class', 'id', 'dir', 'lang', 'title', 'align', 'valign', 'bgcolor', 'background', 'width',
        'height', 'border', 'cellpadding', 'cellspacing', 'color', 'face', 'size', 'colspan', 'rowspan', 'nowrap',
        'href', 'src', 'alt', 'start', 'type', 'cite', 'datetime', 'span', 'reversed', 'open', 'name', 'role',
        'aria-label', 'aria-hidden', 'data-m4w-quote', 'data-m4w-signature', 'target', 'rel', 'scope', 'headers',
        'abbr', 'axis', 'char', 'charoff', 'frame', 'rules', 'summary', 'hspace', 'vspace', 'clear', 'noshade', 'data-m4w-src',
    ];

    private int $blocked = 0;
    /** @var string[] */
    private array $styles = [];

    /**
     * @param bool $blockRemote  hide remote images (anti-tracking)
     * @param ?\Closure $cidResolver fn(string $cid): ?string -> URL
     */
    public function __construct(
        private bool $blockRemote = true,
        private ?\Closure $cidResolver = null,
        private bool $keepStyleTags = true,
    ) {
    }

    public function blockedCount(): int
    {
        return $this->blocked;
    }

    /** @return string sanitized fragment (style blocks first, then body content) */
    public function sanitize(string $html): string
    {
        $this->blocked = 0;
        $this->styles = [];
        if (trim($html) === '') {
            return '';
        }
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = Charset::toUtf8($html, 'Windows-1252');
        }
        // Remove conditional comments / CDATA early.
        $html = preg_replace('/<!\[CDATA\[.*?\]\]>/s', '', $html) ?? $html;

        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_COMPACT | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        // Collect <style> anywhere in the document.
        if ($this->keepStyleTags) {
            foreach (iterator_to_array($doc->getElementsByTagName('style')) as $st) {
                $this->styles[] = $this->sanitizeCss($st->textContent, true);
            }
        }
        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return '';
        }
        $this->cleanChildren($body);

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        $css = trim(implode("\n", array_filter($this->styles)));
        return ($css !== '' ? "<style>\n" . $css . "\n</style>\n" : '') . $out;
    }

    private function cleanChildren(DOMNode $node): void
    {
        $children = iterator_to_array($node->childNodes);
        foreach ($children as $child) {
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE
                || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);
                continue;
            }
            if (in_array($tag, ['html', 'body'], true) || !in_array($tag, self::ALLOWED_TAGS, true)) {
                // Unwrap unknown element: keep its children.
                $this->cleanChildren($child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            $this->cleanAttributes($child, $tag);
            $this->cleanChildren($child);
        }
    }

    private function cleanAttributes(DOMElement $el, string $tag): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            $value = $attr->value;
            if (!in_array($name, self::ALLOWED_ATTRS, true) || str_starts_with($name, 'on')) {
                $el->removeAttribute($attr->name);
                continue;
            }
            switch ($name) {
                case 'href':
                    $safe = $this->safeLink($value);
                    if ($safe === null) {
                        $el->removeAttribute('href');
                    } else {
                        $el->setAttribute('href', $safe);
                    }
                    break;
                case 'src':
                    if ($tag !== 'img') {
                        $el->removeAttribute('src');
                        break;
                    }
                    $this->handleImageSrc($el, $value);
                    break;
                case 'background':
                    $u = $this->safeImageUrl($value);
                    if ($u === null) {
                        $el->removeAttribute('background');
                    } else {
                        $el->setAttribute('background', $u);
                    }
                    break;
                case 'style':
                    $css = $this->sanitizeCss($value, false);
                    if ($css === '') {
                        $el->removeAttribute('style');
                    } else {
                        $el->setAttribute('style', $css);
                    }
                    break;
                case 'data-m4w-src':
                    if (!preg_match('#^https?://#i', $value)) {
                        $el->removeAttribute($name);
                    }
                    break;
                case 'target':
                case 'rel':
                    $el->removeAttribute($name);
                    break;
                case 'id':
                case 'name':
                case 'class':
                    // Avoid DOM clobbering / overly long values.
                    $el->setAttribute($name, mb_substr(preg_replace('/[^\w\s-]/u', '', $value) ?? '', 0, 200));
                    break;
            }
        }
        if ($tag === 'a' && $el->hasAttribute('href')) {
            $href = $el->getAttribute('href');
            if (!str_starts_with($href, '#')) {
                $el->setAttribute('target', '_blank');
                $el->setAttribute('rel', 'noopener noreferrer nofollow');
            }
        }
    }

    private function handleImageSrc(DOMElement $el, string $value): void
    {
        $value = trim($value);
        if (stripos($value, 'cid:') === 0) {
            $cid = trim(substr($value, 4), '<> ');
            $url = $this->cidResolver ? ($this->cidResolver)($cid) : null;
            if ($url) {
                $el->setAttribute('src', $url);
            } else {
                $el->removeAttribute('src');
            }
            return;
        }
        if (preg_match('#^data:image/(png|gif|jpe?g|webp|bmp);base64,[a-z0-9+/=\s]+$#i', $value)) {
            return;
        }
        if (preg_match('#^https?://#i', $value) || str_starts_with($value, '//')) {
            if (str_starts_with($value, '//')) {
                $value = 'https:' . $value;
            }
            if ($this->blockRemote) {
                $this->blocked++;
                $el->setAttribute('data-m4w-src', $value);
                $el->setAttribute('src', 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
                $el->setAttribute('class', trim($el->getAttribute('class') . ' m4w-blocked'));
            } else {
                $el->setAttribute('src', $value);
            }
            return;
        }
        // Relative URL or other scheme: if it is our own attachment URL (compose mode) keep it.
        if ($this->cidResolver === null && preg_match('#^/[\w/.?=&%:@+~-]*$#', $value)) {
            return;
        }
        $el->removeAttribute('src');
    }

    public function safeLink(string $href): ?string
    {
        $h = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $h = preg_replace('/[\x00-\x20\x7f]+/', '', $h) ?? '';
        if ($h === '') {
            return null;
        }
        if (str_starts_with($h, '#')) {
            return $h;
        }
        if (preg_match('#^(https?:|mailto:|tel:|sms:)#i', $h)) {
            return trim($href);
        }
        if (str_starts_with($h, '//')) {
            return 'https:' . $h;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $h)) {
            return null; // javascript:, data:, vbscript:, file: …
        }
        if (preg_match('#^www\.#i', $h)) {
            return 'http://' . $h;
        }
        return null;
    }

    private function safeImageUrl(string $value): ?string
    {
        $value = trim($value);
        if (stripos($value, 'cid:') === 0) {
            $cid = trim(substr($value, 4), '<> ');
            return $this->cidResolver ? ($this->cidResolver)($cid) : null;
        }
        if (preg_match('#^data:image/(png|gif|jpe?g|webp);base64,#i', $value)) {
            return $value;
        }
        if (preg_match('#^https?://#i', $value)) {
            if ($this->blockRemote) {
                $this->blocked++;
                return null;
            }
            return $value;
        }
        return null;
    }

    public function sanitizeCss(string $css, bool $isBlock): string
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';
        // Decode CSS escapes to detect obfuscation (e.g. "\6a avascript").
        $decoded = preg_replace_callback('/\\\\([0-9a-f]{1,6})\s?/i', static fn($m) => mb_chr((int) hexdec($m[1])) ?: '', $css) ?? $css;
        $decoded = str_replace('\\', '', $decoded);
        if (preg_match('/expression\s*\(|javascript\s*:|vbscript\s*:|-moz-binding|behavior\s*:|@import|@charset|<\/?\s*style/i', $decoded)) {
            $css = preg_replace('/expression\s*\([^)]*\)|javascript\s*:|vbscript\s*:|-moz-binding[^;]*|behavior\s*:[^;]*|@import[^;]*;?|@charset[^;]*;?|<\/?\s*style[^>]*>?/i', '', $decoded) ?? '';
        }
        $css = preg_replace_callback('/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/i', function ($m) {
            $u = $this->safeImageUrl($m[2]);
            return $u === null ? 'none' : 'url("' . str_replace(['"', '\\'], '', $u) . '")';
        }, $css) ?? '';
        // Neutralise layouts that could mimic the application chrome.
        $css = preg_replace('/position\s*:\s*fixed/i', 'position:static', $css) ?? $css;
        if ($isBlock) {
            $css = preg_replace('/@font-face\s*\{[^}]*\}/i', '', $css) ?? $css;
            // A "<" can never be needed in CSS and could only serve to break out of <style>.
            return trim(str_replace('<', '', $css));
        }
        return trim(str_replace(["\n", "\r"], ' ', $css));
    }
}
