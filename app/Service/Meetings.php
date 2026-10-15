<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Settings;
use Sabre\VObject\Component\VCalendar;

/**
 * Online meetings attached to events: Jitsi Meet (no account, a private link at once),
 * Microsoft Teams, Google Meet and Zoom. For the last three, the administrator either connects
 * the organisation's account once (the meeting is created by their API, in the organizer's
 * name), or lets everyone use their own personal meeting link.
 */
final class Meetings
{
    public const PROVIDERS = ['jitsi', 'teams', 'meet', 'zoom'];
    public const LABELS = ['jitsi' => 'Jitsi Meet', 'teams' => 'Microsoft Teams', 'meet' => 'Google Meet', 'zoom' => 'Zoom'];
    /** Personal links accepted for each provider (host must match). */
    private const HOSTS = ['teams' => '/(^|\.)teams\.(microsoft|live)\.com$/', 'meet' => '/^meet\.google\.com$/', 'zoom' => '/(^|\.)zoom\.(us|com)$/'];

    /** @var ?callable test hook: fn(string $method, string $url, array $headers, string $body): array{0:int,1:string} */
    public static $transport = null;

    /** off | api | personal (Jitsi: off | on) */
    public static function mode(string $provider): string
    {
        $m = (string) Settings::get('meet.' . $provider, $provider === 'jitsi' ? 'on' : 'off');
        return in_array($m, ['off', 'on', 'api', 'personal'], true) ? $m : 'off';
    }

    public static function personalLink(array $user, string $provider): string
    {
        return (string) ($user['prefs']['meet_' . $provider] ?? '');
    }

    public static function validPersonal(string $provider, string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return str_starts_with($url, 'https://') && isset(self::HOSTS[$provider]) && preg_match(self::HOSTS[$provider], $host) === 1 && strlen($url) <= 500;
    }

    /** Providers this person can use now. @return array<string,string> code => label */
    public static function available(array $user): array
    {
        $out = [];
        foreach (self::PROVIDERS as $p) {
            $m = self::mode($p);
            if (($p === 'jitsi' && $m === 'on') || ($m === 'api' && self::configured($p)) || ($m === 'personal' && self::personalLink($user, $p) !== '')) {
                $out[$p] = self::LABELS[$p];
            }
        }
        return $out;
    }

    public static function configured(string $p): bool
    {
        return match ($p) {
            'zoom' => Settings::get('meet.zoom_account', '') !== '' && Settings::get('meet.zoom_client', '') !== '' && Settings::secret('meet.zoom_secret') !== '',
            'teams' => Settings::get('meet.teams_tenant', '') !== '' && Settings::get('meet.teams_client', '') !== '' && Settings::secret('meet.teams_secret') !== '',
            'meet' => Settings::secret('meet.google_key') !== '',
            default => true,
        };
    }

    // ---------------------------------------------------------------- creation

    /**
     * A meeting for this event. @return array{provider:string,url:string,id:string}
     */
    public static function create(array $user, string $provider, string $title, int $start, int $end): array
    {
        if (!isset(self::available($user)[$provider])) {
            throw new \InvalidArgumentException(t('meet.unavailable'));
        }
        if ($provider === 'jitsi') {
            $base = rtrim((string) Settings::get('meet.jitsi_url', 'https://meet.jit.si'), '/');
            // Unguessable room name: anyone with the link can join, nobody can find it.
            $room = 'M4W-' . bin2hex(random_bytes(10));
            return ['provider' => 'jitsi', 'url' => $base . '/' . $room, 'id' => $room];
        }
        if (self::mode($provider) === 'personal') {
            return ['provider' => $provider, 'url' => self::personalLink($user, $provider), 'id' => ''];
        }
        try {
            return match ($provider) {
                'zoom' => self::zoomCreate($user, $title, $start, $end),
                'teams' => self::teamsCreate($user, $title, $start, $end),
                'meet' => self::meetCreate($user),
            };
        } catch (\RuntimeException $e) {
            error_log('[m4w] ' . $provider . ' meeting: ' . $e->getMessage());
            throw new \InvalidArgumentException(t('meet.failed', ['provider' => self::LABELS[$provider]]) . ' ' . $e->getMessage());
        }
    }

    /** New time or title for a meeting created by API (best effort: the link stays valid anyway). */
    public static function update(array $user, array $info, string $title, int $start, int $end): void
    {
        if ($info['id'] === '' || self::mode($info['provider']) !== 'api') {
            return;
        }
        try {
            if ($info['provider'] === 'zoom') {
                self::http('PATCH', 'https://api.zoom.us/v2/meetings/' . rawurlencode($info['id']), ['Authorization: Bearer ' . self::zoomToken()],
                    self::zoomBody($title, $start, $end));
            } elseif ($info['provider'] === 'teams') {
                self::http('PATCH', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($user['email']) . '/onlineMeetings/' . rawurlencode($info['id']),
                    ['Authorization: Bearer ' . self::teamsToken()], json_encode(['subject' => $title, 'startDateTime' => gmdate('c', $start), 'endDateTime' => gmdate('c', $end)]));
            }
        } catch (\Throwable $e) {
            error_log('[m4w] meeting update: ' . $e->getMessage());
        }
    }

