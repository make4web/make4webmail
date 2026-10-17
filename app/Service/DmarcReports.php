<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Mail\ParsedMessage;

/**
 * DMARC aggregate reports (RFC 7489, "rua"): every day, Gmail, Microsoft, Yahoo and others
 * say which servers sent mail with our domains and whether SPF / DKIM passed. Read here,
 * they show a forgotten sender (a newsletter tool, the copier) failing, or someone
 * spoofing the domain. Only reports about our own domains are kept.
 */
final class DmarcReports
{
    private const MAX_XML = 20 * 1048576;

    /** The report files a message carries (zip, gzip or plain XML). @return string[] XML documents */
    public static function attachments(ParsedMessage $p): array
    {
        $out = [];
        foreach ($p->attachmentParts() as $part) {
            $name = strtolower($part->filename());
            $type = strtolower($part->type);
            $body = $part->decodedBody();
            if ($body === '' || strlen($body) > 5 * 1048576) {
                continue;
            }
            if (str_ends_with($name, '.gz') || in_array($type, ['application/gzip', 'application/x-gzip'], true)) {
                $xml = @gzdecode($body, self::MAX_XML);
                if (is_string($xml)) {
                    $out[] = $xml;
                }
            } elseif (str_ends_with($name, '.zip') || in_array($type, ['application/zip', 'application/x-zip-compressed'], true)) {
                $tmp = tempnam(sys_get_temp_dir(), 'dmarc');
                file_put_contents($tmp, $body);
                $zip = new \ZipArchive();
                if ($zip->open($tmp) === true) {
                    for ($i = 0; $i < min(5, $zip->numFiles); $i++) {
                        $st = $zip->statIndex($i);
                        if ($st && str_ends_with(strtolower($st['name']), '.xml') && $st['size'] <= self::MAX_XML) {
                            $out[] = (string) $zip->getFromIndex($i);
                        }
                    }
                    $zip->close();
                }
                @unlink($tmp);
            } elseif (str_ends_with($name, '.xml') || in_array($type, ['text/xml', 'application/xml'], true)) {
                $out[] = $body;
            }
        }
        return array_values(array_filter($out, static fn($x) => str_contains($x, '<feedback')));
    }

    /** Messages that look like DMARC reports ("Report domain: …" subject, report attachment). */
    public static function looksLike(ParsedMessage $p): bool
    {
        return (bool) preg_match('/report\s*domain|dmarc/i', $p->subject()) && $p->attachmentParts();
    }

    /** Read the reports of a message. Returns the number of reports stored. */
    public static function ingest(ParsedMessage $p): int
    {
        $n = 0;
        foreach (self::attachments($p) as $xml) {
            try {
                $n += self::store($xml) ? 1 : 0;
            } catch (\Throwable $e) {
                error_log('[m4w] dmarc report: ' . $e->getMessage());
            }
        }
        return $n;
    }

