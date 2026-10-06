<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Audit;
use M4W\Core\Crypto;
use M4W\Core\Database as DB;

final class Users
{
    public const PREF_DEFAULTS = [
        'theme'          => 'auto',      // light | dark | auto
        'density'        => 'comfortable', // comfortable | compact
        'reading_pane'   => 'right',     // right | bottom | off
        'page_size'      => 50,
        'conversations'  => 1,
        'show_images'    => 0,           // always show remote images
        'mark_read_delay'=> 1,           // seconds, -1 = manual
        'compose_font'   => 'Arial, Helvetica, sans-serif',
        'compose_size'   => '14px',
        'shortcuts'      => 1,
        'undo_send'      => 1,
        'notifications'  => 1,
        'reply_position' => 'top',
        'avatar'         => '',
        'accent'         => '',
    ];

    /** Allowed values for enumerated preferences (null = free value). */
    private static function prefChoices(string $key): ?array
    {
        return match ($key) {
            'theme' => ['light', 'dark', 'auto'],
            'density' => ['comfortable', 'compact'],
            'reading_pane' => ['right', 'bottom', 'off'],
            'reply_position' => ['top', 'bottom'],
            'compose_font' => array_keys(\M4W\Controller\SettingsController::fonts()),
            'compose_size' => ['12px', '13px', '14px', '16px', '18px'],
            'conversations', 'show_images', 'shortcuts', 'undo_send', 'notifications' => [0, 1],
            default => null,
        };
    }

