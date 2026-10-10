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
        'company', 'website', 'website_label', 'address', 'logo', 'logo_url', 'primary_color',
    ];

    public static function all(): array
    {
        return DB::all('SELECT t.*, (SELECT COUNT(*) FROM users u WHERE u.signature_template_id = t.id) AS users_count FROM signature_templates t ORDER BY is_default DESC, name');
    }

    public static function find(int $id): ?array
    {
        return DB::one('SELECT * FROM signature_templates WHERE id = :id', ['id' => $id]);
    }

    public static function forUser(array $user): ?array
    {
        if (!empty($user['signature_template_id'])) {
            $t = self::find((int) $user['signature_template_id']);
            if ($t) {
                return $t;
            }
        }
        return DB::one('SELECT * FROM signature_templates WHERE is_default = 1 ORDER BY id LIMIT 1');
    }

    public static function save(array $d, ?int $id = null): int
    {
        $data = [
            'name'           => mb_substr(trim((string) ($d['name'] ?? '')) ?: 'Signature', 0, 190),
            'description'    => mb_substr(trim((string) ($d['description'] ?? '')), 0, 255),
            'html'           => self::cleanTemplate((string) ($d['html'] ?? '')),
            'apply_on_reply' => !empty($d['apply_on_reply']) ? 1 : 0,
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

    public static function variables(array $user, string $mode = 'preview'): array
    {
        $site = (string) Settings::get('brand.website', '');
        $logoFile = (string) Settings::get('brand.logo', '');
        $logoUrl = $logoFile !== '' ? self::logoUrl() : '';
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
        $esc = array_map(static fn($v) => e($v), $vars);
        $esc['logo'] = $logoUrl !== ''
            ? '<img src="' . e($logoUrl) . '" alt="' . e($vars['company']) . '" style="max-height:48px;max-width:180px;border:0;display:block">'
            : '';
        return ['raw' => $vars, 'html' => $esc];
    }

    public static function renderTemplate(string $tpl, array $user, string $mode = 'preview'): string
    {
        $v = self::variables($user, $mode);
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
        return $html;
    }

    public static function logoUrl(): string
    {
        $f = (string) Settings::get('brand.logo', '');
        return $f === '' ? '' : url('brand/' . $f);
    }

    /**
     * Full signature HTML for a user (personal part + corporate template).
     * @return array{html:string,needs_logo:bool}
     */
    public static function render(array $user, string $mode = 'preview', bool $isReply = false): array
    {
        $parts = [];
        if ((int) Settings::get('features.user_signature', 0) && trim(strip_tags((string) $user['personal_signature'], '<img>')) !== '') {
            $parts[] = '<div class="m4w-personal-signature">' . $user['personal_signature'] . '</div>';
        }
        $tpl = self::forUser($user);
        if ($tpl && (!$isReply || (int) $tpl['apply_on_reply'])) {
            $parts[] = self::renderTemplate((string) $tpl['html'], $user, $mode);
        }
        $html = implode('', $parts);
        return ['html' => $html, 'needs_logo' => str_contains($html, 'cid:m4w-logo')];
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

    public static function defaultTemplate(): string
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
}