    public static function store(string $xml): bool
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return false; // no DTD, no entities: nothing a report needs
        }
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if (!$doc || $doc->getName() !== 'feedback') {
            return false;
        }
        $domain = mb_strtolower(trim((string) $doc->policy_published->domain));
        if ($domain === '' || !Users::isLocalDomain($domain)) {
            return false;
        }
        $org = mb_substr(trim((string) $doc->report_metadata->org_name), 0, 190);
        $rid = mb_substr(trim((string) $doc->report_metadata->report_id), 0, 190);
        if ($rid === '' || DB::value('SELECT COUNT(*) FROM dmarc_reports WHERE org = :o AND report_id = :r', ['o' => $org, 'r' => $rid])) {
            return false;
        }
        $total = 0;
        $failed = 0;
        $records = [];
        foreach ($doc->record as $r) {
            $count = max(0, (int) $r->row->count);
            $dkim = strtolower((string) $r->row->policy_evaluated->dkim);
            $spf = strtolower((string) $r->row->policy_evaluated->spf);
            $total += $count;
            if ($dkim !== 'pass' && $spf !== 'pass') {
                $failed += $count;
            }
            // The selector that signed for the domain itself (lets the health page find the provider's key).
            $selector = '';
            foreach ($r->auth_results->dkim ?? [] as $d) {
                if (strcasecmp(trim((string) $d->domain), trim((string) $r->identifiers->header_from)) === 0 && trim((string) $d->selector) !== '') {
                    $selector = mb_substr(strtolower(trim((string) $d->selector)), 0, 64);
                    break;
                }
            }
            $records[] = ['dkim_selector' => $selector, 'source_ip' => mb_substr(trim((string) $r->row->source_ip), 0, 64), 'messages' => $count,
                'disposition' => mb_substr(strtolower((string) $r->row->policy_evaluated->disposition), 0, 20), 'dkim' => mb_substr($dkim, 0, 10), 'spf' => mb_substr($spf, 0, 10),
                'header_from' => mb_substr(mb_strtolower(trim((string) $r->identifiers->header_from)), 0, 190)];
            if (count($records) >= 5000) {
                break;
            }
        }
        DB::transaction(function () use ($org, $rid, $domain, $doc, $total, $failed, $records): void {
            $id = DB::insert('dmarc_reports', ['org' => $org, 'report_id' => $rid, 'domain' => $domain, 'policy' => mb_substr(strtolower((string) $doc->policy_published->p), 0, 20),
                'date_begin' => (int) $doc->report_metadata->date_range->begin, 'date_end' => (int) $doc->report_metadata->date_range->end,
                'messages' => $total, 'failed' => $failed, 'created_at' => time()]);
            foreach ($records as $rec) {
                DB::insert('dmarc_records', $rec + ['report_id' => $id]);
            }
        });
        return true;
    }

    /** Summary over the last $days: totals per domain, and the sources that fail. */
    public static function summary(int $days = 30): array
    {
        $since = time() - $days * 86400;
        $domains = DB::all('SELECT domain, SUM(messages) AS messages, SUM(failed) AS failed, COUNT(*) AS reports, COUNT(DISTINCT org) AS orgs FROM dmarc_reports WHERE date_end > :t GROUP BY domain ORDER BY domain', ['t' => $since]);
        $sources = DB::all("SELECT r.source_ip, r.header_from, SUM(r.messages) AS messages,
                SUM(CASE WHEN r.dkim = 'pass' OR r.spf = 'pass' THEN r.messages ELSE 0 END) AS passed,
                MAX(CASE WHEN r.dkim = 'pass' THEN 1 ELSE 0 END) AS dkim_ok, MAX(CASE WHEN r.spf = 'pass' THEN 1 ELSE 0 END) AS spf_ok
            FROM dmarc_records r JOIN dmarc_reports d ON d.id = r.report_id WHERE d.date_end > :t
            GROUP BY r.source_ip, r.header_from ORDER BY (SUM(r.messages) - SUM(CASE WHEN r.dkim = 'pass' OR r.spf = 'pass' THEN r.messages ELSE 0 END)) DESC, SUM(r.messages) DESC LIMIT 25", ['t' => $since]);
        $orgs = DB::all('SELECT org, COUNT(*) AS reports, MAX(date_end) AS last FROM dmarc_reports WHERE date_end > :t GROUP BY org ORDER BY reports DESC LIMIT 10', ['t' => $since]);
        return ['domains' => $domains, 'sources' => $sources, 'orgs' => $orgs];
    }

    public static function gc(): void
    {
        foreach (DB::all('SELECT id FROM dmarc_reports WHERE date_end < :t', ['t' => time() - 400 * 86400]) as $r) {
            DB::delete('dmarc_records', 'report_id = :id', ['id' => $r['id']]);
            DB::delete('dmarc_reports', 'id = :id', ['id' => $r['id']]);
        }
    }
}
