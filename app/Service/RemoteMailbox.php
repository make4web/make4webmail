<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Audit;
use M4W\Core\Crypto;
use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Mail\Address;
use M4W\Mail\ImapClient;
use M4W\Mail\MimeParser;
use M4W\Mail\SmtpClient;
use M4W\Mail\SmtpException;

/**
 * "Webmail" mode: mailboxes stay at an external provider (OVH, Infomaniak…).
 *
 * Every account is linked with credentials entered by an administrator, kept
 * encrypted and independent from the user's webmail password, so the server can
 * work on the mailbox on its own (scheduler, delegates, auto-replies):
 *   - new messages are pulled over IMAP and go through the normal local delivery
 *     pipeline (rules, out-of-office, forwarding, retention…);
 *   - everything sent on behalf of the user leaves through the provider's SMTP,
 *     authenticated as that mailbox.
 */
final class RemoteMailbox
{
    /** Provider presets: [label, imap host, imap port, imap security, smtp host, smtp port, smtp security]. */
    public const PRESETS = [
        'ovh'        => ['OVHcloud (MX Plan, Email Pro)', 'ssl0.ovh.net', 993, 'ssl', 'ssl0.ovh.net', 465, 'ssl'],
        'infomaniak' => ['Infomaniak', 'mail.infomaniak.com', 993, 'ssl', 'mail.infomaniak.com', 465, 'ssl'],
        'gandi'      => ['Gandi', 'mail.gandi.net', 993, 'ssl', 'mail.gandi.net', 465, 'ssl'],
        'ionos'      => ['IONOS', 'imap.ionos.fr', 993, 'ssl', 'smtp.ionos.fr', 465, 'ssl'],
        'o2switch'   => ['o2switch (cPanel)', '', 993, 'ssl', '', 465, 'ssl'],
        'gmail'      => ['Google Workspace / Gmail (mot de passe d\'application)', 'imap.gmail.com', 993, 'ssl', 'smtp.gmail.com', 465, 'ssl'],
        'custom'     => ['Autre fournisseur', '', 993, 'ssl', '', 465, 'ssl'],
    ];

    // --------------------------------------------------------------- mode

    /** server | relay | webmail */
    public static function mode(): string
    {
        $m = (string) Settings::get('mail.mode', '');
        if (in_array($m, ['server', 'relay', 'webmail'], true)) {
            return $m;
        }
        return trim((string) Settings::get('smtp.host', '')) !== '' ? 'relay' : 'server';
    }

    public static function active(): bool
    {
        return self::mode() === 'webmail';
    }

    // ----------------------------------------------------------- accounts

    public static function find(int $userId): ?array
    {
        return DB::one('SELECT * FROM remote_accounts WHERE user_id = :u', ['u' => $userId]);
    }

    /** Account with the instance defaults filled in (servers left empty in the account). */
    public static function effective(array $acc): array
    {
        foreach (['imap_host', 'imap_security', 'smtp_host', 'smtp_security'] as $k) {
            if ((string) $acc[$k] === '') {
                $acc[$k] = (string) Settings::get('webmail.' . $k, '');
            }
        }
        foreach (['imap_port', 'smtp_port'] as $k) {
            if ((int) $acc[$k] <= 0) {
                $acc[$k] = (int) Settings::get('webmail.' . $k, $k === 'imap_port' ? 993 : 465);
            }
        }
        if ((string) $acc['login'] === '') {
            $acc['login'] = (string) DB::value('SELECT email FROM users WHERE id = :u', ['u' => $acc['user_id']]);
        }
        return $acc;
    }

