<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Crypto;
use M4W\Core\Settings;

/**
 * The account on an iPhone in one tap: a configuration profile (Exchange, or IMAP with
 * calendars and contacts) holding the app password just made, behind a link that works once,
 * for fifteen minutes (a QR code on the computer, opened by the iPhone's camera).
 */
final class PhoneProfile
{
    private const TTL = 900;

    private static function file(string $token): string
    {
        return storage_path('profiles/' . hash('sha256', $token) . '.json');
    }

    public static function issue(array $user, string $password, string $mode): string
    {
        $token = Crypto::token(24);
        $dir = dirname(self::file($token));
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            if (filemtime($f) < time() - self::TTL) {
                @unlink($f); // the ones never opened
            }
        }
        file_put_contents(self::file($token), json_encode(['uid' => (int) $user['id'], 'pw' => Crypto::encrypt($password), 'mode' => $mode === 'imap' ? 'imap' : 'eas', 'exp' => time() + self::TTL]));
        return $token;
    }

    /** The profile behind a link, once. */
    public static function take(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,80}$/', $token)) {
            return null;
        }
        $f = self::file($token);
        $d = json_decode((string) @file_get_contents($f), true);
        @unlink($f);
        if (!is_array($d) || (int) $d['exp'] < time() || !($user = Users::find((int) $d['uid'])) || $user['status'] !== 'active') {
            return null;
        }
        return [$user, (string) Crypto::decrypt((string) $d['pw']), (string) $d['mode']];
    }

    public static function xml(array $user, string $password, string $mode): string
    {
        $x = static fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $uuid = static fn() => strtoupper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)));
        $org = (string) Settings::get('brand.name', 'Make4WebMail');
        $mg = MailGateway::config();
        $login = mb_strtolower((string) $user['email']);
        $id = 'fr.make4web.' . md5($mg['host'] . $login);
        $dict = static function (array $kv) use ($x): string {
            $out = '<dict>';
            foreach ($kv as $k => $v) {
                $out .= '<key>' . $x($k) . '</key>' . (is_bool($v) ? ($v ? '<true/>' : '<false/>') : (is_int($v) ? '<integer>' . $v . '</integer>' : '<string>' . $x((string) $v) . '</string>'));
            }
            return $out . '</dict>';
        };
        $base = static fn(string $type, string $suffix, string $name) => ['PayloadType' => $type, 'PayloadVersion' => 1, 'PayloadIdentifier' => $id . '.' . $suffix, 'PayloadUUID' => $uuid(), 'PayloadDisplayName' => $name];
        $payloads = [];
        if ($mode === 'eas') {
            $payloads[] = $dict($base('com.apple.eas.account', 'eas', $org) + ['EmailAddress' => $login, 'Host' => $mg['host'], 'Port' => (int) $mg['imap_port'], 'SSL' => true,
                'UserName' => $login, 'Password' => $password, 'MailNumberOfPastDaysToSync' => 0]);
        } else {
            $payloads[] = $dict($base('com.apple.mail.managed', 'mail', $org) + ['EmailAccountDescription' => $org, 'EmailAccountType' => 'EmailTypeIMAP', 'EmailAddress' => $login,
                'IncomingMailServerHostName' => $mg['host'], 'IncomingMailServerPortNumber' => (int) $mg['imap_port'], 'IncomingMailServerUseSSL' => true,
                'IncomingMailServerUsername' => $login, 'IncomingPassword' => $password, 'IncomingMailServerAuthentication' => 'EmailAuthPassword',
                'OutgoingMailServerHostName' => $mg['host'], 'OutgoingMailServerPortNumber' => (int) $mg['smtp_port'], 'OutgoingMailServerUseSSL' => true,
                'OutgoingMailServerUsername' => $login, 'OutgoingPassword' => $password, 'OutgoingMailServerAuthentication' => 'EmailAuthPassword']);
            $host = (string) parse_url(public_url('/'), PHP_URL_HOST);
            $port = (int) (parse_url(public_url('/'), PHP_URL_PORT) ?: 443);
            $path = rtrim((string) parse_url(public_url('dav/'), PHP_URL_PATH), '/') . '/principals/' . $login . '/';
            foreach (['CalDAV' => ['com.apple.caldav.account', t('dav.calendars')], 'CardDAV' => ['com.apple.carddav.account', t('dav.contacts')]] as $p => [$type, $name]) {
                $payloads[] = $dict($base($type, strtolower($p), $name) + [$p . 'AccountDescription' => $org, $p . 'HostName' => $host, $p . 'Port' => $port,
                    $p . 'PrincipalURL' => $path, $p . 'UseSSL' => true, $p . 'Username' => $login, $p . 'Password' => $password]);
            }
        }
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n"
            . '<plist version="1.0"><dict><key>PayloadContent</key><array>' . implode('', $payloads) . '</array>'
            . '<key>PayloadDisplayName</key><string>' . $x($org . ' — ' . $login) . '</string><key>PayloadIdentifier</key><string>' . $x($id) . '</string>'
            . '<key>PayloadType</key><string>Configuration</string><key>PayloadUUID</key><string>' . $uuid() . '</string><key>PayloadVersion</key><integer>1</integer></dict></plist>';
    }
}
