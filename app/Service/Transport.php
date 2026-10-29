<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Mail\Address;
use M4W\Mail\DkimSigner;
use M4W\Mail\SmtpClient;
use M4W\Mail\SmtpException;

/**
 * Outgoing transport: local delivery for hosted domains, SMTP relay (or direct MX) for the rest,
 * with a persistent retry queue.
 */
final class Transport
{
    /**
     * Deliver a raw message now.
     * @param string[] $recipients
     * Collector mode: recipients beyond the provider's hourly limit are "deferred" and,
     * unless the call comes from the queue itself, queued until the window reopens.
     * @return array{sent:string[],failed:array<string,string>,deferred:string[],retry_at:int}
     */
    public static function send(string $raw, string $envelopeFrom, array $recipients, ?int $userId = null, bool $sign = true, bool $fromQueue = false, string $kind = 'mail'): array
    {
        // Sending limits and blocked accounts (what was queued was checked when queued).
        if ($userId && !$fromQueue) {
            AccountGuard::checkSend($userId, $recipients, $kind, true);
        }
        // Webmail mode: the provider signs and delivers everything, colleagues included,
        // so their own mailbox (and their phone) receives it too.
        $webmail = RemoteMailbox::active();
        $collector = Collector::active();
        // A user whose own mailbox is imposed sends through it: the provider signs.
        $personal = $webmail ? null : RemoteMailbox::personal($userId);
        if ($sign && !$webmail && !$collector && !$personal) {
            $raw = self::dkim($raw);
        }
        $local = [];
        $remote = [];
        // Addresses known not to exist: never tried again (refused here, for each one).
        $gone = HardBounces::among($recipients);
        foreach (array_unique($recipients) as $r) {
            if (isset($gone[mb_strtolower($r)])) {
                continue;
            }
            if ($collector) {
                // Mailboxes only exist here: colleagues are served at once, without using the
                // provider's hourly quota. Other addresses of the domain stay at the provider.
                $isLocal = Users::resolveLocal($r) !== null;
            } else {
                $isLocal = !$webmail && (int) Settings::get('smtp.local_delivery', 1) && Users::isLocalDomain(Address::domain($r));
            }
            if ($isLocal) {
                $local[] = $r;
            } else {
                $remote[] = $r;
            }
        }
        $sent = [];
        $failed = [];
        foreach (array_unique($recipients) as $r) {
            if (isset($gone[mb_strtolower($r)])) {
                $failed[$r] = t('bounce.refused', ['email' => $r, 'date' => format_datetime((int) $gone[mb_strtolower($r)]['first_at'], false)]);
            }
        }
        $deferred = [];
        $retryAt = 0;
        $localSent = [];
        SmtpClient::$lastDsn = false;
        if ($local) {
            $res = Delivery::deliver($raw, $envelopeFrom, $local, 'local');
            foreach ($res as $rcpt => $status) {
                if ($status === 'ok') {
                    $sent[] = $rcpt;
                    $localSent[] = $rcpt;
                } else {
                    $failed[$rcpt] = $status;
                }
            }
        }
        if ($remote) {
            $r = match (true) {
                $personal !== null => RemoteMailbox::sendPersonal($personal, $raw, $envelopeFrom, $remote, (int) $userId, $fromQueue, $kind),
                $collector => Collector::send($raw, $remote, $userId, $fromQueue, $kind),
                default => self::sendRemote($raw, $envelopeFrom, $remote, $userId),
            };
            $sent = array_merge($sent, $r['sent']);
            $failed += $r['failed'];
            // The address does not exist: remembered (the refusals given above are not new).
            try {
                HardBounces::fromFailures($r['failed']);
            } catch (\Throwable $e) {
                error_log('[m4w] hard bounces: ' . $e->getMessage());
            }
            $deferred = $r['deferred'] ?? [];
            $retryAt = (int) ($r['retry_at'] ?? 0);
            $heldNote = (string) ($r['note'] ?? Collector::HELD);
        }
        if ($deferred && !$fromQueue) {
            self::enqueue($raw, $envelopeFrom, $deferred, $userId, $kind, $retryAt, $heldNote ?? Collector::HELD);
        }
        // What became of it, recipient by recipient (shown with the message in Sent).
        try {
            DeliveryReports::recordSend($userId, $raw, $localSent, array_values(array_diff($sent, $localSent)), $failed, $deferred, SmtpClient::$lastDsn);
        } catch (\Throwable $e) {
            error_log('[m4w] delivery status: ' . $e->getMessage());
        }
        $status = $failed ? (count($sent) ? 'partial' : 'failed') : ($deferred ? 'held' : 'ok');
        self::log('out', $userId, $envelopeFrom, $recipients, $raw, $status, $failed ? implode('; ', $failed) : ($deferred ? t('col.log_held', ['n' => count($deferred), 'time' => date('H:i', $retryAt)]) : ''));
        return ['sent' => $sent, 'failed' => $failed, 'deferred' => $deferred, 'retry_at' => $retryAt, 'note' => $heldNote ?? Collector::HELD];
    }

