<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\Audit;
use M4W\Core\Database as DB;
use M4W\Core\HttpException;
use M4W\Core\Response;
use M4W\Core\Settings;
use M4W\Mail\HtmlSanitizer;
use M4W\Mail\MimeParser;
use M4W\Service\Retention;

/** Administration → Deleted messages (retention store). */
final class RetentionController extends Controller
{
    private function page(string $tpl, array $data): Response
    {
        return $this->view('admin/' . $tpl, $data + ['section' => 'deleted'], 'layouts/app');
    }

    public function index(): Response
    {
        $q = $this->req->query;
        $filters = [
            'q' => (string) ($q['q'] ?? ''), 'user' => (int) ($q['user'] ?? 0), 'reason' => (string) ($q['reason'] ?? ''),
            'state' => (string) ($q['state'] ?? ''),
            'from' => !empty($q['from']) ? (int) strtotime((string) $q['from']) : 0,
            'to' => !empty($q['to']) ? (int) strtotime((string) $q['to']) : 0,
        ];
        $page = max(1, (int) ($q['page'] ?? 1));
        $res = Retention::search($filters, 50, ($page - 1) * 50);
        return $this->page('deleted', [
            'items' => $res['items'], 'total' => $res['total'], 'page' => $page, 'filters' => $filters, 'raw' => $q,
            'stats' => Retention::stats(),
            'users' => DB::all('SELECT id, email, display_name, status FROM users ORDER BY display_name, email'),
        ]);
    }

    private function row(string $id): array
    {
        $row = Retention::find((int) $id);
        if (!$row) {
            throw new HttpException(404, t('ret.not_found'));
        }
        return $row;
    }

    public function show(string $id): Response
    {
        $row = $this->row($id);
        $parsed = null;
        if (!Retention::masked($row)) {
            $parsed = MimeParser::parse(Retention::raw($row));
            Audit::log('retention.viewed', $row['user_email'], ['id' => (int) $row['id']]);
        }
        return $this->page('deleted_show', [
            'row' => $row, 'parsed' => $parsed, 'masked' => Retention::masked($row),
            'users' => DB::all("SELECT id, email, display_name FROM users WHERE status = 'active' ORDER BY display_name, email"),
            'owner' => DB::one('SELECT id, email, display_name FROM users WHERE id = :id', ['id' => (int) $row['user_id']]),
        ]);
    }

    /** Sanitized body for the preview frame: no script, no remote content. */
    public function body(string $id): Response
    {
        $row = $this->row($id);
        if (Retention::masked($row)) {
            throw new HttpException(403, t('ret.personal'));
        }
        $parsed = MimeParser::parse(Retention::raw($row));
        $html = (new HtmlSanitizer(true, static fn() => 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=='))->sanitize($parsed->html());
        $doc = '<!DOCTYPE html><html><head><meta charset="utf-8"><base target="_blank"><style>body{margin:0;padding:12px;font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif;font-size:14px;line-height:1.55;color:#1f2937;background:#fff;overflow-wrap:anywhere}img{max-width:100%;height:auto}blockquote{margin:8px 0 8px 4px;padding-left:12px;border-left:3px solid #cbd5e1;color:#475569}.m4w-blocked{background:#f1f5f9;outline:1px dashed #cbd5e1;min-width:16px;min-height:16px}</style></head><body>' . $html . '</body></html>';
        return new Response($doc, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'; sandbox allow-popups allow-popups-to-escape-sandbox",
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function eml(string $id): Response
    {
        $row = $this->row($id);
        if (Retention::masked($row)) {
            throw new HttpException(403, t('ret.personal'));
        }
        Audit::log('retention.downloaded', $row['user_email'], ['id' => (int) $row['id']]);
        $name = preg_replace('/[^\w\- ]+/u', '_', mb_substr($row['subject'] ?: 'message', 0, 60)) . '.eml';
        return Response::download(Retention::raw($row), 'message/rfc822', $name);
    }

    public function restore(): Response
    {
        $ids = $this->req->int('id') ? [$this->req->int('id')] : array_slice(array_map('intval', $this->req->arr('ids')), 0, 500);
        $target = $this->req->int('target') ?: null;
        $ok = 0;
        $errors = [];
        foreach ($ids as $id) {
            try {
                Retention::restore($id, $this->actor(), $target);
                $ok++;
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
        $back = $this->req->str('back') === 'show' && count($ids) === 1 ? '/admin/deleted/' . $ids[0] : '/admin/deleted';
        if ($errors && !$ok) {
            return $this->back($back, 'danger', implode(' ', array_unique($errors)));
        }
        return $this->back($back, $errors ? 'warning' : 'success', t('ret.restored', ['n' => $ok]) . ($errors ? ' ' . implode(' ', array_unique($errors)) : ''));
    }

    public function purge(): Response
    {
        $ids = $this->req->int('id') ? [$this->req->int('id')] : array_slice(array_map('intval', $this->req->arr('ids')), 0, 500);
        foreach ($ids as $id) {
            Retention::destroy($id, $this->actor());
        }
        return $this->back('/admin/deleted', 'success', t('ret.purged', ['n' => count($ids)]));
    }

    public function settings(): Response
    {
        $r = $this->req;
        foreach ([
            'retention.enabled' => $r->bool('enabled') ? 1 : 0,
            'retention.days' => max(0, min(3650, $r->int('days', 365))),
            'retention.keep_spam' => $r->bool('keep_spam') ? 1 : 0,
            'retention.on_account_delete' => $r->bool('on_account_delete') ? 1 : 0,
        ] as $k => $v) {
            Settings::set($k, $v);
        }
        Audit::log('retention.settings');
        return $this->back('/admin/deleted', 'success', t('settings.saved'));
    }
}
