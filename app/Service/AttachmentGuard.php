<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Mail\MimeParser;

/**
 * Antivirus pass on received messages. Each attached file is scanned by ClamAV; an
 * infected one is replaced in the stored message by a short notice that keeps its name
 * and the signature found, so the attachment list still shows it — as removed — and
 * the virus can never come back out (download, forward, .eml export, Files…).
 *
 * The result is stamped in an X-M4W-AV header (any copy of it sent by outsiders is
 * dropped first).
 */
final class AttachmentGuard
{
    public const HEADER = 'X-M4W-AV';
    private const MAX_DEPTH = 12;

    private bool $scan = true;
    private int $infected = 0;
    private int $errors = 0;
    private int $scanned = 0;
    /** @var string[] */
    private array $found = [];

    /** @return array{raw:string,infected:int,errors:int,scanned:int,signatures:string[]} */
    public static function process(string $raw): array
    {
        $raw = Delivery::stripHeader(str_replace(["\r\n", "\r"], "\n", $raw), self::HEADER);
        $raw = Delivery::stripHeader($raw, 'X-M4W-Removed');
        $g = new self();
        // Antivirus off: the tree is still walked, to drop part markers forged by the sender.
        $g->scan = Antivirus::enabled();
        [$head, $body] = self::split($raw);
        $body = $g->entityBody($head, $body, 0);
        if (!$g->scan) {
            return ['raw' => $head . "\n\n" . $body, 'infected' => 0, 'errors' => 0, 'scanned' => 0, 'signatures' => []];
        }
        $status = $g->infected ? 'infected=' . $g->infected : ($g->errors ? 'unscanned' : ($g->scanned ? 'clean' : 'none'));
        $out = self::HEADER . ': ' . $status . "\n" . $head . "\n\n" . $body;
        return ['raw' => $out, 'infected' => $g->infected, 'errors' => $g->errors, 'scanned' => $g->scanned, 'signatures' => $g->found];
    }

    /** @return array{string,string} headers, body (without the blank line) */
    private static function split(string $entity): array
    {
        $pos = strpos($entity, "\n\n");
        return $pos === false ? [rtrim($entity, "\n"), ''] : [substr($entity, 0, $pos), substr($entity, $pos + 2)];
    }

    private static function headerValue(string $head, string $name): string
    {
        $unfolded = preg_replace('/\n[ \t]+/', ' ', $head) ?? $head;
        return preg_match('/^' . preg_quote($name, '/') . ':[ \t]*(.*)$/mi', $unfolded, $m) ? trim($m[1]) : '';
    }

    private function entityBody(string $head, string $body, int $depth): string
    {
        $ct = self::headerValue($head, 'Content-Type');
        if ($depth < self::MAX_DEPTH && stripos($ct, 'multipart/') === 0 && preg_match('/boundary\s*=\s*(?:"([^"]+)"|([^;\s]+))/i', $ct, $m)) {
            return $this->multipart($body, $m[1] !== '' ? $m[1] : $m[2], $depth);
        }
        return $body;
    }

    /** Rebuild a multipart body, part by part, with the same delimiters. */
    private function multipart(string $body, string $boundary, int $depth): string
    {
        $lines = explode("\n", $body);
        $out = [];
        $part = null;
        $delim = '--' . $boundary;
        foreach ($lines as $line) {
            $t = rtrim($line, " \t");
            if ($t === $delim || $t === $delim . '--') {
                if ($part !== null) {
                    $out[] = $this->part(implode("\n", $part), $depth);
                }
                $out[] = $line;
                $part = $t === $delim ? [] : null;
                continue;
            }
            if ($part !== null) {
                $part[] = $line;
            } else {
                $out[] = $line; // preamble / epilogue
            }
        }
        if ($part !== null) {
            $out[] = $this->part(implode("\n", $part), $depth); // unterminated last part
        }
        return implode("\n", $out);
    }

    private function part(string $entity, int $depth): string
    {
        [$head, $body] = self::split($entity);
        // Only this pass may mark a part as removed: a forged marker from the sender is dropped.
        if (stripos($head, 'X-M4W-Removed') !== false) {
            $head = preg_replace('/^X-M4W-Removed:.*(?:\n[ \t].*)*\n?/mi', '', $head) ?? $head;
            $entity = $head . "\n\n" . $body;
        }
        $ct = strtolower(self::headerValue($head, 'Content-Type'));
        if (str_starts_with($ct, 'multipart/')) {
            return $head . "\n\n" . $this->entityBody($head, $body, $depth + 1);
        }
        $disp = strtolower(self::headerValue($head, 'Content-Disposition'));
        $isFile = str_starts_with($disp, 'attachment') || str_contains($disp, 'filename') || str_contains($ct, 'name=')
            || ($ct !== '' && !str_starts_with($ct, 'text/plain') && !str_starts_with($ct, 'text/html') && !str_starts_with($ct, 'multipart/'));
        if (!$isFile || !$this->scan) {
            return $entity;
        }
        $p = MimeParser::parse($head . "\n\n" . $body);
        $bytes = $p->root->decodedBody();
        if ($bytes === '') {
            return $entity;
        }
        $res = Antivirus::scan($bytes);
        $this->scanned++;
        if ($res['status'] === 'error') {
            $this->errors++;
            return $entity;
        }
        if ($res['status'] !== 'infected') {
            return $entity;
        }
        $this->infected++;
        $this->found[] = $res['signature'];
        $name = $p->root->filename() ?: 'fichier';
        return self::notice($name, $res['signature'], strlen($bytes));
    }

    /** The stand-in left where the infected file was. */
    private static function notice(string $name, string $signature, int $size): string
    {
        $enc = static fn(string $s) => rawurlencode($s);
        $safeName = preg_replace('/[\x00-\x1f"\\\\]/', '', $name) ?? 'fichier';
        $text = t('av.notice_body', ['name' => $safeName, 'signature' => $signature]);
        return "Content-Type: text/plain; charset=utf-8\n"
            . "Content-Disposition: attachment; filename*=UTF-8''" . $enc($safeName . '.' . t('av.removed_suffix') . '.txt') . "\n"
            . "Content-Transfer-Encoding: base64\n"
            . 'X-M4W-Removed: virus; size=' . $size . "; name*=UTF-8''" . $enc($safeName) . "; signature*=UTF-8''" . $enc($signature) . "\n\n"
            . chunk_split(base64_encode($text), 76, "\n");
    }

    /** Details of a removed attachment from its part headers, or null. */
    public static function removedInfo(?string $header): ?array
    {
        if ($header === null || !preg_match('/^\s*virus\b/i', $header)) {
            return null;
        }
        $get = static fn(string $k) => preg_match("/\\b$k\\*=UTF-8''([^;\\s]*)/i", $header, $m) ? rawurldecode($m[1]) : '';
        return ['reason' => 'virus', 'name' => $get('name'), 'signature' => $get('signature'),
            'size' => preg_match('/\bsize=(\d+)/', $header, $m) ? (int) $m[1] : 0];
    }
}