    /**
     * Create or update the link of a user (administration). An empty password keeps
     * the stored one. Server fields left empty follow the instance defaults.
     */
    public static function save(int $userId, array $d, int $by): void
    {
        $sec = static fn($v) => in_array($v, ['ssl', 'tls', 'none'], true) ? $v : '';
        $host = static function ($v): string {
            $v = trim((string) $v);
            if ($v !== '' && !preg_match('/^[a-z0-9.-]+$/i', $v)) {
                throw new \InvalidArgumentException(t('wm.invalid_host'));
            }
            return mb_strtolower($v);
        };
        $login = trim((string) ($d['login'] ?? ''));
        $password = (string) ($d['password'] ?? '');
        if (preg_match('/[\r\n\x00]/', $login . $password)) {
            throw new \InvalidArgumentException(t('wm.invalid_login'));
        }
        $data = [
            'login' => mb_substr($login, 0, 190),
            'imap_host' => $host($d['imap_host'] ?? ''), 'imap_port' => max(0, min(65535, (int) ($d['imap_port'] ?? 0))), 'imap_security' => $sec($d['imap_security'] ?? ''),
            'smtp_host' => $host($d['smtp_host'] ?? ''), 'smtp_port' => max(0, min(65535, (int) ($d['smtp_port'] ?? 0))), 'smtp_security' => $sec($d['smtp_security'] ?? ''),
            'enabled' => !empty($d['enabled']) ? 1 : 0, 'updated_at' => time(),
        ];
        if ($password !== '') {
            $data['password_enc'] = Crypto::encrypt($password);
            // New credentials are unverified until the next successful connection.
            $data['last_error'] = '';
            $data['last_ok_at'] = 0;
        }
        $existing = self::find($userId);
        if ($existing) {
            DB::update('remote_accounts', $data, 'id = :id', ['id' => $existing['id']]);
        } else {
            if ($password === '') {
                throw new \InvalidArgumentException(t('wm.need_password'));
            }
            DB::insert('remote_accounts', $data + ['user_id' => $userId, 'created_at' => time()]);
        }
        $email = (string) DB::value('SELECT email FROM users WHERE id = :u', ['u' => $userId]);
        Audit::log('webmail.account_saved', $email, ['password_changed' => $password !== ''], $by);
    }

    public static function delete(int $userId): void
    {
        $acc = self::find($userId);
        if ($acc) {
            DB::delete('remote_uids', 'account_id = :a', ['a' => $acc['id']]);
            DB::delete('remote_accounts', 'id = :a', ['a' => $acc['id']]);
        }
    }

    private static function password(array $acc): string
    {
        return (string) (Crypto::decrypt((string) $acc['password_enc']) ?? '');
    }

    private static function imap(array $acc): ImapClient
    {
        $acc = self::effective($acc);
        if ($acc['imap_host'] === '') {
            throw new \RuntimeException(t('wm.no_imap'));
        }
        $c = new ImapClient($acc['imap_host'], (int) $acc['imap_port'], $acc['imap_security'] ?: 'ssl', 30, (bool) Settings::get('webmail.verify_peer', 1));
        $c->connect($acc['login'], self::password($acc));
        return $c;
    }

    public static function smtp(array $acc): SmtpClient
    {
        $acc = self::effective($acc);
        if ($acc['smtp_host'] === '') {
            throw new SmtpException(t('wm.no_smtp'));
        }
        return new SmtpClient(
            $acc['smtp_host'], (int) $acc['smtp_port'], $acc['smtp_security'] ?: 'ssl', $acc['login'], self::password($acc),
            (int) Settings::get('smtp.timeout', 20), (bool) Settings::get('webmail.verify_peer', 1), (string) Settings::get('smtp.helo', '')
        );
    }

    /** Check IMAP login and SMTP authentication. @return array{imap:string,smtp:string} '' = OK */
    public static function test(int $userId): array
    {
        $acc = self::find($userId);
        if (!$acc) {
            return ['imap' => t('wm.not_linked'), 'smtp' => t('wm.not_linked')];
        }
        $out = ['imap' => '', 'smtp' => ''];
        try {
            $c = self::imap($acc);
            $c->select('INBOX');
            $c->logout();
        } catch (\Throwable $e) {
            $out['imap'] = $e->getMessage();
        }
        try {
            $s = self::smtp($acc);
            $s->connect();
            $s->quit();
        } catch (\Throwable $e) {
            $out['smtp'] = $e->getMessage();
        }
        DB::update('remote_accounts', ['last_error' => mb_substr(trim($out['imap'] . ' ' . $out['smtp']), 0, 500)] + ($out['imap'] === '' && $out['smtp'] === '' ? ['last_ok_at' => time()] : []), 'id = :id', ['id' => $acc['id']]);
        return $out;
    }

    // --------------------------------------------------------------- send

