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
            return ['provider' => 'jitsi', 'url' => $base . '/' . $room, 'id' => $room, 'code' => $room];
        }
        if (self::mode($provider) === 'personal') {
            return ['provider' => $provider, 'url' => self::personalLink($user, $provider), 'id' => ''] + self::fromUrl($provider, self::personalLink($user, $provider));
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
        $extra = json_decode((string) base64_decode((string) ($m->{'X-M4W-MEETING-INFO'} ?? '')), true);
        $info = ['provider' => (string) $m->{'X-M4W-MEETING'}, 'url' => (string) ($m->{'X-M4W-MEETING-URL'} ?? ''), 'id' => (string) ($m->{'X-M4W-MEETING-ID'} ?? '')];
        return $info + (is_array($extra) ? array_intersect_key($extra, array_flip(['code', 'passcode', 'phones', 'phone_code'])) : []);
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
            $extra = array_filter(array_intersect_key($info, array_flip(['code', 'passcode', 'phones', 'phone_code'])));
            if ($extra) {
                $v->{'X-M4W-MEETING-INFO'} = base64_encode((string) json_encode($extra));
            }
            // Typed as URI so that the commas of "tel:…,,code#" are not escaped like text.
            $v->add($cal->createProperty('CONFERENCE', $info['url'], ['VALUE' => 'URI', 'FEATURE' => ['AUDIO', 'VIDEO'], 'LABEL' => $label], 'URI'));
            foreach ((array) ($info['phones'] ?? []) as $ph) {
                // The number alone: vobject escapes the "," pauses of a one-tap link, the code goes in the label.
                $tel = 'tel:' . preg_replace('/[^\d+]/', '', $ph['number']);
                $v->add($cal->createProperty('CONFERENCE', $tel, ['VALUE' => 'URI', 'FEATURE' => ['PHONE'],
                    'LABEL' => trim($label . ' ' . $ph['label'] . (!empty($info['phone_code']) ? ' — ' . t('meet.d_then', ['code' => $info['phone_code']]) : ''))], 'URI'));
            }
            $v->URL = $info['url'];
            // Every calendar shows the description: the connection details are there for Outlook
            // and phones too, in a block of their own that the form never offers for editing.
            $desc = rtrim((string) ($v->DESCRIPTION ?? ''));
            $v->DESCRIPTION = ($desc !== '' ? $desc . "\n\n" : '') . self::textBlock($info);
            if (trim((string) ($v->LOCATION ?? '')) === '') {
                $v->LOCATION = $label;
            }
        }
    }

    /** The description as the person wrote it (without the line we add). */
    public static function userDescription($v): string
    {
        $d = (string) ($v->DESCRIPTION ?? '');
        $d = rtrim(preg_replace('/\n*' . preg_quote(self::MARK, '/') . '.*?' . preg_quote(self::MARK, '/') . '\s*/us', "\n", $d) ?? $d);
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
            // Remove what we added (and only it): the block, or the single line of earlier versions.
            $d = self::userDescription($v);
            if (trim($d) === '') {
                unset($v->DESCRIPTION);
            } else {
                $v->DESCRIPTION = $d;
            }
        }
        if ($url !== '' && (string) ($v->URL ?? '') === $url) {
            unset($v->URL);
        }
        unset($v->{'X-M4W-MEETING'}, $v->{'X-M4W-MEETING-URL'}, $v->{'X-M4W-MEETING-ID'}, $v->{'X-M4W-MEETING-INFO'}, $v->CONFERENCE);
    }

    // ---------------------------------------------------------------- connection details

    /** Fences the connection details in the description (as Google Calendar and Teams do). */
    public const MARK = '-::~:~::~:~::~:~::~:~::~:~::-';

    /** "85412345678" → "854 1234 5678", grouped as Zoom and Teams print them (easier to type). */
    private static function spaced(string $digits): string
    {
        $groups = [9 => [3, 3, 3], 10 => [3, 3, 4], 11 => [3, 4, 4], 12 => [3, 3, 3, 3]][preg_match('/^\d+$/', $digits) ? strlen($digits) : 0] ?? null;
        if (!$groups) {
            return $digits;
        }
        $out = [];
        $at = 0;
        foreach ($groups as $n) {
            $out[] = substr($digits, $at, $n);
            $at += $n;
        }
        return implode(' ', $out);
    }

    /** What a meeting link itself says: Zoom meeting ID, Google Meet code, Jitsi room. */
    public static function fromUrl(string $provider, string $url): array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        return match (true) {
            $provider === 'zoom' && (bool) preg_match('#/(?:j|my|wc/join)/(\d{9,12})#', $path, $m) => ['code' => self::spaced($m[1]), 'phone_code' => $m[1] . '#'],
            $provider === 'meet' && (bool) preg_match('#^/([a-z]{3}-[a-z]{4}-[a-z]{3})#', $path, $m) => ['code' => $m[1]],
            $provider === 'jitsi' && $path !== '' => ['code' => ltrim($path, '/')],
            default => [],
        };
    }

    /**
     * The rows shown to everybody: link, ID, code, phone numbers, how to join.
     * @return array<int,array{0:string,1:string}>
     */
    public static function details(array $info): array
    {
        $rows = [[t('meet.d_link'), $info['url']]];
        if (!empty($info['code'])) {
            $rows[] = [t($info['provider'] === 'jitsi' ? 'meet.d_room' : 'meet.d_id'), (string) $info['code']];
        }
        if (!empty($info['passcode'])) {
            $rows[] = [t('meet.d_passcode'), (string) $info['passcode']];
        }
        foreach ((array) ($info['phones'] ?? []) as $ph) {
            $rows[] = [t('meet.d_phone') . ($ph['label'] !== '' ? ' (' . $ph['label'] . ')' : ''), $ph['number'] . (!empty($info['phone_code']) ? ' — ' . t('meet.d_then', ['code' => $info['phone_code']]) : '')];
        }
        return $rows;
    }

    /** One line on how to join, for this provider. */
    public static function howTo(string $provider): string
    {
        return t('meet.howto_' . (in_array($provider, self::PROVIDERS, true) ? $provider : 'jitsi'));
    }

    /** The block written in the event description (plain text, same for everybody). */
    public static function textBlock(array $info): string
    {
        $label = self::LABELS[$info['provider']] ?? 'Visio';
        $out = self::MARK . "\n" . t('meet.join_line', ['provider' => $label]) . "\n" . $info['url'] . "\n";
        foreach (array_slice(self::details($info), 1) as [$k, $v]) {
            $out .= $k . ' : ' . $v . "\n";
        }
        return $out . self::howTo($info['provider']) . "\n" . self::MARK;
    }

    /**
     * The join link for one person: their name already filled in (and the meeting subject for
     * Jitsi, the right Google account for Meet), so they arrive straight in the waiting room.
     */
    public static function joinUrl(array $info, string $name = '', string $email = '', string $title = ''): string
    {
        $url = (string) $info['url'];
        if (!preg_match('#^https://#', $url)) {
            return $url;
        }
        $q = static fn(string $u, array $p) => $u . (str_contains($u, '?') ? '&' : '?') . http_build_query(array_filter($p, static fn($v) => $v !== ''), '', '&', PHP_QUERY_RFC3986);
        return match ($info['provider']) {
            // Jitsi reads its settings after "#": name, e-mail (avatar) and the subject shown.
            'jitsi' => ($name === '' && $email === '' && $title === '') ? $url : preg_replace('/#.*$/', '', $url) . '#' . implode('&', array_map(static fn($k, $v) => $k . '=' . rawurlencode((string) json_encode($v, JSON_UNESCAPED_UNICODE)),
                array_keys($p = array_filter(['userInfo.displayName' => $name, 'userInfo.email' => $email, 'config.subject' => $title], static fn($v) => $v !== '')), $p)),
            // Zoom: the name the browser client fills in.
            'zoom' => $q($url, ['uname' => $name]),
            // Google Meet: open it with that person's Google account, in their language.
            'meet' => $q($url, ['authuser' => $email, 'hl' => \M4W\Core\I18n::language()]),
            default => $url,
        };
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
        $phones = [];
        foreach (array_slice((array) ($r['settings']['global_dial_in_numbers'] ?? []), 0, 12) as $d) {
            if (!empty($d['number'])) {
                $phones[] = ['number' => (string) $d['number'], 'label' => trim((string) ($d['country_name'] ?? '') . (!empty($d['city']) ? ' (' . $d['city'] . ')' : ''))];
            }
        }
        // Numbers of the organizer's country first.
        usort($phones, static fn($a, $b) => (int) !str_contains(strtolower($a['label']), 'france') <=> (int) !str_contains(strtolower($b['label']), 'france'));
        return ['provider' => 'zoom', 'url' => (string) $r['join_url'], 'id' => (string) $r['id'], 'code' => self::spaced((string) $r['id']),
            'passcode' => (string) ($r['password'] ?? ''), 'phones' => array_slice($phones, 0, 3), 'phone_code' => (string) $r['id'] . '#'];
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
        $out = ['provider' => 'teams', 'url' => (string) $r['joinWebUrl'], 'id' => (string) $r['id']];
        if (!empty($r['joinMeetingIdSettings']['joinMeetingId'])) {
            $out['code'] = self::spaced((string) $r['joinMeetingIdSettings']['joinMeetingId']);
            $out['passcode'] = (string) ($r['joinMeetingIdSettings']['passcode'] ?? '');
        }
        if (!empty($r['audioConferencing']['tollNumber'])) {
            $out['phones'] = [['number' => (string) $r['audioConferencing']['tollNumber'], 'label' => '']];
            $out['phone_code'] = (string) ($r['audioConferencing']['conferenceId'] ?? '') . '#';
        }
        return $out;
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
        return ['provider' => 'meet', 'url' => (string) $r['meetingUri'], 'id' => (string) ($r['name'] ?? ''), 'code' => (string) ($r['meetingCode'] ?? self::fromUrl('meet', (string) $r['meetingUri'])['code'] ?? '')];
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