    /** @return array{sent:string[],failed:array<string,string>} */
    private static function sendRemote(string $raw, string $from, array $rcpts, ?int $userId): array
    {
        if (RemoteMailbox::active()) {
            return RemoteMailbox::send($raw, $from, $rcpts, $userId);
        }
        // An explicit "server" mode ignores a relay left configured from before.
        $host = RemoteMailbox::mode() === 'server' ? '' : trim((string) Settings::get('smtp.host', ''));
        if ($host !== '') {
            $username = (string) Settings::get('smtp.username', '');
            $password = Settings::secret('smtp.password');
            $client = new SmtpClient(
                $host,
                (int) Settings::get('smtp.port', 587),
                (string) Settings::get('smtp.security', 'tls'),
                (int) Settings::get('smtp.auth', 1) ? $username : '',
                $password,
                (int) Settings::get('smtp.timeout', 20),
                (bool) Settings::get('smtp.verify_peer', 1),
                (string) Settings::get('smtp.helo', '')
            );
            try {
                $client->connect();
                $rejected = $client->send($from, $rcpts, $raw);
                $client->quit();
                return ['sent' => array_values(array_diff($rcpts, array_keys($rejected))), 'failed' => $rejected];
            } catch (SmtpException $e) {
                return ['sent' => [], 'failed' => array_fill_keys($rcpts, $e->getMessage())];
            }
        }
        // Direct MX delivery (no relay configured).
        $byDomain = [];
        foreach ($rcpts as $r) {
            $byDomain[Address::domain($r)][] = $r;
        }
        $sent = [];
        $failed = [];
        foreach ($byDomain as $domain => $list) {
            $mxs = self::mxHosts($domain);
            $lastError = 'Aucun serveur MX pour ' . $domain;
            $done = false;
            foreach (array_slice($mxs, 0, 3) as $mx) {
                $client = new SmtpClient($mx, 25, 'none', '', '', 15, false, (string) Settings::get('smtp.helo', ''), true);
                try {
                    $client->connect();
                    $rejected = $client->send($from, $list, $raw);
                    $client->quit();
                    $sent = array_merge($sent, array_values(array_diff($list, array_keys($rejected))));
                    $failed += $rejected;
                    $done = true;
                    break;
                } catch (SmtpException $e) {
                    $lastError = $e->getMessage();
                    if ($e->permanent) {
                        break;
                    }
                }
            }
            if (!$done) {
                foreach ($list as $r) {
                    $failed[$r] = $lastError;
                }
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    private static function mxHosts(string $domain): array
    {
        $hosts = [];
        $weights = [];
        if (function_exists('getmxrr') && @getmxrr($domain, $hosts, $weights) && $hosts) {
            array_multisort($weights, SORT_ASC, $hosts);
            return $hosts;
        }
        return [$domain];
    }

    public static function dkim(string $raw): string
    {
        $domain = trim((string) Settings::get('smtp.dkim_domain', ''));
        $selector = trim((string) Settings::get('smtp.dkim_selector', ''));
        $key = Settings::secret('smtp.dkim_private');
        if ($domain === '' || $selector === '' || $key === '' || str_starts_with($raw, 'DKIM-Signature:')) {
            return $raw;
        }
        // Only sign what we author: the From: domain must align with the signing domain (DMARC relaxed).
        $from = mb_strtolower(Address::domain(self::headerFrom($raw)));
        $domain = mb_strtolower($domain);
        if ($from === '' || ($from !== $domain && !str_ends_with($from, '.' . $domain))) {
            return $raw;
        }
        return (new DkimSigner($domain, $selector, $key))->sign($raw);
    }

    /** Address of the first From: header of a raw message ('' if none). */
    public static function headerFrom(string $raw): string
    {
        $end = strpos($raw, "\r\n\r\n");
        $head = $end === false ? $raw : substr($raw, 0, $end);
        $head = preg_replace('/\r?\n[ \t]+/', ' ', $head) ?? $head;
        if (!preg_match('/^From:[ \t]*(.+)$/mi', $head, $m)) {
            return '';
        }
        $list = Address::parseList(trim($m[1]));
        return (string) ($list[0]['email'] ?? '');
    }

    /** Queue a message for asynchronous delivery (forwards, auto-replies, retries). */
    public static function enqueue(string $raw, string $envelopeFrom, array $recipients, ?int $userId = null, string $kind = 'mail', int $at = 0, string $note = ''): int
    {
        // Forwards, invitations, automatic answers of a blocked or over-limit account do not leave.
        // (Messages deferred by send() were counted there.)
        if ($userId && $note === '' && !AccountGuard::checkSend($userId, $recipients, $kind, false)) {
            return 0;
        }
        $rel = 'queue/' . date('Ymd') . '-' . bin2hex(random_bytes(10)) . '.eml';
        $path = storage_path($rel);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        file_put_contents($path, $raw, LOCK_EX);
        return DB::insert('mail_queue', [
            'user_id'         => $userId,
            'kind'            => $kind,
            'envelope_from'   => $envelopeFrom,
            'recipients'      => json_encode(array_values(array_unique($recipients))),
            'raw_path'        => $rel,
            'status'          => 'pending',
            'attempts'        => 0,
            'next_attempt_at' => $at ?: time(),
            'last_error'      => $note,
            'created_at'      => time(),
        ]);
    }

    /** A message taken for this long without an outcome: its worker died while sending it. */
    public const STALE_SECONDS = 300;
    /** Times a message may stop the worker before it is set aside ("parked"). */
    public const MAX_CRASHES = 2;

    /**
     * Send what is due, oldest first. What the sending itself adds to the queue (a rule passing a
     * message on, an automatic answer) is taken in the same run, until the queue is empty or
     * $seconds have gone: a chain of rules does not wait one scheduled run per step.
     * Returns the number of messages processed.
     */
    public static function processQueue(int $max = 50, int $seconds = 10): int
    {
        self::recoverStale();
        $until = microtime(true) + max(1, $seconds);
        $n = 0;
        $seen = [];
        while ($n < $max && microtime(true) < $until) {
            $rows = DB::all(
                "SELECT * FROM mail_queue WHERE status = 'pending' AND next_attempt_at <= :n ORDER BY id LIMIT " . (int) min(50, $max - $n),
                ['n' => time()]
            );
            $rows = array_values(array_filter($rows, static fn($r) => !isset($seen[(int) $r['id']])));
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $seen[(int) $row['id']] = true;
                if (self::processOne($row)) {
                    $n++;
                }
            }
        }
        return $n;
    }

    /**
     * Messages left "sending" by a worker that died (fatal error, memory, killed): back in the
     * queue, or set aside after MAX_CRASHES — logged, shown in Supervision — so that one message
     * never blocks the others.
     */
    public static function recoverStale(): int
    {
        $rows = DB::all("SELECT id, crashes, recipients, kind FROM mail_queue WHERE status = 'sending' AND claimed_at < :t", ['t' => time() - self::STALE_SECONDS]);
        foreach ($rows as $r) {
            $crashes = (int) $r['crashes'] + 1;
            if ($crashes >= self::MAX_CRASHES) {
                $why = 'parked: sending it stopped the worker ' . $crashes . ' times';
                DB::update('mail_queue', ['status' => 'parked', 'crashes' => $crashes, 'last_error' => $why], 'id = :id', ['id' => $r['id']]);
                error_log('[m4w] queue: message ' . $r['id'] . ' ' . $why);
                \M4W\Core\Audit::log('queue.parked', '#' . $r['id'] . ' ' . mb_substr((string) $r['recipients'], 0, 200), ['kind' => $r['kind'], 'crashes' => $crashes]);
            } else {
                DB::update('mail_queue', ['status' => 'pending', 'crashes' => $crashes, 'next_attempt_at' => time() + 60, 'last_error' => 'worker stopped while sending, retried'], 'id = :id', ['id' => $r['id']]);
            }
        }
        return count($rows);
    }

    /** Send one queue entry (claimed first: several workers may run). Whether it was processed. */
    private static function processOne(array $row): bool
    {
        // Claim the row to avoid double sending from concurrent workers.
        $claimed = DB::run("UPDATE mail_queue SET status = 'sending', claimed_at = :t WHERE id = :id AND status = 'pending'", ['id' => $row['id'], 't' => time()])->rowCount();
        if (!$claimed) {
            return false;
        }
        $path = storage_path($row['raw_path']);
        $raw = is_file($path) ? (string) file_get_contents($path) : '';
        if ($raw === '') {
            DB::update('mail_queue', ['status' => 'failed', 'last_error' => 'message file missing'], 'id = :id', ['id' => $row['id']]);
            return true;
        }
        $rcpts = json_decode((string) $row['recipients'], true) ?: [];
        try {
            // Relayed mail keeps its author's From: and their own signature; ours would vouch for it.
            $res = self::send($raw, $row['envelope_from'], $rcpts, $row['user_id'] !== null ? (int) $row['user_id'] : null, $row['kind'] !== 'forward', true, (string) $row['kind']);
        } catch (\Throwable $e) {
            DB::update('mail_queue', [
                'status' => 'pending', 'attempts' => (int) $row['attempts'] + 1, 'last_error' => mb_substr($e->getMessage(), 0, 500),
                'next_attempt_at' => time() + 300,
            ], 'id = :id', ['id' => $row['id']]);
            return true;
        }
        if ($res['deferred']) {
            // Hourly limit of the provider: wait for the window, it is not a failed attempt.
            DB::update('mail_queue', [
                'status' => 'pending', 'last_error' => $res['note'] ?? Collector::HELD, 'attempts' => (int) $row['attempts'] + ($res['failed'] ? 1 : 0),
                'recipients' => json_encode(array_values(array_merge($res['deferred'], array_keys($res['failed'])))), 'next_attempt_at' => max(time() + 30, $res['retry_at']),
            ], 'id = :id', ['id' => $row['id']]);
            return true;
        }
        $attempts = (int) $row['attempts'] + 1;
        if (!$res['failed']) {
            DB::update('mail_queue', ['status' => 'sent', 'attempts' => $attempts, 'sent_at' => time(), 'last_error' => ''], 'id = :id', ['id' => $row['id']]);
            @unlink($path);
            return true;
        }
        $remaining = array_keys($res['failed']);
        $error = mb_substr(implode('; ', $res['failed']), 0, 500);
        $permanent = (bool) preg_match('/SMTP 5\d\d/', $error);
        if ($permanent || $attempts >= 8) {
            DB::update('mail_queue', ['status' => 'failed', 'attempts' => $attempts, 'last_error' => $error], 'id = :id', ['id' => $row['id']]);
            self::bounce($row, $raw, $remaining, $error);
        } else {
            $delay = min(6 * 3600, 60 * (2 ** $attempts));
            DB::update('mail_queue', [
                'status' => 'pending', 'attempts' => $attempts, 'last_error' => $error,
                'recipients' => json_encode($remaining), 'next_attempt_at' => time() + $delay,
            ], 'id = :id', ['id' => $row['id']]);
        }
        return true;
    }

    /** Notify a local sender that a queued message could not be delivered. */
    private static function bounce(array $row, string $raw, array $rcpts, string $error): void
    {
        if ($row['kind'] !== 'mail' || $row['user_id'] === null) {
            return;
        }
        $user = Users::find((int) $row['user_id']);
        if (!$user) {
            return;
        }
        $b = new \M4W\Mail\MimeBuilder();
        $b->from = ['email' => 'mailer-daemon@' . Address::domain($user['email']), 'name' => 'Mail Delivery System'];
        $b->to = [['email' => $user['email'], 'name' => $user['name']]];
        $b->subject = 'Échec de distribution / Delivery failure';
        $b->html = '<p>Votre message n\'a pas pu être remis aux destinataires suivants :</p><ul><li>'
            . implode('</li><li>', array_map('e', $rcpts)) . '</li></ul><p><code>' . e($error) . '</code></p>';
        $b->messageId = \M4W\Mail\MimeBuilder::newMessageId(Address::domain($user['email']));
        $b->extraHeaders['Auto-Submitted'] = 'auto-replied';
        $b->attachments[] = ['name' => 'message.eml', 'mime' => 'message/rfc822', 'content' => $raw];
        $inbox = Folders::byRole((int) $user['id'], 'inbox');
        Mailbox::store((int) $user['id'], (int) $inbox['id'], $b->build());
    }

    public static function log(string $direction, ?int $userId, string $sender, array $rcpts, string $raw, string $status, string $info = ''): void
    {
        $subject = '';
        if (preg_match('/^Subject:\s*(.*(?:\r?\n[ \t].*)*)/mi', substr($raw, 0, 16384), $m)) {
            $subject = trim(\M4W\Mail\Charset::decodeHeader(preg_replace('/\r?\n[ \t]+/', ' ', $m[1]) ?? ''));
        }
        try {
            DB::insert('mail_log', [
                'direction'  => $direction,
                'user_id'    => $userId,
                'sender'     => mb_substr($sender, 0, 255),
                'recipients' => mb_substr(implode(', ', $rcpts), 0, 5000),
                'subject'    => mb_substr($subject, 0, 500),
                'size'       => strlen($raw),
                'status'     => $status,
                'info'       => mb_substr($info, 0, 500),
                'created_at' => time(),
            ]);
        } catch (\Throwable) {
        }
    }
}