    /**
     * Send through the provider's SMTP with the mailbox of $userId.
     * @return array{sent:string[],failed:array<string,string>}
     */
    public static function send(string $raw, string $envelopeFrom, array $rcpts, ?int $userId): array
    {
        $acc = $userId ? self::find($userId) : null;
        if (!$acc || !(int) $acc['enabled']) {
            return ['sent' => [], 'failed' => array_fill_keys($rcpts, t('wm.not_linked'))];
        }
        // Providers only accept their own mailbox as sender: auto-replies (null sender)
        // and forwards leave with the mailbox address.
        $email = (string) DB::value('SELECT email FROM users WHERE id = :u', ['u' => $userId]);
        $from = $envelopeFrom !== '' && self::ownsAddress((int) $userId, $envelopeFrom) ? $envelopeFrom : $email;
        try {
            $client = self::smtp($acc);
            $client->connect();
            $rejected = $client->send($from, $rcpts, $raw);
            $client->quit();
            return ['sent' => array_values(array_diff($rcpts, array_keys($rejected))), 'failed' => $rejected];
        } catch (SmtpException $e) {
            if (stripos($e->getMessage(), 'auth') !== false || in_array($e->smtpCode, [530, 534, 535], true)) {
                DB::update('remote_accounts', ['last_error' => mb_substr('SMTP : ' . $e->getMessage(), 0, 500)], 'id = :id', ['id' => $acc['id']]);
            }
            return ['sent' => [], 'failed' => array_fill_keys($rcpts, $e->getMessage())];
        }
    }

    private static function ownsAddress(int $userId, string $address): bool
    {
        $address = mb_strtolower($address);
        if (mb_strtolower((string) DB::value('SELECT email FROM users WHERE id = :u', ['u' => $userId])) === $address) {
            return true;
        }
        return (bool) DB::value('SELECT 1 FROM aliases WHERE user_id = :u AND address = :a', ['u' => $userId, 'a' => $address]);
    }

    // --------------------------------------------------------------- sync

    /** Pull new messages of one account. @return array{fetched:int,error:string} */
    public static function sync(array $acc, int $max = 200): array
    {
        $user = Users::find((int) $acc['user_id']);
        if (!$user || !(int) $acc['enabled'] || (string) $acc['password_enc'] === '') {
            return ['fetched' => 0, 'error' => ''];
        }
        // Concurrent runs (scheduler + a user's refresh) must not import twice.
        $claimed = DB::run('UPDATE remote_accounts SET last_sync_at = :now WHERE id = :id AND last_sync_at = :old',
            ['now' => time(), 'id' => $acc['id'], 'old' => $acc['last_sync_at']])->rowCount();
        if (!$claimed) {
            return ['fetched' => 0, 'error' => ''];
        }
        $fetched = 0;
        $error = '';
        $client = null;
        try {
            $client = self::imap($acc);
            $fetched += self::pullFolder($client, $acc, $user, 'INBOX', 'inbox', $max);
            $spam = trim((string) Settings::get('webmail.spam_folder', ''));
            if ($spam !== '') {
                try {
                    $fetched += self::pullFolder($client, $acc, $user, $spam, 'spam', $max);
                } catch (\RuntimeException $e) {
                    // A provider without that folder is not an error for the inbox.
                }
            }
            self::cleanup($client, $acc);
            $client->logout();
        } catch (\Throwable $e) {
            $client?->logout();
            $error = mb_substr($e->getMessage(), 0, 500);
        }
        DB::update('remote_accounts', ['last_error' => $error] + ($error === '' ? ['last_ok_at' => time()] : []), 'id = :id', ['id' => $acc['id']]);
        return ['fetched' => $fetched, 'error' => $error];
    }

    private static function pullFolder(ImapClient $client, array $acc, array $user, string $remote, string $local, int $max): int
    {
        $info = $client->select($remote);
        $uidCol = $local . '_uid';
        $valCol = $local . '_validity';
        $acc = DB::one('SELECT * FROM remote_accounts WHERE id = :id', ['id' => $acc['id']]) ?? $acc;
        $last = (int) $acc[$uidCol];
        if ((int) $acc[$valCol] !== 0 && (int) $acc[$valCol] !== $info['uidvalidity']) {
            // The provider renumbered the folder: never re-import what we already have.
            $all = $client->searchUidsAbove(0);
            DB::update('remote_accounts', [$uidCol => $all ? max($all) : 0, $valCol => $info['uidvalidity']], 'id = :id', ['id' => $acc['id']]);
            return 0;
        }
        $n = 0;
        foreach (array_slice($client->searchUidsAbove($last), 0, $max) as $uid) {
            $raw = $client->fetchRaw($uid);
            if ($raw !== null) {
                self::import($user, $raw, $local);
                DB::insert('remote_uids', ['account_id' => $acc['id'], 'folder' => $local, 'validity' => $info['uidvalidity'], 'uid' => $uid, 'imported_at' => time()]);
                $n++;
            }
            DB::update('remote_accounts', [$uidCol => $uid, $valCol => $info['uidvalidity']], 'id = :id', ['id' => $acc['id']]);
        }
        if ($n === 0 && (int) $acc[$valCol] === 0) {
            DB::update('remote_accounts', [$valCol => $info['uidvalidity']], 'id = :id', ['id' => $acc['id']]);
        }
        return $n;
    }

