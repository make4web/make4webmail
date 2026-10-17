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

    /** server | relay | webmail | collector */
    public static function mode(): string
    {
        $m = (string) Settings::get('mail.mode', '');
        if (in_array($m, ['server', 'relay', 'webmail', 'collector'], true)) {
            return $m;
        }
        return trim((string) Settings::get('smtp.host', '')) !== '' ? 'relay' : 'server';
    }

    public static function active(): bool
    {
        return self::mode() === 'webmail';
    }

    /** Queue marker of messages waiting for a user's own mailbox to send again. */
    public const HELD = 'rate-limit-own';

    /**
     * The user's own mailbox at the provider, imposed by the administrator outside webmail
     * mode: their mail is fetched from it and leaves through it. Null when there is none.
     */
    public static function personal(?int $userId): ?array
    {
        if (!$userId || self::active()) {
            return null;
        }
        $acc = self::find($userId);
        return $acc && (int) $acc['forced'] && (int) $acc['enabled'] && (string) $acc['password_enc'] !== '' ? $acc : null;
    }

    /** A person's own mailbox imposed outside webmail mode (not the collector, not webmail mode). */
    public static function isOwn(array $acc): bool
    {
        return (int) $acc['user_id'] !== Collector::ACCOUNT && (int) ($acc['forced'] ?? 0) === 1 && !self::active();
    }

    /** This user's mail leaves through a provider mailbox (webmail mode, or an own mailbox). */
    public static function viaProvider(?int $userId): bool
    {
        return self::active() || self::personal($userId) !== null;
    }

    /** External recipients sent through this user's own mailbox during the last hour. */
    public static function usedBy(int $userId): int
    {
        return (int) DB::value('SELECT COALESCE(SUM(recipients), 0) FROM send_log WHERE account = :a AND created_at > :t', ['a' => $userId, 't' => time() - 3600]);
    }

    /**
     * Send through the user's own mailbox, within its hourly limit. What does not fit (limit
     * reached, provider refusing for rate) overflows to the collector mailbox when the
     * administrator allows it, or waits in the queue.
     * @return array{sent:string[],failed:array<string,string>,deferred:string[],retry_at:int,note:string}
     */
    public static function sendPersonal(array $acc, string $raw, string $envelopeFrom, array $rcpts, int $userId, bool $fromQueue, string $kind): array
    {
        $out = ['sent' => [], 'failed' => [], 'deferred' => [], 'retry_at' => 0, 'note' => self::HELD];
        $limit = (int) $acc['hourly_limit'];
        $blocked = (int) $acc['blocked_until'] > time();
        $available = $blocked ? 0 : ($limit > 0 ? max(0, $limit - self::usedBy($userId)) : count($rcpts));
        $now = array_slice($rcpts, 0, $available);
        $over = array_values(array_slice($rcpts, count($now)));
        if ($now) {
            $logId = DB::insert('send_log', ['user_id' => $userId, 'account' => $userId, 'kind' => $kind, 'recipients' => count($now), 'created_at' => time()]);
            $r = self::send($raw, $envelopeFrom, $now, $userId);
            $out['sent'] = $r['sent'];
            foreach ($r['failed'] as $rcpt => $why) {
                if (Collector::isRateLimitText($why)) {
                    $over[] = $rcpt;
                    $blocked = true;
                } else {
                    $out['failed'][$rcpt] = $why;
                }
            }
            if ($blocked && (int) $acc['blocked_until'] <= time()) {
                // The provider counts its own way: this mailbox rests 15 minutes.
                DB::update('remote_accounts', ['blocked_until' => time() + 900], 'id = :id', ['id' => $acc['id']]);
                $acc['blocked_until'] = time() + 900;
                \M4W\Core\Audit::log('remote.rate_limited', (string) $acc['login']);
            }
            if (!$out['sent']) {
                DB::delete('send_log', 'id = :id', ['id' => $logId]);
            } elseif (count($out['sent']) !== count($now)) {
                DB::update('send_log', ['recipients' => count($out['sent'])], 'id = :id', ['id' => $logId]);
            }
        }
        if ($over) {
            if ((int) $acc['overflow'] && Collector::active()) {
                $c = Collector::send($raw, $over, $userId, $fromQueue, $kind);
                $out['sent'] = array_merge($out['sent'], $c['sent']);
                $out['failed'] += $c['failed'];
                $out['deferred'] = $c['deferred'];
                $out['retry_at'] = $c['retry_at'];
                $out['note'] = Collector::HELD;
            } else {
                $out['deferred'] = $over;
                $out['retry_at'] = self::nextSlotFor($acc, $userId);
            }
        }
        return $out;
    }

    /** When the user's own mailbox can send again. */
    private static function nextSlotFor(array $acc, int $userId): int
    {
        $at = max(time() + 60, (int) $acc['blocked_until']);
        $limit = (int) $acc['hourly_limit'];
        if ($limit > 0 && self::usedBy($userId) >= $limit) {
            $used = self::usedBy($userId);
            foreach (DB::all('SELECT recipients, created_at FROM send_log WHERE account = :a AND created_at > :t ORDER BY created_at', ['a' => $userId, 't' => time() - 3600]) as $r) {
                $used -= (int) $r['recipients'];
                if ($used < $limit) {
                    return max($at, (int) $r['created_at'] + 3601);
                }
            }
        }
        return $at;
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
        if (array_key_exists('sync_folders', $d)) {
            $data['sync_folders'] = !empty($d['sync_folders']) ? 1 : 0;
        }
        if (array_key_exists('two_way', $d)) {
            $data['two_way'] = !empty($d['two_way']) ? 1 : 0;
        }
        if (array_key_exists('forced', $d)) {
            $data['forced'] = !empty($d['forced']) ? 1 : 0;
            $data['overflow'] = !empty($d['overflow']) ? 1 : 0;
            $data['hourly_limit'] = max(0, min(100000, (int) ($d['hourly_limit'] ?? 0)));
            $data['leave_days'] = in_array((int) ($d['leave_days'] ?? -1), [-1, 0, 7, 30, 90], true) ? (int) ($d['leave_days'] ?? -1) : -1;
            $data['save_sent'] = !empty($d['save_sent']) ? 1 : 0;
            // Outside webmail mode there may be no default servers: they must be known.
            if ($data['forced'] && !self::active()) {
                foreach (['imap_host', 'smtp_host'] as $k) {
                    if ($data[$k] === '' && (string) Settings::get('webmail.' . $k, '') === '') {
                        throw new \InvalidArgumentException(t('own.need_servers'));
                    }
                }
            }
        }
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
            DB::delete('remote_folders', 'account_id = :a', ['a' => $acc['id']]);
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
            // Logged in is not enough: the provider must also accept this sender.
            $from = self::envelopeFor($acc, (int) $userId, '');
            $why = $s->probe($from, $from);
            $s->quit();
            if ($why !== '') {
                $out['smtp'] = t('wm.sender_refused', ['address' => $from]) . ' ' . $why;
            }
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
        $from = self::envelopeFor($acc, (int) $userId, $envelopeFrom);
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

    /**
     * Envelope sender for the provider: one of the person's addresses, and when the mailbox
     * login is another address (a mailbox elsewhere), that login: providers refuse senders
     * that are not the authenticated mailbox.
     */
    public static function envelopeFor(array $acc, int $userId, string $wanted): string
    {
        $email = (string) DB::value('SELECT email FROM users WHERE id = :u', ['u' => $userId]);
        $login = mb_strtolower(trim((string) self::effective($acc)['login']));
        if (is_valid_email($login) && !self::ownsAddress($userId, $login)) {
            return $login;
        }
        return $wanted !== '' && self::ownsAddress($userId, $wanted) ? $wanted : $email;
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
        // The collector account belongs to nobody: its messages are dispatched by recipient.
        $user = (int) $acc['user_id'] === Collector::ACCOUNT ? null : Users::find((int) $acc['user_id']);
        if ((!$user && (int) $acc['user_id'] !== Collector::ACCOUNT) || !(int) $acc['enabled'] || (string) $acc['password_enc'] === '') {
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
            if ($user !== null) {
                try {
                    self::repairHistory($client, $acc);
                } catch (\Throwable $e) {
                    error_log('[m4w] webmail repair: ' . $e->getMessage());
                }
            }
            if ($user !== null && self::isOwn($acc) && (int) ($acc['two_way'] ?? 1)) {
                try {
                    self::pushCopies($client, $acc, $user);
                } catch (\Throwable $e) {
                    error_log('[m4w] provider copies: ' . $e->getMessage());
                }
            }
            // One's own folders, the same on both sides (each person's own mailbox only).
            $folders = [];
            if ($user !== null && ProviderFolders::enabled($acc)) {
                try {
                    $folders = ProviderFolders::prepare($client, $acc, $user);
                    $fetched += ProviderFolders::pull($client, $acc, $user, $folders, $max);
                } catch (\RuntimeException $e) {
                    error_log('[m4w] provider folders: ' . $e->getMessage());
                }
            }
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
            if ($user !== null && self::isOwn($acc)) {
                self::restoreBatch($client, DB::one('SELECT * FROM remote_accounts WHERE id = :id', ['id' => $acc['id']]) ?? $acc, $user);
            }
            if ($user !== null && (int) ($acc['two_way'] ?? 1)) {
                self::reconcile($client, DB::one('SELECT * FROM remote_accounts WHERE id = :id', ['id' => $acc['id']]) ?? $acc, $user, $folders);
                if ($folders) {
                    ProviderFolders::finish($client, $acc, $user, $folders);
                }
                // One's own messages put into one's own folders there when missing, never twice.
                if ($folders) {
                    ProviderFolders::push($client, $acc, $user, $folders);
                }
                // Copies made twice in the past (or by another app): removed every quarter of an hour.
                if ((int) ((self::dedupeStats($acc) ?? [])['at'] ?? 0) < time() - 900) {
                    self::dedupe($client, $acc);
                }
            }
            $client->logout();
        } catch (\Throwable $e) {
            $client?->logout();
            $error = mb_substr($e->getMessage(), 0, 500);
        }
        DB::update('remote_accounts', ['last_error' => $error] + ($error === '' ? ['last_ok_at' => time()] : []), 'id = :id', ['id' => $acc['id']]);
        return ['fetched' => $fetched, 'error' => $error];
    }

    private static function pullFolder(ImapClient $client, array $acc, ?array $user, string $remote, string $local, int $max): int
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
        // What is already in the folder when it is first linked is history, not new mail,
        // even when it takes several passes to bring it all in.
        $uids = $client->searchUidsAbove($last);
        $boundaryKey = 'webmail.history.' . (int) $acc['id'] . '.' . $local;
        if ($last === 0 && (int) $acc[$valCol] === 0) {
            Settings::set($boundaryKey, $uids ? max($uids) : 0);
        }
        $boundary = (int) Settings::get($boundaryKey, 0);
        foreach (array_slice($uids, 0, $max) as $uid) {
            if (DB::value('SELECT COUNT(*) FROM remote_uids WHERE account_id = :a AND folder = :f AND validity = :v AND uid = :u', ['a' => $acc['id'], 'f' => $local, 'v' => $info['uidvalidity'], 'u' => $uid])) {
                // Moved here by this webmail itself (already followed under this number).
                DB::update('remote_accounts', [$uidCol => $uid, $valCol => $info['uidvalidity']], 'id = :id', ['id' => $acc['id']]);
                continue;
            }
            $msg = $client->fetchMessage($uid);
            $mid = $msg !== null && preg_match('/^Message-ID:\s*<([^>\r\n]+)>/mi', substr($msg['raw'], 0, (int) (strpos($msg['raw'], "\r\n\r\n") ?: 65536)), $mm) ? $mm[1] : '';
            if ($msg !== null && $user !== null && self::haveLocally((int) $user['id'], $mid)) {
                // Already here (moved back to the inbox at the provider, copied back, sent to
                // oneself through another way…): followed, not imported twice. Back in the provider's
                // inbox while its copy here is in the Trash: it comes back here too.
                if ($trash = Folders::byRole((int) $user['id'], 'trash')) {
                    $back = DB::one('SELECT id FROM messages WHERE user_id = :u AND message_id = :m AND folder_id = :t AND is_draft = 0 LIMIT 1', ['u' => $user['id'], 'm' => $mid, 't' => $trash['id']]);
                    if ($back && ($to = Folders::byRole((int) $user['id'], $local))) {
                        Mailbox::move((int) $user['id'], [(int) $back['id']], (int) $to['id']);
                        Audit::log('sync.restored_here', mb_substr((string) DB::value('SELECT subject FROM messages WHERE id = :id', ['id' => $back['id']]), 0, 150), ['box' => (string) $acc['login']], (int) $user['id']);
                    }
                }
                DB::insert('remote_uids', ['account_id' => $acc['id'], 'folder' => $local, 'validity' => $info['uidvalidity'], 'uid' => $uid, 'imported_at' => time(),
                    'msgid' => mb_substr($mid, 0, 255), 'seen' => (int) in_array('\\seen', $msg['flags'], true), 'flagged' => (int) in_array('\\flagged', $msg['flags'], true)]);
            } elseif ($msg !== null) {
                // Where it was collected, shown in the message details (and stripped when copied back).
                $kind = (int) $acc['user_id'] === Collector::ACCOUNT ? 'collector' : (self::isOwn($acc) ? 'own' : 'webmail');
                $trace = 'X-M4W-Fetched: ' . preg_replace('/[^\w.@+\-]/', '', (string) self::effective($acc)['login']) . '; ' . $kind . '; ' . preg_replace('/[^\w.\-\/ ]/', '', $remote) . '; uid=' . $uid . '; ' . date('r') . "\r\n";
                self::import($user, $trace . Delivery::stripHeader(str_replace(["\r\n", "\r"], "\n", $msg['raw']), 'X-M4W-Fetched'), $local, $msg, $uid <= $boundary);
                DB::insert('remote_uids', ['account_id' => $acc['id'], 'folder' => $local, 'validity' => $info['uidvalidity'], 'uid' => $uid, 'imported_at' => time(),
                    'msgid' => mb_substr($mid, 0, 255), 'seen' => (int) in_array('\\seen', $msg['flags'], true), 'flagged' => (int) in_array('\\flagged', $msg['flags'], true)]);
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
    private static function import(?array $user, string $raw, string $local, array $meta = [], bool $history = false): void
    {
        if ($user === null) {
            Collector::dispatch($raw, $local);
            return;
        }
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $arrived = (int) ($meta['date'] ?? 0);
        // Mail already in the mailbox when it was linked, or older than two days: history.
        // It keeps its date and read state, and triggers nothing (no notification, rule,
        // forwarding or out-of-office reply to someone who wrote months ago).
        if ($history || ($arrived > 0 && $arrived < time() - 2 * 86400)) {
            self::importHistory($user, $raw, $local, $meta);
            return;
        }
        // An own mailbox next to another way in (our MX, a colleague in copy…): one copy only.
        if (!self::active()) {
            $mid = MimeParser::parse($raw)->messageId();
            if ($mid !== '' && DB::value('SELECT COUNT(*) FROM messages WHERE user_id = :u AND message_id = :m AND date_received > :t',
                ['u' => $user['id'], 'm' => $mid, 't' => time() - 14 * 86400])) {
                return;
            }
        }
        if ($local === 'spam') {
            $spam = Folders::byRole((int) $user['id'], 'spam');
            Mailbox::store((int) $user['id'], (int) $spam['id'], 'Delivered-To: ' . $user['email'] . "\n" . Delivery::sanitizeIncoming($raw));
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

    private static function importHistory(array $user, string $raw, string $local, array $meta): void
    {
        $uid = (int) $user['id'];
        $raw = Delivery::sanitizeIncoming($raw);
        $p = MimeParser::parse($raw);
        $mid = $p->messageId();
        if ($mid !== '' && DB::value('SELECT COUNT(*) FROM messages WHERE user_id = :u AND message_id = :m', ['u' => $uid, 'm' => $mid])) {
            return;
        }
        // No Message-ID (rare): same sender, subject and date means the same message.
        if ($mid === '' && $p->date() > 0 && DB::value('SELECT COUNT(*) FROM messages WHERE user_id = :u AND date_sent = :d AND subject = :s AND LOWER(from_email) = :f',
            ['u' => $uid, 'd' => $p->date(), 's' => mb_substr($p->subject(), 0, 500), 'f' => mb_strtolower($p->from()['email'] ?? '')])) {
            return;
        }
        $flags = (array) ($meta['flags'] ?? []);
        $folder = Folders::byRole($uid, $local === 'spam' ? 'spam' : 'inbox');
        $date = (int) ($meta['date'] ?? 0) ?: ($p->date() ?: time());
        Mailbox::store($uid, (int) $folder['id'], 'Delivered-To: ' . $user['email'] . "\n" . $raw,
            ['read' => in_array('\\seen', $flags, true), 'flagged' => in_array('\\flagged', $flags, true), 'date_received' => min($date, time())], $p);
    }

    /**
     * Mailboxes linked before history import existed: every message was dated "now" and unread.
     * Once per account, put back the real dates and the provider's read state.
     */
    private static function repairHistory(ImapClient $client, array $acc): void
    {
        $key = 'webmail.repaired.' . (int) $acc['id'];
        if ($acc['user_id'] === null || (int) Settings::get($key, 0) || !DB::value('SELECT COUNT(*) FROM remote_uids WHERE account_id = :a', ['a' => $acc['id']])) {
            Settings::set($key, 1);
            return;
        }
        $uid = (int) $acc['user_id'];
        $since = (int) DB::value('SELECT MIN(imported_at) FROM remote_uids WHERE account_id = :a', ['a' => $acc['id']]);
        $client->select('INBOX');
        $flags = $client->flagsByMessageId();
        $inbox = (int) Folders::byRole($uid, 'inbox')['id'];
        foreach (DB::all('SELECT id, message_id, date_sent, date_received FROM messages WHERE user_id = :u AND folder_id = :f AND date_received >= :t',
            ['u' => $uid, 'f' => $inbox, 't' => $since - 60]) as $m) {
            $set = [];
            if ((int) $m['date_sent'] > 0 && (int) $m['date_sent'] < (int) $m['date_received'] - 2 * 86400) {
                $set['date_received'] = (int) $m['date_sent'];
            }
            if (isset($flags[$m['message_id']])) {
                $set['is_read'] = in_array('\\seen', $flags[$m['message_id']], true) ? 1 : 0;
                $set['is_flagged'] = in_array('\\flagged', $flags[$m['message_id']], true) ? 1 : 0;
            }
            if ($set) {
                DB::update('messages', $set, 'id = :id', ['id' => $m['id']]);
            }
        }
        Settings::set($key, 1);
    }

    // ----------------------------------------------------------- copies to the provider

    /** The provider's Sent folder (found once, then remembered). */
    private static function sentFolderOf(ImapClient $client, array $acc): string
    {
        $f = (string) ($acc['sent_folder'] ?? '');
        if ($f === '') {
            $f = $client->sentFolder();
            if ($f !== '') {
                DB::update('remote_accounts', ['sent_folder' => mb_substr($f, 0, 190)], 'id = :id', ['id' => $acc['id']]);
            }
        }
        return $f;
    }

    /**
     * A message sent from the webmail through the person's own mailbox is also put in that
     * mailbox's Sent folder, so it is found there too (phone, Outlook, provider's webmail).
     */
    public static function saveSent(int $userId, string $raw): bool
    {
        $acc = self::personal($userId);
        if (!$acc || !(int) ($acc['save_sent'] ?? 1)) {
            return false;
        }
        try {
            $client = self::imap($acc);
            $folder = self::sentFolderOf($client, $acc);
            if ($folder !== '') {
                $client->append($folder, $raw, ['\\Seen'], time());
            }
            $client->logout();
            return $folder !== '';
        } catch (\Throwable $e) {
            error_log('[m4w] own mailbox sent copy: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * A message delivered here directly for someone whose own mailbox (two-way) is at the
     * provider: its copy is placed in the provider's inbox at the next synchronisation.
     */
    /**
     * How long a copy for the provider waits: mail delivered here is often on its way there too
     * (sent through the provider, a forward keeping a copy…), and must not arrive twice.
     */
    private const COPY_DELAY = 600;

    public static function queueCopy(int $userId, int $messageId, string $source = ''): void
    {
        $acc = self::personal($userId);
        if ($acc && (int) ($acc['two_way'] ?? 1)) {
            DB::insert('provider_copies', ['user_id' => $userId, 'message_id' => $messageId, 'created_at' => time(), 'source' => mb_substr($source, 0, 20)]);
        }
    }

    /** Where the provider mailbox may hold a message: inbox, junk, Trash, Archives, one's own folders. */
    private static function providerPaths(ImapClient $client, array $acc, bool $withBins = true): array
    {
        $paths = ['INBOX', trim((string) Settings::get('webmail.spam_folder', ''))];
        if ($withBins) {
            $paths[] = self::providerFolder($client, $acc, 'trash_folder');
            $paths[] = self::providerFolder($client, $acc, 'archive_folder');
        }
        foreach (DB::all('SELECT path FROM remote_folders WHERE account_id = :a AND missing = 0', ['a' => $acc['id']]) as $f) {
            $paths[] = (string) $f['path'];
        }
        return array_values(array_unique(array_filter($paths)));
    }

    /**
     * What the provider mailbox holds anywhere one may have put a message, by Message-ID and by
     * sender + date + subject (a copy whose Message-ID was changed on the way): a copy or a
     * restore never adds a second one. ['ids' => set, 'keys' => set]
     */
    public static function providerHolds(ImapClient $client, array $acc): array
    {
        $out = ['ids' => [], 'keys' => []];
        foreach (self::providerPaths($client, $acc) as $path) {
            try {
                $client->select($path);
                foreach ($client->fingerprints() as $f) {
                    if ($f['msgid'] !== '') {
                        $out['ids'][$f['msgid']] = true;
                    }
                    if ($f['key'] !== '') {
                        $out['keys'][$f['key']] = true;
                    }
                }
            } catch (\RuntimeException) {
                // a folder the provider does not have
            }
        }
        return $out;
    }

    /** Whether the provider holds this stored message already (see providerHolds). */
    public static function heldThere(array $holds, array $m): bool
    {
        if ($m['message_id'] !== '' && isset($holds['ids'][$m['message_id']])) {
            return true;
        }
        $key = self::keyOf($m);
        return $key !== '' && isset($holds['keys'][$key]);
    }

    /** Sender + date + subject of a stored message (ImapClient::fingerprint). */
    private static function keyOf(array $m): string
    {
        $raw = str_replace("\r\n", "\n", Mailbox::raw($m));
        return ImapClient::fingerprint(substr($raw, 0, (int) (strpos($raw, "\n\n") ?: 65536)))['key'];
    }

    /**
     * Put a message of this webmail into the provider mailbox, never twice: every copy made is
     * written down (provider_pushed), whatever the provider's search answers afterwards, and
     * shown in the logs. Returns whether it was put there now.
     */
    public static function appendOnce(ImapClient $client, array $acc, array $m, string $path, string $why): bool
    {
        $key = self::keyOf($m);
        $done = DB::value('SELECT COUNT(*) FROM provider_pushed WHERE account_id = :a AND (local_id = :l' . ($m['message_id'] !== '' ? ' OR msgid = :m' : '') . ($key !== '' ? ' OR fkey = :k' : '') . ')',
            ['a' => $acc['id'], 'l' => $m['id']] + ($m['message_id'] !== '' ? ['m' => $m['message_id']] : []) + ($key !== '' ? ['k' => $key] : []));
        if ($done) {
            return false;
        }
        $client->append($path, self::providerRaw(Mailbox::raw($m)),
            array_merge($m['is_read'] ? ['\\Seen'] : [], $m['is_flagged'] ? ['\\Flagged'] : [], $m['is_answered'] ? ['\\Answered'] : []), (int) ($m['date_received'] ?: $m['date_sent'] ?: time()));
        DB::insert('provider_pushed', ['account_id' => $acc['id'], 'local_id' => $m['id'], 'msgid' => mb_substr((string) $m['message_id'], 0, 255), 'fkey' => $key, 'created_at' => time()]);
        Audit::log('sync.copied_there', mb_substr((string) $m['subject'], 0, 150), ['box' => (string) $acc['login'], 'to' => ImapClient::decodeName($path), 'why' => $why, 'msgid' => (string) $m['message_id']], (int) $acc['user_id']);
        return true;
    }

    /**
     * Two copies of one message at the provider (same Message-ID in one folder: a copy made here
     * while the original was still on its way): one is kept, with the read / flagged state of both.
     * Once a day, and when a restore ends. Returns how many were removed.
     */
    public static function dedupe(ImapClient $client, array $acc): int
    {
        $n = 0;
        $keys = ['INBOX' => 'inbox'];
        foreach (DB::all('SELECT folder_id, path FROM remote_folders WHERE account_id = :a AND missing = 0', ['a' => $acc['id']]) as $f) {
            $keys[(string) $f['path']] = 'f' . (int) $f['folder_id'];
        }
        foreach (self::providerPaths($client, $acc, false) as $path) {
            try {
                $info = $client->select($path);
                // Copies of one message: the same Message-ID, or the same sender, date (to the
                // second) and subject (a copy whose Message-ID was changed on the way).
                $prints = array_filter($client->fingerprints(), static fn($f) => !in_array('\\deleted', $f['flags'], true));
                $parent = [];
                $find = static function (int $u) use (&$parent, &$find): int {
                    return $parent[$u] === $u ? $u : ($parent[$u] = $find($parent[$u]));
                };
                $seen = [];
                foreach ($prints as $uid => $f) {
                    $parent[$uid] = $uid;
                    foreach (['i:' . $f['msgid'] => $f['msgid'], 'k:' . $f['key'] => $f['key']] as $tag => $v) {
                        if ($v === '') {
                            continue;
                        }
                        if (isset($seen[$tag])) {
                            $parent[$find($uid)] = $find($seen[$tag]);
                        } else {
                            $seen[$tag] = $uid;
                        }
                    }
                }
                $groups = [];
                foreach ($prints as $uid => $f) {
                    $groups[$find($uid)][$uid] = $f['flags'];
                }
                $removed = [];
                foreach ($groups as $copies) {
                    if (count($copies) < 2) {
                        continue;
                    }
                    // The one followed here is kept (else the first one).
                    [$uin, $up] = DB::in('q', array_keys($copies));
                    $followed = isset($keys[$path]) ? array_map('intval', array_column(DB::all("SELECT uid FROM remote_uids WHERE account_id = :a AND folder = :f AND validity = :v AND uid IN $uin",
                        $up + ['a' => $acc['id'], 'f' => $keys[$path], 'v' => $info['uidvalidity']]), 'uid')) : [];
                    $keep = array_values(array_intersect(array_keys($copies), $followed))[0] ?? min(array_keys($copies));
                    $all = array_merge(...array_values($copies));
                    foreach (['\\seen' => '\\Seen', '\\flagged' => '\\Flagged', '\\answered' => '\\Answered'] as $flag => $imap) {
                        if (in_array($flag, $all, true) && !in_array($flag, $copies[$keep], true)) {
                            $client->storeFlags($keep, true, [$imap]);
                        }
                    }
                    foreach (array_keys($copies) as $uid) {
                        if ($uid !== $keep) {
                            $client->markDeleted($uid);
                            $removed[] = $uid;
                        }
                    }
                }
                if ($removed) {
                    $client->expunge();
                    if (isset($keys[$path])) {
                        [$in, $p] = DB::in('u', $removed);
                        DB::run("DELETE FROM remote_uids WHERE account_id = :a AND folder = :f AND validity = :v AND uid IN $in", $p + ['a' => $acc['id'], 'f' => $keys[$path], 'v' => $info['uidvalidity']]);
                    }
                    $n += count($removed);
                }
            } catch (\RuntimeException $e) {
                error_log('[m4w] provider duplicates: ' . $e->getMessage());
            }
        }
        $prev = self::dedupeStats($acc) ?? [];
        Settings::set('webmail.dedupe3.' . (int) $acc['id'], json_encode(['at' => time(), 'n' => (int) ($prev['n'] ?? 0) + $n]));
        if ($n) {
            Audit::log('sync.duplicates_removed', (string) $acc['login'], ['n' => $n], (int) $acc['user_id']);
        }
        return $n;
    }

    public static function dedupeStats(array $acc): ?array
    {
        // (dedupe3: runs again once after the update, now that whole folders are seen)
        $d = json_decode((string) Settings::get('webmail.dedupe3.' . (int) $acc['id'], ''), true);
        return is_array($d) ? $d : null;
    }

    /**
     * Pending copies into the provider's inbox. The synchronisation then finds them there by their
     * Message-ID (already here: followed, not imported again), and deletions, read and flagged
     * states go both ways like for any other message.
     */
    private static function pushCopies(ImapClient $client, array $acc, array $user, int $max = 50): void
    {
        $now = time();
        $rows = DB::all("SELECT * FROM provider_copies WHERE user_id = :u AND created_at <= :t ORDER BY id LIMIT $max", ['u' => $user['id'], 't' => $now - self::COPY_DELAY]);
        if (!$rows) {
            return;
        }
        $there = self::providerHolds($client, $acc);
        $client->select('INBOX');
        foreach ($rows as $r) {
            $m = Mailbox::get((int) $user['id'], (int) $r['message_id']);
            $path = $m ? storage_path((string) $m['storage_path']) : '';
            // Gone, moved out of the inbox meanwhile (the sync would bring it back), without a
            // Message-ID to recognise it by, or already at the provider: nothing to copy.
            if (!$m || (int) $m['folder_id'] !== (int) (Folders::byRole((int) $user['id'], 'inbox')['id'] ?? 0) || $m['message_id'] === ''
                || $m['storage_path'] === '' || !is_file($path) || self::heldThere($there, $m)) {
                DB::run('DELETE FROM provider_copies WHERE id = :id', ['id' => $r['id']]);
                continue;
            }
            try {
                self::appendOnce($client, $acc, $m, 'INBOX', 'copy');
                DB::run('DELETE FROM provider_copies WHERE id = :id', ['id' => $r['id']]);
            } catch (\Throwable $e) {
                error_log('[m4w] provider copy ' . (int) $r['message_id'] . ': ' . $e->getMessage());
                if ((int) $r['tries'] >= 20) {
                    DB::run('DELETE FROM provider_copies WHERE id = :id', ['id' => $r['id']]);
                } else {
                    DB::run('UPDATE provider_copies SET tries = tries + 1 WHERE id = :id', ['id' => $r['id']]);
                }
            }
        }
    }

    /** A stored message as the provider should get it: without our own delivery and filtering lines. */
    private static function providerRaw(string $raw): string
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $raw = preg_replace('/^Delivered-To: [^\n]*\n/', '', $raw, 1) ?? $raw;
        foreach (['X-M4W-Fetched', 'X-M4W-Spam', 'X-M4W-AV', 'X-M4W-Auth', 'X-M4W-Unrouted'] as $own) {
            $raw = Delivery::stripHeader($raw, $own);
        }
        return $raw;
    }

    /**
     * Copy the mail kept here back into the person's own mailbox (after it was emptied by a
     * clean-up, or to bring the two together). Inbox and Sent, oldest first, done a little at
     * every synchronisation; what the mailbox already has is skipped.
     * @param string[] $folders inbox, sent
     */
    public static function startRestore(int $userId, array $folders, int $by): int
    {
        $acc = self::find($userId);
        if (!$acc || !self::isOwn($acc)) {
            throw new \InvalidArgumentException(t('own.restore_only_own'));
        }
        $folders = array_values(array_intersect(['inbox', 'sent'], $folders)) ?: ['inbox'];
        $ids = array_map(static fn($r) => (int) $r['id'], array_filter(array_map(static fn($f) => Folders::byRole($userId, $f), $folders)));
        [$in, $p] = DB::in('f', $ids);
        $total = (int) DB::value("SELECT COUNT(*) FROM messages WHERE user_id = :u AND is_draft = 0 AND folder_id IN $in", $p + ['u' => $userId]);
        DB::update('remote_accounts', ['restore_state' => json_encode(['folders' => $folders, 'next' => 0, 'done' => 0, 'skipped' => 0, 'failed' => 0, 'total' => $total,
            'started' => time(), 'finished' => 0, 'error' => ''])], 'id = :id', ['id' => $acc['id']]);
        Audit::log('own.restore_started', implode(',', $folders), ['total' => $total], $by);
        return $total;
    }

    public static function restoreState(array $acc): ?array
    {
        $s = json_decode((string) ($acc['restore_state'] ?? ''), true);
        return is_array($s) ? $s : null;
    }

    private static function restoreBatch(ImapClient $client, array $acc, array $user, int $max = 150): void
    {
        $st = self::restoreState($acc);
        if (!$st || $st['finished']) {
            return;
        }
        $uid = (int) $user['id'];
        $map = [];
        foreach ($st['folders'] as $f) {
            $row = Folders::byRole($uid, $f);
            $target = match ($f) {
                'sent' => self::sentFolderOf($client, $acc),
                default => 'INBOX',
            };
            if ($row && $target !== '') {
                $map[(int) $row['id']] = $target;
            }
        }
        if (!$map) {
            $st['finished'] = time();
            $st['error'] = t('own.restore_no_folder');
            DB::update('remote_accounts', ['restore_state' => json_encode($st)], 'id = :id', ['id' => $acc['id']]);
            return;
        }
        // What the mailbox already holds, by Message-ID (per target folder).
        $have = [];
        foreach (array_unique($map) as $target) {
            // Anywhere it may have been filed or deleted there since, not only the inbox.
            $have[$target] = $target === 'INBOX' ? self::providerHolds($client, $acc) : ['ids' => [], 'keys' => []];
            if ($target !== 'INBOX') {
                $client->select($target);
                foreach ($client->fingerprints() as $f) {
                    $f['msgid'] !== '' && $have[$target]['ids'][$f['msgid']] = true;
                    $f['key'] !== '' && $have[$target]['keys'][$f['key']] = true;
                }
            }
        }
        [$in, $p] = DB::in('f', array_keys($map));
        $rows = DB::all("SELECT * FROM messages WHERE user_id = :u AND is_draft = 0 AND folder_id IN $in AND id > :n ORDER BY id LIMIT $max", $p + ['u' => $uid, 'n' => (int) $st['next']]);
        foreach ($rows as $m) {
            $st['next'] = (int) $m['id'];
            $target = $map[(int) $m['folder_id']];
            $path = storage_path((string) $m['storage_path']);
            if ($m['storage_path'] === '' || !is_file($path)) {
                $st['failed']++;
                continue;
            }
            if (self::heldThere($have[$target], $m)) {
                $st['skipped']++;
                continue;
            }
            try {
                // Our own lines (delivery, collection, antispam, antivirus) are not part of the message.
                self::appendOnce($client, $acc, $m, $target, 'restore') ? $st['done']++ : $st['skipped']++;
            } catch (\Throwable $e) {
                $st['failed']++;
                $st['error'] = mb_substr($e->getMessage(), 0, 300);
            }
        }
        if (count($rows) < $max) {
            $st['finished'] = time();
            self::dedupe($client, $acc);
            Audit::log('own.restore_finished', (string) $acc['login'], ['done' => $st['done'], 'skipped' => $st['skipped'], 'failed' => $st['failed']], $uid);
        }
        DB::update('remote_accounts', ['restore_state' => json_encode($st)], 'id = :id', ['id' => $acc['id']]);
    }

    // --------------------------------------------------------- two-way synchronisation

    /** A message with this Message-ID is already in the person's mailbox (outside Sent and Drafts). */
    private static function haveLocally(int $userId, string $msgid): bool
    {
        if ($msgid === '') {
            return false;
        }
        return (bool) DB::value("SELECT 1 FROM messages m JOIN folders f ON f.id = m.folder_id WHERE m.user_id = :u AND m.message_id = :m AND m.is_draft = 0 AND (f.role IS NULL OR f.role <> 'sent') LIMIT 1",
            ['u' => $userId, 'm' => $msgid]);
    }

    /** A folder of the provider by role, remembered once found ('' = it has none, for now). */
    private static function providerFolder(ImapClient $client, array &$acc, string $col): string
    {
        $f = (string) ($acc[$col] ?? '');
        if ($f === '' || $f === '-') {
            // Remembered once found; looked for again otherwise (it may be created later).
            $f = $col === 'trash_folder' ? $client->trashFolder() : $client->archiveFolder();
            if ($f !== '') {
                DB::update('remote_accounts', [$col => mb_substr($f, 0, 190)], 'id = :id', ['id' => $acc['id']]);
            }
            $acc[$col] = $f;
        }
        return $f;
    }

    /**
     * The same mailbox seen from the phone and from the webmail. For each message collected:
     *  - gone from the provider (deleted on the phone): to the Trash here, or to the Archives
     *    when it was archived there;
     *  - deleted, archived or marked as junk here: moved likewise at the provider (its Trash at
     *    least, never erased);
     *  - read / flagged: whichever side changed since they last agreed wins.
     * What this webmail removes itself (clean-up) is no longer followed, so never mistaken for
     * a deletion on the phone. An inbox that suddenly looks empty must look so twice in a row.
     */
    /** @param array $folders one's own folders followed (ProviderFolders::prepare) */
    private static function reconcile(ImapClient $client, array $acc, array $user, array $folders = [], int $max = 300): void
    {
        $uid = (int) $user['id'];
        $roles = [];
        foreach (['inbox', 'spam', 'trash', 'archive', 'sent', 'drafts'] as $r) {
            $roles[$r] = (int) (Folders::byRole($uid, $r)['id'] ?? 0);
        }
        $spamRemote = trim((string) Settings::get('webmail.spam_folder', ''));
        $actions = 0;
        // What this pass saw and did, shown on the user form (to tell why something did not follow).
        $report = ['at' => time(), 'followed' => 0, 'provider' => 0, 'trashed' => 0, 'archived' => 0, 'moved' => 0, 'flags' => 0, 'other_way' => 0, 'waiting_empty' => 0, 'failed' => 0, 'error' => ''];
        // One's own folders: messages filed there (by hand or by a rule) stay in the provider inbox,
        // their read / flagged state still follows.
        $ownFolders = array_flip(array_map('intval', array_column(DB::all('SELECT id FROM folders WHERE user_id = :u AND role IS NULL', ['u' => $uid]), 'id')));
        $save = static function () use (&$report, $acc): void {
            // Counted once the pass is done: what it found gone is no longer followed.
            $report['followed'] = min($report['provider'], (int) DB::value('SELECT COUNT(*) FROM remote_uids WHERE account_id = :a', ['a' => $acc['id']]));
            Settings::set('webmail.twoway.' . (int) $acc['id'], json_encode($report));
        };
        // Where each followed place is: here (folder id) and at the provider (path). One's own
        // folders first: a message moved on the phone from one of them to the inbox is then
        // seen leaving it (and filed here likewise) before the inbox is looked at.
        $places = [];
        $homes = [];
        $pathOf = [];
        foreach ($folders as $key => $f) {
            $places[$key] = $f['path'];
            $homes[$key] = (int) $f['folder_id'];
            if (!$f['gone_there']) {
                $pathOf[(int) $f['folder_id']] = $f['path'];
            }
        }
        $places += ['inbox' => 'INBOX', 'spam' => $spamRemote];
        $homes += ['inbox' => $roles['inbox'], 'spam' => $roles['spam']];
        $keyOf = array_flip(array_map('intval', $homes));
        foreach ($places as $local => $remote) {
            if ($remote === '') {
                continue;
            }
            $home = (int) $homes[$local];
            $own = isset($folders[$local]);
            $vanished = $own && $folders[$local]['gone_there'];
            if ($vanished) {
                // Deleted (or renamed) at the provider: everything in it has left it.
                $info = ['uidvalidity' => (int) DB::value('SELECT validity FROM remote_folders WHERE id = :id', ['id' => $folders[$local]['map_id']])];
            } else {
                try {
                    $info = $client->select($remote);
                } catch (\RuntimeException) {
                    continue;
                }
                $known = $own ? (int) DB::value('SELECT validity FROM remote_folders WHERE id = :id', ['id' => $folders[$local]['map_id']]) : (int) $acc[$local . '_validity'];
                if ($known !== $info['uidvalidity']) {
                    continue; // renumbered: the pull starts again from there
                }
            }
            $rows = DB::all('SELECT * FROM remote_uids WHERE account_id = :a AND folder = :f AND validity = :v', ['a' => $acc['id'], 'f' => $local, 'v' => $info['uidvalidity']]);
            if (!$rows) {
                continue;
            }
            $index = $vanished ? [] : $client->index();
            if (!$vanished && !$index && count($rows) > 20) {
                // Empty all of a sudden: believed only when seen twice in a row.
                if ((int) $acc['empty_streak'] < 1) {
                    DB::update('remote_accounts', ['empty_streak' => (int) $acc['empty_streak'] + 1], 'id = :id', ['id' => $acc['id']]);
                    $report['waiting_empty'] = 1;
                    continue;
                }
            }
            $report['followed'] += count($rows);
            $report['provider'] += count($index);
            DB::update('remote_accounts', ['empty_streak' => 0], 'id = :id', ['id' => $acc['id']]);
            // The same message may come back under another number (redirections, filters at the
            // provider): what counts is the message, not its number.
            $byMsgid = [];
            foreach ($index as $iu => $it) {
                if ($it['msgid'] !== '' && !in_array('\\deleted', $it['flags'], true)) {
                    $byMsgid[$it['msgid']] ??= $iu;
                }
            }
            // Messages here, by Message-ID (the copy outside Sent / Drafts).
            $ids = array_values(array_unique(array_filter(array_map(static fn($r) => (string) ($r['msgid'] !== '' ? $r['msgid'] : ($index[(int) $r['uid']]['msgid'] ?? '')), $rows))));
            $here = [];
            foreach (array_chunk($ids, 400) as $chunk) {
                [$in, $p] = DB::in('m', $chunk);
                foreach (DB::all("SELECT id, message_id, folder_id, is_read, is_flagged FROM messages WHERE user_id = :u AND is_draft = 0 AND message_id IN $in AND folder_id <> :s", $p + ['u' => $uid, 's' => $roles['sent']]) as $m) {
                    $here[$m['message_id']] ??= $m;
                    if ((int) $m['folder_id'] === $home) {
                        $here[$m['message_id']] = $m; // the copy where it was put wins
                    }
                }
            }
            if ($local === 'inbox' && $roles['inbox']) {
                // Messages of the inbox here that this mailbox did not bring (another way in):
                // they cannot follow what happens to it.
                $followed = array_flip(array_merge($ids, array_filter(array_column($index, 'msgid'))));
                foreach (DB::all('SELECT message_id FROM messages WHERE user_id = :u AND folder_id = :f AND is_draft = 0', ['u' => $uid, 'f' => $roles['inbox']]) as $lm) {
                    if ($lm['message_id'] === '' || !isset($followed[$lm['message_id']])) {
                        $report['other_way']++;
                    }
                }
            }
            foreach ($rows as $r) {
                if ($actions >= $max) {
                    $save();
                    return; // the rest at the next synchronisation
                }
                $ruid = (int) $r['uid'];
                $msgid = (string) $r['msgid'];
                if ($msgid === '' && isset($index[$ruid]) && $index[$ruid]['msgid'] !== '') {
                    $msgid = $index[$ruid]['msgid'];
                    DB::update('remote_uids', ['msgid' => mb_substr($msgid, 0, 255)], 'id = :id', ['id' => $r['id']]);
                }
                $m = $msgid !== '' ? ($here[$msgid] ?? null) : null;
                // 1. Gone from the provider folder, or marked deleted there (many mail apps only
                //    set \Deleted and erase later): deleted (or archived) on the phone.
                $gone = !isset($index[$ruid]) || in_array('\\deleted', $index[$ruid]['flags'], true);
                if ($gone && $msgid !== '' && ($elsewhere = DB::one('SELECT folder FROM remote_uids WHERE account_id = :a AND msgid = :m AND folder <> :f LIMIT 1',
                    ['a' => $acc['id'], 'm' => $msgid, 'f' => $local]))) {
                    // Moved at the provider to another place followed (on the phone, or by us): filed
                    // here likewise, unless this side already put it elsewhere on purpose.
                    $to = (int) ($homes[$elsewhere['folder']] ?? 0);
                    if ($m && $to && (int) $m['folder_id'] === $home) {
                        Mailbox::move($uid, [(int) $m['id']], $to);
                        $report['moved_here'] = ($report['moved_here'] ?? 0) + 1;
                    }
                    DB::delete('remote_uids', 'id = :id', ['id' => $r['id']]);
                    continue;
                }
                if ($gone && $msgid !== '' && isset($byMsgid[$msgid])) {
                    // Still there under another number: follow the new one, nothing was deleted.
                    $taken = (bool) DB::value('SELECT 1 FROM remote_uids WHERE account_id = :a AND folder = :f AND validity = :v AND uid = :u', ['a' => $acc['id'], 'f' => $local, 'v' => $info['uidvalidity'], 'u' => $byMsgid[$msgid]]);
                    $taken ? DB::delete('remote_uids', 'id = :id', ['id' => $r['id']]) : DB::update('remote_uids', ['uid' => $byMsgid[$msgid]], 'id = :id', ['id' => $r['id']]);
                    continue;
                }
                if ($gone && $msgid !== '' && !isset($index[$ruid]) && !$vanished) {
                    // Before touching anything here, ask the provider once more, by Message-ID.
                    try {
                        $still = array_filter($client->searchMessageId($msgid), static fn($x) => !in_array('\\deleted', $index[$x]['flags'] ?? [], true));
                    } catch (\RuntimeException) {
                        continue; // not sure: next time
                    }
                    if ($still) {
                        DB::update('remote_uids', ['uid' => (int) reset($still)], 'id = :id', ['id' => $r['id']]);
                        continue;
                    }
                }
                if ($gone) {
                    // Wherever its copy is here (inbox, a folder of one's own…), unless already in the Trash.
                    if ($m && (int) $m['folder_id'] !== $roles['trash']) {
                        $archived = false;
                        if (!$vanished && ($arch = self::providerFolder($client, $acc, 'archive_folder')) !== '' && $roles['archive']) {
                            try {
                                $client->select($arch);
                                $archived = (bool) $client->searchMessageId($msgid);
                            } catch (\RuntimeException) {
                            }
                            $client->select($remote);
                        }
                        Mailbox::move($uid, [(int) $m['id']], $archived ? $roles['archive'] : $roles['trash']);
                        $report[$archived ? 'archived' : 'trashed']++;
                        // Traced in the logs, so that one can always tell why a message moved.
                        Audit::log($archived ? 'sync.archived_here' : 'sync.trashed_here', mb_substr((string) DB::value('SELECT subject FROM messages WHERE id = :id', ['id' => $m['id']]), 0, 150),
                            ['box' => (string) $acc['login'], 'uid' => $ruid, 'flags' => isset($index[$ruid]) ? implode(' ', $index[$ruid]['flags']) : 'absent'], $uid);
                        $actions++;
                    }
                    DB::delete('remote_uids', 'id = :id', ['id' => $r['id']]);
                    continue;
                }
                if ($msgid === '') {
                    continue; // nothing to tie it to here
                }
                // 2. Deleted, archived or classified here: the provider follows.
                $where = $m ? (int) $m['folder_id'] : 0;
                if ((int) $r['seen'] === -1 && (!$m || $where !== $home)) {
                    // Collected before both sides were followed: what was done here back then is
                    // not replayed at the provider; followed from now on.
                    DB::update('remote_uids', ['seen' => (int) in_array('\\seen', $index[$ruid]['flags'], true), 'flagged' => (int) in_array('\\flagged', $index[$ruid]['flags'], true)], 'id = :id', ['id' => $r['id']]);
                    continue;
                }
                $target = null;
                if (!$m || $where === $roles['trash']) {
                    $target = self::providerFolder($client, $acc, 'trash_folder');
                } elseif ($where === $roles['archive'] && $roles['archive']) {
                    $target = self::providerFolder($client, $acc, 'archive_folder') ?: null;
                } elseif ($where === $roles['spam'] && $home !== $roles['spam'] && $spamRemote !== '') {
                    $target = $spamRemote;
                } elseif ($where === $roles['inbox'] && $home !== $roles['inbox']) {
                    $target = 'INBOX';
                } elseif (isset($pathOf[$where]) && $where !== $home) {
                    $target = $pathOf[$where]; // filed here in a folder of one's own: filed there too
                }
                if ($target !== null) {
                    try {
                        // Read here: read there too (an unread message in the provider Trash would
                        // still count as new in the other mail apps).
                        if ($m && (int) $m['is_read'] && !in_array('\\seen', $index[$ruid]['flags'], true)) {
                            $client->storeFlags($ruid, true, ['\\Seen']);
                        }
                        if ($target === '') {
                            // No Trash folder at the provider: marked deleted, as a mail app would.
                            $client->markDeleted($ruid);
                            $client->expunge();
                        } else {
                            $client->moveTo($ruid, $target);
                        }
                        DB::delete('remote_uids', 'id = :id', ['id' => $r['id']]);
                        // Moved to a place followed too: followed there at once under its new number
                        // (the next pull must not take it for a move made on the phone).
                        if ($target !== '' && isset($keyOf[$where]) && ($tkey = $keyOf[$where]) !== $local) {
                            $tinfo = $client->select($target);
                            foreach (array_slice($client->searchMessageId($msgid), -1) as $nuid) {
                                DB::insert('remote_uids', ['account_id' => $acc['id'], 'folder' => $tkey, 'validity' => $tinfo['uidvalidity'], 'uid' => $nuid, 'imported_at' => time(),
                                    'msgid' => mb_substr($msgid, 0, 255), 'seen' => (int) ($m['is_read'] ?? 0), 'flagged' => (int) ($m['is_flagged'] ?? 0)]);
                            }
                            $client->select($remote);
                        }
                        $report['moved']++;
                        Audit::log('sync.moved_there', mb_substr((string) ($m ? DB::value('SELECT subject FROM messages WHERE id = :id', ['id' => $m['id']]) : $msgid), 0, 150), ['box' => (string) $acc['login'], 'to' => $target !== '' ? $target : 'deleted'], $uid);
                        $actions++;
                    } catch (\RuntimeException $e) {
                        // Tried again at the next pass; shown on the account (not only in a log).
                        error_log('[m4w] two-way move: ' . $e->getMessage());
                        $report['failed']++;
                        $report['error'] = mb_substr(($target !== '' ? $target . ' : ' : '') . $e->getMessage(), 0, 300);
                    }
                    continue;
                }
                if (!$m || ($where !== $home && !isset($ownFolders[$where]))) {
                    continue; // elsewhere here (Sent…): left alone at the provider
                }
                // 3. Read / flagged: the side that changed since they last agreed wins.
                $pf = $index[$ruid]['flags'];
                $set = [];
                $store = [];
                foreach (['seen' => ['\\seen', 'is_read', '\\Seen'], 'flagged' => ['\\flagged', 'is_flagged', '\\Flagged']] as $col => [$flag, $field, $imapFlag]) {
                    $remoteOn = (int) in_array($flag, $pf, true);
                    $localOn = (int) $m[$field];
                    $last = (int) $r[$col];
                    if ($remoteOn === $localOn) {
                        if ($last !== $remoteOn) {
                            DB::update('remote_uids', [$col => $remoteOn], 'id = :id', ['id' => $r['id']]);
                        }
                        continue;
                    }
                    if ($last === -1) {
                        // Never compared before: read / flagged on either side counts.
                        $on = 1;
                        $localOn ? $store[] = [$imapFlag, true] : $set[$field] = 1;
                    } elseif ($remoteOn !== $last) {
                        $on = $remoteOn;
                        $set[$field] = $remoteOn;
                    } else {
                        $on = $localOn;
                        $store[] = [$imapFlag, (bool) $localOn];
                    }
                    DB::update('remote_uids', [$col => $on], 'id = :id', ['id' => $r['id']]);
                }
                if ($set) {
                    Mailbox::setFlags($uid, [(int) $m['id']], $set);
                }
                $report['flags'] += count($set) + count($store);
                foreach ($store as [$f, $on]) {
                    try {
                        $client->storeFlags($ruid, $on, [$f]);
                    } catch (\RuntimeException) {
                    }
                }
            }
        }
        $save();
    }

    /** Remove imported messages from the provider after the configured delay. */
    private static function cleanup(ImapClient $client, array $acc): void
    {
        // An own mailbox follows its own rule (never emptied unless chosen), not the one of the
        // collector / webmail mode.
        $days = self::isOwn($acc) ? (int) ($acc['leave_days'] ?? -1) : (int) Settings::get('webmail.leave_days', -1);
        if ($days < 0) {
            return;
        }
        // The collector set up on somebody's own mailbox: that mailbox keeps its own rule.
        if ((int) $acc['user_id'] === Collector::ACCOUNT && self::sharedWithOwn($acc)) {
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
                // Kept count, so that one can tell whether the webmail empties a mailbox.
                $key = 'webmail.deleted.' . (int) $acc['id'];
                Settings::set($key, json_encode(['n' => (int) ((json_decode((string) Settings::get($key, ''), true) ?: [])['n'] ?? 0) + count($rows), 'last' => time(), 'days' => $days]));
            } catch (\RuntimeException) {
                // retried at the next run
            }
        }
    }

    /** Messages this webmail removed from the provider mailbox: {n, last, days} or null. */
    public static function deletedStats(array $acc): ?array
    {
        $d = json_decode((string) Settings::get('webmail.deleted.' . (int) $acc['id'], ''), true);
        return is_array($d) && ($d['n'] ?? 0) > 0 ? $d : null;
    }

    /** Last two-way pass: what it saw and did, or null. */
    public static function twoWayReport(array $acc): ?array
    {
        $d = json_decode((string) Settings::get('webmail.twoway.' . (int) $acc['id'], ''), true);
        return is_array($d) ? $d : null;
    }

    /** The collector account and an own mailbox are one and the same mailbox. */
    public static function sharedWithOwn(array $collector): bool
    {
        $e = self::effective($collector);
        foreach (DB::all('SELECT * FROM remote_accounts WHERE forced = 1 AND user_id <> :c', ['c' => Collector::ACCOUNT]) as $own) {
            $o = self::effective($own);
            if (mb_strtolower((string) $o['login']) === mb_strtolower((string) $e['login']) && mb_strtolower((string) $o['imap_host']) === mb_strtolower((string) $e['imap_host'])) {
                return true;
            }
        }
        return false;
    }

    /** How often mailboxes at a provider are checked (minutes): every minute at most, 15 at least. */
    public static function interval(): int
    {
        return max(1, min(15, (int) Settings::get('webmail.interval', 1)));
    }

    /** Scheduler: every enabled account whose last run is older than the interval. */
    public static function syncAll(): int
    {
        $interval = self::interval() * 60 - 5;
        $n = 0;
        // The accounts of the current mode (the collector, or every user's mailbox), plus the
        // own mailboxes imposed by the administrator, in any mode.
        $which = match (true) {
            self::active() => 'user_id <> 0',
            Collector::active() => '(user_id = 0 OR forced = 1)',
            default => '(user_id <> 0 AND forced = 1)',
        };
        foreach (DB::all("SELECT * FROM remote_accounts WHERE enabled = 1 AND $which AND last_sync_at < :t", ['t' => time() - $interval]) as $acc) {
            $n += self::sync($acc)['fetched'];
        }
        return $n;
    }

    /** Opportunistic sync when someone looks at the mailbox (no scheduler needed for reading). */
    public static function syncIfDue(int $userId, int $minAge = 60): int
    {
        $accounts = [];
        if (self::active()) {
            $accounts[] = self::find($userId);
        } else {
            $accounts[] = self::personal($userId);
            if (Collector::active()) {
                $accounts[] = self::find(Collector::ACCOUNT);
            }
        }
        $n = 0;
        foreach (array_filter($accounts) as $acc) {
            if ((int) $acc['last_sync_at'] <= time() - $minAge) {
                $n += self::sync($acc)['fetched'];
            }
        }
        return $n;
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