    public static function delete(array $user, array $info): void
    {
        if ($info['id'] === '' || self::mode($info['provider']) !== 'api') {
            return;
        }
        try {
            if ($info['provider'] === 'zoom') {
                self::http('DELETE', 'https://api.zoom.us/v2/meetings/' . rawurlencode($info['id']), ['Authorization: Bearer ' . self::zoomToken()]);
            } elseif ($info['provider'] === 'teams') {
                self::http('DELETE', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($user['email']) . '/onlineMeetings/' . rawurlencode($info['id']), ['Authorization: Bearer ' . self::teamsToken()]);
            }
        } catch (\Throwable $e) {
            error_log('[m4w] meeting delete: ' . $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- the event side

    /** @return ?array{provider:string,url:string,id:string} */
    public static function of(VCalendar $cal): ?array
    {
        $m = $cal->getBaseComponent('VEVENT') ?? $cal->VEVENT;
        if (!$m || !isset($m->{'X-M4W-MEETING'})) {
            return null;
        }
        return ['provider' => (string) $m->{'X-M4W-MEETING'}, 'url' => (string) ($m->{'X-M4W-MEETING-URL'} ?? ''), 'id' => (string) ($m->{'X-M4W-MEETING-ID'} ?? '')];
    }

    /** Write the meeting into the event: standard CONFERENCE property and a line in the description. */
    public static function attach(VCalendar $cal, array $info): void
    {
        $label = self::LABELS[$info['provider']] ?? 'Visio';
        foreach ($cal->VEVENT as $v) {
            self::strip($v);
            $v->{'X-M4W-MEETING'} = $info['provider'];
            $v->{'X-M4W-MEETING-URL'} = $info['url'];
            if ($info['id'] !== '') {
                $v->{'X-M4W-MEETING-ID'} = $info['id'];
            }
            $v->add('CONFERENCE', $info['url'], ['VALUE' => 'URI', 'FEATURE' => ['AUDIO', 'VIDEO'], 'LABEL' => $label]);
            $v->URL = $info['url'];
            // Every calendar shows the description: the link is there for Outlook and phones too.
            $desc = rtrim((string) ($v->DESCRIPTION ?? ''));
            $v->DESCRIPTION = ($desc !== '' ? $desc . "\n\n" : '') . t('meet.join_line', ['provider' => $label]) . "\n" . $info['url'];
            if (trim((string) ($v->LOCATION ?? '')) === '') {
                $v->LOCATION = $label;
            }
        }
    }

    /** The description as the person wrote it (without the line we add). */
    public static function userDescription($v): string
    {
        $d = (string) ($v->DESCRIPTION ?? '');
        $url = (string) ($v->{'X-M4W-MEETING-URL'} ?? '');
        return $url !== '' ? rtrim(preg_replace('/\n*[^\n]*\n' . preg_quote($url, '/') . '\s*$/u', '', $d) ?? $d) : $d;
    }

    public static function detach(VCalendar $cal): void
    {
        foreach ($cal->VEVENT as $v) {
            $loc = trim((string) ($v->LOCATION ?? ''));
            $provider = (string) ($v->{'X-M4W-MEETING'} ?? '');
            self::strip($v);
            if ($provider !== '' && $loc === (self::LABELS[$provider] ?? '')) {
                unset($v->LOCATION);
            }
        }
    }

    private static function strip($v): void
    {
        $url = (string) ($v->{'X-M4W-MEETING-URL'} ?? '');
        if ($url !== '' && isset($v->DESCRIPTION)) {
            // Remove the line we added (and only it).
            $d = preg_replace('/\n*[^\n]*\n' . preg_quote($url, '/') . '\s*$/u', '', (string) $v->DESCRIPTION) ?? (string) $v->DESCRIPTION;
            if (trim($d) === '') {
                unset($v->DESCRIPTION);
            } else {
                $v->DESCRIPTION = $d;
            }
        }
        if ($url !== '' && (string) ($v->URL ?? '') === $url) {
            unset($v->URL);
        }
        unset($v->{'X-M4W-MEETING'}, $v->{'X-M4W-MEETING-URL'}, $v->{'X-M4W-MEETING-ID'}, $v->CONFERENCE);
    }

    // ---------------------------------------------------------------- providers

    private static function zoomToken(): string
    {
        [$st, $body] = self::http('POST', 'https://zoom.us/oauth/token?grant_type=account_credentials&account_id=' . rawurlencode((string) Settings::get('meet.zoom_account', '')),
            ['Authorization: Basic ' . base64_encode(Settings::get('meet.zoom_client', '') . ':' . Settings::secret('meet.zoom_secret')), 'Content-Type: application/x-www-form-urlencoded'], '');
        $tok = json_decode($body, true)['access_token'] ?? '';
        if ($st !== 200 || $tok === '') {
            throw new \RuntimeException('Zoom: ' . self::err($body, $st));
        }
        return $tok;
    }

    private static function zoomBody(string $title, int $start, int $end): string
    {
        return (string) json_encode(['topic' => mb_substr($title, 0, 200), 'type' => 2, 'start_time' => gmdate('Y-m-d\TH:i:s\Z', $start),
            'duration' => max(1, intdiv($end - $start, 60)), 'timezone' => 'UTC', 'settings' => ['join_before_host' => false, 'waiting_room' => true]]);
    }

    private static function zoomCreate(array $user, string $title, int $start, int $end): array
    {
        [$st, $body] = self::http('POST', 'https://api.zoom.us/v2/users/' . rawurlencode($user['email']) . '/meetings',
            ['Authorization: Bearer ' . self::zoomToken(), 'Content-Type: application/json'], self::zoomBody($title, $start, $end));
        $r = json_decode($body, true) ?: [];
        if ($st !== 201 || empty($r['join_url'])) {
            throw new \RuntimeException('Zoom: ' . self::err($body, $st));
        }
        return ['provider' => 'zoom', 'url' => (string) $r['join_url'], 'id' => (string) $r['id']];
    }

    private static function teamsToken(): string
    {
        [$st, $body] = self::http('POST', 'https://login.microsoftonline.com/' . rawurlencode((string) Settings::get('meet.teams_tenant', '')) . '/oauth2/v2.0/token',
            ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['client_id' => Settings::get('meet.teams_client', ''), 'client_secret' => Settings::secret('meet.teams_secret'),
                'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials']));
        $tok = json_decode($body, true)['access_token'] ?? '';
        if ($st !== 200 || $tok === '') {
            throw new \RuntimeException('Microsoft: ' . self::err($body, $st));
        }
        return $tok;
    }

    private static function teamsCreate(array $user, string $title, int $start, int $end): array
    {
        [$st, $body] = self::http('POST', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($user['email']) . '/onlineMeetings',
            ['Authorization: Bearer ' . self::teamsToken(), 'Content-Type: application/json'],
            (string) json_encode(['subject' => mb_substr($title, 0, 200), 'startDateTime' => gmdate('c', $start), 'endDateTime' => gmdate('c', $end)]));
        $r = json_decode($body, true) ?: [];
        if ($st !== 201 || empty($r['joinWebUrl'])) {
            throw new \RuntimeException('Teams: ' . self::err($body, $st));
        }
        return ['provider' => 'teams', 'url' => (string) $r['joinWebUrl'], 'id' => (string) $r['id']];
    }

    /** Google Meet: a service account with domain-wide delegation acts as the organizer. */
    private static function meetCreate(array $user): array
    {
        $key = json_decode(Settings::secret('meet.google_key'), true) ?: [];
        if (empty($key['client_email']) || empty($key['private_key'])) {
            throw new \RuntimeException('Google: ' . t('meet.bad_key'));
        }
        $b64 = static fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();
        $head = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $b64((string) json_encode(['iss' => $key['client_email'], 'sub' => $user['email'], 'scope' => 'https://www.googleapis.com/auth/meetings.space.created',
            'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 600]));
        if (!openssl_sign($head . '.' . $claims, $sig, $key['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Google: ' . t('meet.bad_key'));
        }
        [$st, $body] = self::http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $head . '.' . $claims . '.' . $b64($sig)]));
        $tok = json_decode($body, true)['access_token'] ?? '';
        if ($st !== 200 || $tok === '') {
            throw new \RuntimeException('Google: ' . self::err($body, $st));
        }
        [$st, $body] = self::http('POST', 'https://meet.googleapis.com/v2/spaces', ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'], '{}');
        $r = json_decode($body, true) ?: [];
        if ($st !== 200 || empty($r['meetingUri'])) {
            throw new \RuntimeException('Google Meet: ' . self::err($body, $st));
        }
        return ['provider' => 'meet', 'url' => (string) $r['meetingUri'], 'id' => (string) ($r['name'] ?? '')];
    }

    private static function err(string $body, int $status): string
    {
        $j = json_decode($body, true);
        $m = $j['error']['message'] ?? $j['error_description'] ?? $j['message'] ?? (is_string($j['error'] ?? null) ? $j['error'] : '');
        return trim(($status ? 'HTTP ' . $status . ' ' : '') . mb_substr((string) $m, 0, 200));
    }

    /** @return array{0:int,1:string} */
    private static function http(string $method, string $url, array $headers, string $body = ''): array
    {
        if (self::$transport) {
            return (self::$transport)($method, $url, $headers, $body);
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('curl');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false] + ($method !== 'GET' && $method !== 'DELETE' ? [CURLOPT_POSTFIELDS => $body] : []));
        $out = curl_exec($ch);
        $st = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) {
            throw new \RuntimeException($err);
        }
        return [$st, (string) $out];
    }
}