    /** Local delivery of a pulled message: same pipeline as mail received by our own server. */
    private static function import(array $user, string $raw, string $local): void
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        if ($local === 'spam') {
            $spam = Folders::byRole((int) $user['id'], 'spam');
            Mailbox::store((int) $user['id'], (int) $spam['id'], 'Delivered-To: ' . $user['email'] . "\n" . $raw);
            return;
        }
        // The provider's Return-Path is the envelope sender: "<>" marks bounces and
        // automatic mail, which must never get an out-of-office reply.
        $p = MimeParser::parse($raw);
        $rp = trim((string) $p->header('Return-Path'));
        $envelope = preg_match('/<([^>]*)>/', $rp, $m) ? trim($m[1]) : ($rp !== '' ? $rp : ($p->from()['email'] ?? ''));
        if ($envelope !== '' && !is_valid_email(Address::asciiEmail($envelope))) {
            $envelope = '';
        }
        Delivery::deliver($raw, $envelope, [$user['email']], 'sync');
    }

    /** Remove imported messages from the provider after the configured delay. */
    private static function cleanup(ImapClient $client, array $acc): void
    {
        $days = (int) Settings::get('webmail.leave_days', -1);
        if ($days < 0) {
            return;
        }
        $limit = time() - $days * 86400;
        $spam = trim((string) Settings::get('webmail.spam_folder', ''));
        foreach (['inbox' => 'INBOX', 'spam' => $spam] as $local => $remote) {
            if ($remote === '') {
                continue;
            }
            $rows = DB::all('SELECT id, uid FROM remote_uids WHERE account_id = :a AND folder = :f AND imported_at <= :t LIMIT 500',
                ['a' => $acc['id'], 'f' => $local, 't' => $limit]);
            if (!$rows) {
                continue;
            }
            try {
                $client->select($remote);
                foreach ($rows as $r) {
                    $client->markDeleted((int) $r['uid']);
                }
                $client->expunge();
                [$in, $params] = DB::in('r', array_column($rows, 'id'));
                DB::run("DELETE FROM remote_uids WHERE id IN $in", $params);
            } catch (\RuntimeException) {
                // retried at the next run
            }
        }
    }

    /** Scheduler: every enabled account whose last run is older than the interval. */
    public static function syncAll(): int
    {
        if (!self::active()) {
            return 0;
        }
        $interval = max(1, (int) Settings::get('webmail.interval', 2)) * 60 - 5;
        $n = 0;
        foreach (DB::all('SELECT * FROM remote_accounts WHERE enabled = 1 AND last_sync_at < :t', ['t' => time() - $interval]) as $acc) {
            $n += self::sync($acc)['fetched'];
        }
        return $n;
    }

    /** Opportunistic sync when someone looks at the mailbox (no scheduler needed for reading). */
    public static function syncIfDue(int $userId, int $minAge = 60): int
    {
        if (!self::active()) {
            return 0;
        }
        $acc = self::find($userId);
        if (!$acc || (int) $acc['last_sync_at'] > time() - $minAge) {
            return 0;
        }
        return self::sync($acc)['fetched'];
    }

    /** Status shown to administrators and users. */
    public static function status(?array $acc): string
    {
        if (!$acc || (string) $acc['password_enc'] === '') {
            return 'missing';
        }
        if (!(int) $acc['enabled']) {
            return 'disabled';
        }
        if ($acc['last_error'] !== '') {
            return 'error';
        }
        return (int) $acc['last_ok_at'] > 0 ? 'ok' : 'pending';
    }
}
