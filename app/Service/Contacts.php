<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;

final class Contacts
{
    public static function list(int $userId, string $q = '', int $limit = 500): array
    {
        $params = ['u' => $userId];
        $where = 'user_id = :u';
        if ($q !== '') {
            $where .= " AND (LOWER(name) LIKE :q OR LOWER(email) LIKE :q OR LOWER(company) LIKE :q)";
            $params['q'] = '%' . mb_strtolower($q) . '%';
        }
        return DB::all("SELECT * FROM contacts WHERE $where ORDER BY is_favorite DESC, auto_collected ASC, name, email LIMIT $limit", $params);
    }

    /** Autocomplete: personal contacts + colleagues of the organisation directory. */
    public static function suggest(int $userId, string $q, int $limit = 8): array
    {
        $q = mb_strtolower(trim($q));
        if ($q === '') {
            return [];
        }
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        $rows = DB::all(
            "SELECT name, email, use_count FROM contacts WHERE user_id = :u AND (LOWER(name) LIKE :q ESCAPE '!' OR LOWER(email) LIKE :q ESCAPE '!')
             ORDER BY is_favorite DESC, use_count DESC, last_used_at DESC LIMIT $limit",
            ['u' => $userId, 'q' => $like]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[mb_strtolower($r['email'])] = ['name' => $r['name'], 'email' => $r['email'], 'type' => 'contact'];
        }
        $dir = DB::all(
            "SELECT display_name, first_name, last_name, email, job_title FROM users WHERE status = 'active' AND id <> :u
             AND (LOWER(email) LIKE :q ESCAPE '!' OR LOWER(display_name) LIKE :q ESCAPE '!' OR LOWER(first_name) LIKE :q ESCAPE '!' OR LOWER(last_name) LIKE :q ESCAPE '!')
             LIMIT $limit",
            ['u' => $userId, 'q' => $like]
        );
        foreach ($dir as $d) {
            $k = mb_strtolower($d['email']);
            if (!isset($out[$k])) {
                $name = $d['display_name'] ?: trim($d['first_name'] . ' ' . $d['last_name']);
                $out[$k] = ['name' => $name, 'email' => $d['email'], 'type' => 'directory', 'title' => $d['job_title']];
            }
        }
        return array_slice(array_values($out), 0, $limit);
    }

    /** Remember recipients the user writes to (auto-collected contacts). */
    public static function touchRecipients(int $userId, array $addresses): void
    {
        foreach ($addresses as $a) {
            $email = mb_strtolower($a['email']);
            if ($email === '') {
                continue;
            }
            $row = DB::one('SELECT id, name FROM contacts WHERE user_id = :u AND LOWER(email) = :e', ['u' => $userId, 'e' => $email]);
            if ($row) {
                DB::run('UPDATE contacts SET use_count = use_count + 1, last_used_at = :t WHERE id = :id', ['t' => time(), 'id' => $row['id']]);
                if ($row['name'] === '' && ($a['name'] ?? '') !== '') {
                    DB::update('contacts', ['name' => $a['name']], 'id = :id', ['id' => $row['id']]);
                }
            } else {
                DB::insert('contacts', [
                    'user_id' => $userId, 'name' => mb_substr($a['name'] ?? '', 0, 190), 'email' => $email,
                    'auto_collected' => 1, 'use_count' => 1, 'last_used_at' => time(), 'created_at' => time(), 'notes' => '',
                ]);
            }
        }
    }

    public static function touchSender(int $userId, array $from): void
    {
        // Only enrich existing contacts' names; don't collect every incoming sender.
        if (($from['email'] ?? '') === '' || ($from['name'] ?? '') === '') {
            return;
        }
        DB::run(
            "UPDATE contacts SET name = :n WHERE user_id = :u AND LOWER(email) = :e AND name = ''",
            ['n' => mb_substr($from['name'], 0, 190), 'u' => $userId, 'e' => mb_strtolower($from['email'])]
        );
    }

