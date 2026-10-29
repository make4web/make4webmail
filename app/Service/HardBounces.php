<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Mail\Address;

/**
 * Addresses that do not exist (hard bounce: "550 5.1.1 user unknown", a domain that does not
 * exist…), remembered so that no message is ever sent to them again: sending there again and
 * again harms the reputation of the server. Only the address is at fault, never a refusal for
 * spam, policy, a full mailbox or a temporary trouble. Removed by an administrator (Logs) if the
 * address comes back to life.
 */
final class HardBounces
{
    /** The answer says the address (or its domain) does not exist. */
    public static function isHard(string $answer): bool
    {
        // Temporary (4xx) or a refusal about the message or the sender, not the address.
        if (!preg_match('/(?:^|\D)5\d\d(?:\D|$)/', $answer) || preg_match('/\b5\.(?:7|2\.2|3|4|6)\.\d+\b/', $answer)) {
            return false;
        }
        return (bool) preg_match('/\b5\.1\.(?:1|2|3|6|10)\b/', $answer)
            || (bool) preg_match('/user unknown|unknown user|no such user|does not exist|doesn\'t exist|invalid recipient|recipient (?:address )?rejected|address rejected|mailbox (?:not found|unavailable|does not)|no mailbox|not a valid mailbox|account (?:has been )?disabled|inexistant|n\'existe pas|introuvable/i', $answer);
    }

    public static function record(string $email, string $answer, string $source = 'smtp'): void
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return;
        }
        $now = time();
        $row = DB::one('SELECT id, hits FROM hard_bounces WHERE email = :e', ['e' => $email]);
        if ($row) {
            DB::update('hard_bounces', ['reason' => mb_substr($answer, 0, 500), 'last_at' => $now, 'hits' => (int) $row['hits'] + 1], 'id = :id', ['id' => $row['id']]);
            return;
        }
        DB::insert('hard_bounces', ['email' => $email, 'reason' => mb_substr($answer, 0, 500), 'source' => $source, 'first_at' => $now, 'last_at' => $now, 'hits' => 1]);
        \M4W\Core\Audit::log('bounce.recorded', $email, ['reason' => mb_substr($answer, 0, 200), 'source' => $source]);
    }

    /** Records the hard ones among refused recipients (address => answer). */
    public static function fromFailures(array $failed, string $source = 'smtp'): void
    {
        foreach ($failed as $email => $answer) {
            if (self::isHard((string) $answer)) {
                self::record((string) $email, (string) $answer, $source);
            }
        }
    }

    /** @return array<string, array> the given addresses that are refused for good */
    public static function among(array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map(static fn($e) => mb_strtolower(trim((string) $e)), $emails))));
        if (!$emails) {
            return [];
        }
        $out = [];
        foreach (array_chunk($emails, 200) as $chunk) {
            $in = [];
            $p = [];
            foreach ($chunk as $i => $e) {
                $in[] = ':e' . $i;
                $p['e' . $i] = $e;
            }
            foreach (DB::all('SELECT * FROM hard_bounces WHERE email IN (' . implode(',', $in) . ')', $p) as $r) {
                $out[$r['email']] = $r;
            }
        }
        return $out;
    }

    public static function all(string $q = ''): array
    {
        return $q !== '' ? DB::all('SELECT * FROM hard_bounces WHERE email LIKE :q ORDER BY last_at DESC LIMIT 500', ['q' => '%' . mb_strtolower($q) . '%'])
            : DB::all('SELECT * FROM hard_bounces ORDER BY last_at DESC LIMIT 500');
    }

    public static function remove(int $id): ?string
    {
        $email = DB::value('SELECT email FROM hard_bounces WHERE id = :id', ['id' => $id]);
        if ($email) {
            DB::delete('hard_bounces', 'id = :id', ['id' => $id]);
            \M4W\Core\Audit::log('bounce.removed', (string) $email);
        }
        return $email ? (string) $email : null;
    }

    public static function addByHand(string $email, string $note): bool
    {
        $email = mb_strtolower(trim($email));
        if (!is_valid_email(Address::asciiEmail($email))) {
            return false;
        }
        self::record($email, $note !== '' ? $note : t('bounce.by_hand'), 'admin');
        return true;
    }
}
