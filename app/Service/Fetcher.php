<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Crypto;
use M4W\Core\Database as DB;
use M4W\Mail\ImapClient;

/**
 * Pulls messages from external IMAP accounts into local mailboxes ("Check mail from other accounts").
 */
final class Fetcher
{
    public static function forUser(int $userId): array
    {
        return DB::all('SELECT * FROM fetch_accounts WHERE user_id = :u ORDER BY id', ['u' => $userId]);
    }

    public static function save(int $userId, array $d, ?int $id = null): int
    {
        $data = [
            'label'         => mb_substr(trim((string) ($d['label'] ?? '')), 0, 190),
            'protocol'      => 'imap',
            'host'          => mb_substr(trim((string) ($d['host'] ?? '')), 0, 190),
            'port'          => max(1, min(65535, (int) ($d['port'] ?? 993))),
            'security'      => in_array($d['security'] ?? 'ssl', ['ssl', 'tls', 'none'], true) ? $d['security'] : 'ssl',
            'username'      => mb_substr(trim((string) ($d['username'] ?? '')), 0, 190),
            'remote_folder' => mb_substr(trim((string) ($d['remote_folder'] ?? 'INBOX')) ?: 'INBOX', 0, 190),
            'delete_remote' => !empty($d['delete_remote']) ? 1 : 0,
            'apply_rules'   => !empty($d['apply_rules']) ? 1 : 0,
            'enabled'       => !empty($d['enabled']) ? 1 : 0,
        ];
        if ($data['host'] === '' || $data['username'] === '' || preg_match('/[\r\n\x00]/', $data['username'] . $data['remote_folder'] . (string) ($d['password'] ?? '')) || !preg_match('/^[a-z0-9.-]+$/i', $data['host'])) {
            throw new \InvalidArgumentException(t('fetch.invalid'));
        }
        self::assertPublicHost($data['host']);
        if (($d['password'] ?? '') !== '') {
            $data['password_enc'] = Crypto::encrypt((string) $d['password']);
        }
        if ($id) {
            DB::update('fetch_accounts', $data, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $userId]);
            return $id;
        }
        if (empty($data['password_enc'])) {
            throw new \InvalidArgumentException(t('fetch.need_password'));
        }
        return DB::insert('fetch_accounts', $data + ['user_id' => $userId, 'created_at' => time()]);
    }

    /** Prevent SSRF: user-defined servers must resolve to public addresses. */
    public static function assertPublicHost(string $host): string
    {
        if ((int) \M4W\Core\Settings::get('features.fetch_allow_private', 0)) {
            return $host;
        }
        return \M4W\Core\Net::publicIp($host);
    }

    /** @return array{fetched:int,error:string} */
    public static function run(array $acc, int $max = 200): array
    {
        $user = Users::find((int) $acc['user_id']);
        if (!$user) {
            return ['fetched' => 0, 'error' => 'user missing'];
        }
        $fetched = 0;
        $error = '';
        try {
            $client = new ImapClient($acc['host'], (int) $acc['port'], $acc['security'], 30, true, self::assertPublicHost((string) $acc['host']));
            $client->connect($acc['username'], Crypto::decrypt((string) $acc['password_enc']) ?? '');
            $info = $client->select($acc['remote_folder']);
            $lastUid = (int) $acc['last_uid'];
            if ((int) $acc['uid_validity'] !== $info['uidvalidity']) {
                $lastUid = 0;
            }
            $uids = array_slice($client->searchUidsAbove($lastUid), 0, $max);
            foreach ($uids as $uid) {
                $raw = $client->fetchRaw($uid);
                if ($raw === null) {
                    continue;
                }
                $header = 'X-M4W-Fetched-From: ' . $acc['username'] . '@' . $acc['host'] . "\r\n";
                Delivery::deliver($header . $raw, '', [$user['email']], 'fetch', (bool) $acc['apply_rules']);
                if ($acc['delete_remote']) {
                    $client->markDeleted($uid);
                }
                $lastUid = max($lastUid, $uid);
                $fetched++;
                DB::update('fetch_accounts', ['last_uid' => $lastUid, 'uid_validity' => $info['uidvalidity']], 'id = :id', ['id' => $acc['id']]);
            }
            if ($acc['delete_remote'] && $fetched) {
                $client->expunge();
            }
            $client->logout();
        } catch (\Throwable $e) {
            isset($client) && $client->logout();
            $error = mb_substr($e->getMessage(), 0, 500);
        }
        DB::update('fetch_accounts', ['last_run_at' => time(), 'last_error' => $error], 'id = :id', ['id' => $acc['id']]);
        return ['fetched' => $fetched, 'error' => $error];
    }

    public static function runAll(): int
    {
        $n = 0;
        foreach (DB::all('SELECT * FROM fetch_accounts WHERE enabled = 1 AND last_run_at < :t', ['t' => time() - 240]) as $acc) {
            $n += self::run($acc)['fetched'];
        }
        return $n;
    }
}