    public static function find(int $id): ?array
    {
        $u = DB::one('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        return $u ? self::hydrate($u) : null;
    }

    public static function findByEmail(string $email): ?array
    {
        $u = DB::one('SELECT * FROM users WHERE email = :e', ['e' => mb_strtolower(trim($email))]);
        return $u ? self::hydrate($u) : null;
    }

    private static function hydrate(array $u): array
    {
        $p = json_decode((string) $u['prefs'], true);
        $u['prefs'] = array_replace(self::PREF_DEFAULTS, is_array($p) ? $p : []);
        $u['name'] = $u['display_name'] !== '' ? $u['display_name'] : trim($u['first_name'] . ' ' . $u['last_name']);
        if ($u['name'] === '') {
            $u['name'] = strstr($u['email'], '@', true) ?: $u['email'];
        }
        return $u;
    }

    /** Resolve a recipient address (user email or alias) to a local active user. */
    public static function resolveLocal(string $address): ?array
    {
        $address = mb_strtolower(trim($address));
        // Strip sub-addressing (user+tag@domain).
        $plain = preg_replace('/\+[^@]*@/', '@', $address) ?? $address;
        foreach (array_unique([$address, $plain]) as $a) {
            $u = self::findByEmail($a);
            if ($u) {
                return $u['status'] === 'active' ? $u : null;
            }
            $alias = DB::one('SELECT user_id FROM aliases WHERE address = :a', ['a' => $a]);
            if ($alias) {
                $u = self::find((int) $alias['user_id']);
                return $u && $u['status'] === 'active' ? $u : null;
            }
        }
        return null;
    }

    public static function isLocalDomain(string $domain): bool
    {
        return (bool) DB::value('SELECT COUNT(*) FROM domains WHERE name = :d AND active = 1', ['d' => mb_strtolower($domain)]);
    }

    public static function create(array $data): int
    {
        $now = time();
        $email = mb_strtolower(trim((string) $data['email']));
        $id = DB::insert('users', [
            'email'                 => $email,
            'password_hash'         => Crypto::hashPassword((string) $data['password']),
            'display_name'          => trim((string) ($data['display_name'] ?? '')),
            'first_name'            => trim((string) ($data['first_name'] ?? '')),
            'last_name'             => trim((string) ($data['last_name'] ?? '')),
            'job_title'             => trim((string) ($data['job_title'] ?? '')),
            'department'            => trim((string) ($data['department'] ?? '')),
            'phone'                 => trim((string) ($data['phone'] ?? '')),
            'mobile'                => trim((string) ($data['mobile'] ?? '')),
            'role'                  => ($data['role'] ?? 'user') === 'admin' ? 'admin' : 'user',
            'status'                => ($data['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active',
            'quota_mb'              => max(0, (int) ($data['quota_mb'] ?? 2048)),
            'signature_template_id' => !empty($data['signature_template_id']) ? (int) $data['signature_template_id'] : null,
            'language'              => (string) ($data['language'] ?? 'fr'),
            'prefs'                 => json_encode([]),
            'personal_signature'    => '',
            'totp_secret'           => '',
            'must_change_password'  => !empty($data['must_change_password']) ? 1 : 0,
            'password_changed_at'   => $now,
            'created_at'            => $now,
            'updated_at'            => $now,
        ]);
        Folders::ensureDefaults($id);
        $domain = substr($email, strpos($email, '@') + 1);
        if (!self::isLocalDomain($domain)) {
            if (!DB::value('SELECT COUNT(*) FROM domains WHERE name = :d', ['d' => $domain])) {
                DB::insert('domains', ['name' => $domain, 'active' => 1, 'created_at' => $now]);
            }
        }
        return $id;
    }

    public static function update(int $id, array $data): void
    {
        $allowed = ['display_name', 'first_name', 'last_name', 'job_title', 'department', 'phone', 'mobile',
            'role', 'status', 'quota_mb', 'signature_template_id', 'language', 'personal_signature', 'must_change_password'];
        $set = array_intersect_key($data, array_flip($allowed));
        if (isset($data['password']) && $data['password'] !== '') {
            $set['password_hash'] = Crypto::hashPassword((string) $data['password']);
            $set['password_changed_at'] = time();
        }
        if (array_key_exists('signature_template_id', $set)) {
            $set['signature_template_id'] = $set['signature_template_id'] ? (int) $set['signature_template_id'] : null;
        }
        if (!$set) {
            return;
        }
        $set['updated_at'] = time();
        DB::update('users', $set, 'id = :id', ['id' => $id]);
    }

    public static function savePrefs(int $id, array $prefs): void
    {
        $u = self::find($id);
        if (!$u) {
            return;
        }
        $clean = [];
        foreach ($prefs as $k => $v) {
            if (array_key_exists($k, self::PREF_DEFAULTS)) {
                $v = is_int(self::PREF_DEFAULTS[$k]) ? (int) $v : mb_substr((string) $v, 0, 200);
                $v = match ($k) {
                    'page_size' => max(20, min(200, (int) $v)),
                    'mark_read_delay' => max(-1, min(30, (int) $v)),
                    'accent' => preg_match('/^#[0-9a-f]{6}$/i', (string) $v) ? (string) $v : '',
                    'avatar' => '',
                    default => $v,
                };
                $allowed = self::prefChoices($k);
                if ($allowed !== null && !in_array($v, $allowed, true)) {
                    continue; // values that end up in CSS or outgoing mail are whitelisted
                }
                $clean[$k] = $v;
            }
        }
        $merged = array_replace($u['prefs'], $clean);
        DB::update('users', ['prefs' => json_encode($merged), 'updated_at' => time()], 'id = :id', ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        Files::deleteUser($id);
        Delegation::deleteUser($id);
        DB::transaction(function () use ($id) {
            foreach (['messages', 'folders', 'contacts', 'rules', 'vacation_log', 'fetch_accounts', 'user_sessions', 'uploads', 'aliases'] as $t) {
                DB::delete($t, 'user_id = :u', ['u' => $id]);
            }
            DB::delete('vacations', 'user_id = :u', ['u' => $id]);
            DB::delete('forwardings', 'user_id = :u', ['u' => $id]);
            DB::delete('users', 'id = :u', ['u' => $id]);
        });
        $dir = storage_path('mail/' . $id);
        self::rrmdir($dir);
        Audit::log('user.deleted', (string) $id);
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    public static function recalcUsage(int $userId): int
    {
        $bytes = (int) DB::value('SELECT COALESCE(SUM(size),0) FROM messages WHERE user_id = :u', ['u' => $userId]);
        DB::update('users', ['used_bytes' => $bytes], 'id = :id', ['id' => $userId]);
        return $bytes;
    }

    public static function overQuota(array $user, int $extra = 0): bool
    {
        $quota = (int) $user['quota_mb'];
        return $quota > 0 && ((int) $user['used_bytes'] + $extra) > $quota * 1024 * 1024;
    }
}
