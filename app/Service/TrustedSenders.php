<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Mail\Address;

/**
 * Senders a person told the filter about with "Not junk": their mail goes to the inbox from
 * then on (as long as it is authentic: a forged sender gains nothing). "Junk" on one of their
 * messages takes the trust back.
 */
final class TrustedSenders
{
    /** @return array<int,array{address:string,created_at:int}> */
    public static function list(int $userId): array
    {
        return DB::all('SELECT address, created_at FROM spam_trusted WHERE user_id = :u ORDER BY address', ['u' => $userId]);
    }

    public static function has(int $userId, string $address): bool
    {
        $address = mb_strtolower(trim($address));
        return $address !== '' && (bool) DB::value('SELECT 1 FROM spam_trusted WHERE user_id = :u AND address = :a', ['u' => $userId, 'a' => $address]);
    }

    public static function add(int $userId, string $address): bool
    {
        $address = mb_strtolower(trim($address));
        if (!is_valid_email(Address::asciiEmail($address)) || self::has($userId, $address)) {
            return false;
        }
        DB::insert('spam_trusted', ['user_id' => $userId, 'address' => mb_substr($address, 0, 255), 'created_at' => time()]);
        return true;
    }

    public static function remove(int $userId, string $address): void
    {
        DB::delete('spam_trusted', 'user_id = :u AND address = :a', ['u' => $userId, 'a' => mb_strtolower(trim($address))]);
    }

    /**
     * "Not junk" on messages: back to the inbox, the filter learns (Mailbox::move), the senders
     * are trusted. @return array{count:int,senders:string[]}
     */
    public static function notJunk(int $userId, array $ids, int $by): array
    {
        $senders = [];
        foreach ($ids as $id) {
            $m = Mailbox::get($userId, (int) $id);
            $from = $m ? mb_strtolower((string) $m['from_email']) : '';
            // Never trust an address of our own domains this way (anyone can write it).
            if ($from !== '' && !Users::isLocalDomain(Address::domain($from)) && self::add($userId, $from)) {
                $senders[] = $from;
            }
        }
        $n = Mailbox::move($userId, $ids, (int) Folders::byRole($userId, 'inbox')['id']);
        \M4W\Core\Audit::log('spam.false_positive', implode(', ', array_slice($senders, 0, 5)), ['count' => count($ids)], $by);
        return ['count' => $n, 'senders' => $senders];
    }
}
