<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Mail\ParsedMessage;

/**
 * What became of each message sent, recipient by recipient: handed over to the sending server,
 * waiting (the receiver asked to try later, greylisting…), delivered, or refused and why.
 *
 * When sending, delivery reports are asked of the server (SMTP DSN, RFC 3461: SmtpClient adds
 * them when the server offers it, with the message's Message-ID as envelope id). The reports
 * that come back (RFC 3464) update the status here: the good news ("delivered", "delayed") is
 * not filed in the mailbox, a refusal is (the person must see it).
 */
final class DeliveryReports
{
    public const STATES = ['queued', 'handed', 'delayed', 'relayed', 'delivered', 'failed'];

    private static function messageId(string $raw): string
    {
        $head = substr($raw, 0, (int) (strpos(str_replace("\r\n", "\n", $raw), "\n\n") ?: 65536));
        $head = (string) preg_replace("/\r?\n[ \t]+/", ' ', $head);
        return preg_match('/^Message-ID:\s*<([^<>\s]+)>/mi', $head, $m) ? mb_substr($m[1], 0, 250) : '';
    }

    private static function set(int $userId, string $msgid, string $rcpt, string $state, string $detail = '', bool $dsn = false): void
    {
        $rcpt = mb_strtolower(trim($rcpt));
        if ($msgid === '' || $rcpt === '') {
            return;
        }
        $row = DB::one('SELECT * FROM delivery_status WHERE user_id = :u AND message_id = :m AND recipient = :r', ['u' => $userId, 'm' => $msgid, 'r' => $rcpt]);
        $data = ['state' => $state, 'detail' => mb_substr($detail, 0, 500), 'updated_at' => time()];
        if ($row) {
            // A late "delayed" never hides a delivery or a refusal already known.
            if ($state === 'delayed' && in_array($row['state'], ['delivered', 'relayed', 'failed'], true)) {
                return;
            }
            DB::update('delivery_status', $data + ($dsn ? ['dsn' => 1] : []), 'id = :id', ['id' => $row['id']]);
        } else {
            DB::insert('delivery_status', $data + ['user_id' => $userId, 'message_id' => $msgid, 'recipient' => mb_substr($rcpt, 0, 255), 'dsn' => $dsn ? 1 : 0, 'created_at' => time()]);
        }
    }

    /**
     * After a sending attempt (Transport::send): delivered here, handed over to the sending
     * server (with or without delivery reports asked), waiting in our queue, or refused.
     */
    public static function recordSend(?int $userId, string $raw, array $local, array $remote, array $failed, array $deferred, bool $dsn): void
    {
        if (!$userId) {
            return;
        }
        $msgid = self::messageId($raw);
        if ($msgid === '') {
            return;
        }
        foreach ($local as $r) {
            self::set($userId, $msgid, $r, 'delivered', 'local');
        }
        foreach ($remote as $r) {
            self::set($userId, $msgid, $r, 'handed', '', $dsn);
        }
        foreach ($deferred as $r) {
            self::set($userId, $msgid, $r, 'queued');
        }
        foreach ($failed as $r => $why) {
            self::set($userId, $msgid, (string) $r, 'failed', (string) $why);
        }
    }

