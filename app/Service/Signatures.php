<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Mail\HtmlSanitizer;

/**
 * Centralised signatures: an admin-managed HTML template with placeholders,
 * rendered per user and injected server-side at send time.
 */
final class Signatures
{
    public const VARIABLES = [
        'display_name', 'first_name', 'last_name', 'initials', 'email', 'job_title', 'department', 'phone', 'mobile',
        'company', 'website', 'website_label', 'address', 'logo', 'logo_url', 'primary_color', 'separator_color', 'socials', 'notice',
    ];

    /** Social networks offered (icons in public/assets/social, drawn by bin/social-icons.js). */
    public const NETWORKS = [
        'linkedin' => 'LinkedIn', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'x' => 'X', 'youtube' => 'YouTube',
        'tiktok' => 'TikTok', 'threads' => 'Threads', 'bluesky' => 'Bluesky', 'mastodon' => 'Mastodon', 'pinterest' => 'Pinterest',
        'github' => 'GitHub', 'vimeo' => 'Vimeo', 'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'discord' => 'Discord', 'website' => 'Site',
    ];

    public const NOTICE_MAX = 600;

    /** Where a sent signature's pictures point before they become cid: links (see renderTemplate). */
    private const CID_MARK = '/m4w-cid/';

    /**
     * Layout of a template made with the guided editor (stored as JSON in signature_templates.options).
     * The template's HTML is built from it unless the administrator wrote their own (html not empty).
     */
    public const OPTIONS = [
        'logo'            => 'brand', // brand (the logo of Customisation) | custom (logo_file) | none
        'logo_file'       => '',
        'logo_height'     => 48,
        'logo_position'   => 'left',  // left | right
        'separator'       => 1,
        'separator_color' => '',      // '' = the main colour
        'website_below'   => 1,       // the website on its own line, under the address
        'socials'         => [],      // [['net' => 'linkedin', 'url' => 'https://…'], …]
        'social_style'    => 'color', // color | gray
        'notice'          => '',      // shown in a box above the signature when not empty
        'notice_user'     => 1,       // people may write their own notice instead (or none)
    ];

    public static function options(?array $tpl): array
    {
        $o = json_decode((string) ($tpl['options'] ?? ''), true);
        return array_replace(self::OPTIONS, is_array($o) ? array_intersect_key($o, self::OPTIONS) : []);
    }

    /** Options as posted by the guided editor, checked (only known values reach outgoing mail). */
    public static function cleanOptions(array $in, array $old = []): array
    {
        $color = static fn($v) => preg_match('/^#[0-9a-f]{6}$/i', (string) $v) ? strtolower((string) $v) : '';
        $socials = [];
        foreach ((array) ($in['socials'] ?? []) as $row) {
            $net = (string) ($row['net'] ?? '');
            $url = trim((string) ($row['url'] ?? ''));
            if ($url !== '' && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
                $url = 'https://' . $url;
            }
            if (isset(self::NETWORKS[$net]) && preg_match('#^https?://[^\s"<>]+$#i', $url) && count($socials) < 10) {
                $socials[] = ['net' => $net, 'url' => mb_substr($url, 0, 300)];
            }
        }
        $logo = in_array($in['logo'] ?? '', ['brand', 'custom', 'none'], true) ? (string) $in['logo'] : 'brand';
        $file = (string) ($in['logo_file'] ?? ($old['logo_file'] ?? ''));
        return [
            'logo'            => $logo === 'custom' && $file === '' ? 'brand' : $logo,
            'logo_file'       => preg_match('/^siglogo-[0-9a-f]{12}\.(png|jpg|gif|webp)$/', $file) ? $file : '',
            'logo_height'     => max(24, min(120, (int) ($in['logo_height'] ?? 48))),
            'logo_position'   => ($in['logo_position'] ?? '') === 'right' ? 'right' : 'left',
            'separator'       => !empty($in['separator']) ? 1 : 0,
            'separator_color' => $color($in['separator_color'] ?? ''),
            'website_below'   => !empty($in['website_below']) ? 1 : 0,
            'socials'         => $socials,
            'social_style'    => ($in['social_style'] ?? '') === 'gray' ? 'gray' : 'color',
            'notice'          => mb_substr(trim(str_replace("\r", '', (string) ($in['notice'] ?? ''))), 0, self::NOTICE_MAX),
            'notice_user'     => !empty($in['notice_user']) ? 1 : 0,
        ];
    }