    public static function save(int $userId, array $d, ?int $id = null): int
    {
        $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
        if (!is_valid_email($email)) {
            throw new \InvalidArgumentException(t('contacts.invalid_email'));
        }
        $data = [
            'name'           => mb_substr(trim((string) ($d['name'] ?? '')), 0, 190),
            'email'          => $email,
            'phone'          => mb_substr(trim((string) ($d['phone'] ?? '')), 0, 60),
            'company'        => mb_substr(trim((string) ($d['company'] ?? '')), 0, 190),
            'notes'          => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 2000),
            'is_favorite'    => !empty($d['is_favorite']) ? 1 : 0,
            'auto_collected' => 0,
        ];
        if ($id) {
            DB::update('contacts', $data, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $userId]);
            return $id;
        }
        $existing = DB::one('SELECT id FROM contacts WHERE user_id = :u AND LOWER(email) = :e', ['u' => $userId, 'e' => $email]);
        if ($existing) {
            DB::update('contacts', $data, 'id = :id', ['id' => $existing['id']]);
            return (int) $existing['id'];
        }
        return DB::insert('contacts', $data + ['user_id' => $userId, 'created_at' => time()]);
    }

    public static function delete(int $userId, array $ids): void
    {
        [$in, $p] = DB::in('c', array_map('intval', $ids));
        DB::run("DELETE FROM contacts WHERE user_id = :u AND id IN $in", $p + ['u' => $userId]);
    }

    /** Import vCard (.vcf) or CSV (name,email,...). Returns count. */
    public static function import(int $userId, string $content): int
    {
        $n = 0;
        if (stripos($content, 'BEGIN:VCARD') !== false) {
            preg_match_all('/BEGIN:VCARD(.*?)END:VCARD/si', $content, $cards);
            foreach ($cards[1] as $card) {
                $card = preg_replace("/\r?\n[ \t]/", '', $card) ?? $card;
                $name = preg_match('/^FN[^:]*:(.*)$/mi', $card, $m) ? trim($m[1]) : '';
                $email = preg_match('/^EMAIL[^:]*:(.*)$/mi', $card, $m) ? trim($m[1]) : '';
                $tel = preg_match('/^TEL[^:]*:(.*)$/mi', $card, $m) ? trim($m[1]) : '';
                $org = preg_match('/^ORG[^:]*:(.*)$/mi', $card, $m) ? trim(str_replace(';', ' ', $m[1])) : '';
                if (is_valid_email($email)) {
                    self::save($userId, ['name' => $name, 'email' => $email, 'phone' => $tel, 'company' => $org]);
                    $n++;
                }
            }
            return $n;
        }
        foreach (preg_split('/\r?\n/', $content) ?: [] as $i => $line) {
            $cols = str_getcsv($line, str_contains($line, ';') ? ';' : ',', '"', '\\');
            $email = '';
            $name = '';
            foreach ($cols as $c) {
                if ($email === '' && is_valid_email(trim($c))) {
                    $email = trim($c);
                } elseif ($name === '' && trim($c) !== '' && !str_contains($c, '@')) {
                    $name = trim($c);
                }
            }
            if ($email !== '') {
                self::save($userId, ['name' => $name, 'email' => $email]);
                $n++;
            }
        }
        return $n;
    }

    public static function exportVcf(int $userId): string
    {
        $out = '';
        foreach (self::list($userId, '', 100000) as $c) {
            if ($c['auto_collected']) {
                continue;
            }
            // RFC 6350 escaping; CR and other control characters could otherwise inject properties.
            $esc = static fn($s) => str_replace(['\\', ',', ';', "\r\n", "\n", "\r"], ['\\\\', '\\,', '\\;', '\\n', '\\n', '\\n'], preg_replace('/[\x00-\x09\x0b\x0c\x0e-\x1f\x7f]/', '', (string) $s) ?? '');
            $out .= "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:" . $esc($c['name'] ?: $c['email']) . "\r\nEMAIL;TYPE=INTERNET:" . $esc($c['email']) . "\r\n";
            if ($c['phone']) {
                $out .= 'TEL:' . $esc($c['phone']) . "\r\n";
            }
            if ($c['company']) {
                $out .= 'ORG:' . $esc($c['company']) . "\r\n";
            }
            $out .= "END:VCARD\r\n";
        }
        return $out;
    }
}