    /**
     * A delivery report received by $userId about mail they sent: statuses updated. Returns
     * 'quiet' when it only brings good news (delivered, relayed, delayed: not filed), 'failed'
     * when something was refused (filed as usual), null when it is not a report of ours.
     */
    public static function consume(int $userId, ParsedMessage $p): ?string
    {
        $type = strtolower((string) $p->header('Content-Type'));
        if (!str_contains($type, 'multipart/report') || !str_contains($type, 'delivery-status')) {
            return null;
        }
        $status = '';
        $headers = '';
        foreach ($p->attachmentParts() as $part) {
            if (preg_match('#^message/(global-)?delivery-status#i', (string) $part->type)) {
                $status .= "\n" . $part->decodedBody();
            } elseif (preg_match('#^(text/rfc822-headers|message/rfc822)#i', (string) $part->type)) {
                $headers .= "\n" . $part->decodedBody();
            }
        }
        if ($status === '') {
            return null;
        }
        $status = str_replace("\r\n", "\n", $status);
        $msgid = '';
        if (preg_match('/^Original-Envelope-Id:\s*(\S+)/mi', $status, $m)) {
            $msgid = self::xtextDecode(trim($m[1], '<>'));
            $msgid = trim($msgid, '<>');
        }
        if ($msgid === '' && preg_match('/^Message-ID:\s*<([^<>\s]+)>/mi', (string) preg_replace("/\r?\n[ \t]+/", ' ', $headers), $m)) {
            $msgid = $m[1];
        }
        if ($msgid === '' || !DB::value('SELECT COUNT(*) FROM delivery_status WHERE user_id = :u AND message_id = :m', ['u' => $userId, 'm' => $msgid])) {
            return null; // not about a message of ours that is followed
        }
        $mta = preg_match('/^Reporting-MTA:\s*(?:dns;)?\s*(\S+)/mi', $status, $m) ? $m[1] : '';
        $anyFailed = false;
        $seen = false;
        // One block per recipient, separated by blank lines.
        foreach (preg_split('/\n\s*\n/', $status) as $block) {
            $block = (string) preg_replace("/\n[ \t]+/", ' ', $block);
            if (!preg_match('/^(?:Final|Original)-Recipient:\s*(?:rfc822;)?\s*<?([^\s<>;]+@[^\s<>;]+)>?/mi', $block, $r)) {
                continue;
            }
            $action = preg_match('/^Action:\s*(\w+)/mi', $block, $a) ? strtolower($a[1]) : '';
            $code = preg_match('/^Status:\s*([245]\.\d+\.\d+)/mi', $block, $c) ? $c[1] : '';
            $diag = preg_match('/^Diagnostic-Code:\s*(?:\w+;)?\s*(.+)$/mi', $block, $d) ? trim($d[1]) : '';
            $remote = preg_match('/^Remote-MTA:\s*(?:dns;)?\s*(\S+)/mi', $block, $x) ? $x[1] : $mta;
            $state = match ($action) {
                'delivered' => 'delivered',
                'relayed', 'expanded' => 'relayed',
                'delayed' => 'delayed',
                'failed' => 'failed',
                default => str_starts_with($code, '5') ? 'failed' : (str_starts_with($code, '4') ? 'delayed' : ''),
            };
            if ($state === '') {
                continue;
            }
            $seen = true;
            $anyFailed = $anyFailed || $state === 'failed';
            $detail = trim(implode(' · ', array_filter([$code, $diag, $remote !== '' ? $remote : null])));
            self::set($userId, $msgid, self::xtextDecode($r[1]), $state, $detail, true);
            if ($state === 'failed' && HardBounces::isHard('550 ' . $code . ' ' . $diag)) {
                HardBounces::record(self::xtextDecode($r[1]), trim($code . ' ' . $diag), 'dsn');
            }
        }
        if (!$seen) {
            return null;
        }
        return $anyFailed ? 'failed' : 'quiet';
    }

    /** RFC 3461 xtext: "+HH" for the characters that cannot be written as they are. */
    public static function xtext(string $s): string
    {
        $out = '';
        foreach (str_split($s) as $ch) {
            $o = ord($ch);
            $out .= ($o < 33 || $o > 126 || $ch === '+' || $ch === '=') ? sprintf('+%02X', $o) : $ch;
        }
        return $out;
    }

    public static function xtextDecode(string $s): string
    {
        return (string) preg_replace_callback('/\+([0-9A-Fa-f]{2})/', static fn($m) => chr(hexdec($m[1])), $s);
    }

    /** Status of each recipient of a message sent by this person (reader of the Sent folder). */
    public static function forMessage(int $userId, string $msgid): array
    {
        if ($msgid === '') {
            return [];
        }
        return array_map(static fn($r) => ['recipient' => $r['recipient'], 'state' => $r['state'], 'detail' => $r['detail'], 'dsn' => (int) $r['dsn'], 'at' => (int) $r['updated_at']],
            DB::all('SELECT * FROM delivery_status WHERE user_id = :u AND message_id = :m ORDER BY recipient', ['u' => $userId, 'm' => $msgid]));
    }

    /** Counts by state for a domain's senders lately (domain health page). */
    public static function domainTotals(string $domain, int $days = 7): array
    {
        $out = array_fill_keys(self::STATES, 0);
        foreach (DB::all('SELECT s.state, COUNT(*) n FROM delivery_status s JOIN users u ON u.id = s.user_id WHERE LOWER(u.email) LIKE :d AND s.created_at > :t GROUP BY s.state',
            ['d' => '%@' . mb_strtolower($domain), 't' => time() - $days * 86400]) as $r) {
            $out[$r['state']] = (int) $r['n'];
        }
        return $out;
    }

    public static function gc(int $days = 90): void
    {
        DB::run('DELETE FROM delivery_status WHERE updated_at < :t', ['t' => time() - $days * 86400]);
    }
}