    public static function all(): array
    {
        return DB::all('SELECT t.*, 0 AS users_count FROM signature_templates t ORDER BY is_default DESC, name');
    }

    public static function find(int $id): ?array
    {
        return DB::one('SELECT * FROM signature_templates WHERE id = :id', ['id' => $id]);
    }

    /** The active template applies to everyone: nobody gets another one or none at all. */
    public static function forUser(array $user): ?array
    {
        return DB::one('SELECT * FROM signature_templates WHERE is_default = 1 ORDER BY id LIMIT 1');
    }

    public static function save(array $d, ?int $id = null): int
    {
        $data = [
            'name'           => mb_substr(trim((string) ($d['name'] ?? '')) ?: 'Signature', 0, 190),
            'description'    => mb_substr(trim((string) ($d['description'] ?? '')), 0, 255),
            'html'           => self::cleanTemplate((string) ($d['html'] ?? '')),
            'options'        => json_encode(self::cleanOptions((array) ($d['options'] ?? []), (array) ($d['old_options'] ?? []))),
            'apply_on_reply' => 1,
            'updated_at'     => time(),
        ];
        DB::transaction(function () use (&$id, $data, $d) {
            if (!empty($d['is_default'])) {
                DB::run('UPDATE signature_templates SET is_default = 0');
                $data['is_default'] = 1;
            }
            if ($id) {
                DB::update('signature_templates', $data, 'id = :id', ['id' => $id]);
            } else {
                $data['created_at'] = time();
                $data['is_default'] = $data['is_default'] ?? (DB::value('SELECT COUNT(*) FROM signature_templates') ? 0 : 1);
                $id = DB::insert('signature_templates', $data);
            }
        });
        return (int) $id;
    }

    public static function delete(int $id): void
    {
        DB::run('UPDATE users SET signature_template_id = NULL WHERE signature_template_id = :id', ['id' => $id]);
        DB::delete('signature_templates', 'id = :id', ['id' => $id]);
        // There is always an active template while any exists.
        if (!DB::value('SELECT COUNT(*) FROM signature_templates WHERE is_default = 1')) {
            DB::run('UPDATE signature_templates SET is_default = 1 WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM signature_templates) x)');
        }
    }

