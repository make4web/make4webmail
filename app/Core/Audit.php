<?php
declare(strict_types=1);

namespace M4W\Core;

final class Audit
{
    public static function log(string $action, string $target = '', array $details = [], ?int $userId = null): void
    {
        try {
            Database::insert('audit_log', [
                'user_id'    => $userId ?? Auth::id(),
                'action'     => $action,
                'target'     => mb_substr($target, 0, 255),
                'details'    => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : '',
                'ip'         => App::request()?->ip() ?? 'cli',
                'created_at' => time(),
            ]);
        } catch (\Throwable $e) {
            error_log('audit failure: ' . $e->getMessage());
        }
    }
}
