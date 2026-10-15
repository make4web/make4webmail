<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\Audit;
use M4W\Core\Response;
use M4W\Core\Session;
use M4W\Mail\LinkCheck;
use M4W\Service\LinkGuard;

/** Click on a link of a received message: straight through, warning, or blocked. */
final class LinkController extends Controller
{
    public function go(): Response
    {
        $u = $this->user();
        Session::close();
        $q = $this->req->query;
        $href = (string) ($q['u'] ?? '');
        $text = (string) ($q['t'] ?? '');
        $mid = (int) ($q['m'] ?? 0);
        if (!preg_match('#^https?://#i', $href) || !LinkGuard::verify($href, $text, $mid, (int) $u['id'], (string) ($q['s'] ?? '')) || !\M4W\Service\Mailbox::get((int) $u['id'], $mid)) {
            // Not a link we signed: never redirect (no open redirect).
            return $this->page(['href' => '', 'host' => '', 'text' => '', 'problems' => [], 'threat' => 'INVALID'], 400);
        }
        $a = LinkGuard::assess($href, $text);
        if ($a['threat'] === '' && !$a['problems']) {
            return (new Response('', 302, ['Location' => $href]))->header('Referrer-Policy', 'no-referrer');
        }
        if ($a['threat'] !== '') {
            Audit::log('link.blocked', $a['host'], ['threat' => $a['threat'], 'message' => $mid], (int) $u['id']);
        }
        return $this->page(['href' => $href, 'host' => $a['host'], 'text' => $text, 'problems' => $a['problems'], 'threat' => $a['threat']]);
    }

    private function page(array $d, int $status = 200): Response
    {
        // Never show a look-alike name as the destination: its real (punycode) form instead.
        $d['display_href'] = $d['href'];
        if ($d['host'] !== '' && \M4W\Mail\Homograph::isIdn($d['host'])) {
            $ascii = \M4W\Mail\Homograph::ascii(\M4W\Mail\Homograph::unicode($d['host']));
            $d['display_href'] = preg_replace('#^(https?://(?:[^/@?\#]*@)?)[^/:?\#]+#i', '${1}' . $ascii, $d['href']) ?? $d['href'];
        }
        $d['registrable'] = LinkCheck::registrable($d['host']);
        $d['shown'] = LinkCheck::textDomain($d['text']);
        // An internationalised name is shown as read (look-alike letters marked) and as sent.
        $d['idn'] = $d['host'] !== '' && \M4W\Mail\Homograph::isIdn($d['host'])
            ? ['html' => \M4W\Mail\Homograph::highlight($d['host']), 'ascii' => \M4W\Mail\Homograph::ascii(\M4W\Mail\Homograph::unicode($d['host'])), 'imitates' => \M4W\Mail\Homograph::imitates($d['host'])]
            : null;
        if ($d['idn']) {
            $d['host'] = $d['idn']['ascii'];
            $d['registrable'] = LinkCheck::registrable($d['host']);
        }
        $r = $this->view('mail/link', $d, 'layouts/blank');
        $r->status = $status;
        return $r->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'private, no-store');
    }
}