    /**
     * Light pre-cleaning of the admin template (placeholders must survive, so no DOM pass here);
     * the rendered result is always passed through the DOM sanitizer.
     */
    public static function cleanTemplate(string $html): string
    {
        $html = preg_replace('#<(script|iframe|object|embed|form|style|link|meta|base)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? '';
        $html = preg_replace('#<(script|iframe|object|embed|link|meta|base)\b[^>]*/?>#is', '', $html) ?? '';
        $html = preg_replace('#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? '';
        return preg_replace('#(href|src)\s*=\s*(["\'])\s*(javascript|vbscript|data):[^"\']*\2#i', '$1="#"', $html) ?? '';
    }

    /** The logo file of a template (in uploads/brand), or ''. */
    public static function logoFile(array $opts): string
    {
        return match ($opts['logo']) {
            'none' => '',
            'custom' => (string) $opts['logo_file'],
            default => (string) Settings::get('brand.logo', ''),
        };
    }

    public static function variables(array $user, string $mode = 'preview', ?array $opts = null): array
    {
        $opts ??= self::OPTIONS;
        $site = (string) Settings::get('brand.website', '');
        $logoFile = self::logoFile($opts);
        $logoUrl = $logoFile !== '' ? url('brand/' . $logoFile) : '';
        $vars = [
            'display_name'  => $user['name'] ?? '',
            'first_name'    => $user['first_name'] ?? '',
            'last_name'     => $user['last_name'] ?? '',
            'initials'      => initials($user['name'] ?? ''),
            'email'         => $user['email'] ?? '',
            'job_title'     => $user['job_title'] ?? '',
            'department'    => $user['department'] ?? '',
            'phone'         => $user['phone'] ?? '',
            'mobile'        => $user['mobile'] ?? '',
            'company'       => (string) Settings::get('brand.company', ''),
            'website'       => $site,
            'website_label' => preg_replace('#^https?://(www\.)?#i', '', rtrim($site, '/')) ?? $site,
            'address'       => (string) Settings::get('brand.address', ''),
            'logo_url'      => $logoUrl,
            'primary_color' => (string) Settings::get('brand.primary', '#2563eb'),
        ];
        $vars['separator_color'] = $opts['separator_color'] ?: $vars['primary_color'];
        $vars['socials'] = $opts['socials'] ? 'x' : '';
        $vars['notice'] = self::noticeText($user, $opts);
        $esc = array_map(static fn($v) => e($v), $vars);
        $h = (int) $opts['logo_height'];
        // When sent, pictures point to a fixed marker replaced by their cid once sanitized (url()
        // depends on the entry point: relative from the scheduled task, and dropped by the sanitizer).
        $esc['logo'] = $logoUrl !== ''
            ? '<img src="' . e($mode === 'send' ? self::CID_MARK . 'logo' : $logoUrl) . '" alt="' . e($vars['company']) . '" height="' . $h . '" style="height:' . $h . 'px;max-height:' . $h . 'px;max-width:' . ($h * 4) . 'px;border:0;display:block">'
            : '';
        $esc['socials'] = self::socialsHtml($opts, $mode);
        $esc['notice'] = $vars['notice'] !== '' ? self::noticeHtml($vars['notice'], $vars['primary_color']) : '';
        return ['raw' => $vars, 'html' => $esc];
    }

    /** Icons in a row, each a link to the page; images are sent with the message (cid), not fetched. */
    public static function socialsHtml(array $opts, string $mode = 'preview'): string
    {
        $out = [];
        foreach ($opts['socials'] as $s) {
            $file = $s['net'] . ($opts['social_style'] === 'gray' ? '-gray' : '') . '.png';
            $src = $mode === 'send' ? self::CID_MARK . 'soc-' . $s['net'] : url('assets/social/' . $file);
            $out[] = '<a href="' . e($s['url']) . '" title="' . e(self::NETWORKS[$s['net']] ?? $s['net']) . '" style="text-decoration:none;display:inline-block;margin-right:6px">'
                . '<img src="' . e($src) . '" alt="' . e(self::NETWORKS[$s['net']] ?? $s['net']) . '" width="22" height="22" style="width:22px;height:22px;border:0;display:inline-block;vertical-align:middle"></a>';
        }
        return implode('', $out);
    }

    /** Inline images a sent signature needs: [cid => path]. */
    public static function inlineImages(array $opts): array
    {
        $out = [];
        $logo = self::logoFile($opts);
        if ($logo !== '' && is_file($p = storage_path('uploads/brand/' . basename($logo)))) {
            $out['m4w-logo'] = $p;
        }
        foreach ($opts['socials'] as $s) {
            $p = M4W_ROOT . '/public/assets/social/' . $s['net'] . ($opts['social_style'] === 'gray' ? '-gray' : '') . '.png';
            if (is_file($p)) {
                $out['m4w-soc-' . $s['net']] = $p;
            }
        }
        return $out;
    }

    /** The notice above a person's signature: the company's, theirs (when allowed), or none. */
    public static function noticeText(array $user, array $opts): string
    {
        $prefs = is_array($user['prefs'] ?? null) ? $user['prefs'] : [];
        if ($opts['notice_user']) {
            if (($prefs['sig_notice_mode'] ?? '') === 'none') {
                return '';
            }
            if (($prefs['sig_notice_mode'] ?? '') === 'custom') {
                return trim((string) ($prefs['sig_notice'] ?? ''));
            }
        }
        return trim((string) $opts['notice']);
    }

    public static function noticeHtml(string $text, string $color): string
    {
        $tint = self::tint($color, 0.9);
        return '<table cellpadding="0" cellspacing="0" border="0" style="margin:0 0 12px 0;border-collapse:collapse"><tr>'
            . '<td style="background:' . $tint . ';border-left:3px solid ' . e($color) . ';padding:8px 12px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.45;color:#1e293b">'
            . nl2br(e($text), false) . '</td></tr></table>';
    }

    /** A colour mixed with white ($amount of white). */
    private static function tint(string $hex, float $amount): string
    {
        if (!preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m)) {
            return '#f1f5f9';
        }
        $c = array_map(static fn($x) => (int) round(hexdec($x) + (255 - hexdec($x)) * $amount), array_slice($m, 1));
        return sprintf('#%02x%02x%02x', ...$c);
    }

    public static function renderTemplate(string $tpl, array $user, string $mode = 'preview', ?array $opts = null): string
    {
        $opts ??= self::OPTIONS;
        if (trim($tpl) === '') {
            $tpl = self::build($opts);
        }
        // The notice goes above the signature, unless the template places it itself.
        if (!str_contains($tpl, '{{notice}}')) {
            $tpl = '{{#notice}}{{notice}}{{/notice}}' . $tpl;
        }
        $v = self::variables($user, $mode, $opts);
        $raw = $v['raw'];
        $raw['logo'] = $v['html']['logo'];
        // Sections: {{#var}}...{{/var}} shown when var not empty; {{^var}}...{{/var}} when empty.
        $section = static function ($m) use ($raw) {
            $filled = trim((string) ($raw[$m[2]] ?? '')) !== '';
            return ($m[1] === '#' ? $filled : !$filled) ? $m[3] : '';
        };
        // Innermost sections first, repeated to support nesting.
        for ($i = 0; $i < 6; $i++) {
            $next = preg_replace_callback('/\{\{([#^])\s*(\w+)\s*\}\}((?:(?!\{\{[#^]).)*?)\{\{\/\s*\2\s*\}\}/s', $section, $tpl) ?? $tpl;
            if ($next === $tpl) {
                break;
            }
            $tpl = $next;
        }
        $html = preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', static fn($m) => $v['html'][$m[1]] ?? '', $tpl) ?? $tpl;
        $html = (new HtmlSanitizer(false, null, false))->sanitize($html);
        if ($mode === 'send' && $v['raw']['logo_url'] !== '') {
            $html = str_replace('src="' . e($v['raw']['logo_url']) . '"', 'src="cid:m4w-logo"', $html);
        }
        if ($mode === 'send') {
            $html = preg_replace('#src="' . preg_quote(self::CID_MARK, '#') . '([a-z-]+)"#', 'src="cid:m4w-$1"', $html) ?? $html;
        }
        return $html;
    }

    public static function logoUrl(): string
    {
        $f = (string) Settings::get('brand.logo', '');
        return $f === '' ? '' : url('brand/' . $f);
    }

    /**
     * Signature HTML of a user: the template set by the administration, nothing else
     * (users never write or pick their own signature).
     * @return array{html:string,needs_logo:bool}
     */
    public static function render(array $user, string $mode = 'preview', bool $isReply = false): array
    {
        $tpl = self::forUser($user);
        if (!$tpl) {
            return ['html' => '', 'needs_logo' => false, 'inline' => []];
        }
        $opts = self::options($tpl);
        $html = self::renderTemplate((string) $tpl['html'], $user, $mode, $opts);
        // Only the images the signature really shows travel with the message.
        $inline = array_filter(self::inlineImages($opts), static fn($cid) => str_contains($html, 'cid:' . $cid), ARRAY_FILTER_USE_KEY);
        return ['html' => $html, 'needs_logo' => isset($inline['m4w-logo']), 'inline' => $inline];
    }

    /** Insert the signature into composed HTML: before quoted content, else at the end. */
    public static function inject(string $body, string $signatureHtml): string
    {
        if (trim($signatureHtml) === '') {
            return $body;
        }
        $block = '<div class="m4w-signature" data-m4w-signature="1" style="margin-top:16px">' . $signatureHtml . '</div>';
        $pos = stripos($body, 'data-m4w-quote');
        if ($pos !== false) {
            $tagStart = strrpos(substr($body, 0, $pos), '<');
            if ($tagStart !== false) {
                return substr($body, 0, $tagStart) . $block . substr($body, $tagStart);
            }
        }
        return $body . $block;
    }

    /** A new template starts in the guided editor (its HTML is built from the options). */
    public static function defaultTemplate(): string
    {
        return '';
    }

    /** The default template of older versions: kept as it is it becomes a guided one (migration 43). */
    public static function legacyDefault(): string
    {
        return <<<'HTML'
<table cellpadding="0" cellspacing="0" border="0" style="font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.45;color:#334155">
  <tr>
    {{#logo_url}}<td style="padding-right:16px;vertical-align:top;border-right:3px solid {{primary_color}}">{{logo}}</td>{{/logo_url}}
    <td style="padding-left:16px;vertical-align:top">
      <div style="font-size:15px;font-weight:bold;color:#0f172a">{{display_name}}</div>
      {{#job_title}}<div style="color:{{primary_color}};font-weight:bold">{{job_title}}{{#department}} · {{department}}{{/department}}</div>{{/job_title}}
      <div style="margin-top:6px">
        {{#phone}}<span>Tél. {{phone}}</span>{{/phone}}{{#mobile}}<span> · Mob. {{mobile}}</span>{{/mobile}}
      </div>
      <div><a href="mailto:{{email}}" style="color:{{primary_color}};text-decoration:none">{{email}}</a>{{#website}} · <a href="{{website}}" style="color:{{primary_color}};text-decoration:none">{{website_label}}</a>{{/website}}</div>
      {{#company}}<div style="margin-top:6px;font-weight:bold">{{company}}</div>{{/company}}
      {{#address}}<div style="color:#64748b;font-size:12px">{{address}}</div>{{/address}}
    </td>
  </tr>
</table>
HTML;
    }

    /** HTML of a guided template: logo left or right, separator or not, details, networks. */
    public static function build(array $o): string
    {
        $sep = $o['separator'] ? 'border-%s:3px solid {{separator_color}};' : '';
        $right = $o['logo_position'] === 'right';
        $logoCell = '{{#logo_url}}<td style="vertical-align:top;' . ($right ? 'padding-left:16px;' . sprintf($sep, 'left') : 'padding-right:16px;' . sprintf($sep, 'right')) . '">{{logo}}</td>{{/logo_url}}';
        $textPad = $right ? 'padding-right:16px' : '{{#logo_url}}padding-left:' . ($o['separator'] ? '16' : '0') . 'px{{/logo_url}}';
        $link = 'color:{{primary_color}};text-decoration:none';
        $web = '{{#website}}<a href="{{website}}" style="' . $link . '">{{website_label}}</a>{{/website}}';
        $text = '<td style="vertical-align:top;' . $textPad . '">'
            . "\n      " . '<div style="font-size:15px;font-weight:bold;color:#0f172a">{{display_name}}</div>'
            . "\n      " . '{{#job_title}}<div style="color:{{primary_color}};font-weight:bold">{{job_title}}{{#department}} · {{department}}{{/department}}</div>{{/job_title}}'
            . "\n      " . '<div style="margin-top:6px">{{#phone}}<span>Tél. {{phone}}</span>{{/phone}}{{#mobile}}{{#phone}}<br>{{/phone}}<span>Mob. {{mobile}}</span>{{/mobile}}</div>'
            . "\n      " . '<div><a href="mailto:{{email}}" style="' . $link . '">{{email}}</a>' . ($o['website_below'] ? '</div>' . "\n      " . '{{#website}}<div>' . $web . '</div>{{/website}}' : '{{#website}} · {{/website}}' . $web . '</div>')
            . "\n      " . '{{#company}}<div style="margin-top:6px;font-weight:bold">{{company}}</div>{{/company}}'
            . "\n      " . '{{#address}}<div style="color:#64748b;font-size:12px">{{address}}</div>{{/address}}'
            . "\n      " . '{{#socials}}<div style="margin-top:8px">{{socials}}</div>{{/socials}}'
            . "\n    </td>";
        return '<table cellpadding="0" cellspacing="0" border="0" style="font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.45;color:#334155">' . "\n  <tr>\n    "
            . ($right ? $text . "\n    " . $logoCell : $logoCell . "\n    " . $text) . "\n  </tr>\n</table>";
    }
}
