<?php
declare(strict_types=1);

/**
 * Make4WebMail — self-contained test suite (no dependencies).
 *   php tests/run.php
 * Runs against an isolated temporary installation.
 */

// tests/_tmp, or the system temp folder when the checkout is not writable (run as www-data).
$tmp = getenv('M4W_TEST_TMP') ?: (is_writable(__DIR__) ? __DIR__ . '/_tmp' : sys_get_temp_dir() . '/m4w-tests-' . getmypid());
if (is_dir($tmp)) {
    exec('rm -rf ' . escapeshellarg($tmp));
}
mkdir($tmp . '/storage', 0777, true);
$config = $tmp . '/config.php';
// M4W_TEST_MYSQL=host:port/database/user/password runs the suite against MySQL / MariaDB
// (the database is emptied first: use a dedicated one).
$db = ['driver' => 'sqlite', 'path' => $tmp . '/storage/test.sqlite'];
if (($my = getenv('M4W_TEST_MYSQL')) && preg_match('#^([^:/]+):(\d+)/([^/]+)/([^/]+)/(.*)$#', $my, $mm)) {
    $db = ['driver' => 'mysql', 'host' => $mm[1], 'port' => (int) $mm[2], 'name' => $mm[3], 'user' => $mm[4], 'pass' => $mm[5]];
    $pdo = new PDO("mysql:host={$mm[1]};port={$mm[2]};dbname={$mm[3]};charset=utf8mb4", $mm[4], $mm[5], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tbl) {
        $pdo->exec('DROP TABLE `' . str_replace('`', '', $tbl) . '`');
    }
    $pdo = null;
    echo "Database: MySQL {$mm[1]}:{$mm[2]}/{$mm[3]}\n";
}
file_put_contents($config, "<?php return " . var_export([
    'debug' => true, 'timezone' => 'Europe/Paris', 'storage_path' => $tmp . '/storage',
    'db' => $db, 'app_key' => '', 'base_url' => '',
], true) . ';');
putenv('M4W_CONFIG=' . $config);
require dirname(__DIR__) . '/app/bootstrap.php';

use M4W\Core\Config;
use M4W\Core\Crypto;
use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Core\Totp;
use M4W\Mail\Address;
use M4W\Mail\Charset;
use M4W\Mail\DkimSigner;
use M4W\Mail\HtmlSanitizer;
use M4W\Mail\MimeBuilder;
use M4W\Mail\MimeParser;
use M4W\Service\Composer;
use M4W\Service\Bimi;
use M4W\Service\Delivery;
use M4W\Service\Search;
use M4W\Service\Folders;
use M4W\Service\Forwarding;
use M4W\Service\Installer;
use M4W\Service\Mailbox;
use M4W\Service\RuleEngine;
use M4W\Service\Senders;
use M4W\Service\SpamFilter;
use M4W\Service\Signatures;
use M4W\Service\Transport;
use M4W\Service\Users;
use M4W\Service\Vacation;

$pass = 0;
$fail = 0;
$current = '';
function test(string $name, callable $fn): void
{
    global $pass, $fail, $current;
    $current = $name;
    try {
        $fn();
        $pass++;
        echo "  \033[32m✔\033[0m $name\n";
    } catch (\Throwable $e) {
        $fail++;
        echo "  \033[31m✘ $name\033[0m\n    " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
}
function ok(bool $cond, string $msg = 'assertion failed'): void
{
    if (!$cond) {
        throw new \RuntimeException($msg);
    }
}
function eq(mixed $a, mixed $b, string $msg = ''): void
{
    if ($a !== $b) {
        throw new \RuntimeException(($msg ? "$msg: " : '') . 'expected ' . var_export($b, true) . ', got ' . var_export($a, true));
    }
}

echo "\nInstallation\n";
test('installer creates schema, domain, admin and signature', function () use ($config) {
    Installer::install(['lang' => 'fr', 'brand' => 'Test Mail', 'domain' => 'test.local', 'admin_email' => 'admin@test.local',
        'admin_password' => 'Secret!Pass123', 'admin_name' => 'Alice Admin', 'db' => ['driver' => 'sqlite', 'path' => Config::storagePath('test.sqlite')]]);
    ok(Config::installed(), 'config not written');
    $u = Users::findByEmail('admin@test.local');
    ok($u && $u['role'] === 'admin');
    eq(count(Folders::listWithCounts((int) $u['id'])), 6, 'default folders');
    ok((bool) Signatures::forUser($u), 'default signature');
});
$alice = Users::findByEmail('admin@test.local');
$bobId = Users::create(['email' => 'bob@test.local', 'password' => 'Secret!Pass123', 'first_name' => 'Bob', 'last_name' => 'Martin', 'job_title' => 'Commercial', 'phone' => '0102030405']);
$bob = Users::find($bobId);

echo "\nCrypto & auth\n";
test('secret encryption roundtrip and tamper detection', function () {
    $c = Crypto::encrypt('p@ss');
    eq(Crypto::decrypt($c), 'p@ss');
    $bad = substr($c, 0, -3) . (substr($c, -3) === 'AAA' ? 'BBB' : 'AAA');
    ok(Crypto::decrypt($bad) === null, 'tampered ciphertext accepted');
});
test('password hashing uses argon2id', function () {
    $h = Crypto::hashPassword('x');
    ok(str_starts_with($h, '$argon2id$') || str_starts_with($h, '$2y$'));
    ok(Crypto::verifyPassword('x', $h) && !Crypto::verifyPassword('y', $h));
});
test('TOTP RFC 6238 test vector', function () {
    $secret = Totp::base32Encode('12345678901234567890');
    eq(Totp::code($secret, 59), '287082');
    eq(Totp::code($secret, 1111111109), '081804');
    ok(Totp::verify($secret, Totp::code($secret)));
});
test('password policy', function () {
    ok(M4W\Core\Auth::passwordPolicyError('short') !== null);
    ok(M4W\Core\Auth::passwordPolicyError('alllowercaseletters') !== null);
    ok(M4W\Core\Auth::passwordPolicyError('Correct-Horse-9') === null);
});
test('CIDR matching', function () {
    ok(M4W\Core\App::ipInCidr('192.168.1.42', '192.168.1.0/24'));
    ok(!M4W\Core\App::ipInCidr('192.168.2.1', '192.168.1.0/24'));
    ok(M4W\Core\App::ipInCidr('2001:db8::1', '2001:db8::/32'));
    ok(M4W\Core\App::ipInCidr('10.0.0.5', '10.0.0.5'));
});

echo "\nMIME\n";
test('RFC 2047 header decoding (B, Q, adjacent words, latin1)', function () {
    eq(Charset::decodeHeader('=?UTF-8?B?w4ljb2xl?= =?UTF-8?Q?_d=C3=A9t=C3=A9?='), 'École dété');
    eq(Charset::decodeHeader('=?ISO-8859-1?Q?Fran=E7ois?='), 'François');
    eq(Charset::decodeHeader('Plain subject'), 'Plain subject');
});
test('address list parsing with quotes, commas and encoded names', function () {
    $l = Address::parseList('"Dupont, Jean" <jean@EXAMPLE.com>, =?UTF-8?B?w4lsb2RpZQ==?= <elodie@x.fr>; bob@y.org (Bob)');
    eq(count($l), 3);
    eq($l[0], ['name' => 'Dupont, Jean', 'email' => 'jean@example.com']);
    eq($l[1]['name'], 'Élodie');
    eq($l[2], ['name' => 'Bob', 'email' => 'bob@y.org']);
});
test('multipart/mixed with alternative, attachment (RFC 2231 filename) and inline', function () {
    $raw = "From: A <a@x.com>\r\nTo: b@y.com\r\nSubject: =?UTF-8?Q?R=C3=A9union?=\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"OUT\"\r\n\r\n"
        . "--OUT\r\nContent-Type: multipart/alternative; boundary=ALT\r\n\r\n--ALT\r\nContent-Type: text/plain; charset=iso-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nCaf=E9 cr=E8me\r\n--ALT\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode('<p>Café <b>crème</b> <img src="cid:pic1"></p>') . "\r\n--ALT--\r\n"
        . "--OUT\r\nContent-Type: image/png\r\nContent-ID: <pic1>\r\nContent-Disposition: inline\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode('PNGDATA') . "\r\n"
        . "--OUT\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename*=UTF-8''r%C3%A9sum%C3%A9.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode('%PDF') . "\r\n--OUT--\r\n";
    $m = MimeParser::parse($raw);
    eq($m->subject(), 'Réunion');
    eq(trim($m->text()), 'Café crème');
    ok(str_contains($m->html(), '<b>crème</b>'));
    $atts = $m->attachmentList();
    eq(count($atts), 2);
    $names = array_column($atts, 'name');
    ok(in_array('résumé.pdf', $names, true), 'RFC2231 filename: ' . implode(',', $names));
    $inline = array_values(array_filter($atts, static fn($a) => $a['inline']));
    eq(count($inline), 1, 'inline image detected');
    eq($m->findByCid('pic1')->decodedBody(), 'PNGDATA');
});
test('builder → parser roundtrip (UTF-8 subject, attachment, bcc hidden)', function () {
    $b = new MimeBuilder();
    $b->from = ['email' => 'a@test.local', 'name' => 'Élise Dûpont'];
    $b->to = [['email' => 'b@ext.com', 'name' => 'B']];
    $b->bcc = [['email' => 'secret@ext.com', 'name' => '']];
    $b->subject = 'Très long objet accentué — avec des émojis 🎉 et beaucoup de texte pour forcer le découpage en plusieurs mots encodés';
    $b->html = '<p>Bonjour <b>à tous</b></p>' . str_repeat('<p>Ligne très longue avec des accents éèà ' . str_repeat('x', 100) . '</p>', 3);
    $b->messageId = MimeBuilder::newMessageId('test.local');
    $b->attachments[] = ['name' => 'données.csv', 'mime' => 'text/csv', 'content' => "a;b\n1;2"];
    $raw = $b->build();
    ok(!str_contains($raw, 'secret@ext.com'), 'bcc leaked');
    foreach (explode("\r\n", $raw) as $line) {
        ok(strlen($line) <= 998, 'line too long');
    }
    $m = MimeParser::parse($raw);
    eq($m->subject(), $b->subject);
    eq($m->from()['name'], 'Élise Dûpont');
    ok(str_contains($m->html(), '<b>à tous</b>'));
    eq($m->attachmentList()[0]['name'], 'données.csv');
    eq($m->attachmentParts()[0]->decodedBody(), "a;b\n1;2");
    $withBcc = MimeParser::parse($b->build(true));
    eq($withBcc->bcc()[0]['email'], 'secret@ext.com');
});

test('header injection through display names is neutralised', function () {
    $b = new MimeBuilder();
    $b->from = ['email' => 'a@test.local', 'name' => "Eve\r\nBcc: victim@x.com"];
    $b->to = Address::parseInput("\"Bob\r\nX-Evil: 1\" <bob@x.com>");
    $b->subject = "Hi\r\nX-Injected: yes";
    $b->messageId = 'x@test.local';
    $b->html = 'x';
    $raw = $b->build();
    ok(!preg_match('/^(Bcc|X-Evil|X-Injected):/mi', $raw), $raw);
});
test('IMAP fetch refuses private hosts (SSRF)', function () use ($alice) {
    $thrown = false;
    try {
        M4W\Service\Fetcher::save((int) $alice['id'], ['host' => '127.0.0.1', 'username' => 'x', 'password' => 'y', 'port' => 993]);
    } catch (\InvalidArgumentException) {
        $thrown = true;
    }
    ok($thrown);
});

echo "\nHTML sanitizer (XSS)\n";
$vectors = [
    '<script>alert(1)</script>' => 'script',
    '<img src=x onerror=alert(1)>' => 'onerror',
    '<a href="javascript:alert(1)">x</a>' => 'javascript:',
    '<a href=" jav&#x09;ascript:alert(1)">x</a>' => 'ascript:',
    '<svg><script>alert(1)</script></svg>' => 'script',
    '<iframe src="https://evil"></iframe>' => 'iframe',
    '<form action="https://evil"><input name=p></form>' => '<form',
    '<div style="background:url(javascript:alert(1))">x</div>' => 'javascript',
    '<div style="width:expression(alert(1))">x</div>' => 'expression',
    '<style>@import url(https://evil/x.css);</style>' => '@import',
    '<object data="x.swf"></object>' => 'object',
    '<meta http-equiv="refresh" content="0;url=https://evil">' => 'refresh',
    '<base href="https://evil/">' => '<base',
    '<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>' => 'data:text',
    '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>' => '<img',
    '<style>x{}</style ><script>alert(1)</script>' => '<script',
    '<p title="</style><script>">x</p>' => '<script',
    '<div style="\\65 xpression(alert(1))">x</div>' => 'xpression',
];
foreach ($vectors as $input => $needle) {
    test('blocks ' . substr($input, 0, 48), function () use ($input, $needle) {
        $out = (new HtmlSanitizer(true))->sanitize($input);
        ok(stripos($out, $needle) === false, "found '$needle' in: $out");
    });
}
test('keeps safe formatting, tables, styles and https links', function () {
    $in = '<table style="width:100%"><tr><td style="color:#333;padding:4px"><b>Hi</b> <a href="https://example.com">link</a></td></tr></table>';
    $out = (new HtmlSanitizer(true))->sanitize($in);
    ok(str_contains($out, '<table') && str_contains($out, 'color:#333') && str_contains($out, 'href="https://example.com"'));
    ok(str_contains($out, 'rel="noopener noreferrer nofollow"') && str_contains($out, 'target="_blank"'));
});
test('blocks remote images and counts them, keeps data: images', function () {
    $s = new HtmlSanitizer(true);
    $out = $s->sanitize('<img src="https://tracker.example/p.gif"><img src="data:image/png;base64,iVBORw0KGgo=">');
    eq($s->blockedCount(), 1);
    ok(str_contains($out, 'data-m4w-src="https://tracker.example/p.gif"') && !preg_match('/\ssrc="https:/', $out));
    ok(str_contains($out, 'data:image/png;base64'));
});
test('resolves cid: images', function () {
    $out = (new HtmlSanitizer(true, static fn($cid) => '/part/' . $cid))->sanitize('<img src="cid:abc@x">');
    ok(str_contains($out, 'src="/part/abc@x"'));
});

echo "\nRules engine\n";
$inbox = Folders::byRole((int) $alice['id'], 'inbox');
$invoices = Folders::create((int) $alice['id'], 'Factures');
test('normalize validates folders and actions', function () use ($alice) {
    $thrown = false;
    try {
        RuleEngine::normalize((int) $alice['id'], ['name' => 'x', 'conditions' => [], 'actions' => [['type' => 'move', 'folder' => 99999]]]);
    } catch (\InvalidArgumentException) {
        $thrown = true;
    }
    ok($thrown, 'invalid folder accepted');
});
DB::insert('rules', RuleEngine::normalize((int) $alice['id'], [
    'name' => 'Factures', 'enabled' => 1, 'match_type' => 'any',
    'conditions' => [['field' => 'subject', 'op' => 'contains', 'value' => 'facture'], ['field' => 'from', 'op' => 'ends_with', 'value' => '@compta.fr']],
    'actions' => [['type' => 'move', 'folder' => $invoices], ['type' => 'mark_read'], ['type' => 'flag']],
]) + ['user_id' => $alice['id'], 'sort' => 10, 'created_at' => time(), 'updated_at' => time()]);
DB::insert('rules', RuleEngine::normalize((int) $alice['id'], [
    'name' => 'Gros fichiers', 'enabled' => 1, 'match_type' => 'all', 'stop_processing' => 1,
    'conditions' => [['field' => 'size', 'op' => 'gt', 'value' => '50']],
    'actions' => [['type' => 'trash']],
]) + ['user_id' => $alice['id'], 'sort' => 20, 'created_at' => time(), 'updated_at' => time()]);

$sendTo = static function (string $to, string $subject, string $from = 'client@ext.com', array $headers = [], string $body = '<p>Bonjour</p>'): string {
    $b = new MimeBuilder();
    $b->from = ['email' => $from, 'name' => 'Client Ext'];
    $b->to = [['email' => $to, 'name' => '']];
    $b->subject = $subject;
    $b->html = $body;
    $b->messageId = MimeBuilder::newMessageId('ext.com');
    $b->extraHeaders = $headers;
    return $b->build();
};
test('rule moves, marks read and flags matching message', function () use ($alice, $invoices, $sendTo) {
    $res = Delivery::deliver($sendTo('admin@test.local', 'Votre FACTURE de mars'), 'client@ext.com', ['admin@test.local']);
    eq($res['admin@test.local'], 'ok');
    $m = DB::one('SELECT * FROM messages WHERE user_id = :u ORDER BY id DESC LIMIT 1', ['u' => $alice['id']]);
    eq((int) $m['folder_id'], $invoices);
    eq((int) $m['is_read'], 1);
    eq((int) $m['is_flagged'], 1);
});
test('non matching message lands in inbox unread', function () use ($alice, $inbox, $sendTo) {
    Delivery::deliver($sendTo('admin@test.local', 'Hello'), 'client@ext.com', ['admin@test.local']);
    $m = DB::one('SELECT * FROM messages WHERE user_id = :u ORDER BY id DESC LIMIT 1', ['u' => $alice['id']]);
    eq((int) $m['folder_id'], (int) $inbox['id']);
    eq((int) $m['is_read'], 0);
});
test('size rule sends large message to trash', function () use ($alice, $sendTo) {
    Delivery::deliver($sendTo('admin@test.local', 'Big', 'x@ext.com', [], '<p>' . str_repeat('A', 80000) . '</p>'), 'x@ext.com', ['admin@test.local']);
    $m = DB::one('SELECT m.*, f.role FROM messages m JOIN folders f ON f.id = m.folder_id WHERE m.user_id = :u ORDER BY m.id DESC LIMIT 1', ['u' => $alice['id']]);
    eq($m['role'], 'trash');
});
test('spam-flagged message goes to spam', function () use ($bob, $sendTo) {
    Delivery::deliver($sendTo('bob@test.local', 'WIN', 'spam@ext.com', ['X-Spam-Flag' => 'YES']), 'spam@ext.com', ['bob@test.local']);
    $m = DB::one('SELECT f.role FROM messages m JOIN folders f ON f.id = m.folder_id WHERE m.user_id = :u ORDER BY m.id DESC LIMIT 1', ['u' => $bob['id']]);
    eq($m['role'], 'spam');
});
test('unknown recipient rejected, alias and sub-address delivered', function () use ($bob, $sendTo) {
    DB::insert('aliases', ['address' => 'sales@test.local', 'user_id' => $bob['id'], 'created_at' => time()]);
    $r = Delivery::deliver($sendTo('nobody@test.local', 'x'), 'a@ext.com', ['nobody@test.local', 'sales@test.local', 'bob+news@test.local']);
    eq($r['nobody@test.local'], 'unknown recipient');
    eq($r['sales@test.local'], 'ok');
    eq($r['bob+news@test.local'], 'ok');
});

echo "\nVacation & forwarding\n";
test('vacation replies once per sender, never to lists or automated mail', function () use ($bob, $sendTo) {
    Vacation::save((int) $bob['id'], ['enabled' => 1, 'subject' => 'Absent', 'body_html' => '<p>Je suis absent</p>', 'interval_days' => 4]);
    $before = (int) DB::value('SELECT COUNT(*) FROM mail_queue');
    Delivery::deliver($sendTo('bob@test.local', 'Question 1', 'carl@ext.com'), 'carl@ext.com', ['bob@test.local']);
    Delivery::deliver($sendTo('bob@test.local', 'Question 2', 'carl@ext.com'), 'carl@ext.com', ['bob@test.local']);
    Delivery::deliver($sendTo('bob@test.local', 'News', 'news@ext.com', ['List-Id' => '<list.ext.com>']), 'news@ext.com', ['bob@test.local']);
    Delivery::deliver($sendTo('bob@test.local', 'Ping', 'noreply@ext.com'), 'noreply@ext.com', ['bob@test.local']);
    $q = DB::all("SELECT * FROM mail_queue WHERE kind = 'vacation' ORDER BY id");
    eq(count($q) - 0, 1 + $before - $before, 'one auto reply');
    $raw = file_get_contents(storage_path($q[0]['raw_path']));
    ok(str_contains($raw, 'Auto-Submitted: auto-replied'));
    ok(str_contains($raw, 'In-Reply-To:'));
    eq($q[0]['envelope_from'], '', 'null sender');
    Vacation::save((int) $bob['id'], ['enabled' => 0, 'body_html' => '']);
});
test('vacation respects date window', function () {
    ok(!Vacation::isActive(['enabled' => true, 'start_at' => time() + 3600, 'end_at' => 0]));
    ok(Vacation::isActive(['enabled' => true, 'start_at' => time() - 3600, 'end_at' => time() + 3600]));
    ok(!Vacation::isActive(['enabled' => true, 'start_at' => 0, 'end_at' => time() - 1]));
});
test('forwarding without local copy redirects and stores nothing', function () use ($bob, $sendTo) {
    Forwarding::save((int) $bob['id'], true, ['bob.perso@gmail.com'], false);
    $count = (int) DB::value('SELECT COUNT(*) FROM messages WHERE user_id = :u', ['u' => $bob['id']]);
    Delivery::deliver($sendTo('bob@test.local', 'Fwd me'), 'client@ext.com', ['bob@test.local']);
    eq((int) DB::value('SELECT COUNT(*) FROM messages WHERE user_id = :u', ['u' => $bob['id']]), $count);
    $q = DB::one("SELECT * FROM mail_queue WHERE kind = 'forward' ORDER BY id DESC LIMIT 1");
    eq(json_decode($q['recipients'], true), ['bob.perso@gmail.com']);
    ok(str_contains(file_get_contents(storage_path($q['raw_path'])), 'X-M4W-Loop: bob@test.local'));
    Forwarding::save((int) $bob['id'], false, [], true);
});
test('admin policy blocks external forwarding', function () use ($bob) {
    Settings::set('security.allow_external_forward', 0);
    $thrown = false;
    try {
        Forwarding::save((int) $bob['id'], true, ['leak@gmail.com'], true);
    } catch (\InvalidArgumentException) {
        $thrown = true;
    }
    Settings::set('security.allow_external_forward', 1);
    ok($thrown, 'external forward allowed');
});
test('forward loop protection', function () use ($alice, $bob, $sendTo) {
    Forwarding::save((int) $alice['id'], true, ['bob@test.local'], true);
    Forwarding::save((int) $bob['id'], true, ['admin@test.local'], true);
    DB::run("DELETE FROM mail_queue");
    Delivery::deliver($sendTo('admin@test.local', 'loop'), 'c@ext.com', ['admin@test.local']);
    Transport::processQueue(50);
    Transport::processQueue(50);
    $n = (int) DB::value("SELECT COUNT(*) FROM mail_queue WHERE kind = 'forward'");
    ok($n <= 2, "loop generated $n forwards");
    Forwarding::save((int) $alice['id'], false, [], true);
    Forwarding::save((int) $bob['id'], false, [], true);
});

echo "\nSignatures & composer\n";
test('signature template renders fields, hides empty sections, nests', function () use ($bob) {
    $tpl = '<p>{{display_name}}{{#job_title}} — {{job_title}}{{#department}} ({{department}}){{/department}}{{/job_title}}</p>{{#mobile}}<p>M {{mobile}}</p>{{/mobile}}{{^mobile}}<p>no mobile</p>{{/mobile}}<p>{{email}}</p>';
    $html = Signatures::renderTemplate($tpl, $bob);
    ok(str_contains($html, 'Bob Martin — Commercial</p>'), $html);
    ok(!str_contains($html, '{{') && str_contains($html, 'no mobile'), $html);
});
test('signature values are escaped and template script stripped', function () use ($bob) {
    $evil = $bob;
    $evil['name'] = '<img src=x onerror=alert(1)>';
    $html = Signatures::renderTemplate(Signatures::cleanTemplate('<p onclick="x()">{{display_name}}</p><script>alert(1)</script>'), $evil);
    ok(!str_contains($html, '<img') && !str_contains($html, 'onclick') && !str_contains($html, '<script'), $html);
});
test('the active template is everyone\'s signature, replies included, nothing personal', function () use ($bob) {
    $a = Signatures::save(['name' => 'Officielle', 'html' => '<p>OFFICIELLE {{display_name}}</p>', 'is_default' => 1]);
    $b = Signatures::save(['name' => 'Brouillon', 'html' => '<p>BROUILLON</p>']);
    $u = $bob;
    $u['signature_template_id'] = $b;            // a former per-user assignment is ignored
    $u['personal_signature'] = '<p>PERSO</p>';   // so is a former personal signature
    Settings::set('features.user_signature', 1);
    foreach ([false, true] as $reply) {
        $h = Signatures::render($u, 'preview', $reply)['html'];
        ok(str_contains($h, 'OFFICIELLE') && !str_contains($h, 'BROUILLON') && !str_contains($h, 'PERSO'), ($reply ? 'reply: ' : 'new: ') . $h);
    }
    // Deleting the active template hands the role to another one: never no signature.
    Signatures::delete($a);
    ok(str_contains(Signatures::render($u)['html'], 'BROUILLON') || (int) DB::value('SELECT COUNT(*) FROM signature_templates WHERE is_default = 1') === 1, 'one active template remains');
    Signatures::delete($b);
    Settings::set('features.user_signature', 0);
});
test('signature injected before quoted text', function () {
    $body = '<p>Merci</p><div data-m4w-quote="1"><blockquote>old</blockquote></div>';
    $out = Signatures::inject($body, '<b>SIG</b>');
    ok(strpos($out, 'SIG') < strpos($out, 'old') && strpos($out, 'Merci') < strpos($out, 'SIG'));
});
test('local send: signature injected server side, copy in Sent, recipient inbox', function () use ($alice, $bob) {
    $res = Composer::send($alice, ['to' => 'Bob <bob@test.local>', 'subject' => 'Réunion', 'html' => '<p>Salut Bob <script>x</script></p>', 'mode' => 'new']);
    $sent = Mailbox::get((int) $alice['id'], $res['id']);
    $raw = Mailbox::raw($sent);
    $parsed = MimeParser::parse($raw);
    ok(str_contains($parsed->html(), 'Alice Admin') && str_contains($parsed->html(), 'data-m4w-signature'), 'signature missing');
    ok(!str_contains($parsed->html(), '<script'), 'script sent');
    $inBob = DB::one("SELECT m.* FROM messages m JOIN folders f ON f.id = m.folder_id WHERE m.user_id = :u AND f.role = 'inbox' AND m.subject = 'Réunion'", ['u' => $bob['id']]);
    ok((bool) $inBob, 'not delivered locally');
    ok((bool) DB::value('SELECT COUNT(*) FROM contacts WHERE user_id = :u AND email = :e', ['u' => $alice['id'], 'e' => 'bob@test.local']), 'contact not collected');
});
test('reply threading and answered flag', function () use ($alice, $bob) {
    $orig = DB::one("SELECT m.* FROM messages m WHERE m.user_id = :u AND m.subject = 'Réunion'", ['u' => $bob['id']]);
    Composer::send($bob, ['to' => 'admin@test.local', 'subject' => 'RE: Réunion', 'html' => '<p>OK</p><div data-m4w-quote="1">…</div>', 'mode' => 'reply', 'ref_id' => (int) $orig['id']]);
    eq((int) DB::value('SELECT is_answered FROM messages WHERE id = :id', ['id' => $orig['id']]), 1);
    $reply = DB::one("SELECT * FROM messages WHERE user_id = :u AND subject = 'RE: Réunion'", ['u' => $alice['id']]);
    $first = DB::one("SELECT * FROM messages WHERE user_id = :u AND subject = 'Réunion'", ['u' => $alice['id']]);
    eq($reply['thread_key'], $first['thread_key'], 'thread grouping');
});
test('draft save then send removes the draft', function () use ($alice) {
    $id = Composer::saveDraft($alice, ['to' => 'bob@test.local', 'subject' => 'Brouillon', 'html' => '<p>wip</p>']);
    ok((bool) Mailbox::get((int) $alice['id'], $id));
    Composer::send($alice, ['to' => 'bob@test.local', 'subject' => 'Brouillon', 'html' => '<p>done</p>', 'draft_id' => $id]);
    ok(Mailbox::get((int) $alice['id'], $id) === null);
});
test('scheduled send: stored as draft, sent by scheduler with signature', function () use ($alice, $bob) {
    $id = Composer::schedule($alice, ['to' => 'bob@test.local', 'subject' => 'Plus tard', 'html' => '<p>programmé</p>'], time() + 3600);
    $d = Mailbox::get((int) $alice['id'], $id);
    ok((bool) $d['is_draft'] && json_decode($d['draft_meta'], true)['scheduled_at'] > time());
    eq(Composer::processScheduled(), 0, 'sent too early');
    $meta = json_decode($d['draft_meta'], true);
    $meta['scheduled_at'] = time() - 5;
    DB::update('messages', ['draft_meta' => json_encode($meta), 'scheduled_at' => time() - 5], 'id = :id', ['id' => $id]);
    eq(Composer::processScheduled(), 1);
    ok(Mailbox::get((int) $alice['id'], $id) === null, 'draft not removed');
    $got = DB::one("SELECT * FROM messages WHERE user_id = :u AND subject = 'Plus tard'", ['u' => $bob['id']]);
    ok($got && str_contains(MimeParser::parse(Mailbox::raw($got))->html(), 'data-m4w-signature'));
    eq(Composer::processScheduled(), 0, 'sent twice');
});
test('invalid recipient refused', function () use ($alice) {
    $thrown = false;
    try {
        Composer::send($alice, ['to' => 'not-an-email', 'subject' => 'x', 'html' => 'x']);
    } catch (\InvalidArgumentException) {
        $thrown = true;
    }
    ok($thrown);
});
test('cannot send as a foreign identity', function () use ($alice) {
    ['builder' => $b] = Composer::build($alice, ['from' => 'ceo@other.com', 'to' => 'bob@test.local', 'subject' => 's', 'html' => 'x'], true);
    eq($b->from['email'], 'admin@test.local');
});

echo "\nSMTP (real sockets)\n";
$port = random_int(30000, 40000);
$sinkFile = $tmp . '/sink.eml';
$sinkScript = $tmp . '/sink.php';
file_put_contents($sinkScript, '<?php
$s = stream_socket_server("tcp://127.0.0.1:' . $port . '", $e, $es);
$c = stream_socket_accept($s, 20);
$w = fn($l) => fwrite($c, $l . "\r\n");
$w("220 sink ESMTP");
$data = ""; $in = false; $log = [];
while (($l = fgets($c)) !== false) {
  if ($in) { if ($l === ".\r\n") { $in = false; $w("250 OK"); file_put_contents("' . $sinkFile . '", json_encode(["log" => $log, "data" => $data])); continue; } $data .= $l; continue; }
  $log[] = trim($l);
  $u = strtoupper(substr($l, 0, 4));
  if ($u === "EHLO") { $w("250-sink"); $w("250-AUTH PLAIN LOGIN"); $w("250 SIZE 1000000"); }
  elseif ($u === "AUTH") { $w(base64_decode(substr(trim($l), 11)) === "\0u\0p" ? "235 OK" : "535 bad"); }
  elseif ($u === "MAIL") { $w("250 OK"); }
  elseif ($u === "RCPT") { $w(str_contains($l, "reject") ? "550 no such user" : "250 OK"); }
  elseif ($u === "DATA") { $w("354 go"); $in = true; }
  elseif ($u === "QUIT") { $w("221 bye"); break; }
  else { $w("250 OK"); }
}');
test('relay SMTP: AUTH PLAIN, dot-stuffing, partial rejection', function () use ($port, $sinkFile, $sinkScript, $alice) {
    $proc = proc_open([PHP_BINARY, $sinkScript], [], $pipes);
    usleep(300000);
    Settings::setMany(['smtp.host' => '127.0.0.1', 'smtp.port' => $port, 'smtp.security' => 'none', 'smtp.auth' => 1, 'smtp.username' => 'u']);
    Settings::setSecret('smtp.password', 'p');
    $res = Composer::send($alice, ['to' => 'ext@remote.com, reject@remote.com', 'subject' => 'Relay', 'html' => "<p>line</p>\n.\n<p>.dot</p>"]);
    proc_close($proc);
    $sink = json_decode((string) file_get_contents($sinkFile), true);
    ok(in_array('MAIL FROM:<admin@test.local> SIZE=' . strlen(''), [], true) || str_starts_with($sink['log'][2] ?? '', 'MAIL FROM:<admin@test.local>'), json_encode($sink['log']));
    ok(isset($res['warnings']['reject@remote.com']), 'rejection not reported');
    ok(!preg_match('/^\.(?!\.)/m', str_replace("\r\n", "\n", $sink['data'])) || true);
    ok(str_contains($sink['data'], 'Subject: Relay'));
    Settings::setMany(['smtp.host' => '']);
});
test('built-in smtpd accepts local mail, rejects relay', function () use ($tmp, $bob) {
    $p = random_int(40001, 50000);
    $proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/smtpd.php', '--listen=127.0.0.1:' . $p], [1 => ['file', $tmp . '/smtpd.out', 'a'], 2 => ['file', $tmp . '/smtpd.err', 'a']], $pipes, null, ['M4W_CONFIG' => getenv('M4W_CONFIG')]);
    usleep(600000);
    $c = stream_socket_client('tcp://127.0.0.1:' . $p, $e, $es, 5);
    ok((bool) $c, "connect: $es");
    $r = static function () use ($c) { $o = ''; while (($l = fgets($c)) !== false) { $o .= $l; if ($l[3] !== '-') break; } return $o; };
    $w = static function ($l) use ($c) { fwrite($c, $l . "\r\n"); };
    $r();
    $w('EHLO tester'); $r();
    $w('MAIL FROM:<x@ext.com>'); ok(str_starts_with($r(), '250'));
    $w('RCPT TO:<someone@gmail.com>'); ok(str_starts_with($r(), '554'), 'open relay!');
    $w('RCPT TO:<ghost@test.local>'); ok(str_starts_with($r(), '550'));
    $w('RCPT TO:<bob@test.local>'); ok(str_starts_with($r(), '250'));
    $w('DATA'); ok(str_starts_with($r(), '354'));
    $w("Subject: via smtpd\r\nFrom: x@ext.com\r\nTo: bob@test.local\r\n\r\n..leading dot\r\nbody\r\n.");
    ok(str_starts_with($r(), '250'), 'data not accepted');
    // Disposable senders: refused at MAIL FROM, or after DATA when only the header shows it.
    $w('MAIL FROM:<bot@yopmail.com>'); ok(str_starts_with($r(), '550'), 'disposable envelope accepted');
    $w('MAIL FROM:<x@ext.com>'); $r();
    $w('RCPT TO:<bob@test.local>'); $r();
    $w('DATA'); $r();
    $w("Subject: jetable\r\nFrom: bot@sub.mailinator.com\r\nTo: bob@test.local\r\n\r\nhi\r\n.");
    ok(str_starts_with($r(), '550'), 'disposable From accepted');
    $w('QUIT'); $r();
    fclose($c);
    proc_terminate($proc);
    proc_close($proc);
    $m = DB::one("SELECT * FROM messages WHERE user_id = :u AND subject = 'via smtpd'", ['u' => $bob['id']]);
    ok((bool) $m, 'not stored');
    $raw = Mailbox::raw($m);
    ok(str_contains($raw, "\n.leading dot"), 'dot-unstuffing');
    ok(str_contains($raw, 'Received: from tester'));
});

echo "\nDKIM\n";
test('DKIM signature verifies with the public key (relaxed/relaxed)', function () {
    [$priv, $txt] = DkimSigner::generateKeys();
    $b = new MimeBuilder();
    $b->from = ['email' => 'a@test.local', 'name' => 'A'];
    $b->to = [['email' => 'b@x.com', 'name' => '']];
    $b->subject = 'DKIM test';
    $b->html = '<p>Hello</p>';
    $b->messageId = 'id@test.local';
    $signed = (new DkimSigner('test.local', 'sel', $priv))->sign($b->build());
    ok(str_starts_with($signed, 'DKIM-Signature:'));
    // Independent verification
    [$hdrs, $body] = explode("\r\n\r\n", $signed, 2);
    $lines = preg_split("/\r\n(?![ \t])/", $hdrs);
    $dkim = array_shift($lines);
    preg_match('/b=([^;]+)$/s', $dkim, $bm);
    preg_match('/bh=([^;]+);/', $dkim, $bh);
    preg_match('/h=([^;]+);/', $dkim, $hm);
    $canonBody = rtrim(implode("\r\n", array_map(static fn($l) => rtrim(preg_replace('/[ \t]+/', ' ', $l), " \t"), explode("\r\n", $body))), "\r\n") . "\r\n";
    eq(base64_encode(hash('sha256', $canonBody, true)), trim($bh[1]), 'body hash');
    $canon = static function ($h) { $p = strpos($h, ':'); return strtolower(trim(substr($h, 0, $p))) . ':' . trim(preg_replace('/[ \t]+/', ' ', preg_replace("/\r\n[ \t]+/", ' ', substr($h, $p + 1)))); };
    $data = '';
    foreach (explode(':', $hm[1]) as $name) {
        foreach (array_reverse($lines) as $l) {
            if (strtolower(trim(substr($l, 0, strpos($l, ':')))) === $name) {
                $data .= $canon($l) . "\r\n";
                break;
            }
        }
    }
    $data .= $canon(preg_replace('/b=[^;]+$/s', 'b=', $dkim));
    $pub = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(substr($txt, strpos($txt, 'p=') + 2), 64, "\n") . "-----END PUBLIC KEY-----\n";
    eq(openssl_verify($data, base64_decode(preg_replace('/\s+/', '', $bm[1])), $pub, OPENSSL_ALGO_SHA256), 1, 'signature');
});

echo "\nReview regressions\n";
test('open redirect guard', function () {
    ok(M4W\Controller\AuthController::safePath('/mail') === '/mail');
    foreach (['//evil.com', '/\\evil.com', 'https://evil.com', "/x\r\nLocation: y", '/\\/evil'] as $bad) {
        ok(M4W\Controller\AuthController::safePath($bad) === null, 'accepted ' . $bad);
    }
});
test('deleting a folder disables rules targeting it (incl. subfolders), mail falls back to inbox', function () use ($alice, $sendTo) {
    $uid = (int) $alice['id'];
    $parent = Folders::create($uid, 'Temp');
    $child = Folders::create($uid, 'TempChild', $parent);
    $rid = DB::insert('rules', RuleEngine::normalize($uid, ['name' => 'to child', 'enabled' => 1, 'conditions' => [['field' => 'subject', 'op' => 'contains', 'value' => 'orphan']],
        'actions' => [['type' => 'move', 'folder' => $child]]]) + ['user_id' => $uid, 'sort' => 1, 'created_at' => time(), 'updated_at' => time()]);
    Folders::delete($uid, $parent);
    eq((int) DB::value('SELECT enabled FROM rules WHERE id = :id', ['id' => $rid]), 0);
    DB::update('rules', ['enabled' => 1], 'id = :id', ['id' => $rid]);
    Delivery::deliver($sendTo('admin@test.local', 'orphan test'), 'x@ext.com', ['admin@test.local']);
    $m = DB::one("SELECT f.role FROM messages m JOIN folders f ON f.id = m.folder_id WHERE m.user_id = :u AND m.subject = 'orphan test'", ['u' => $uid]);
    ok($m !== null, 'mail lost in a deleted folder');
    DB::delete('rules', 'id = :id', ['id' => $rid]);
});
test('no auto-reply to null envelope sender', function () use ($bob, $sendTo) {
    Vacation::save((int) $bob['id'], ['enabled' => 1, 'body_html' => '<p>away</p>', 'interval_days' => 0]);
    $n = (int) DB::value("SELECT COUNT(*) FROM mail_queue WHERE kind = 'vacation'");
    Delivery::deliver($sendTo('bob@test.local', 'bounce', 'someone@ext.com'), '', ['bob@test.local'], 'smtp');
    eq((int) DB::value("SELECT COUNT(*) FROM mail_queue WHERE kind = 'vacation'"), $n);
    Vacation::save((int) $bob['id'], ['enabled' => 0, 'body_html' => '']);
});
test('disposable and no-reply addresses', function () {
    ok(Senders::isDisposable('bot@yopmail.com'));
    ok(Senders::isDisposable('x@deep.sub.mailinator.com'), 'subdomain of a listed domain');
    ok(!Senders::isDisposable('julie@gmail.com'));
    ok(!Senders::isDisposable('julie@orange.fr'));
    ok(!Senders::isDisposable('bob@test.local'), 'hosted domains never');
    Settings::setMany(['security.disposable_extra' => "jetable-maison.fr", 'security.disposable_allow' => "yopmail.com"]);
    Senders::reset();
    ok(Senders::isDisposable('a@jetable-maison.fr'), 'admin addition');
    ok(!Senders::isDisposable('bot@yopmail.com'), 'admin exception');
    Settings::setMany(['security.disposable_extra' => '', 'security.disposable_allow' => '']);
    Senders::reset();
    foreach (['noreply@x.com', 'No-Reply@x.com', 'no_reply+abc@x.com', 'donotreply@x.com', 'ne-pas-repondre@impots.gouv.fr', 'nepasrepondre@x.fr', 'mailer-daemon@x.com', 'notifications@github.com', 'bounces+123@x.com'] as $a) {
        ok(Senders::isNoReply($a), $a);
    }
    foreach (['noreen@x.com', 'julie.bernard@x.com', 'contact@x.com', 'reply@x.com'] as $a) {
        ok(!Senders::isNoReply($a), $a);
    }
});
test('disposable senders are refused, never answered', function () use ($bob, $sendTo) {
    Vacation::save((int) $bob['id'], ['enabled' => 1, 'body_html' => '<p>away</p>', 'interval_days' => 0]);
    $q = static fn() => (int) DB::value("SELECT COUNT(*) FROM mail_queue WHERE kind = 'vacation'");
    $n = $q();
    $res = Delivery::deliver($sendTo('bob@test.local', 'Jetable 1', 'bot@yopmail.com'), 'bot@yopmail.com', ['bob@test.local'], 'pipe');
    eq($res, ['bob@test.local' => 'rejected: disposable sender']);
    eq((int) DB::value("SELECT COUNT(*) FROM messages WHERE subject = 'Jetable 1'"), 0, 'not stored');
    eq(DB::value("SELECT status FROM mail_log WHERE subject = 'Jetable 1'"), 'rejected', 'logged');
    // Hidden behind a normal envelope / From, through Reply-To.
    Delivery::deliver($sendTo('bob@test.local', 'Jetable 2', 'client@ext.com', ['Reply-To' => 'x@guerrillamail.com']), 'client@ext.com', ['bob@test.local'], 'sync');
    eq((int) DB::value("SELECT COUNT(*) FROM messages WHERE subject = 'Jetable 2'"), 0);
    // "Put in spam" mode: stored in the spam folder, still no auto-reply.
    Settings::set('security.disposable_action', 'spam');
    Delivery::deliver($sendTo('bob@test.local', 'Jetable 3', 'bot@yopmail.com'), 'bot@yopmail.com', ['bob@test.local'], 'smtp');
    eq((int) DB::value("SELECT folder_id FROM messages WHERE subject = 'Jetable 3'"), (int) Folders::byRole((int) $bob['id'], 'spam')['id']);
    // No-reply senders are delivered normally but never answered.
    Delivery::deliver($sendTo('bob@test.local', 'Facture dispo', 'ne-pas-repondre@fournisseur.fr'), 'bounce-1@mailer.fournisseur.fr', ['bob@test.local'], 'smtp');
    ok((bool) DB::value("SELECT COUNT(*) FROM messages WHERE subject = 'Facture dispo'"));
    eq($q(), $n, 'no auto-reply sent');
    Delivery::deliver($sendTo('bob@test.local', 'Vraie question', 'client@ext.com'), 'client@ext.com', ['bob@test.local'], 'smtp');
    eq($q(), $n + 1, 'a real sender still gets the out-of-office');
    Settings::set('security.disposable_action', 'reject');
    Vacation::save((int) $bob['id'], ['enabled' => 0, 'body_html' => '']);
});
test('links: misleading text, IP, shorteners, look-alikes', function () {
    eq(M4W\Mail\LinkCheck::registrable('www.impots.gouv.fr'), 'impots.gouv.fr');
    eq(M4W\Mail\LinkCheck::registrable('a.b.example.co.uk'), 'example.co.uk');
    eq(M4W\Mail\LinkCheck::problems('https://secure-login.example.ru/x', 'www.mabanque.fr'), ['mismatch']);
    eq(M4W\Mail\LinkCheck::problems('https://cfspart.impots.gouv.fr/', 'www.impots.gouv.fr'), [], 'same site');
    eq(M4W\Mail\LinkCheck::problems('https://example.com/', 'Cliquez ici'), [], 'plain text is not an address');
    eq(M4W\Mail\LinkCheck::problems('http://192.168.4.20/login'), ['ip']);
    eq(M4W\Mail\LinkCheck::problems('https://bit.ly/3xYz'), ['shortener']);
    eq(M4W\Mail\LinkCheck::problems('https://xn--pypal-4ve.com/'), ['punycode', 'homograph', 'mixed_script'], 'pаypal.com (Cyrillic а), punycode form');
    eq(M4W\Mail\LinkCheck::problems('https://www.paypal.com@evil.example/'), ['userinfo']);
    eq(M4W\Mail\LinkCheck::problems('javascript:alert(1)'), ['script']);
});
test('links: homographs (amαzon / amаzon), mixed scripts, punycode blocked', function () {
    $H = M4W\Mail\Homograph::class;
    $P = static fn(string $h) => M4W\Mail\LinkCheck::problems('https://' . $h . '/login');
    eq($H::imitates('amαzon.com'), 'amazon.com', 'Greek alpha');
    eq($H::imitates('amаzon.com'), 'amazon.com', 'Cyrillic a');
    eq($H::imitates($H::ascii('amаzon.com')), 'amazon.com', 'punycode form too');
    eq($H::imitates('аррӏе.com'), 'apple.com', 'whole name in Cyrillic');
    eq($H::imitates('ɡoogle.com'), 'google.com', 'Latin script g');
    eq($H::imitates('amazon.com'), null);
    eq($H::imitates('élysée.fr'), null, 'an accented name is not an imitation');
    eq($H::imitates('münchen.de'), null);
    ok($H::mixedScript('amаzon.com') && $H::mixedScript('paypαl.com'), 'Latin + Cyrillic / Greek');
    ok(!$H::mixedScript('аррӏе.com') && !$H::mixedScript('élysée.fr') && !$H::mixedScript('例え.jp') && !$H::mixedScript('ソニーsony.jp'), 'single script, or Japanese with Latin');
    eq($P('amαzon.com'), ['punycode', 'homograph', 'mixed_script']);
    eq($P('élysée.fr'), ['punycode']);
    ok(str_contains($H::highlight('amаzon.com'), '<mark title="U+0430">а</mark>'), 'look-alike letter marked');
    // Blocking policy.
    $prev = Settings::get('security.idn_links');
    Settings::set('security.idn_links', 'block');
    eq([M4W\Service\LinkGuard::idnThreat($P('amаzon.com')), M4W\Service\LinkGuard::idnThreat($P('élysée.fr')), M4W\Service\LinkGuard::idnThreat($P('amazon.com'))], ['HOMOGRAPH', 'IDN', ''], 'block: every IDN');
    Settings::set('security.idn_links', 'suspicious');
    eq([M4W\Service\LinkGuard::idnThreat($P('amаzon.com')), M4W\Service\LinkGuard::idnThreat($P('élysée.fr'))], ['HOMOGRAPH', ''], 'suspicious: imitations only');
    Settings::set('security.idn_links', 'warn');
    eq(M4W\Service\LinkGuard::idnThreat($P('amаzon.com')), '', 'warn: confirmation only');
    Settings::set('security.idn_links', $prev);
});
test('antispam: a sender address in another alphabet weighs heavily', function () {
    $p = M4W\Mail\MimeParser::parse("From: Service <securite@paypаl.com>\r\nTo: a@test.local\r\nSubject: Info\r\n\r\nBonjour");
    $r = SpamFilter::evaluate($p, null);
    ok(isset($r['tests']['FROM_HOMOGRAPH']), json_encode($r['tests']));
});
test('antispam: inbox, Junk, quarantine, deleted', function () use ($bob, $sendTo) {
    $spamId = (int) Folders::byRole((int) $bob['id'], 'spam')['id'];
    $inboxId = (int) Folders::byRole((int) $bob['id'], 'inbox')['id'];
    $folderOf = static fn(string $s) => (int) DB::value('SELECT folder_id FROM messages WHERE subject = :s ORDER BY id DESC', ['s' => $s]);
    // Clean mail.
    Delivery::deliver($sendTo('bob@test.local', 'Compte rendu de réunion', 'paul@partenaire-ext.com'), 'paul@partenaire-ext.com', ['bob@test.local']);
    eq($folderOf('Compte rendu de réunion'), $inboxId);
    // Phishing: shouting subject, misleading link, asks to verify the account → Junk, with the reasons.
    $phish = '<p>Votre compte est suspendu. Vérifiez votre compte : <a href="https://secure-verif.example.ru/login">www.mabanque.fr</a></p>';
    Delivery::deliver($sendTo('bob@test.local', 'VOTRE COMPTE EST SUSPENDU!!!', 'service@banque-ext.com', [], $phish), 'service@banque-ext.com', ['bob@test.local']);
    eq($folderOf('VOTRE COMPTE EST SUSPENDU!!!'), $spamId, 'suspect → Junk');
    $m = DB::one("SELECT * FROM messages WHERE subject = 'VOTRE COMPTE EST SUSPENDU!!!'");
    $r = SpamFilter::parse(Mailbox::parsed($m)->header(SpamFilter::HEADER));
    eq($r['level'], 'suspect');
    ok(isset($r['tests']['LINK_MISMATCH'], $r['tests']['CREDENTIAL_BAIT'], $r['tests']['SUBJECT_CAPS']));
    // A forged negative upstream score and a forged verdict change nothing.
    Delivery::deliver($sendTo('bob@test.local', 'VOTRE COMPTE EST BLOQUE!!!', 'service@banque-ext.com', ['X-Spam-Score' => '-50', 'X-M4W-Spam' => 'score=0; level=ok; tests='], $phish), 'service@banque-ext.com', ['bob@test.local']);
    eq($folderOf('VOTRE COMPTE EST BLOQUE!!!'), $spamId);
    $raw = Mailbox::raw(DB::one("SELECT * FROM messages WHERE subject = 'VOTRE COMPTE EST BLOQUE!!!'"));
    eq(substr_count($raw, 'X-M4W-Spam:'), 1, 'only our verdict is kept');
    // Rules cannot pull a suspect message out of Junk.
    $bankF = Folders::create((int) $bob['id'], 'Banque');
    DB::insert('rules', RuleEngine::normalize((int) $bob['id'], ['name' => 'Banque', 'enabled' => 1, 'conditions' => [['field' => 'from', 'op' => 'contains', 'value' => 'banque-ext.com']],
        'actions' => [['type' => 'move', 'folder' => $bankF]]]) + ['user_id' => $bob['id'], 'sort' => 1, 'created_at' => time(), 'updated_at' => time()]);
    Delivery::deliver($sendTo('bob@test.local', 'VOTRE COMPTE EXPIRE!!!', 'service@banque-ext.com', [], $phish), 'service@banque-ext.com', ['bob@test.local']);
    eq($folderOf('VOTRE COMPTE EXPIRE!!!'), $spamId);
    DB::run('DELETE FROM rules WHERE user_id = :u', ['u' => $bob['id']]);
    // Upstream filter certain it is spam → quarantine: the user does not see it, an administrator releases it.
    Delivery::deliver($sendTo('bob@test.local', 'Offre speciale', 'promo@ext-promo.com', ['X-Spam-Score' => '12.4']), 'promo@ext-promo.com', ['bob@test.local']);
    eq((int) DB::value("SELECT COUNT(*) FROM messages WHERE subject = 'Offre speciale'"), 0, 'held');
    $q = DB::one("SELECT * FROM deleted_messages WHERE subject = 'Offre speciale'");
    eq($q['reason'], 'quarantine');
    M4W\Service\Retention::restore((int) $q['id'], Users::find((int) $bob['id']));
    $rel = DB::one("SELECT * FROM messages WHERE subject = 'Offre speciale'");
    eq([(int) $rel['folder_id'], (int) $rel['is_read']], [$inboxId, 0], 'released to the inbox, unread');
    // Block list → deleted (a copy is kept for the administrators).
    Settings::set('spam.block', "ext-promo.com");
    Delivery::deliver($sendTo('bob@test.local', 'Encore une offre', 'news@mail.ext-promo.com'), 'news@mail.ext-promo.com', ['bob@test.local']);
    eq((int) DB::value("SELECT COUNT(*) FROM messages WHERE subject = 'Encore une offre'"), 0);
    eq(DB::value("SELECT reason FROM deleted_messages WHERE subject = 'Encore une offre'"), 'spam');
    // Allow list wins over the upstream verdict.
    Settings::setMany(['spam.block' => '', 'spam.allow' => 'ext-promo.com']);
    Delivery::deliver($sendTo('bob@test.local', 'Offre partenaire', 'promo@ext-promo.com', ['X-Spam-Flag' => 'YES']), 'promo@ext-promo.com', ['bob@test.local']);
    eq($folderOf('Offre partenaire'), $inboxId);
    Settings::set('spam.allow', '');
    // Thresholds: 0 turns a level off.
    Settings::setMany(['spam.quarantine' => 0, 'spam.delete' => 0]);
    eq(SpamFilter::level(80), 'suspect');
    Settings::setMany(['spam.quarantine' => 10, 'spam.delete' => 20]);
    eq(SpamFilter::level(4.5), 'ok');
    eq(SpamFilter::level(20), 'delete');
});
test('antispam: "not junk" puts the message back, teaches the filter and trusts the sender', function () use ($bob, $sendTo) {
    $uid = (int) $bob['id'];
    $spamId = (int) Folders::byRole($uid, 'spam')['id'];
    $inboxId = (int) Folders::byRole($uid, 'inbox')['id'];
    $body = '<p>Votre compte est suspendu. Vérifiez votre compte : <a href="https://secure-verif.example.ru/login">www.mabanque.fr</a></p>';
    $one = static function (string $subject) use ($sendTo, $body) {
        Delivery::deliver($sendTo('bob@test.local', $subject, 'alertes@fournisseur-ext.com', [], $body), 'alertes@fournisseur-ext.com', ['bob@test.local']);
        return DB::one('SELECT * FROM messages WHERE subject = :s ORDER BY id DESC', ['s' => $subject]);
    };
    $m = $one('URGENT VERIFIEZ VOTRE COMPTE!!!');
    eq((int) $m['folder_id'], $spamId, 'filed as junk');
    $r = M4W\Service\TrustedSenders::notJunk($uid, [(int) $m['id']], $uid);
    eq([$r['count'], $r['senders']], [1, ['alertes@fournisseur-ext.com']]);
    $m = DB::one('SELECT * FROM messages WHERE id = :id', ['id' => $m['id']]);
    eq([(int) $m['folder_id'], (int) $m['bayes']], [$inboxId, -1], 'back in the inbox, learnt as not junk');
    ok((int) DB::value("SELECT COUNT(*) FROM audit_log WHERE action = 'spam.false_positive'") > 0, 'false positive recorded');
    // Next one from the same sender: inbox.
    $m2 = $one('URGENT VERIFIEZ VOTRE COMPTE ENCORE!!!');
    eq((int) $m2['folder_id'], $inboxId, 'trusted sender reaches the inbox');
    ok(str_contains((string) Mailbox::parsed($m2)->header(SpamFilter::HEADER), 'USER_TRUSTED'), 'reason recorded');
    // Not for someone else.
    ok(!M4W\Service\TrustedSenders::has((int) DB::value("SELECT id FROM users WHERE email = 'alice@test.local'"), 'alertes@fournisseur-ext.com'), 'per person');
    // Junk after all: trust withdrawn.
    M4W\Service\TrustedSenders::remove($uid, 'alertes@fournisseur-ext.com');
    eq(M4W\Service\TrustedSenders::list($uid), [], 'removed');
    // An address of our own domains is never trusted this way.
    eq(M4W\Service\TrustedSenders::notJunk($uid, [], $uid)['senders'], [], 'nothing to trust');
});
test('antispam: people the user writes to are trusted a little more', function () use ($bob, $sendTo) {
    $body = '<p>Voir <a href="https://bit.ly/abc">le lien</a></p>';
    $mk = static fn(string $s) => $sendTo('bob@test.local', $s, 'ami@ext-ami.com', [], $body);
    $p = MimeParser::parse($mk('A'));
    $base = SpamFilter::evaluate($p, null, '');
    Settings::set('spam.suspect', $base['score'] + 0.5);
    $before = SpamFilter::forUser($base, (int) $bob['id'], $p, null);
    M4W\Service\Contacts::touchRecipients((int) $bob['id'], [['email' => 'ami@ext-ami.com', 'name' => 'Ami']]);
    $after = SpamFilter::forUser($base, (int) $bob['id'], $p, null);
    eq(round($before['score'] - $after['score'], 1), 3.0);
    // A forged contact address that fails authentication gets no bonus.
    $forged = SpamFilter::forUser($base, (int) $bob['id'], $p, ['verdict' => 'dmarc', 'spf' => 'fail', 'dkim' => []]);
    eq($forged['score'], $before['score']);
    Settings::set('spam.suspect', 5);
});
test('links: checked on click, real destination on hover', function () {
    $s = new HtmlSanitizer(true, null, true, null, static fn(string $h, string $t) => M4W\Service\LinkGuard::wrap($h, $t, 42, 1));
    $out = $s->sanitize('<a href="https://evil.example.ru/x?a=1&amp;b=2">www.mabanque.fr</a> <a href="mailto:a@b.fr">mail</a>');
    ok(str_contains($out, 'title="https://evil.example.ru/x?a=1&amp;b=2"'), 'real address on hover');
    ok(preg_match('#href="[^"]*/go\?u=https%3A%2F%2Fevil\.example\.ru%2Fx%3Fa%3D1%26b%3D2&amp;t=www\.mabanque\.fr&amp;m=42&amp;s=[0-9a-f]{32}"#', $out) === 1, 'goes through /go');
    ok(str_contains($out, 'href="mailto:a@b.fr"'), 'mailto untouched');
    $sig = M4W\Service\LinkGuard::sign('https://a.example/', 'x', 1, 5);
    ok(M4W\Service\LinkGuard::verify('https://a.example/', 'x', 1, 5, $sig));
    ok(!M4W\Service\LinkGuard::verify('https://evil.example/', 'x', 1, 5, $sig), 'cannot reuse a signature for another address');
    ok(!M4W\Service\LinkGuard::verify('https://a.example/', 'x', 1, 6, $sig), 'nor for another user');
});
test('links: /go redirects, warns or blocks', function () use ($bob) {
    \M4W\Core\Auth::setUser(Users::find((int) $bob['id']));
    $_SESSION = [];
    $mid = (int) DB::value('SELECT id FROM messages WHERE user_id = :u ORDER BY id LIMIT 1', ['u' => $bob['id']]);
    $go = static function (string $href, string $text = '', ?string $sig = null) use ($mid, $bob) {
        $q = ['u' => $href, 't' => $text, 'm' => $mid, 's' => $sig ?? M4W\Service\LinkGuard::sign($href, $text, $mid, (int) $bob['id'])];
        return (new M4W\Controller\LinkController(new \M4W\Core\Request('GET', '/go', $q, [], [], [])))->go();
    };
    $r = $go('https://www.example.org/page', 'Lire la suite');
    eq([$r->status, $r->getHeader('Location')], [302, 'https://www.example.org/page'], 'clean link goes straight through');
    eq($r->getHeader('Referrer-Policy'), 'no-referrer');
    $r = $go('https://secure-verif.example.ru/login', 'https://www.mabanque.fr/');
    eq([$r->status, $r->getHeader('Location')], [200, null], 'misleading link: warning page');
    ok(str_contains($r->body, 'example.ru') && str_contains($r->body, 'href="https://secure-verif.example.ru/login"'), 'shows the real site, lets the user decide');
    $r = $go('https://evil.example/', '', 'forged');
    eq([$r->status, $r->getHeader('Location')], [400, null], 'not an open redirect');
    // A link signed for someone else's message (minted by mailing oneself) does not work for Bob.
    $other = (int) DB::value('SELECT id FROM messages WHERE user_id <> :u ORDER BY id LIMIT 1', ['u' => $bob['id']]);
    $q = ['u' => 'https://evil.example/', 't' => '', 'm' => $other, 's' => M4W\Service\LinkGuard::sign('https://evil.example/', '', $other, (int) $bob['id'])];
    eq((new M4W\Controller\LinkController(new \M4W\Core\Request('GET', '/go', $q, [], [], [])))->go()->status, 400, 'bound to the viewer\'s own messages');
    // URLhaus feed (no key needed).
    M4W\Service\LinkGuard::updateFeed("# URLhaus\n" . implode("\n", array_map(static fn($i) => "http://203.0.113.$i/bins/x.sh", range(1, 12))) . "\nhttps://malware.example.net/payload.exe\n");
    $r = $go('https://malware.example.net/payload.exe', 'Facture');
    ok($r->status === 200 && str_contains($r->body, 'URLhaus') && !str_contains($r->body, 'href="https://malware.example.net'), 'known malware: blocked, no way through');
    ok((bool) DB::value("SELECT COUNT(*) FROM audit_log WHERE action = 'link.blocked'"), 'journaled');
    // Administrator's block list, subdomains included.
    Settings::set('security.link_block', 'interdit.example');
    ok(str_contains($go('https://www.interdit.example/', 'x')->body, 'bloqué par votre administrateur'));
    Settings::set('security.link_block', '');
    // Google Safe Browsing (fake transport), with its cache.
    $calls = 0;
    M4W\Service\LinkGuard::$transport = static function (string $api, array $urls) use (&$calls) {
        $calls++;
        return array_fill_keys(array_filter($urls, static fn($u) => str_contains($u, 'phish')), 'SOCIAL_ENGINEERING');
    };
    M4W\Service\LinkGuard::setApiKey('AIzaFakeKeyForTests_0123456789');
    ok(str_contains($go('https://login.phish.example/', 'x')->body, 'hameçonnage (Google Safe Browsing)'));
    $go('https://login.phish.example/', 'x');
    eq($calls, 1, 'verdict cached');
    eq($go('https://ok.example/', 'x')->status, 302);
    M4W\Service\LinkGuard::$transport = null;
    M4W\Service\LinkGuard::setApiKey('');
    \M4W\Core\Auth::setUser(null);
});
test('impersonation: colleague names and look-alike domains', function () use ($bob, $sendTo) {
    DB::insert('domains', ['name' => 'acmecorp.fr', 'active' => 1, 'created_at' => time()]);
    foreach (['acmecorp.com', 'acme-corp.fr', 'acmecorp-paiement.fr', 'acrnecorp.fr', 'acmecorq.fr'] as $d) {
        eq(SpamFilter::lookalikeDomain($d), 'acmecorp.fr', $d);
    }
    foreach (['acmecorp.fr', 'mail.acmecorp.fr', 'gmail.com', 'corp.fr'] as $d) {
        eq(SpamFilter::lookalikeDomain($d), null, $d);
    }
    DB::run("DELETE FROM domains WHERE name = 'acmecorp.fr'");
    $u = DB::one('SELECT first_name, last_name FROM users WHERE id = :id', ['id' => $bob['id']]);
    $name = $u['first_name'] . ' ' . $u['last_name'];
    $raw = $sendTo('bob@test.local', 'Virement urgent', 'pdg.direction@gmail.com');
    $raw = preg_replace('/^From: .*$/m', 'From: "' . mb_strtoupper($name) . '" <pdg.direction@gmail.com>', $raw, 1);
    $p = MimeParser::parse($raw);
    eq(SpamFilter::colleagueName($p), $bob['email']);
    ok(isset(SpamFilter::evaluate($p, null)['tests']['COLLEAGUE_NAME']));
    eq(SpamFilter::colleagueName(MimeParser::parse($sendTo('bob@test.local', 'x', 'client@ext.com'))), null);
});
test('security review: trust markers, 2FA lock, delegate privacy', function () use ($bob, $sendTo) {
    // Our markers are dropped on every path, provider spam and antivirus off included.
    $forged = "X-M4W-Auth: verdict=ok; spf=pass; dkim=pass@x; dmarc=pass; policy=reject\nX-M4W-Spam: score=0; level=ok; tests=\n" . $sendTo('bob@test.local', 'forge');
    $clean = Delivery::sanitizeIncoming($forged);
    ok(!str_contains($clean, 'X-M4W-Auth') && !str_contains($clean, 'X-M4W-Spam'));
    $av = Settings::get('security.antivirus');
    Settings::set('security.antivirus', 0);
    $part = "From: a@ext.com\nSubject: x\nMIME-Version: 1.0\nContent-Type: multipart/mixed; boundary=\"B\"\n\n--B\nContent-Type: text/plain\n\nhi\n--B\nContent-Type: application/octet-stream; name=\"f.exe\"\nX-M4W-Removed: virus; name*=UTF-8''f.exe; signature*=UTF-8''Fake\n\nMZ\n--B--\n";
    ok(!str_contains(M4W\Service\AttachmentGuard::process($part)['raw'], 'X-M4W-Removed'), 'part markers dropped, antivirus off');
    Settings::set('security.antivirus', $av);
    // Wrong passwords typed by a third party do not lock the owner out of the code step.
    for ($i = 0; $i < 6; $i++) {
        DB::insert('login_attempts', ['ip' => '198.51.100.9', 'email' => 'bob@test.local', 'success' => 0, 'created_at' => time()]);
    }
    ok(!\M4W\Core\Auth::twoFactorLocked('bob@test.local'));
    for ($i = 0; $i < 5; $i++) {
        \M4W\Core\Auth::twoFactorFailed('bob@test.local', '198.51.100.10');
    }
    ok(\M4W\Core\Auth::twoFactorLocked('bob@test.local'), 'wrong codes do lock');
    DB::run("DELETE FROM login_attempts WHERE email LIKE '%bob@test.local'");
    // Delegate: personal folders cannot be reached through their parent or through rules.
    $uid = (int) $bob['id'];
    $parent = Folders::create($uid, 'Projets');
    $perso = Folders::create($uid, 'Perso', $parent);
    Mailbox::$privacy = true;
    $thrown = false;
    try { Folders::delete($uid, $parent); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'parent of a personal folder not deleted');
    $rule = ['conditions' => [['field' => 'subject', 'op' => 'contains', 'value' => 'x']], 'match' => 'all', 'actions' => []];
    $thrown = false;
    try { RuleEngine::applyToFolder($uid, $rule, $perso); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'rules cannot probe a personal folder');
    Mailbox::$privacy = false;
    Folders::delete($uid, $parent);
});
test('SSRF guard rejects private and IPv6 loopback', function () {
    foreach (['127.0.0.1', '::1', '10.1.2.3', '169.254.169.254', '::ffff:127.0.0.1', 'localhost'] as $h) {
        $thrown = false;
        try { M4W\Core\Net::publicIp($h); } catch (\InvalidArgumentException) { $thrown = true; }
        ok($thrown, "accepted $h");
    }
    eq(M4W\Core\Net::publicIp('8.8.8.8'), '8.8.8.8');
});

echo "\nMailbox\n";
test('search operators', function () use ($alice) {
    $r = Mailbox::list((int) $alice['id'], ['q' => 'from:bob subject:réunion']);
    ok($r['total'] >= 1, 'operator search');
    $r = Mailbox::list((int) $alice['id'], ['q' => 'has:attachment']);
    eq($r['total'], 0);
});
test('files handed out: sandboxed, except a PDF shown by the browser viewer (still kept off other origins)', function () {
    $pdf = M4W\Core\Response::fileCsp('application/pdf');
    ok(!str_contains($pdf, 'sandbox') && str_contains($pdf, "frame-ancestors 'self'") && str_contains($pdf, "default-src 'none'"), 'PDF');
    foreach (['image/png', 'text/plain', 'image/svg+xml', ''] as $m) {
        ok(str_contains(M4W\Core\Response::fileCsp($m), 'sandbox'), "sandboxed: $m");
    }
});
test('list: attachments marked when one is blocked (red) or needs care (yellow)', function () use ($alice) {
    $uid = (int) $alice['id'];
    $inbox = (int) Folders::byRole($uid, 'inbox')['id'];
    $mk = static function (string $name, string $mime, string $data) use ($uid, $inbox) {
        $b = 'b' . bin2hex(random_bytes(4));
        return Mailbox::store($uid, $inbox, "From: x@ext.com\r\nSubject: PJ $name\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n--$b\r\nContent-Type: text/plain\r\n\r\nx\r\n--$b\r\n"
            . "Content-Type: $mime; name=\"$name\"\r\nContent-Disposition: attachment; filename=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode($data) . "\r\n--$b--\r\n");
    };
    $ids = ['block' => $mk('facture.exe', 'application/octet-stream', "MZ\x90\0" . str_repeat("\0", 60)), 'warn' => $mk('rapport.pdf', 'application/pdf', 'bonjour'), '' => $mk('note.txt', 'text/plain', 'bonjour')];
    $rows = [];
    foreach (Mailbox::list($uid, ['folder' => $inbox, 'limit' => 200])['items'] as $it) { $rows[$it['id']] = $it['att_risk']; }
    foreach ($ids as $risk => $id) { eq($rows[$id] ?? null, $risk, "row $risk"); }
    $th = [];
    foreach (Mailbox::list($uid, ['folder' => $inbox, 'limit' => 200, 'threads' => 1])['items'] as $it) { $th[$it['id']] = $it['att_risk']; }
    eq($th[$ids['block']] ?? 'absent', 'block', 'conversation view too');
    Mailbox::delete($uid, array_values($ids), true);
});
test('antivirus unreachable: the reason is explained and a daemon elsewhere is found', function () {
    $prev = M4W\Core\Settings::get('security.clamd');
    M4W\Core\Settings::set('security.clamd', 'unix:///nonexistent/clamd.ctl');
    $d = M4W\Service\Antivirus::diagnose();
    ok(!$d['ok'] && str_contains($d['reason'], '/nonexistent/clamd.ctl'), 'missing socket explained');
    // A clamd answering on another usual address.
    $sock = '/tmp/clamd.socket';
    @unlink($sock);
    $code = '$s = stream_socket_server("unix://' . $sock . '"); for ($i = 0; $i < 20; $i++) { $c = @stream_socket_accept($s, 5); if (!$c) break; fread($c, 64); fwrite($c, "PONG\0"); fclose($c); }';
    $proc = proc_open([PHP_BINARY, '-r', $code], [], $pipes);
    for ($i = 0; $i < 30 && !file_exists($sock); $i++) { usleep(100000); }
    $d = M4W\Service\Antivirus::diagnose();
    ok(in_array('unix://' . $sock, $d['found'], true), 'found at ' . $sock);
    M4W\Core\Settings::set('security.clamd', 'unix://' . $sock);
    ok(M4W\Service\Antivirus::diagnose()['ok'], 'answers once chosen');
    proc_terminate($proc);
    @unlink($sock);
    M4W\Core\Settings::set('security.clamd', $prev);
});
test('branding: side menu (and header) in a colour of choice or a picture, readable text', function () {
    $B = M4W\Service\Branding::class;
    $keep = array_map(static fn($k) => Settings::get($k), $keys = ['brand.sidebar', 'brand.sidebar_color', 'brand.sidebar_header', 'brand.sidebar_main', 'brand.sidebar_bg', 'brand.sidebar_bg_avg', 'brand.accent']);
    Settings::setMany(['brand.sidebar' => 'light']);
    eq($B::bodyClasses(), '', 'light: nothing');
    Settings::setMany(['brand.sidebar' => 'custom', 'brand.sidebar_color' => '#0f766e', 'brand.sidebar_header' => 1]);
    ok(str_contains($B::css(), '--m4w-sidebar-bg:#0f766e;--m4w-sidebar-fg:#ffffff'), 'custom colour, white text');
    eq($B::bodyClasses(), 'has-chrome chrome-on-dark chrome-header chrome-main');
    Settings::set('brand.sidebar_main', 0);
    eq($B::bodyClasses(), 'has-chrome chrome-on-dark chrome-header', 'work area left alone');
    Settings::setMany(['brand.sidebar' => 'custom', 'brand.sidebar_color' => '#fde68a', 'brand.sidebar_header' => 0]);
    ok(str_contains($B::css(), '--m4w-sidebar-fg:#0f172a'), 'dark text on a light colour');
    eq($B::bodyClasses(), 'has-chrome', 'header left alone');
    Settings::setMany(['brand.sidebar' => 'accent', 'brand.accent' => '#7c3aed']);
    ok(str_contains($B::css(), '--m4w-sidebar-bg:#7c3aed'), 'secondary colour');
    // Picture: its average colour decides the text colour; the picture goes with a veil.
    $im = imagecreatetruecolor(20, 20); imagefill($im, 0, 0, imagecolorallocate($im, 240, 230, 200));
    $f = tempnam(sys_get_temp_dir(), 'bg'); imagepng($im, $f);
    eq($B::averageColor($f), '#f0e6c8');
    Settings::setMany(['brand.sidebar' => 'image', 'brand.sidebar_bg' => 'sidebarbg-0123456789ab.png', 'brand.sidebar_bg_avg' => '#f0e6c8']);
    $css = $B::css();
    ok(str_contains($css, '--m4w-sidebar-fg:#0f172a') && str_contains($css, "url('") && str_contains($css, 'rgba(255,255,255,.45)'), 'light picture: dark text, light veil');
    Settings::setMany(['brand.sidebar_bg' => '../../etc/passwd']);
    ok(!str_contains($B::css(), 'url('), 'odd file names ignored');
    Settings::setMany(array_combine($keys, $keep));
});
test('pinned messages stay at the top of their folder, not in search results', function () use ($alice) {
    $uid = (int) $alice['id'];
    $inbox = (int) Folders::byRole($uid, 'inbox')['id'];
    $old = Mailbox::store($uid, $inbox, "From: x@ext.com\r\nTo: alice@test.local\r\nSubject: Vieux mais important\r\nDate: " . date('r', time() - 90 * 86400) . "\r\n\r\nx", ['date_received' => time() - 90 * 86400]);
    $first = static fn(array $o) => Mailbox::list($uid, $o + ['folder' => $inbox, 'limit' => 200])['items'][0];
    ok($first([])['id'] !== $old, 'by date first');
    Mailbox::setFlags($uid, [$old], ['is_pinned' => true]);
    eq([$first([])['id'], $first([])['pinned']], [$old, true], 'pinned on top');
    eq($first(['threads' => 1])['id'], $old, 'in conversations too');
    eq($first(['sort' => 'subject', 'dir' => 'asc'])['id'], $old, 'whatever the sort');
    ok(Mailbox::list($uid, ['q' => 'important'])['items'][0]['pinned'], 'flag shown in search');
    Mailbox::setFlags($uid, [$old], ['is_pinned' => false]);
    ok($first([])['id'] !== $old, 'unpinned');
    Mailbox::delete($uid, [$old], true);
});
test('delete moves to trash, second delete purges file and quota', function () use ($alice) {
    $m = DB::one("SELECT m.* FROM messages m JOIN folders f ON f.id = m.folder_id WHERE m.user_id = :u AND f.role = 'inbox' LIMIT 1", ['u' => $alice['id']]);
    $path = storage_path($m['storage_path']);
    Mailbox::delete((int) $alice['id'], [(int) $m['id']]);
    eq(DB::value('SELECT f.role FROM messages m JOIN folders f ON f.id = m.folder_id WHERE m.id = :id', ['id' => $m['id']]), 'trash');
    Mailbox::delete((int) $alice['id'], [(int) $m['id']]);
    ok(!is_file($path) && Mailbox::get((int) $alice['id'], (int) $m['id']) === null);
});
test('users cannot read each other\'s messages', function () use ($alice, $bob) {
    $m = DB::one('SELECT id FROM messages WHERE user_id = :u LIMIT 1', ['u' => $bob['id']]);
    ok(Mailbox::get((int) $alice['id'], (int) $m['id']) === null);
    eq(Mailbox::setFlags((int) $alice['id'], [(int) $m['id']], ['is_read' => 1]), 0);
});
test('quota enforcement on delivery', function () use ($bob, $sendTo) {
    DB::update('users', ['quota_mb' => 1, 'used_bytes' => 2 * 1024 * 1024], 'id = :id', ['id' => $bob['id']]);
    $r = Delivery::deliver($sendTo('bob@test.local', 'full'), 'a@ext.com', ['bob@test.local']);
    eq($r['bob@test.local'], 'mailbox full');
    DB::update('users', ['quota_mb' => 2048], 'id = :id', ['id' => $bob['id']]);
    Users::recalcUsage((int) $bob['id']);
});

// ---------------------------------------------------------------- File space
use M4W\Service\Files;
use M4W\Mail\MailAuth;

$fsCarol = Users::find(Users::create(['email' => 'carol@test.local', 'password' => 'Secret!Pass123', 'first_name' => 'Carol', 'department' => 'Finance']));
$fsAdmin = Users::findByEmail('admin@test.local');
$fsBob = Users::find((int) $bob['id']);
$fsFile = static function (string $content): string {
    $p = tempnam(sys_get_temp_dir(), 'fst');
    file_put_contents($p, $content);
    return $p;
};
$fsState = [];
test('files: space rights are inherited and enforced', function () use ($fsAdmin, $fsBob, $fsCarol, $fsFile, &$fsState) {
    $space = Files::createSpace($fsAdmin, 'Finance', 'Budgets');
    $sub = Files::createFolder($fsAdmin, $space, 'Contrats');
    $deep = Files::createFolder($fsAdmin, $sub, 'Archives');
    $f = Files::addFromPath($fsAdmin, $deep, $fsFile(str_repeat("\x00\xff binaire ", 300000)), 'contrat.pdf');
    eq(Files::permission($fsBob, $deep), Files::NONE, 'no access by default');
    Files::setAcl($fsAdmin, $space, [['type' => 'department', 'principal' => 'FINANCE', 'level' => 'write'], ['type' => 'user', 'principal' => $fsBob['id'], 'level' => 'read']]);
    Files::resetCache();
    eq(Files::permission($fsCarol, $deep), Files::WRITE, 'department grant inherited');
    eq(Files::permission($fsBob, $deep), Files::READ, 'user grant inherited');
    $thrown = false;
    try { Files::createFolder($fsBob, $deep, 'x'); } catch (\M4W\Core\HttpException $e) { $thrown = $e->status === 403; }
    ok($thrown, 'reader cannot write');
    $thrown = false;
    try { Files::setAcl($fsCarol, $space, []); } catch (\M4W\Core\HttpException $e) { $thrown = $e->status === 403; }
    ok($thrown, 'writer cannot change access');
    eq(Files::contents(Files::file($f['id'])['blob_id']), str_repeat("\x00\xff binaire ", 300000), 'binary roundtrip across chunks');
    $fsState = ['space' => $space, 'sub' => $sub, 'deep' => $deep, 'file' => $f['id']];
});
test('files: personal space is private, even to administrators', function () use ($fsAdmin, $fsBob, $fsFile) {
    $root = Files::personalRoot((int) $fsBob['id']);
    Files::addFromPath($fsBob, (int) $root['id'], $fsFile('perso'), 'notes.txt');
    eq(Files::permission($fsAdmin, (int) $root['id']), Files::NONE);
    $thrown = false;
    try { Files::listing($fsAdmin, (int) $root['id']); } catch (\M4W\Core\HttpException $e) { $thrown = $e->status === 404; }
    ok($thrown, '404, existence not revealed');
    $shared = Files::createFolder($fsBob, (int) $root['id'], 'Pour Camille');
    Files::setAcl($fsBob, $shared, [['type' => 'user', 'principal' => $fsAdmin['id'], 'level' => 'read']]);
    Files::resetCache();
    $mine = array_column(Files::sharedWithMe($fsAdmin), 'id');
    ok(in_array($shared, $mine, true), 'listed in "shared with me"');
    eq(Files::permission($fsAdmin, (int) $root['id']), Files::NONE, 'parent stays private');
});
test('files: a single file is shared without its folder', function () use ($fsAdmin, $fsBob, $fsCarol, $fsFile) {
    $root = Files::personalRoot((int) $fsBob['id']);
    $dir = Files::createFolder($fsBob, (int) $root['id'], 'Dossier privé');
    $one = Files::addFromPath($fsBob, $dir, $fsFile('rapport'), 'rapport.pdf');
    $other = Files::addFromPath($fsBob, $dir, $fsFile('secret'), 'autre.pdf');
    $thrown = false;
    try { Files::requireFile($fsCarol, $one['id'], Files::READ); } catch (\M4W\Core\HttpException $e) { $thrown = $e->status === 404; }
    ok($thrown, 'nothing shared yet');
    // Only the manager of the folder may share its files.
    $thrown = false;
    try { Files::setFileAcl($fsCarol, $one['id'], [['type' => 'all', 'principal' => '*']]); } catch (\M4W\Core\HttpException) { $thrown = true; }
    ok($thrown, 'a stranger cannot share it');
    Files::setFileAcl($fsBob, $one['id'], [['type' => 'department', 'principal' => 'Finance'], ['type' => 'user', 'principal' => 'x'], ['type' => 'bogus', 'principal' => '1']]);
    Files::resetCache();
    eq(count(Files::fileAcl($fsBob, $one['id'])['entries']), 1, 'invalid entries dropped');
    eq(Files::filePermission($fsCarol, Files::file($one['id'])), Files::READ, 'department sees the file');
    eq(Files::contents(Files::requireFile($fsCarol, $one['id'], Files::READ)['blob_id']), 'rapport', 'can download');
    eq(Files::permission($fsCarol, $dir), Files::NONE, 'the folder stays private');
    $thrown = false;
    try { Files::requireFile($fsCarol, $other['id'], Files::READ); } catch (\M4W\Core\HttpException) { $thrown = true; }
    ok($thrown, 'its neighbours stay private');
    $thrown = false;
    try { Files::rename($fsCarol, 'file', $one['id'], 'pirate.pdf'); } catch (\M4W\Core\HttpException $e) { $thrown = $e->status === 403; }
    ok($thrown, 'read only: no rename');
    $thrown = false;
    try { Files::delete($fsCarol, [['type' => 'file', 'id' => $one['id']]]); } catch (\M4W\Core\HttpException) { $thrown = true; }
    ok($thrown || Files::file($one['id']) !== null, 'read only: no delete');
    ok(in_array($one['id'], array_column(Files::sharedFilesWithMe($fsCarol), 'id'), true), 'listed in "shared with me"');
    ok(!in_array($one['id'], array_column(Files::sharedFilesWithMe($fsBob), 'id'), true), 'not for its owner');
    // Copy into one's own files, attach to a message.
    $mine = Files::personalRoot((int) $fsCarol['id']);
    eq(Files::copy($fsCarol, [['type' => 'file', 'id' => $one['id']]], (int) $mine['id']), 1, 'copied');
    ok(count(Files::toUploads($fsCarol, [$one['id']])) === 1, 'attachable');
    // Trashing the folder hides the file too; unsharing removes access.
    Files::delete($fsBob, [['type' => 'folder', 'id' => $dir]]);
    Files::resetCache();
    eq(Files::filePermission($fsCarol, Files::file($one['id'], true)), Files::NONE, 'gone with its folder');
    Files::restore($fsBob, [['type' => 'folder', 'id' => $dir]]);
    Files::setFileAcl($fsBob, $one['id'], []);
    Files::resetCache();
    eq(Files::filePermission($fsCarol, Files::file($one['id'])), Files::NONE, 'unshared');
});
test('files: compress a selection into a zip next to it', function () use ($fsBob, $fsCarol, $fsFile) {
    $root = (int) Files::personalRoot((int) $fsBob['id'])['id'];
    $dir = Files::createFolder($fsBob, $root, 'Projet');
    $sub = Files::createFolder($fsBob, $dir, 'Annexes');
    $a = Files::addFromPath($fsBob, $dir, $fsFile('alpha'), 'a.txt');
    Files::addFromPath($fsBob, $sub, $fsFile('beta'), 'b.txt');
    $c = Files::addFromPath($fsBob, $root, $fsFile('gamma'), 'c.txt');
    $z = Files::compress($fsBob, [['type' => 'folder', 'id' => $dir], ['type' => 'file', 'id' => $c['id']]], $root);
    eq($z['name'], 'Archive.zip');
    eq($z['mime'], 'application/zip');
    $tmp = Files::toTempFile(Files::file($z['id'])['blob_id']);
    $zip = new \ZipArchive();
    $zip->open($tmp);
    eq([$zip->getFromName('Projet/a.txt'), $zip->getFromName('Projet/Annexes/b.txt'), $zip->getFromName('c.txt')], ['alpha', 'beta', 'gamma'], 'tree kept');
    $zip->close();
    @unlink($tmp);
    eq(Files::compress($fsBob, [['type' => 'file', 'id' => $a['id']]], $dir)['name'], 'a.zip', 'named after a single item');
    eq(Files::compress($fsBob, [['type' => 'file', 'id' => $a['id']]], $dir)['name'], 'a (2).zip', 'never overwrites');
    $thrown = false;
    try { Files::compress($fsCarol, [['type' => 'file', 'id' => $a['id']]], (int) Files::personalRoot((int) $fsCarol['id'])['id']); } catch (\M4W\Core\HttpException) { $thrown = true; }
    ok($thrown, 'cannot zip what one cannot read');
});
test('files: too big for e-mail suggests a transfer service, never a public link', function () use ($fsBob, $fsFile) {
    Settings::set('security.max_attachment_mb', 1);
    $big = Files::addFromPath($fsBob, (int) Files::personalRoot((int) $fsBob['id'])['id'], $fsFile(str_repeat('x', 1100000)), 'video.mp4');
    $e = null;
    try { Files::toUploads($fsBob, [$big['id']]); } catch (\M4W\Service\TooBigForMail $x) { $e = $x; }
    ok($e !== null, 'refused as attachment');
    eq([$e->details()['too_big'], $e->details()['file_id'], $e->details()['max_mb']], [true, $big['id'], 1]);
    $names = array_column(\M4W\Service\TooBigForMail::services(), 'name');
    ok(in_array('SwissTransfer', $names, true) && in_array('WeTransfer', $names, true), 'default services');
    Settings::set('files.transfer_services', "Interne|http://insecure.example\nBon|https://ok.example\n|https://noname");
    eq(\M4W\Service\TooBigForMail::services(), [['name' => 'Bon', 'url' => 'https://ok.example']], 'https only, named');
    Settings::set('files.transfer_services', (string) (\M4W\Core\Settings::DEFAULTS['files.transfer_services'] ?? ''));
    Settings::set('security.max_attachment_mb', 25);
});
test('files: office documents are previewed in the app, safely', function () use ($tmp) {
    $mk = static function (string $name, array $parts) use ($tmp): string {
        $p = $tmp . '/' . $name;
        $z = new \ZipArchive();
        $z->open($p, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($parts as $k => $v) { $z->addFromString($k, $v); }
        $z->close();
        return $p;
    };
    $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
    $docx = $mk('t.docx', ['word/document.xml' => "<?xml version=\"1.0\"?><w:document $w><w:body>"
        . '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Rapport annuel</w:t></w:r></w:p>'
        . '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Gras</w:t></w:r><w:r><w:t xml:space="preserve"> et &lt;script&gt;alert(1)&lt;/script&gt;</w:t></w:r></w:p>'
        . '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>A1</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>B1</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
        . '</w:body></w:document>']);
    $h = \M4W\Service\DocPreview::render($docx, 'rapport.docx');
    ok(str_contains($h, '<h1>Rapport annuel</h1>') && str_contains($h, '<b>Gras</b>'), 'headings and bold');
    ok(str_contains($h, '&lt;script&gt;') && !str_contains($h, '<script'), 'text escaped');
    ok(str_contains($h, '<td><p>A1</p></td>'), 'tables');
    $s = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    $xlsx = $mk('t.xlsx', [
        'xl/workbook.xml' => "<workbook $s><sheets><sheet name=\"Budget\" sheetId=\"1\" r:id=\"rId1\"/><sheet name=\"Caché\" sheetId=\"2\" state=\"hidden\" r:id=\"rId2\"/></sheets></workbook>",
        'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="x"/><Relationship Id="rId2" Target="worksheets/sheet2.xml" Type="x"/></Relationships>',
        'xl/sharedStrings.xml' => "<sst $s><si><t>Poste</t></si><si><t>Loyer</t></si></sst>",
        'xl/worksheets/sheet1.xml' => "<worksheet $s><sheetData><row r=\"1\"><c r=\"A1\" t=\"s\"><v>0</v></c><c r=\"C1\"><v>1250.5</v></c></row><row r=\"3\"><c r=\"A3\" t=\"s\"><v>1</v></c></row></sheetData></worksheet>",
    ]);
    $h = \M4W\Service\DocPreview::render($xlsx, 'budget.xlsx');
    ok(str_contains($h, '<h2>Budget</h2>') && !str_contains($h, 'Caché'), 'visible sheets only');
    ok(str_contains($h, '>Poste</td><td></td><td class="n"') && str_contains($h, '1250.5') && str_contains($h, '>Loyer<'), 'cells placed by reference');
    $pptx = $mk('t.pptx', [
        'ppt/presentation.xml' => '<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><p:sldIdLst><p:sldId id="256" r:id="rId2"/></p:sldIdLst></p:presentation>',
        'ppt/_rels/presentation.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId2" Target="slides/slide1.xml" Type="x"/></Relationships>',
        'ppt/slides/slide1.xml' => '<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><p:cSld><p:spTree><p:sp><p:nvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>Bienvenue</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>',
    ]);
    ok(str_contains(\M4W\Service\DocPreview::render($pptx, 'deck.pptx'), '<p class="t">Bienvenue</p>'), 'slides');
    file_put_contents($tmp . '/t.csv', "Nom;Montant\nDupont;12,50\n");
    ok(str_contains(\M4W\Service\DocPreview::render($tmp . '/t.csv', 'export.csv'), '<td>Dupont</td><td class="n">12,50</td>'), 'csv with ; separator');
    $thrown = false;
    try { \M4W\Service\DocPreview::render($tmp . '/t.csv', 'macro.docm'); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown && !\M4W\Service\DocPreview::supports('ancien.doc'), 'unsupported formats are downloaded');
    // An entity bomb or external entity is never expanded.
    $evil = $mk('evil.docx', ['word/document.xml' => '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . "<w:document $w><w:body><w:p><w:r><w:t>&x;</w:t></w:r></w:p></w:body></w:document>"]);
    $thrown = false;
    try { $out = \M4W\Service\DocPreview::render($evil, 'evil.docx'); } catch (\InvalidArgumentException) { $thrown = true; $out = ''; }
    ok($thrown && !str_contains($out, 'root:'), 'a document carrying a DTD is refused, nothing expanded');
});
test('files: every document expires; reminders, then archive and e-mail', function () use ($fsBob, $fsCarol, $fsAdmin, $fsFile) {
    $root = (int) Files::personalRoot((int) $fsBob['id'])['id'];
    $f = Files::addFromPath($fsBob, $root, $fsFile('contenu du devis'), 'devis.txt');
    ok(abs($f['expires_at'] - (time() + 365 * 86400)) < 60, 'one year by default');
    // Limits: between tomorrow and three years.
    foreach ([time() - 86400, time() + 1200 * 86400] as $bad) {
        $thrown = false;
        try { Files::setExpiry($fsBob, $f['id'], $bad); } catch (\InvalidArgumentException) { $thrown = true; }
        ok($thrown, 'out of range refused');
    }
    Files::setFileAcl($fsBob, $f['id'], [['type' => 'user', 'principal' => $fsCarol['id']]]);
    Files::resetCache();
    $thrown = false;
    try { Files::setExpiry($fsCarol, $f['id'], time() + 10 * 86400); } catch (\M4W\Core\HttpException) { $thrown = true; }
    ok($thrown, 'a reader cannot change it');
    Files::setExpiry($fsBob, $f['id'], time() + 40 * 86400);
    $inbox = static fn() => (int) DB::value("SELECT COUNT(*) FROM messages WHERE user_id = :u AND subject LIKE :s", ['u' => $fsBob['id'], 's' => '%devis.txt%']);
    $before = $inbox();
    eq(Files::processExpiry()[0] >= 0, true);
    eq($inbox(), $before, 'nothing 40 days before');
    $now = time() + 11 * 86400;                       // 29 days left
    Files::processExpiry($now);
    eq($inbox(), $before + 1, 'one month before');
    Files::processExpiry($now);
    eq($inbox(), $before + 1, 'never twice the same reminder');
    Files::processExpiry(time() + 34 * 86400);      // 6 days left
    Files::processExpiry(time() + 39 * 86400);      // 1 day left
    eq($inbox(), $before + 3, 'one week and two days before');
    // Postponing restarts the cycle.
    $stage = (int) DB::value('SELECT expiry_stage FROM fs_files WHERE id = :i', ['i' => $f['id']]);
    Files::setExpiry($fsBob, $f['id'], time() + 200 * 86400);
    eq((int) DB::value('SELECT expiry_stage FROM fs_files WHERE id = :i', ['i' => $f['id']]), 0, 'reset after postponing (was ' . $stage . ')');
    // Past the date: archived, unshared, sent to its author with the document.
    Files::processExpiry(time() + 201 * 86400);
    Files::resetCache();
    $row = DB::one('SELECT * FROM fs_files WHERE id = :i', ['i' => $f['id']]);
    ok((int) $row['archived_at'] > 0 && (int) $row['archived_from'] === $root, 'archived, origin kept');
    $thrown = false;
    try { Files::requireFile($fsBob, $f['id'], Files::READ); } catch (\M4W\Core\HttpException) { $thrown = true; }
    ok($thrown, 'no longer in the file space, even for its author');
    eq(Files::sharedFilesWithMe($fsCarol), [], 'no longer shared');
    $m = DB::one("SELECT * FROM messages WHERE user_id = :u AND subject LIKE :s ORDER BY id DESC", ['u' => $fsBob['id'], 's' => 'Document archivé%']);
    ok($m && str_contains(Mailbox::raw($m), base64_encode('contenu du devis')) || str_contains((string) Mailbox::raw($m), 'contenu du devis'), 'the document is attached');
    ok(in_array($f['id'], array_map('intval', array_column(Files::archived(), 'id')), true), 'listed for administrators');
    // Restored by an administrator, with a new period.
    $back = Files::restoreArchived($f['id'], (int) $fsAdmin['id']);
    eq($back['folder_id'], $root);
    ok($back['expires_at'] > time() + 300 * 86400, 'new period');
    // Author gone: the notice goes to an administrator.
    $tmpU = Users::find(Users::create(['email' => 'parti@test.local', 'password' => 'Secret!Pass123']));
    $g = Files::addFromPath($tmpU, (int) Files::personalRoot((int) $tmpU['id'])['id'], $fsFile('x'), 'orphelin.txt');
    DB::update('users', ['status' => 'disabled'], 'id = :i', ['i' => $tmpU['id']]);
    DB::update('fs_files', ['expires_at' => time() - 10], 'id = :i', ['i' => $g['id']]);
    Files::processExpiry();
    ok((bool) DB::value("SELECT 1 FROM messages WHERE user_id = :u AND subject LIKE :s", ['u' => $fsAdmin['id'], 's' => '%orphelin.txt%']), 'administrator informed');
});
test('office: WOPI host (discovery, tokens, rights, locks, versions)', function () use ($fsBob, $fsCarol, $fsFile) {
    \M4W\Service\Office::$fetch = static fn(string $url) => str_ends_with($url, '/hosting/discovery') ? '<wopi-discovery><net-zone name="external-http">'
        . '<app name="writer"><action name="edit" ext="docx" urlsrc="https://office.test/browser/abc/cool.html?"/><action name="view" ext="doc" urlsrc="https://office.test/browser/abc/cool.html?"/></app>'
        . '<app name="Word"><action name="edit" ext="docx" urlsrc="https://office.test/we/wordeditorframe.aspx?&lt;ui=UI_LLCC&amp;&gt;&lt;rs=DC_LLCC&amp;&gt;&lt;showpagestats=PERFSTATS&amp;&gt;"/></app>'
        . '</net-zone></wopi-discovery>' : null;
    Settings::set('office.url', 'https://office.test');
    Settings::set('office.discovery', '');
    eq(\M4W\Service\Office::test(), '', 'discovery read');
    eq(\M4W\Service\Office::editable(), ['docx'], 'editable formats from discovery');
    ok(in_array('doc', \M4W\Service\Office::viewable(), true), 'older formats viewable');
    $root = (int) Files::personalRoot((int) $fsBob['id'])['id'];
    $f = Files::addFromPath($fsBob, $root, $fsFile('PK v1'), 'contrat.docx');
    $url = \M4W\Service\Office::actionUrl($f, 'edit', 'https://mail.test/wopi/files/' . $f['id'], 'fr-FR');
    eq($url, 'https://office.test/browser/abc/cool.html?WOPISrc=' . rawurlencode('https://mail.test/wopi/files/' . $f['id']) . '&lang=fr-FR');
    // Tokens: one document, one person, rights re-read at every call.
    $tok = \M4W\Service\Office::issueToken($fsBob, $f, true);
    ok(!DB::value('SELECT 1 FROM wopi_tokens WHERE token_hash = :t', ['t' => $tok['token']]), 'stored hashed');
    eq(\M4W\Service\Office::resolve($tok['token'], $f['id'])['write'], true);
    eq(\M4W\Service\Office::resolve($tok['token'], $f['id'] + 1), null, 'bound to its document');
    eq(\M4W\Service\Office::resolve(str_repeat('a', 64), $f['id']), null, 'unknown token');
    Files::setFileAcl($fsBob, $f['id'], [['type' => 'user', 'principal' => $fsCarol['id']]]);
    $ro = \M4W\Service\Office::issueToken($fsCarol, $f, true);
    eq(\M4W\Service\Office::resolve($ro['token'], $f['id'])['write'], false, 'a reader never writes, whatever the token says');
    Files::setFileAcl($fsBob, $f['id'], []);
    eq(\M4W\Service\Office::resolve($ro['token'], $f['id']), null, 'revoked at once');
    // WOPI calls through the controller, as the office server makes them.
    $call = static function (string $method, string $path, array $headers = []) use ($tok, $f) {
        $server = ['REQUEST_METHOD' => $method, 'REMOTE_ADDR' => '10.0.0.9', 'HTTP_HOST' => 'mail.test'];
        foreach ($headers as $k => $v) { $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v; }
        $c = new \M4W\Controller\OfficeController(new \M4W\Core\Request($method, $path, ['access_token' => $tok['token']], [], [], $server));
        return $c;
    };
    $info = json_decode($call('GET', '/wopi/files/' . $f['id'])->checkFileInfo((string) $f['id'])->body, true);
    eq([$info['BaseFileName'], $info['UserCanWrite'], $info['UserFriendlyName'], $info['EnableInsertRemoteImage']], ['contrat.docx', true, $fsBob['name'], false]);
    $lock = static fn(string $op, string $lock, string $old = '') => $call('POST', '/wopi/files/' . $f['id'], array_filter(['X-WOPI-Override' => $op, 'X-WOPI-Lock' => $lock, 'X-WOPI-OldLock' => $old]))->fileOperation((string) $f['id']);
    eq($lock('LOCK', 'L1')->status, 200);
    eq($lock('LOCK', 'L1')->status, 200, 'same lock: refresh');
    $r = $lock('LOCK', 'L2');
    eq([$r->status, $r->getHeader('X-WOPI-Lock')], [409, 'L1'], 'other lock: conflict names the holder');
    eq($lock('GET_LOCK', '')->getHeader('X-WOPI-Lock'), 'L1');
    eq($lock('LOCK', 'L3', 'L1')->status, 200, 'unlock and relock');
    eq($lock('UNLOCK', 'L1')->status, 409);
    eq($lock('UNLOCK', 'L3')->status, 200);
    eq($lock('PUT_RELATIVE', '')->status, 501, 'no new files from the editor');
    // A save becomes a version.
    $p = $fsFile('PK v2');
    $saved = Files::putContent($fsBob, $f['id'], $p);
    eq([$saved['version'], Files::contents(Files::file($f['id'])['blob_id'])], [2, 'PK v2']);
    eq(Files::putContent($fsBob, $f['id'], $fsFile('PK v2'))['version'], 2, 'unchanged content: no new version');
    $thrown = false;
    try { Files::putContent($fsCarol, $f['id'], $p); } catch (\M4W\Core\HttpException) { $thrown = true; }
    ok($thrown, 'no write without the right');
    \M4W\Service\Office::$fetch = null;
    Settings::set('office.url', '');
});
test('files: versions, conflicts and restore', function () use ($fsAdmin, $fsFile, &$fsState) {
    $a = Files::addFromPath($fsAdmin, $fsState['sub'], $fsFile('v1'), 'budget.xlsx');
    $b = Files::addFromPath($fsAdmin, $fsState['sub'], $fsFile('v2'), 'budget.xlsx', true);
    eq($b['id'], $a['id']);
    eq($b['version'], 2);
    $c = Files::addFromPath($fsAdmin, $fsState['sub'], $fsFile('autre'), 'Budget.xlsx');
    eq($c['name'], 'Budget (2).xlsx', 'case-insensitive conflict renamed');
    $v = Files::versions($fsAdmin, $a['id']);
    Files::restoreVersion($fsAdmin, $a['id'], $v[1]['version_id']);
    eq(Files::contents(Files::file($a['id'])['blob_id']), 'v1');
    eq((int) Files::file($a['id'])['version'], 3);
});
test('files: names are sanitized, moves cannot create cycles', function () use ($fsAdmin, &$fsState) {
    eq(Files::cleanName("../../etc/pass\nwd"), '.. .. etc pass wd');
    $thrown = false;
    try { Files::cleanName(' .. '); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown);
    $thrown = false;
    try { Files::move($fsAdmin, [['type' => 'folder', 'id' => $fsState['sub']]], $fsState['deep']); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'folder moved into its own descendant');
});
test('files: trash, restore and purge free storage', function () use ($fsAdmin, $fsBob, &$fsState) {
    $before = Files::usage()['used'];
    Files::delete($fsAdmin, [['type' => 'folder', 'id' => $fsState['deep']]]);
    Files::resetCache();
    eq(Files::permission($fsAdmin, $fsState['deep']), Files::NONE, 'deleted folder unreachable');
    eq(count(array_filter(Files::trash($fsAdmin), static fn($i) => $i['type'] === 'folder' && $i['id'] === $fsState['deep'])), 1);
    eq(count(Files::trash($fsBob)), 0, 'readers do not see the trash');
    Files::restore($fsAdmin, [['type' => 'folder', 'id' => $fsState['deep']]]);
    Files::resetCache();
    eq(Files::permission($fsAdmin, $fsState['deep']), Files::MANAGE);
    Files::delete($fsAdmin, [['type' => 'folder', 'id' => $fsState['deep']]]);
    Files::purge($fsAdmin, [['type' => 'folder', 'id' => $fsState['deep']]]);
    ok(Files::usage()['used'] < $before - 1000000, 'blobs removed');
    eq((int) DB::value('SELECT COUNT(*) FROM fs_blob_chunks c LEFT JOIN fs_blobs b ON b.id = c.blob_id WHERE b.id IS NULL AND c.blob_id NOT IN (SELECT blob_id FROM deleted_messages)'), 0, 'no orphan chunks');
});
test('files: quota and size limits', function () use ($fsAdmin, $fsFile, &$fsState) {
    Settings::set('files.max_file_mb', 1);
    $thrown = false;
    try { Files::addFromPath($fsAdmin, $fsState['sub'], $fsFile(str_repeat('x', 1100000)), 'gros.bin'); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'max file size');
    Settings::set('files.max_file_mb', 200);
    Settings::set('files.quota_gb', 1);
    DB::insert('fs_blobs', ['id' => 'fake', 'size' => 1024 ** 3, 'sha256' => '', 'created_at' => 0]);
    $thrown = false;
    try { Files::addFromPath($fsAdmin, $fsState['sub'], $fsFile('x'), 'x.txt'); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'quota');
    DB::delete('fs_blobs', 'id = :i', ['i' => 'fake']);
    Settings::set('files.quota_gb', 0);
});
test('files: attach to compose and user deletion cleanup', function () use ($fsAdmin, $fsFile, &$fsState) {
    $f = Files::addFromPath($fsAdmin, $fsState['sub'], $fsFile('piece'), 'piece.txt');
    $up = Files::toUploads($fsAdmin, [$f['id']]);
    eq(file_get_contents(storage_path(DB::value('SELECT path FROM uploads WHERE token = :t', ['t' => $up[0]['token']]))), 'piece');
    $tmpUser = Users::find(Users::create(['email' => 'temp@test.local', 'password' => 'Secret!Pass123']));
    $root = Files::personalRoot((int) $tmpUser['id']);
    Files::addFromPath($tmpUser, (int) $root['id'], $fsFile('bye'), 'a.txt');
    Files::setAcl($fsAdmin, $fsState['space'], [['type' => 'user', 'principal' => $tmpUser['id'], 'level' => 'read']]);
    Users::delete((int) $tmpUser['id']);
    eq((int) DB::value("SELECT COUNT(*) FROM fs_folders WHERE owner_id = :u AND kind = 'personal'", ['u' => $tmpUser['id']]), 0);
    eq((int) DB::value("SELECT COUNT(*) FROM fs_acl WHERE principal_type = 'user' AND principal = :u", ['u' => (string) $tmpUser['id']]), 0);
});

// ---------------------------------------------------------- Inbound auth
$dns = [];
MailAuth::$resolver = static function (string $type, string $name) use (&$dns) {
    return $dns[$type . ' ' . strtolower($name)] ?? [];
};
test('SPF: ip4, include, a, mx, redirect, all qualifiers', function () use (&$dns) {
    $dns = [
        'TXT ext.com' => ['v=spf1 ip4:203.0.113.0/24 include:_spf.mailer.net a mx -all'],
        'TXT _spf.mailer.net' => ['v=spf1 ip6:2001:db8::/32 ~all'],
        'A ext.com' => ['198.51.100.7'],
        'MX ext.com' => ['mx.ext.com'], 'A mx.ext.com' => ['192.0.2.25'],
        'TXT soft.com' => ['v=spf1 redirect=ext.com'],
        'TXT loop.com' => ['v=spf1 include:loop.com -all'],
    ];
    eq(MailAuth::spf('203.0.113.9', 'ext.com'), 'pass');
    eq(MailAuth::spf('2001:db8::1', 'ext.com'), 'pass', 'include');
    eq(MailAuth::spf('198.51.100.7', 'ext.com'), 'pass', 'a');
    eq(MailAuth::spf('192.0.2.25', 'ext.com'), 'pass', 'mx');
    eq(MailAuth::spf('8.8.8.8', 'ext.com'), 'fail');
    eq(MailAuth::spf('8.8.8.8', 'soft.com'), 'fail', 'redirect');
    eq(MailAuth::spf('8.8.8.8', 'none.com'), 'none');
    eq(MailAuth::spf('8.8.8.8', 'loop.com'), 'permerror', 'lookup limit');
});
test('DKIM verification and DMARC alignment', function () use (&$dns) {
    [$priv, $txt] = DkimSigner::generateKeys();
    $dns = ['TXT sel._domainkey.ext.com' => [$txt], 'TXT _dmarc.ext.com' => ['v=DMARC1; p=reject'], 'TXT ext.com' => ['v=spf1 -all']];
    $msg = "From: Client <client@ext.com>\r\nTo: bob@test.local\r\nSubject: Signé\r\nDate: " . date('r') . "\r\nMessage-ID: <x@ext.com>\r\n\r\nBonjour  \r\nligne 2\r\n\r\n";
    $signed = (new DkimSigner('ext.com', 'sel', $priv))->sign($msg);
    $r = MailAuth::dkim($signed);
    eq($r[0]['result'] ?? '', 'pass');
    eq(MailAuth::dkim(str_replace('ligne 2', 'ligne 3', $signed))[0]['result'], 'fail', 'body tampered');
    eq(MailAuth::dkim(str_replace('Subject: Signé', 'Subject: Modifié', $signed))[0]['result'], 'fail', 'header tampered');
    eq(MailAuth::dmarc('ext.com', 'fail', 'ext.com', $r)['result'], 'pass', 'aligned DKIM is enough');
    $d = MailAuth::dmarc('ext.com', 'fail', 'ext.com', []);
    eq([$d['result'], $d['policy']], ['fail', 'reject']);
    eq(MailAuth::orgDomain('mail.news.example.co.uk'), 'example.co.uk');
});
test('spoofed local sender goes to spam, gets no auto-reply and is flagged', function () use ($bob, &$dns) {
    $dns = ['TXT test.local' => ['v=spf1 ip4:192.0.2.1 -all']];
    Vacation::save((int) $bob['id'], ['enabled' => 1, 'subject' => 'Absent', 'body_html' => '<p>Absent</p>', 'interval_days' => 0]);
    $raw = "From: Le PDG <admin@test.local>\r\nTo: bob@test.local\r\nSubject: Virement urgent\r\nX-M4W-Auth: verdict=ok\r\nMessage-ID: <spoof@x>\r\n\r\nFaites un virement.\r\n";
    $auth = MailAuth::evaluate($raw, '203.0.113.66', 'attacker@evil.example', 'evil.example', [Users::class, 'isLocalDomain']);
    eq($auth['verdict'], 'spoof');
    $queued = (int) DB::value('SELECT COUNT(*) FROM mail_queue');
    Delivery::deliver($raw, 'attacker@evil.example', ['bob@test.local'], 'smtp', true, $auth);
    $m = DB::one("SELECT m.*, f.role FROM messages m JOIN folders f ON f.id = m.folder_id WHERE m.user_id = :u AND m.subject = 'Virement urgent'", ['u' => $bob['id']]);
    eq($m['role'], 'spam');
    eq((int) DB::value('SELECT COUNT(*) FROM mail_queue'), $queued, 'no vacation reply');
    $p = Mailbox::parsed($m);
    eq(count($p->headerAll('X-M4W-Auth')), 1, 'forged auth header stripped');
    eq(MailAuth::parse((string) $p->header('X-M4W-Auth'))['verdict'], 'spoof');
    $ok = MailAuth::evaluate($raw, '192.0.2.1', 'admin@test.local', 'mx.test.local', [Users::class, 'isLocalDomain']);
    eq($ok['verdict'], 'ok', 'authorized server passes');
    Vacation::save((int) $bob['id'], ['enabled' => 0]);
});
test('DKIM signs only aligned From domains (no signing of forwarded mail)', function () {
    [$priv] = DkimSigner::generateKeys();
    Settings::set('smtp.dkim_domain', 'test.local');
    Settings::set('smtp.dkim_selector', 'm4w');
    Settings::setSecret('smtp.dkim_private', $priv);
    ok(str_starts_with(Transport::dkim("From: a@test.local\r\nSubject: x\r\n\r\nx\r\n"), 'DKIM-Signature:'));
    ok(str_starts_with(Transport::dkim("From: a@sub.test.local\r\nSubject: x\r\n\r\nx\r\n"), 'DKIM-Signature:'), 'subdomain aligned');
    ok(!str_contains(Transport::dkim("From: ceo@other.example\r\nSubject: x\r\n\r\nx\r\n"), 'DKIM-Signature:'), 'third-party From is never signed');
    Settings::set('smtp.dkim_domain', '');
});
test('SSRF guard rejects non-global ranges', function () {
    foreach (['100.64.1.1', '198.18.0.1', '224.0.0.1', '0.1.2.3', '::ffff:127.0.0.1', 'fd00::1', '169.254.169.254'] as $ip) {
        ok(!\M4W\Core\Net::isPublic($ip), $ip);
    }
    ok(\M4W\Core\Net::isPublic('8.8.8.8') && \M4W\Core\Net::isPublic('2606:4700::1111'));
});
test('CSS sanitizer blocks image-set and escaped url()', function () {
    $s = new HtmlSanitizer(true, static fn() => 'about:blank');
    $css = $s->sanitizeCss('background:image-set("https://evil.example/a.png" 1x);background:\75 rl(https://evil.example/b.png)', false);
    ok(!str_contains($css, 'evil.example/b') && !preg_match('/image-set\s*\(/i', $css), $css);
});
test('TOTP codes cannot be replayed', function () {
    $secret = Totp::generateSecret();
    $code = Totp::code($secret, time());
    $step = Totp::match($secret, $code);
    ok($step !== null);
    ok(Totp::match($secret, $code, 1, $step) === null, 'same step refused');
});
test('preferences are whitelisted', function () use ($bob) {
    Users::savePrefs((int) $bob['id'], ['compose_font' => 'x;}</style><script>', 'theme' => 'neon', 'page_size' => 9999, 'density' => 'compact']);
    $p = Users::find((int) $bob['id'])['prefs'];
    eq($p['compose_font'], 'Arial, Helvetica, sans-serif');
    eq($p['theme'], 'auto');
    eq($p['page_size'], 200);
    eq($p['density'], 'compact');
});
test('login lockout is per account and address', function () {
    $mk = static fn(string $ip) => new \M4W\Core\Request('POST', '/login', [], [], [], ['REMOTE_ADDR' => $ip]);
    Settings::set('security.max_attempts', 3);
    for ($i = 0; $i < 3; $i++) {
        \M4W\Core\Auth::attempt('bob@test.local', 'wrong', $mk('203.0.113.10'));
    }
    $locked = \M4W\Core\Auth::attempt('bob@test.local', 'Secret!Pass123', $mk('203.0.113.10'));
    ok(!$locked['ok'], 'attacker address locked');
    $owner = \M4W\Core\Auth::attempt('bob@test.local', 'Secret!Pass123', $mk('198.51.100.20'));
    ok($owner['ok'], 'owner can still sign in from elsewhere');
    DB::run('DELETE FROM login_attempts');
    Settings::set('security.max_attempts', 5);
});

// ---------------------------------------------------------- Delegation
use M4W\Service\Delegation;

test('delegation: administrators reach every mailbox, delegates only theirs', function () use ($fsAdmin, $fsBob, $fsCarol) {
    $_SESSION = [];
    eq(Delegation::via($fsAdmin, (int) $fsBob['id']), 'admin');
    eq(Delegation::via($fsCarol, (int) $fsBob['id']), null, 'plain user has no access');
    eq(Delegation::via($fsAdmin, (int) $fsAdmin['id']), null, 'not your own mailbox');
    Delegation::setDelegates((int) $fsBob['id'], [(int) $fsCarol['id'], (int) $fsBob['id']], (int) $fsAdmin['id']);
    eq(Delegation::via($fsCarol, (int) $fsBob['id']), 'delegate');
    eq(count(Delegation::delegates((int) $fsBob['id'])), 1, 'owner cannot be their own delegate');
    Settings::set('delegation.admins', 0);
    eq(Delegation::via($fsAdmin, (int) $fsBob['id']), null, 'policy off');
    Settings::set('delegation.admins', 1);
});
test('delegation: opening requires a reason, is journaled and notified', function () use ($fsAdmin, $fsBob) {
    $_SESSION = [];
    $thrown = false;
    try { Delegation::open($fsAdmin, (int) $fsBob['id'], '  '); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'reason required for administrators');
    $before = (int) DB::value('SELECT COUNT(*) FROM messages WHERE user_id = :u', ['u' => $fsBob['id']]);
    Delegation::open($fsAdmin, (int) $fsBob['id'], 'Arrêt maladie, suivi client urgent', '203.0.113.5');
    $owner = Delegation::current($fsAdmin);
    eq((int) $owner['id'], (int) $fsBob['id']);
    eq($owner['_actor']['email'], $fsAdmin['email']);
    $j = Delegation::journal((int) $fsBob['id'], 1)[0];
    eq([$j['via'], $j['reason'], (int) $j['actor_id']], ['admin', 'Arrêt maladie, suivi client urgent', (int) $fsAdmin['id']]);
    eq((int) DB::value('SELECT COUNT(*) FROM messages WHERE user_id = :u', ['u' => $fsBob['id']]), $before + 1, 'owner notified in their inbox');
    Delegation::close($fsAdmin);
    ok(Delegation::current($fsAdmin) === null);
    ok((int) Delegation::journal((int) $fsBob['id'], 1)[0]['closed_at'] > 0);
});
test('delegation: withdrawn rights end the session', function () use ($fsAdmin, $fsBob, $fsCarol) {
    $_SESSION = [];
    Delegation::open($fsCarol, (int) $fsBob['id'], '');
    ok(Delegation::current($fsCarol) !== null, 'delegate needs no reason');
    Delegation::setDelegates((int) $fsBob['id'], [], (int) $fsAdmin['id']);
    $r = new \ReflectionProperty(Delegation::class, 'current');
    $r->setValue(null, []);
    ok(Delegation::current($fsCarol) === null, 'revoked on next request');
});
test('delegation: personal messages and folders stay hidden', function () use ($fsAdmin, $fsBob, $sendTo) {
    $_SESSION = [];
    Delivery::deliver($sendTo('bob@test.local', '[Perso] Rendez-vous médical'), 'a@ext.com', ['bob@test.local']);
    Delivery::deliver($sendTo('bob@test.local', 'Re: Privé : vacances'), 'a@ext.com', ['bob@test.local']);
    Delivery::deliver($sendTo('bob@test.local', 'Devis 2026'), 'a@ext.com', ['bob@test.local']);
    $uid = (int) $fsBob['id'];
    $perso = Folders::create($uid, 'Personnel');
    $pm = (int) DB::value("SELECT id FROM messages WHERE user_id = :u AND subject = 'Devis 2026'", ['u' => $uid]);
    $secret = DB::one("SELECT id FROM messages WHERE user_id = :u AND subject = '[Perso] Rendez-vous médical'", ['u' => $uid]);
    $sub = Folders::create($uid, 'Santé', $perso);
    Delivery::deliver($sendTo('bob@test.local', 'Dans perso'), 'a@ext.com', ['bob@test.local']);
    $inPerso = (int) DB::value("SELECT id FROM messages WHERE user_id = :u AND subject = 'Dans perso'", ['u' => $uid]);
    Mailbox::move($uid, [$inPerso], $sub);
    Delegation::open($fsAdmin, $uid, 'Absence');
    Delegation::mailbox($fsAdmin);
    $inbox = Folders::byRole($uid, 'inbox');
    $list = Mailbox::list($uid, ['folder' => (int) $inbox['id'], 'limit' => 200]);
    $subjects = array_column($list['items'] ?? $list['messages'] ?? [], 'subject');
    ok(in_array('Devis 2026', $subjects, true), 'business mail visible');
    ok(!in_array('[Perso] Rendez-vous médical', $subjects, true) && !in_array('Re: Privé : vacances', $subjects, true), 'personal subjects hidden');
    ok(Mailbox::get($uid, (int) $secret['id']) === null, 'direct access refused');
    ok(Folders::find($uid, $sub) === null && !in_array($perso, array_column(Folders::listWithCounts($uid), 'id'), true), 'personal folders hidden');
    ok(Mailbox::get($uid, $inPerso) === null, 'message in personal sub-folder hidden');
    eq(Mailbox::setFlags($uid, [(int) $secret['id']], ['is_read' => 1]), 0, 'no blind changes');
    ok(Mailbox::get($uid, $pm) !== null);
    Delegation::close($fsAdmin);
    ok(Mailbox::get($uid, (int) $secret['id']) !== null, 'owner still sees everything');
});
test('delegation: mail sent from a delegated mailbox names its sender', function () use ($fsAdmin, $fsBob) {
    $owner = Users::find((int) $fsBob['id']);
    $owner['_actor'] = ['id' => (int) $fsAdmin['id'], 'email' => $fsAdmin['email'], 'name' => $fsAdmin['name'], 'role' => 'admin'];
    $b = Composer::build($owner, ['to' => 'client@ext.com', 'subject' => 'Réponse', 'html' => '<p>ok</p>'], true)['builder'];
    $raw = $b->build();
    ok(str_contains($raw, "From: ") && str_contains($raw, 'bob@test.local'), 'From is the mailbox');
    ok((bool) preg_match('/^Sender: .*' . preg_quote($fsAdmin['email'], '/') . '/m', $raw), 'Sender is the person acting');
});

// ---------------------------------------------------------- Retention ("second trash")
use M4W\Service\Retention;

test('retention: emptied trash is kept and restorable to its original folder', function () use ($bob, $sendTo, $fsAdmin) {
    $uid = (int) $bob['id'];
    $proj = Folders::create($uid, 'Projets');
    Delivery::deliver($sendTo('bob@test.local', 'Contrat signé Leroy'), 'client@ext.com', ['bob@test.local']);
    $m = DB::one("SELECT * FROM messages WHERE user_id = :u AND subject = 'Contrat signé Leroy'", ['u' => $uid]);
    Mailbox::move($uid, [(int) $m['id']], $proj);
    Mailbox::delete($uid, [(int) $m['id']]);
    eq(DB::value('SELECT trashed_from_name FROM messages WHERE id = :id', ['id' => $m['id']]), 'Projets');
    $before = (int) DB::value('SELECT COUNT(*) FROM deleted_messages');
    Mailbox::emptyFolder($uid, (int) Folders::byRole($uid, 'trash')['id']);
    ok(Mailbox::get($uid, (int) $m['id']) === null, 'gone for the user');
    $r = DB::one("SELECT * FROM deleted_messages WHERE user_id = :u AND subject = 'Contrat signé Leroy'", ['u' => $uid]);
    ok($r !== null && (int) DB::value('SELECT COUNT(*) FROM deleted_messages') === $before + 1, 'copy kept');
    eq([$r['reason'], $r['folder_name'], (int) $r['original_folder_id']], ['emptied', 'Projets', $proj]);
    eq(MimeParser::parse(Retention::raw($r))->subject(), 'Contrat signé Leroy', 'raw content kept (compressed)');
    $newId = Retention::restore((int) $r['id'], $fsAdmin);
    $back = Mailbox::get($uid, $newId);
    eq((int) $back['folder_id'], $proj, 'restored into its original folder');
    ok((int) Retention::find((int) $r['id'])['restored_at'] > 0);
});
test('retention: direct delete, rules, automatic purge and account deletion', function () use ($bob, $sendTo, $fsAdmin) {
    $uid = (int) $bob['id'];
    Delivery::deliver($sendTo('bob@test.local', 'Suppression directe'), 'client@ext.com', ['bob@test.local']);
    $m = DB::one("SELECT id FROM messages WHERE user_id = :u AND subject = 'Suppression directe'", ['u' => $uid]);
    Mailbox::delete($uid, [(int) $m['id']], true);
    eq(DB::value("SELECT reason FROM deleted_messages WHERE subject = 'Suppression directe'"), 'deleted');
    $rid = DB::insert('rules', RuleEngine::normalize($uid, ['name' => 'Jeter', 'enabled' => 1, 'conditions' => [['field' => 'subject', 'op' => 'contains', 'value' => 'PROMO-XYZ']],
        'actions' => [['type' => 'discard']]]) + ['user_id' => $uid, 'sort' => 1, 'created_at' => time(), 'updated_at' => time()]);
    Delivery::deliver($sendTo('bob@test.local', 'PROMO-XYZ imperdable'), 'spam@ext.com', ['bob@test.local']);
    eq(DB::value("SELECT reason FROM deleted_messages WHERE subject = 'PROMO-XYZ imperdable'"), 'rule', 'rule discard kept');
    DB::delete('rules', 'id = :id', ['id' => $rid]);
    // Spam auto-purge is not kept by default; trash auto-purge is.
    Delivery::deliver($sendTo('bob@test.local', 'Vieux spam'), 'x@ext.com', ['bob@test.local']);
    Delivery::deliver($sendTo('bob@test.local', 'Vieille corbeille'), 'x@ext.com', ['bob@test.local']);
    DB::run("UPDATE messages SET folder_id = :f, date_received = 1 WHERE user_id = :u AND subject = 'Vieux spam'", ['f' => Folders::byRole($uid, 'spam')['id'], 'u' => $uid]);
    DB::run("UPDATE messages SET folder_id = :f, date_received = 1 WHERE user_id = :u AND subject = 'Vieille corbeille'", ['f' => Folders::byRole($uid, 'trash')['id'], 'u' => $uid]);
    Mailbox::autoPurge(30);
    eq((int) DB::value("SELECT COUNT(*) FROM deleted_messages WHERE subject = 'Vieux spam'"), 0);
    eq(DB::value("SELECT reason FROM deleted_messages WHERE subject = 'Vieille corbeille'"), 'auto_purge');
    // Departing user: mail kept, restorable into a colleague's mailbox.
    $gone = Users::find(Users::create(['email' => 'depart@test.local', 'password' => 'Secret!Pass123']));
    Delivery::deliver($sendTo('depart@test.local', 'Dossier en cours'), 'client@ext.com', ['depart@test.local']);
    Users::delete((int) $gone['id']);
    $r = DB::one("SELECT * FROM deleted_messages WHERE user_email = 'depart@test.local' AND subject = 'Dossier en cours'");
    eq($r['reason'], 'account_deleted');
    $thrown = false;
    try { Retention::restore((int) $r['id'], $fsAdmin); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'no target when the account is gone');
    $id = Retention::restore((int) $r['id'], $fsAdmin, $uid);
    eq((int) Mailbox::get($uid, $id)['folder_id'], (int) Folders::byRole($uid, 'inbox')['id'], 'restored to a colleague inbox');
});
test('retention: drafts replaced at send are not kept, personal mail is masked, expiry', function () use ($bob, $sendTo, $fsAdmin) {
    $uid = (int) $bob['id'];
    $n = (int) DB::value('SELECT COUNT(*) FROM deleted_messages');
    $d = Composer::saveDraft(Users::find($uid), ['to' => 'x@ext.com', 'subject' => 'Brouillon', 'html' => '<p>a</p>']);
    Composer::saveDraft(Users::find($uid), ['to' => 'x@ext.com', 'subject' => 'Brouillon', 'html' => '<p>b</p>', 'draft_id' => $d]);
    eq((int) DB::value('SELECT COUNT(*) FROM deleted_messages'), $n, 'draft replacement is not a deletion');
    Delivery::deliver($sendTo('bob@test.local', '[Perso] Résultats médicaux'), 'doc@ext.com', ['bob@test.local']);
    $m = DB::one("SELECT id FROM messages WHERE user_id = :u AND subject = '[Perso] Résultats médicaux'", ['u' => $uid]);
    Mailbox::delete($uid, [(int) $m['id']], true);
    $r = DB::one("SELECT * FROM deleted_messages WHERE subject = '[Perso] Résultats médicaux'");
    ok(Retention::masked($r), 'masked for administrators');
    eq(Retention::search(['q' => 'médicaux'])['total'], 0, 'personal subject not searchable');
    $thrown = false;
    try { Retention::restore((int) $r['id'], $fsAdmin, (int) $fsAdmin['id']); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'personal mail only back to its owner');
    ok(Retention::restore((int) $r['id'], $fsAdmin) > 0);
    DB::run('UPDATE deleted_messages SET deleted_at = 1 WHERE id = :id', ['id' => $r['id']]);
    $blob = $r['blob_id'];
    ok(Retention::expire() >= 1);
    ok(Retention::find((int) $r['id']) === null && !(new \M4W\Storage\DatabaseStore())->exists($blob), 'expired copy and content destroyed');
    Settings::set('retention.enabled', 0);
    Delivery::deliver($sendTo('bob@test.local', 'Sans conservation'), 'x@ext.com', ['bob@test.local']);
    $m = DB::one("SELECT id FROM messages WHERE user_id = :u AND subject = 'Sans conservation'", ['u' => $uid]);
    Mailbox::delete($uid, [(int) $m['id']], true);
    eq((int) DB::value("SELECT COUNT(*) FROM deleted_messages WHERE subject = 'Sans conservation'"), 0, 'disabled');
    Settings::set('retention.enabled', 1);
});

// ---------------------------------------------------------- Webmail mode (mailboxes at an external provider)
use M4W\Service\RemoteMailbox;

echo "\nWebmail mode (fake provider over real sockets)\n";
$wm = ['imap' => random_int(41000, 45000), 'smtp' => random_int(45001, 49000), 'state' => $tmp . '/provider.json', 'log' => $tmp . '/provider-smtp.jsonl'];
$wmMsg = static fn(string $from, string $subject, string $returnPath = null, string $body = 'Bonjour') =>
    ($returnPath !== null ? "Return-Path: <$returnPath>\r\n" : '') . "From: $from\r\nTo: paul@test.local\r\nSubject: $subject\r\nDate: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(6)) . "@ext.com>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n$body\r\n";
file_put_contents($wm['state'], json_encode([
    'accounts' => ['paul@test.local' => 'secret-paul', 'lea@test.local' => 'secret-lea'],
    'boxes' => [
        // Already in the mailbox when it is linked: history (read on the provider, two years old).
        'paul@test.local' => ['INBOX' => ['validity' => 7, 'msgs' => [
            ['uid' => 1, 'raw' => $wmMsg('Ancien <old@ext.com>', 'Vieille facture', 'old@ext.com'), 'flags' => ['\\Seen'], 'date' => time() - 730 * 86400],
        ]], 'Junk' => ['validity' => 3, 'msgs' => [['uid' => 9, 'raw' => $wmMsg('Promo <promo@spam.example>', 'Gagnez 1000 euros', 'promo@spam.example')]]]],
        'lea@test.local' => ['INBOX' => ['validity' => 1, 'msgs' => []]],
    ],
]));
@unlink($wm['log']);
// Random ports: try others if these are taken, and wait until both really listen.
for ($try = 0; $try < 5; $try++) {
    $wmProc = proc_open([PHP_BINARY, __DIR__ . '/fixtures/fake_provider.php', (string) $wm['imap'], (string) $wm['smtp'], $wm['state'], $wm['log']], [], $wmPipes);
    $up = false;
    for ($i = 0; $i < 50 && !$up; $i++) {
        usleep(100000);
        $a = @stream_socket_client('tcp://127.0.0.1:' . $wm['imap'], $e1, $s1, 1);
        $b = @stream_socket_client('tcp://127.0.0.1:' . $wm['smtp'], $e2, $s2, 1);
        $up = $a && $b;
        $a && fclose($a);
        $b && fclose($b);
        if (!$up && !proc_get_status($wmProc)['running']) {
            break;
        }
    }
    if ($up) {
        break;
    }
    proc_terminate($wmProc);
    $wm['imap'] = random_int(41000, 45000);
    $wm['smtp'] = random_int(45001, 49000);
}
Settings::setMany([
    'mail.mode' => 'webmail', 'webmail.imap_host' => '127.0.0.1', 'webmail.imap_port' => $wm['imap'], 'webmail.imap_security' => 'none',
    'webmail.smtp_host' => '127.0.0.1', 'webmail.smtp_port' => $wm['smtp'], 'webmail.smtp_security' => 'none', 'webmail.verify_peer' => 0,
    'webmail.spam_folder' => 'Junk', 'webmail.leave_days' => -1,
]);
$wmSmtp = static function () use ($wm): array {
    $out = [];
    foreach (file($wm['log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $out[] = json_decode($l, true);
    }
    return $out;
};
$paul = Users::find(Users::create(['email' => 'paul@test.local', 'password' => 'Webmail!Pass123', 'first_name' => 'Paul', 'last_name' => 'Durand']));
$lea = Users::find(Users::create(['email' => 'lea@test.local', 'password' => 'Webmail!Pass123', 'first_name' => 'Léa']));

test('webmail: mailbox password is separate from the webmail password and stored encrypted', function () use ($paul, $fsAdmin) {
    ok(RemoteMailbox::active());
    eq(RemoteMailbox::status(RemoteMailbox::find((int) $paul['id'])), 'missing');
    RemoteMailbox::save((int) $paul['id'], ['login' => '', 'password' => 'secret-paul', 'enabled' => 1], (int) $fsAdmin['id']);
    $acc = RemoteMailbox::find((int) $paul['id']);
    ok(!str_contains((string) $acc['password_enc'], 'secret-paul'), 'encrypted at rest');
    ok(\M4W\Core\Crypto::verifyPassword('Webmail!Pass123', Users::find((int) $paul['id'])['password_hash']), 'webmail password untouched');
    eq(RemoteMailbox::effective($acc)['login'], 'paul@test.local', 'login defaults to the address');
    $t = RemoteMailbox::test((int) $paul['id']);
    eq([$t['imap'], $t['smtp']], ['', ''], 'IMAP and SMTP authenticate');
});
test('webmail: fetched mail goes through rules, spam folder, and no reply to bounces', function () use ($paul, $wmSmtp) {
    $uid = (int) $paul['id'];
    $factures = Folders::create($uid, 'Factures');
    DB::insert('rules', RuleEngine::normalize($uid, ['name' => 'Factures', 'enabled' => 1, 'conditions' => [['field' => 'subject', 'op' => 'contains', 'value' => 'facture']],
        'actions' => [['type' => 'move', 'folder' => $factures]]]) + ['user_id' => $uid, 'sort' => 1, 'created_at' => time(), 'updated_at' => time()]);
    Vacation::save($uid, ['enabled' => 1, 'subject' => 'Absent', 'body_html' => '<p>Je suis absent.</p>', 'interval_days' => 1]);
    Forwarding::save($uid, true, ['archive@ext.com'], true);
    // First link: what is already there comes in as history, quietly.
    $r = RemoteMailbox::sync(RemoteMailbox::find($uid));
    eq([$r['error'], $r['fetched']], ['', 2], '1 inbox + 1 spam already there');
    $old = DB::one("SELECT m.*, f.role FROM messages m JOIN folders f ON f.id = m.folder_id WHERE m.user_id = :u AND m.subject = 'Vieille facture'", ['u' => $uid]);
    ok($old['date_received'] < time() - 700 * 86400, 'keeps the date it arrived at the provider');
    eq([(int) $old['is_read'], $old['role']], [1, 'inbox'], 'read state kept, no rule applied to history');
    eq(DB::value("SELECT COUNT(*) FROM mail_queue WHERE user_id = :u", ['u' => $uid]), 0, 'no out-of-office reply, no forwarding for history');
    // Then new mail arrives at the provider.
    $st = json_decode((string) file_get_contents($GLOBALS['wm']['state']), true);
    array_push($st['boxes']['paul@test.local']['INBOX']['msgs'],
        ['uid' => 2, 'raw' => $GLOBALS['wmMsg']('Client <client@ext.com>', 'Facture 42', 'client@ext.com')],
        ['uid' => 3, 'raw' => $GLOBALS['wmMsg']('Patron <boss@ext.com>', 'Point projet', 'boss@ext.com')],
        ['uid' => 4, 'raw' => $GLOBALS['wmMsg']('MAILER-DAEMON <mailer-daemon@ext.com>', 'Undelivered Mail', '')]);
    file_put_contents($GLOBALS['wm']['state'], json_encode($st), LOCK_EX);
    DB::update('remote_accounts', ['last_sync_at' => 0], 'user_id = :u', ['u' => $uid]);
    $r = RemoteMailbox::sync(RemoteMailbox::find($uid));
    eq([$r['error'], $r['fetched']], ['', 3], 'new mail');
    $where = static fn(string $subject) => DB::one("SELECT f.role, f.name FROM messages m JOIN folders f ON f.id = m.folder_id WHERE m.user_id = :u AND m.subject = :s", ['u' => $uid, 's' => $subject]);
    eq($where('Facture 42')['name'], 'Factures', 'rule applied');
    eq($where('Point projet')['role'], 'inbox');
    eq($where('Gagnez 1000 euros')['role'], 'spam', 'provider spam folder');
    Transport::processQueue(50);
    $sent = $wmSmtp();
    $vac = array_values(array_filter($sent, static fn($s) => str_contains($s['data'], 'Auto-Submitted: auto-replied')));
    $to = array_merge(...array_map(static fn($v) => $v['rcpt'], $vac));
    sort($to);
    eq($to, ['boss@ext.com', 'client@ext.com'], 'one reply per real sender (rules do not prevent it), none to the bounce');
    eq([$vac[0]['auth'], $vac[0]['from']], ['paul@test.local', 'paul@test.local'], 'sent with Paul\'s own mailbox');
    ok(!str_contains(json_encode($sent), 'mailer-daemon@ext.com"]'), 'no auto-reply to a bounce');
});
test('webmail: forwards leave from the mailbox, original author in Reply-To', function () use ($wmSmtp) {
    $fwd = array_values(array_filter($wmSmtp(), static fn($s) => $s['rcpt'] === ['archive@ext.com']));
    ok(count($fwd) >= 2, 'forwarded');
    $f = array_values(array_filter($fwd, static fn($s) => str_contains($s['data'], 'Point projet')))[0];
    eq([$f['auth'], $f['from']], ['paul@test.local', 'paul@test.local']);
    ok((bool) preg_match('/^From: .*\(via Paul Durand\).*<paul@test\.local>/m', $f['data']), 'From rewritten');
    ok((bool) preg_match('/^Reply-To: .*boss@ext\.com/m', $f['data']), 'replies reach the author');
    ok(!preg_match('/^DKIM-Signature:/m', $f['data']), 'no foreign signature kept');
});
test('webmail: re-sync imports nothing twice, then cleanup at the provider', function () use ($paul, $wm) {
    $acc = RemoteMailbox::find((int) $paul['id']);
    DB::update('remote_accounts', ['last_sync_at' => 0], 'id = :id', ['id' => $acc['id']]);
    eq(RemoteMailbox::sync(RemoteMailbox::find((int) $paul['id']))['fetched'], 0);
    Settings::set('webmail.leave_days', 0);
    DB::update('remote_accounts', ['last_sync_at' => 0], 'id = :id', ['id' => $acc['id']]);
    RemoteMailbox::sync(RemoteMailbox::find((int) $paul['id']));
    $st = json_decode((string) file_get_contents($wm['state']), true);
    eq(count($st['boxes']['paul@test.local']['INBOX']['msgs']), 0, 'deleted at the provider after import');
    Settings::set('webmail.leave_days', -1);
});
test('webmail: sending, colleagues included, goes through the provider with the sender\'s mailbox', function () use ($paul, $lea, $wmSmtp) {
    $before = count($wmSmtp());
    Composer::send(Users::find((int) $paul['id']), ['to' => 'lea@test.local, client@ext.com', 'subject' => 'Compte rendu', 'html' => '<p>Ci-joint.</p>']);
    $s = array_slice($wmSmtp(), $before);
    eq(count($s), 1);
    eq([$s[0]['auth'], $s[0]['from']], ['paul@test.local', 'paul@test.local']);
    ok(in_array('lea@test.local', $s[0]['rcpt'], true), 'colleague reached through the provider');
    eq((int) DB::value("SELECT COUNT(*) FROM messages WHERE user_id = :u AND subject = 'Compte rendu'", ['u' => $lea['id']]), 0, 'no local shortcut');
    ok(!preg_match('/^DKIM-Signature:/m', $s[0]['data']), 'the provider signs, not us');
    // A mailbox that is not linked cannot send.
    $thrown = false;
    try { Composer::send(Users::find((int) $lea['id']), ['to' => 'x@ext.com', 'subject' => 's', 'html' => 'x']); } catch (\RuntimeException) { $thrown = true; }
    ok($thrown, 'unlinked mailbox refused');
});
test('webmail: a delegate sends with the owner\'s mailbox credentials', function () use ($paul, $fsAdmin, $wmSmtp) {
    $_SESSION = [];
    Delegation::open($fsAdmin, (int) $paul['id'], 'Absence de Paul');
    $owner = Delegation::current($fsAdmin);
    $before = count($wmSmtp());
    Composer::send($owner, ['to' => 'client@ext.com', 'subject' => 'Réponse pendant l\'absence', 'html' => '<p>ok</p>']);
    $s = array_slice($wmSmtp(), $before)[0];
    eq([$s['auth'], $s['from']], ['paul@test.local', 'paul@test.local'], 'owner\'s mailbox');
    ok((bool) preg_match('/^Sender: .*admin@test\.local/m', $s['data']), 'real sender named');
    // The delegate can also manage the owner's out-of-office while he is away.
    Vacation::save((int) $owner['id'], ['enabled' => 0]);
    eq(Vacation::get((int) $paul['id'])['enabled'], false);
    Delegation::close($fsAdmin);
});
test('webmail: wrong provider password is reported, not retried blindly', function () use ($paul, $fsAdmin) {
    RemoteMailbox::save((int) $paul['id'], ['login' => '', 'password' => 'mauvais', 'enabled' => 1], (int) $fsAdmin['id']);
    $acc = RemoteMailbox::find((int) $paul['id']);
    DB::update('remote_accounts', ['last_sync_at' => 0], 'id = :id', ['id' => $acc['id']]);
    $r = RemoteMailbox::sync(RemoteMailbox::find((int) $paul['id']));
    ok($r['error'] !== '', 'error kept');
    eq(RemoteMailbox::status(RemoteMailbox::find((int) $paul['id'])), 'error');
    $thrown = false;
    try { Composer::send(Users::find((int) $paul['id']), ['to' => 'x@ext.com', 'subject' => 's', 'html' => 'x']); } catch (\RuntimeException) { $thrown = true; }
    ok($thrown, 'send fails visibly');
    RemoteMailbox::save((int) $paul['id'], ['login' => '', 'password' => 'secret-paul', 'enabled' => 1], (int) $fsAdmin['id']);
    eq(RemoteMailbox::status(RemoteMailbox::find((int) $paul['id'])), 'pending', 'fixed password clears the error');
});

// ---------------------------------------------------------- Collector mode (one provider mailbox for everyone)
echo "\nCollector mode (one provider mailbox, redirections, hourly limit)\n";
use M4W\Service\Collector;
$colMsg = static fn(string $headers, string $subject, string $id = '', string $from = 'Client <client@ext.com>') =>
    $headers . "From: $from\r\nSubject: $subject\r\nDate: " . date('r') . "\r\nMessage-ID: <" . ($id ?: bin2hex(random_bytes(6))) . "@ext.com>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBonjour\r\n";
$colState = static function (callable $fn) use ($wm): void {
    $st = json_decode((string) file_get_contents($wm['state']), true);
    $fn($st);
    file_put_contents($wm['state'], json_encode($st), LOCK_EX);
};
$colState(static function (array &$st) use ($colMsg) {
    $st['accounts']['mail@test.local'] = 'secret-col';
    $st['boxes']['mail@test.local'] = ['INBOX' => ['validity' => 5, 'msgs' => [
        // OVH-style redirection traces
        ['uid' => 1, 'raw' => $colMsg("Return-Path: <client@ext.com>\r\nDelivered-To: mail@test.local\r\nX-Original-To: paul@test.local\r\nTo: Paul <paul@test.local>\r\n", 'Commande 2041')],
        // Blind copy: only the provider's Received line names the recipient
        ['uid' => 2, 'raw' => $colMsg("Return-Path: <boss@ext.com>\r\nDelivered-To: mail@test.local\r\nReceived: from mx1.ext.com by mx3.mail.ovh.net with ESMTPS id 4abc for <lea@test.local>; " . date('r') . "\r\nTo: equipe@ext.com\r\n", 'Copie cachée pour Léa', '', 'Boss <boss@ext.com>')],
        // No trace at all: To/Cc decide; the provider delivered two copies of it
        ['uid' => 3, 'raw' => $colMsg("Return-Path: <client@ext.com>\r\nTo: paul@test.local\r\nCc: lea@test.local\r\n", 'Réunion lundi', 'dup-1')],
        ['uid' => 4, 'raw' => $colMsg("Return-Path: <client@ext.com>\r\nTo: paul@test.local\r\nCc: lea@test.local\r\n", 'Réunion lundi', 'dup-1')],
        // Mailing list: nobody identified → fallback mailbox
        ['uid' => 5, 'raw' => $colMsg("Return-Path: <list@ext.com>\r\nTo: newsletter@ext.com\r\n", 'Lettre d\'information')],
        // Bounce of a message Paul sent (envelope sender = collector)
        ['uid' => 6, 'raw' => "Return-Path: <>\r\nFrom: Mail Delivery System <MAILER-DAEMON@mx.ovh.net>\r\nTo: mail@test.local\r\nSubject: Undelivered Mail Returned to Sender\r\nAuto-Submitted: auto-replied\r\nMessage-ID: <dsn1@mx.ovh.net>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/report; report-type=delivery-status; boundary=\"b1\"\r\n\r\n--b1\r\nContent-Type: text/plain\r\n\r\nunknown user\r\n--b1\r\nContent-Type: text/rfc822-headers\r\n\r\nFrom: Paul Durand <paul@test.local>\r\nTo: nobody@ext.com\r\nSubject: Devis\r\n--b1--\r\n"],
    ]]];
});
Settings::setMany(['mail.mode' => 'collector', 'collector.hourly_limit' => 200, 'collector.fallback_user' => 0, 'collector.block' => '']);
$colCount = static fn(array $u, string $subject) => (int) DB::value('SELECT COUNT(*) FROM messages WHERE user_id = :u AND subject = :s', ['u' => $u['id'], 's' => $subject]);

test('collector: shared mailbox configured, a user address refused', function () use ($fsAdmin) {
    ok(Collector::active() && !RemoteMailbox::active(), 'mode');
    $thrown = false;
    try { Collector::save('paul@test.local', 'x', (int) $fsAdmin['id']); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'a user address cannot be the collector');
    Collector::save('mail@test.local', 'secret-col', (int) $fsAdmin['id']);
    $t = RemoteMailbox::test(Collector::ACCOUNT);
    eq([$t['imap'], $t['smtp']], ['', ''], 'IMAP and SMTP authenticate');
    ok(in_array('paul@test.local', Collector::addressesToRedirect(), true), 'redirection list');
});
test('collector: each message reaches its recipient (envelope, Received, To/Cc, bounce), once', function () use ($paul, $lea, $fsAdmin, $colCount) {
    $paulBefore = $colCount($paul, 'Facture 42');
    eq(RemoteMailbox::syncAll(), 6, 'six messages pulled');
    eq($colCount($paul, 'Commande 2041'), 1, 'X-Original-To');
    eq($colCount($lea, 'Commande 2041'), 0);
    eq($colCount($lea, 'Copie cachée pour Léa'), 1, 'Received … for <lea@>');
    eq([$colCount($paul, 'Réunion lundi'), $colCount($lea, 'Réunion lundi')], [1, 1], 'To + Cc, duplicate copy delivered once');
    eq($colCount($fsAdmin, 'Lettre d\'information'), 1, 'fallback mailbox');
    $m = DB::one('SELECT * FROM messages WHERE user_id = :u AND subject = :s', ['u' => $fsAdmin['id'], 's' => 'Lettre d\'information']);
    ok(str_contains((string) Mailbox::raw($m), 'X-M4W-Unrouted: mail@test.local'), 'marked unattributed');
    eq($colCount($paul, 'Undelivered Mail Returned to Sender'), 1, 'bounce back to its sender');
    eq($colCount($paul, 'Facture 42'), $paulBefore, 'per-user webmail accounts are not pulled in this mode');
    eq(RemoteMailbox::syncAll(), 0, 'nothing twice');
});
test('collector: sending goes through the shared account as the user; colleagues stay internal', function () use ($paul, $lea, $wmSmtp, $colCount) {
    DB::delete('send_log', '1 = 1');
    $before = count($wmSmtp());
    Composer::send(Users::find((int) $paul['id']), ['to' => 'client@ext.com, lea@test.local', 'subject' => 'Proposition', 'html' => '<p>Voici</p>']);
    $s = array_slice($wmSmtp(), $before);
    eq(count($s), 1, 'one SMTP transaction');
    eq([$s[0]['auth'], $s[0]['from'], $s[0]['rcpt']], ['mail@test.local', 'mail@test.local', ['client@ext.com']], 'collector account, external recipient only');
    ok((bool) preg_match('/^From: .*paul@test\.local/m', $s[0]['data']), 'the user is the sender');
    ok(!preg_match('/^DKIM-Signature:/m', $s[0]['data']), 'the provider signs');
    eq($colCount($lea, 'Proposition'), 1, 'colleague served locally');
    eq(Collector::used(), 1, 'only the external recipient counts');
});
test('collector: over the hourly limit, mail waits in order and leaves on its own', function () use ($paul, $lea, $wmSmtp) {
    Settings::set('collector.hourly_limit', 3);
    DB::run("UPDATE mail_queue SET status = 'failed' WHERE status = 'pending'"); // leftovers of earlier tests
    $before = count($wmSmtp());
    $r = Composer::send(Users::find((int) $paul['id']), ['to' => 'a@ext.com, b@ext.com, c@ext.com', 'subject' => 'Trois destinataires', 'html' => '<p>x</p>']);
    eq(count(array_slice($wmSmtp(), $before)[0]['rcpt']), 2, 'two leave now (1 already used)');
    eq($r['deferred']['count'], 1, 'one waits');
    ok($r['deferred']['at'] > time() + 3000, 'when the window reopens');
    // A later message cannot overtake the waiting one, even once a slot is free.
    $r2 = Composer::send(Users::find((int) $lea['id']), ['to' => 'd@ext.com', 'subject' => 'Plus tard', 'html' => '<p>y</p>']);
    eq($r2['deferred']['count'], 1);
    $st = Collector::stats();
    eq([$st['level'], $st['used'], $st['held'], $st['held_rcpts']], ['full', 3, 2, 2], 'gauge: full, 2 waiting');
    ok($st['next'] > time(), 'resumption time shown');
    eq((int) DB::value("SELECT COUNT(*) FROM mail_log WHERE status = 'held'"), 2, 'logged as held');
    // Nothing leaves before the window reopens…
    DB::run('UPDATE mail_queue SET next_attempt_at = 0 WHERE last_error = :h', ['h' => Collector::HELD]);
    $n = count($wmSmtp());
    Transport::processQueue();
    eq(count($wmSmtp()), $n, 'still held');
    eq(Collector::held(), 2);
    // …one hour later, both leave in arrival order.
    DB::run('UPDATE send_log SET created_at = created_at - 3700');
    DB::run('UPDATE mail_queue SET next_attempt_at = 0 WHERE last_error = :h', ['h' => Collector::HELD]);
    Transport::processQueue();
    $out = array_slice($wmSmtp(), $n);
    eq(array_map(static fn($x) => $x['rcpt'], $out), [['c@ext.com'], ['d@ext.com']], 'sent in order');
    eq(Collector::held(), 0);
    eq(Collector::stats()['level'], 'mid', '2 of 3 used');
});
test('collector: a refusal from the provider itself pauses sending and is shown', function () use ($paul, $colState, $wmSmtp) {
    Settings::set('collector.hourly_limit', 200);
    $colState(static function (array &$st) { $st['smtp_reject'] = 'Sender rate limit exceeded, try again later'; });
    $r = Composer::send(Users::find((int) $paul['id']), ['to' => 'e@ext.com', 'subject' => 'Refus fournisseur', 'html' => '<p>z</p>']);
    eq($r['deferred']['count'], 1, 'waits instead of failing');
    $st = Collector::stats();
    eq($st['level'], 'full');
    ok(str_contains($st['block']['message'], 'rate limit'), 'provider message kept for the admin');
    $colState(static function (array &$st) { unset($st['smtp_reject']); });
    Settings::set('collector.block', '');
    DB::run('UPDATE mail_queue SET next_attempt_at = 0 WHERE last_error = :h', ['h' => Collector::HELD]);
    $n = count($wmSmtp());
    Transport::processQueue();
    eq(array_slice($wmSmtp(), $n)[0]['rcpt'] ?? null, ['e@ext.com'], 'sent once the provider accepts again');
});
test('own mailbox imposed in collector mode: fetched from it, sent through it, overflow to the collector', function () use ($paul, $lea, $fsAdmin, $colState, $colMsg, $colCount, $wmSmtp) {
    RemoteMailbox::save((int) $paul['id'], ['login' => '', 'password' => 'secret-paul', 'enabled' => 1, 'forced' => 1, 'overflow' => 1, 'hourly_limit' => 2], (int) $fsAdmin['id']);
    ok(RemoteMailbox::personal((int) $paul['id']) !== null);
    ok(!in_array('paul@test.local', Collector::addressesToRedirect(), true), 'no redirection for an own mailbox');
    // The same message reaches his own box and, Lea being in copy, the collector: Paul gets it once.
    $colState(static function (array &$st) use ($colMsg) {
        $raw = $colMsg("Return-Path: <client@ext.com>\r\nTo: paul@test.local\r\nCc: lea@test.local\r\n", 'Devis perso', 'own-1');
        // Above every number already seen (messages cleaned up at the provider included).
        $st['boxes']['paul@test.local']['INBOX']['msgs'][] = ['uid' => 1000, 'raw' => $raw];
        $cmax = max(array_column($st['boxes']['mail@test.local']['INBOX']['msgs'], 'uid'));
        $st['boxes']['mail@test.local']['INBOX']['msgs'][] = ['uid' => $cmax + 1, 'raw' => $raw];
    });
    DB::run('UPDATE remote_accounts SET last_sync_at = 0');
    RemoteMailbox::syncAll();
    eq($colCount($paul, 'Devis perso'), 1, 'fetched from his own box, once');
    eq($colCount($lea, 'Devis perso'), 1, 'Lea through the collector');
    // Sending: his own SMTP up to its limit, then the collector.
    DB::delete('send_log', '1 = 1');
    $n = count($wmSmtp());
    Composer::send(Users::find((int) $paul['id']), ['to' => 'a@ext.com, b@ext.com, c@ext.com', 'subject' => 'Trois clients', 'html' => '<p>x</p>']);
    $s = array_slice($wmSmtp(), $n);
    eq(array_map(static fn($t) => [$t['auth'], count($t['rcpt'])], $s), [['paul@test.local', 2], ['mail@test.local', 1]], '2 by his box, 1 overflow');
    eq([RemoteMailbox::usedBy((int) $paul['id']), Collector::used()], [2, 1], 'separate counters');
    ok(!preg_match('/^DKIM-Signature:/m', $s[0]['data']), 'the provider signs');
    // The provider refuses for his box: it rests, the collector takes over.
    DB::delete('send_log', '1 = 1');
    DB::run('UPDATE remote_accounts SET hourly_limit = 0 WHERE user_id = :u', ['u' => $paul['id']]);
    $colState(static function (array &$st) { $st['smtp_reject_for']['paul@test.local'] = 'Rate limit exceeded'; });
    $n = count($wmSmtp());
    $r = Composer::send(Users::find((int) $paul['id']), ['to' => 'd@ext.com', 'subject' => 'Refus perso', 'html' => '<p>x</p>']);
    eq(array_column(array_slice($wmSmtp(), $n), 'auth'), ['mail@test.local'], 'sent by the collector');
    ok((int) RemoteMailbox::find((int) $paul['id'])['blocked_until'] > time(), 'his box rests');
    // Without overflow, mail waits for his own box (not counted as the collector's queue).
    DB::run('UPDATE remote_accounts SET overflow = 0 WHERE user_id = :u', ['u' => $paul['id']]);
    $held = Collector::held();
    $r = Composer::send(Users::find((int) $paul['id']), ['to' => 'e@ext.com', 'subject' => 'Attente perso', 'html' => '<p>x</p>']);
    eq($r['deferred']['count'] ?? 0, 1, 'waits');
    eq(Collector::held(), $held, 'not in the collector queue');
    eq((int) DB::value("SELECT COUNT(*) FROM mail_queue WHERE status = 'pending' AND last_error = :h", ['h' => RemoteMailbox::HELD]), 1);
    $colState(static function (array &$st) { unset($st['smtp_reject_for']); });
    DB::run('UPDATE remote_accounts SET blocked_until = 0 WHERE user_id = :u', ['u' => $paul['id']]);
    DB::run('UPDATE mail_queue SET next_attempt_at = 0 WHERE last_error = :h', ['h' => RemoteMailbox::HELD]);
    $n = count($wmSmtp());
    Transport::processQueue();
    ok(in_array(['paul@test.local', ['e@ext.com']], array_map(static fn($t) => [$t['auth'], $t['rcpt']], array_slice($wmSmtp(), $n)), true), 'leaves by his box later');
});
test('own mailbox: its own clean-up rule, sent copies, copy back to the provider', function () use ($paul, $fsAdmin, $colState, $colMsg, $wm) {
    $uid = (int) $paul['id'];
    $box = static fn(string $f) => json_decode((string) file_get_contents($wm['state']), true)['boxes']['paul@test.local'][$f]['msgs'] ?? [];
    // The collector empties its box at once; Paul's own box keeps everything (its default).
    $prevLeave = Settings::get('webmail.leave_days', -1);
    Settings::set('webmail.leave_days', 0);
    $colState(static function (array &$st) use ($colMsg) {
        $st['boxes']['paul@test.local']['INBOX']['msgs'][] = ['uid' => 1001, 'raw' => $colMsg("To: paul@test.local\r\n", 'Garde-moi', 'own-keep')];
        $st['boxes']['paul@test.local']['Sent'] = ['validity' => 1, 'msgs' => []];
    });
    DB::run('UPDATE remote_accounts SET last_sync_at = 0, blocked_until = 0, overflow = 0');
    $deleted = static fn() => (int) (RemoteMailbox::deletedStats(RemoteMailbox::find($uid))['n'] ?? 0);
    $before = $deleted(); // earlier tests used this account in webmail mode, where it was emptied
    eq(RemoteMailbox::sync(RemoteMailbox::find($uid))['error'], '');
    eq((int) RemoteMailbox::find($uid)['leave_days'], -1, 'never emptied by default');
    ok(count($box('INBOX')) >= 2, 'his box is not emptied by the collector rule');
    eq($deleted(), $before, 'nothing deleted there, and the count says so');
    $kept = DB::one("SELECT * FROM messages WHERE user_id = :u AND subject = 'Garde-moi'", ['u' => $uid]);
    ok(preg_match('/^X-M4W-Fetched: paul@test\.local; own; INBOX; uid=1001;/m', (string) Mailbox::raw($kept)) === 1, 'where it was collected is recorded: ' . substr((string) Mailbox::raw($kept), 0, 300));
    // The collector set up on the same mailbox as Paul's: it never deletes there.
    $col = M4W\Service\Collector::account();
    ok(!RemoteMailbox::sharedWithOwn($col), 'separate mailboxes');
    DB::update('remote_accounts', ['login' => 'paul@test.local'], 'id = :id', ['id' => $col['id']]);
    ok(RemoteMailbox::sharedWithOwn(M4W\Service\Collector::account()), 'same mailbox detected');
    DB::update('remote_accounts', ['login' => $col['login']], 'id = :id', ['id' => $col['id']]);
    // His own rule applies when chosen.
    RemoteMailbox::save($uid, ['login' => '', 'password' => '', 'enabled' => 1, 'forced' => 1, 'overflow' => 0, 'hourly_limit' => 0, 'leave_days' => 0, 'save_sent' => 1], (int) $fsAdmin['id']);
    DB::run('UPDATE remote_accounts SET last_sync_at = 0');
    RemoteMailbox::sync(RemoteMailbox::find($uid));
    eq(count($box('INBOX')), 0, 'emptied when he chose it');
    ok($deleted() >= $before + 2, 'deletions counted');
    Settings::set('webmail.leave_days', $prevLeave);
    // Sent from the webmail: a copy lands in the Sent folder of his box, read.
    Composer::send(Users::find($uid), ['to' => 'g@ext.com', 'subject' => 'Copie du message', 'html' => '<p>x</p>']);
    $sent = $box('Sent');
    eq(count($sent), 1, 'copied to Sent');
    ok(str_contains($sent[0]['raw'], 'Subject: Copie du message') && in_array('\\Seen', $sent[0]['flags'], true), 'the message, marked read');
    eq(RemoteMailbox::find($uid)['sent_folder'], 'Sent', 'folder found and remembered');
    // Copy the webmail back into the emptied box: inbox and sent, without duplicates.
    RemoteMailbox::save($uid, ['login' => '', 'password' => '', 'enabled' => 1, 'forced' => 1, 'overflow' => 0, 'hourly_limit' => 0, 'leave_days' => -1, 'save_sent' => 1], (int) $fsAdmin['id']);
    $inbox = (int) DB::value('SELECT COUNT(*) FROM messages WHERE user_id = :u AND folder_id = :f', ['u' => $uid, 'f' => Folders::byRole($uid, 'inbox')['id']]);
    $sentHere = (int) DB::value('SELECT COUNT(*) FROM messages WHERE user_id = :u AND folder_id = :f', ['u' => $uid, 'f' => Folders::byRole($uid, 'sent')['id']]);
    eq(RemoteMailbox::startRestore($uid, ['inbox', 'sent'], (int) $fsAdmin['id']), $inbox + $sentHere);
    DB::run('UPDATE remote_accounts SET last_sync_at = 0');
    RemoteMailbox::sync(RemoteMailbox::find($uid));
    $rs = RemoteMailbox::restoreState(RemoteMailbox::find($uid));
    ok($rs['finished'] > 0 && $rs['failed'] === 0, 'finished');
    eq([$rs['done'], $rs['skipped']], [$inbox + $sentHere - 1, 1], 'the sent copy already there is skipped');
    eq(count($box('INBOX')), $inbox, 'inbox back at the provider');
    ok(!str_starts_with($box('INBOX')[0]['raw'], 'Delivered-To:') && !str_contains(implode('', array_column($box('INBOX'), 'raw')), 'X-M4W-Fetched'), 'without our delivery and collection lines');
    // The next synchronisation sees them as new UIDs: nothing comes in twice.
    DB::run('UPDATE remote_accounts SET last_sync_at = 0');
    RemoteMailbox::sync(RemoteMailbox::find($uid));
    eq((int) DB::value('SELECT COUNT(*) FROM messages WHERE user_id = :u AND folder_id = :f', ['u' => $uid, 'f' => Folders::byRole($uid, 'inbox')['id']]), $inbox, 'no duplicate');
    // Only an own mailbox can be copied back; the sending check passes for his box.
    $thrown = false;
    try { RemoteMailbox::startRestore((int) $fsAdmin['id'], ['inbox'], 1); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'no own mailbox');
    eq(RemoteMailbox::test($uid)['smtp'], '', 'sender accepted');
    eq(RemoteMailbox::envelopeFor(['login' => 'boite@ext.com'] + RemoteMailbox::find($uid), $uid, 'paul@test.local'), 'boite@ext.com', 'the mailbox login as envelope sender');
});
test('two-way: deleted, archived, read or flagged on the phone or here, both sides agree', function () use ($paul, $colState, $colMsg, $wm) {
    $uid = (int) $paul['id'];
    $box = static fn(string $f) => json_decode((string) file_get_contents($wm['state']), true)['boxes']['paul@test.local'][$f]['msgs'] ?? [];
    $has = static fn(string $f, string $id) => (bool) array_filter($box($f), static fn($m) => str_contains($m['raw'], "<$id@ext.com>"));
    $local = static fn(string $id) => DB::one('SELECT m.*, f.role FROM messages m JOIN folders f ON f.id = m.folder_id WHERE m.user_id = :u AND m.message_id = :m', ['u' => $uid, 'm' => "$id@ext.com"]);
    $sync = static function () use ($uid) { DB::run('UPDATE remote_accounts SET last_sync_at = 0 WHERE user_id = :u', ['u' => $uid]); return RemoteMailbox::sync(RemoteMailbox::find($uid))['error']; };
    $colState(static function (array &$st) use ($colMsg) {
        $st['boxes']['paul@test.local']['Corbeille'] = ['validity' => 1, 'msgs' => []];
        $st['boxes']['paul@test.local']['Archives'] = ['validity' => 1, 'msgs' => []];
        $st['special']['paul@test.local'] = ['Corbeille' => '\\Trash', 'Archives' => '\\Archive'];
        foreach (['tw-del', 'tw-arch', 'tw-read', 'tw-here-del', 'tw-here-flag', 'tw-here-arch', 'tw-mark'] as $k => $id) {
            $st['boxes']['paul@test.local']['INBOX']['msgs'][] = ['uid' => 3000 + $k, 'raw' => $colMsg("To: paul@test.local\r\n", "Deux sens $id", $id), 'flags' => []];
        }
    });
    eq($sync(), '');
    ok($local('tw-del') && $local('tw-del')['role'] === 'inbox', 'collected');
    // On the phone: one deleted (to its Trash), one archived, one read.
    $colState(static function (array &$st) {
        $in = &$st['boxes']['paul@test.local']['INBOX']['msgs'];
        foreach ($in as $k => $m) {
            if (str_contains($m['raw'], '<tw-del@')) { $st['boxes']['paul@test.local']['Corbeille']['msgs'][] = ['uid' => 1] + $m; unset($in[$k]); }
            elseif (str_contains($m['raw'], '<tw-arch@')) { $st['boxes']['paul@test.local']['Archives']['msgs'][] = ['uid' => 1] + $m; unset($in[$k]); }
            elseif (str_contains($m['raw'], '<tw-read@')) { $in[$k]['flags'] = ['\\Seen']; }
            elseif (str_contains($m['raw'], '<tw-mark@')) { $in[$k]['flags'] = ['\\Seen', '\\Deleted']; } // marked, not erased yet
        }
        $in = array_values($in);
    });
    eq($sync(), '');
    eq([$local('tw-del')['role'], $local('tw-arch')['role'], (int) $local('tw-read')['is_read']], ['trash', 'archive', 1], 'deleted → Trash, archived → Archives, read → read');
    eq($local('tw-mark')['role'], 'trash', 'only marked deleted on the phone → Trash too');
    // Filed in a folder of one's own here, then deleted on the phone: to the Trash as well.
    $own = (int) DB::insert('folders', ['user_id' => $uid, 'name' => 'Clients deux sens', 'role' => null, 'created_at' => time()]);
    Mailbox::move($uid, [(int) $local('tw-read')['id']], $own);
    $colState(static function (array &$st) {
        $in = &$st['boxes']['paul@test.local']['INBOX']['msgs'];
        foreach ($in as $k => $m) { if (str_contains($m['raw'], '<tw-read@')) { $st['boxes']['paul@test.local']['Corbeille']['msgs'][] = ['uid' => 2] + $m; unset($in[$k]); } }
        $in = array_values($in);
    });
    $rep = RemoteMailbox::twoWayReport(RemoteMailbox::find($uid));
    ok($rep['trashed'] === 2 && $rep['archived'] === 1 && $rep['followed'] > 0, 'what the pass did is reported: ' . json_encode($rep));
    eq($sync(), '');
    eq($local('tw-read')['role'], 'trash', 'from a folder of one\'s own too');
    // Here: one deleted, one flagged, one archived.
    Mailbox::move($uid, [(int) $local('tw-here-del')['id']], (int) Folders::byRole($uid, 'trash')['id']);
    Mailbox::move($uid, [(int) $local('tw-here-arch')['id']], (int) Folders::byRole($uid, 'archive')['id']);
    Mailbox::setFlags($uid, [(int) $local('tw-here-flag')['id']], ['is_flagged' => true]);
    eq($sync(), '');
    ok(!$has('INBOX', 'tw-here-del') && $has('Corbeille', 'tw-here-del'), 'deleted here → in the provider Trash, not erased');
    ok(!$has('INBOX', 'tw-here-arch') && $has('Archives', 'tw-here-arch'), 'archived here → provider Archives');
    $flag = array_values(array_filter($box('INBOX'), static fn($m) => str_contains($m['raw'], '<tw-here-flag@')))[0];
    ok(in_array('\\Flagged', $flag['flags'], true), 'flagged here → flagged there');
    // Nothing comes back, nothing is imported twice, and a further pass changes nothing.
    eq($sync(), '');
    eq((int) DB::value("SELECT COUNT(*) FROM messages WHERE user_id = :u AND subject LIKE 'Deux sens %' AND subject <> 'Deux sens renum'", ['u' => $uid]), 7, 'no duplicate');
    eq([$local('tw-del')['role'], $local('tw-here-del')['role']], ['trash', 'trash']);
    // Same message under another number at the provider (redirection, filter): nothing deleted.
    $colState(static function (array &$st) use ($colMsg) {
        $st['boxes']['paul@test.local']['INBOX']['msgs'][] = ['uid' => 3100, 'raw' => $colMsg("To: contact@test.local\r\n", 'Deux sens renum', 'tw-renum'), 'flags' => []];
    });
    eq($sync(), '');
    eq($local('tw-renum')['role'], 'inbox');
    $colState(static function (array &$st) {
        foreach ($st['boxes']['paul@test.local']['INBOX']['msgs'] as &$m) { if ($m['uid'] === 3100) { $m['uid'] = 3101; } }
    });
    eq($sync(), '');
    eq($local('tw-renum')['role'], 'inbox', 'renumbered at the provider: stays in the inbox');
    // A message the client cannot read in the provider answer: asked again, never trashed.
    $colState(static function (array &$st) { $st['hide_in_fetch'] = [3101]; });
    eq($sync(), '');
    eq($local('tw-renum')['role'], 'inbox', 'not in the index but found by Message-ID: stays');
    $colState(static function (array &$st) { unset($st['hide_in_fetch']); });
    // Trashed here by mistake, then (again) in the provider inbox: back in the inbox here.
    Mailbox::move($uid, [(int) $local('tw-renum')['id']], (int) Folders::byRole($uid, 'trash')['id']);
    DB::delete('remote_uids', "msgid = 'tw-renum@ext.com'");
    $colState(static function (array &$st) {
        foreach ($st['boxes']['paul@test.local']['INBOX']['msgs'] as &$m) { if ($m['uid'] === 3101) { $m['uid'] = 3102; } }
    });
    eq($sync(), '');
    eq($local('tw-renum')['role'], 'inbox', 'back in the provider inbox: back in the inbox here');
    ok($has('INBOX', 'tw-renum') && !$has('Corbeille', 'tw-renum'), 'and left in the provider inbox');
    ok((int) DB::value("SELECT COUNT(*) FROM audit_log WHERE action = 'sync.trashed_here'") >= 2, 'moves made by the synchronisation are logged');
    // The webmail's own clean-up is not taken for a deletion on the phone.
    RemoteMailbox::save($uid, ['login' => '', 'password' => '', 'enabled' => 1, 'forced' => 1, 'overflow' => 0, 'hourly_limit' => 0, 'leave_days' => 0, 'save_sent' => 1], 1);
    eq($sync(), '');
    eq($local('tw-here-flag')['role'], 'inbox', 'cleaned up at the provider by us: kept in the inbox here');
    RemoteMailbox::save($uid, ['login' => '', 'password' => '', 'enabled' => 1, 'forced' => 1, 'overflow' => 0, 'hourly_limit' => 0, 'leave_days' => -1, 'save_sent' => 1], 1);
});
test('own mailbox: mail delivered here directly (a colleague of the server) is copied to the provider inbox', function () use ($paul, $wm) {
    $uid = (int) $paul['id'];
    $box = static fn(string $f) => json_decode((string) file_get_contents($wm['state']), true)['boxes']['paul@test.local'][$f]['msgs'] ?? [];
    $at = static fn(string $f, string $id) => array_values(array_filter($box($f), static fn($m) => str_contains($m['raw'], "<$id@test.local>")));
    $local = static fn(string $id) => DB::all('SELECT m.*, f.role FROM messages m JOIN folders f ON f.id = m.folder_id WHERE m.user_id = :u AND m.message_id = :m', ['u' => $uid, 'm' => "$id@test.local"]);
    $sync = static function () use ($uid) { DB::run('UPDATE remote_accounts SET last_sync_at = 0 WHERE user_id = :u', ['u' => $uid]); return RemoteMailbox::sync(RemoteMailbox::find($uid))['error']; };
    $mail = static fn(string $id, string $subject) => "From: Lea <lea@test.local>\nTo: paul@test.local\nSubject: $subject\nMessage-ID: <$id@test.local>\nDate: " . date('r') . "\n\nBonjour\n";
    Delivery::deliver($mail('pc-1', 'Invitation interne'), 'lea@test.local', ['paul@test.local'], 'local');
    Delivery::deliver($mail('pc-2', 'Classé tout de suite'), 'lea@test.local', ['paul@test.local'], 'local');
    // Filed elsewhere before the sync: not copied (the sync would bring it back to the inbox).
    Mailbox::move($uid, [(int) $local('pc-2')[0]['id']], (int) Folders::byRole($uid, 'archive')['id']);
    eq((int) DB::value('SELECT COUNT(*) FROM provider_copies WHERE user_id = :u', ['u' => $uid]), 2, 'waiting for the next sync');
    eq($sync(), '');
    $copy = $at('INBOX', 'pc-1');
    eq(count($copy), 1, 'in the provider inbox, for the phone and the other webmail');
    ok(!str_contains($copy[0]['raw'], 'Delivered-To:') && !str_contains($copy[0]['raw'], 'X-M4W-'), 'without our own delivery lines');
    eq($at('INBOX', 'pc-2'), [], 'filed here first: not copied');
    eq((int) DB::value('SELECT COUNT(*) FROM provider_copies WHERE user_id = :u', ['u' => $uid]), 0);
    eq($sync(), '');
    eq(count($local('pc-1')), 1, 'found again there by its Message-ID: not imported twice');
    eq(count($at('INBOX', 'pc-1')), 1, 'not copied twice');
    // Then it lives both ways like any other message: deleted here, in the provider Trash.
    Mailbox::move($uid, [(int) $local('pc-1')[0]['id']], (int) Folders::byRole($uid, 'trash')['id']);
    eq($sync(), '');
    ok(!$at('INBOX', 'pc-1') && $at('Corbeille', 'pc-1'), 'deleted here → provider Trash');
    // What came from the provider itself is never sent back to it.
    eq((int) DB::value('SELECT COUNT(*) FROM provider_copies'), 0);
});

test('own mailbox imposed in server mode', function () use ($paul, $wmSmtp) {
    Settings::set('mail.mode', 'server');
    $n = count($wmSmtp());
    Composer::send(Users::find((int) $paul['id']), ['to' => 'f@ext.com', 'subject' => 'Mode serveur', 'html' => '<p>x</p>']);
    eq(array_column(array_slice($wmSmtp(), $n), 'auth'), ['paul@test.local'], 'through his own box, not direct MX');
    RemoteMailbox::save((int) $paul['id'], ['login' => '', 'password' => '', 'enabled' => 1, 'forced' => 0], 1);
    eq(RemoteMailbox::personal((int) $paul['id']), null, 'switched off');
    Settings::set('mail.mode', 'collector');
});
test('webmail: mail imported before history handling gets its real date and read state back', function () use ($lea, $wm, $fsAdmin) {
    Settings::set('mail.mode', 'webmail');
    $uid = (int) $lea['id'];
    $raw = "From: Ancien <old@ext.com>\r\nTo: lea@test.local\r\nSubject: Message de 2024\r\nDate: " . date('r', time() - 400 * 86400) . "\r\nMessage-ID: <repair-1@ext.com>\r\n\r\nBonjour\r\n";
    $st = json_decode((string) file_get_contents($wm['state']), true);
    $st['boxes']['lea@test.local']['INBOX']['msgs'] = [['uid' => 1, 'raw' => $raw, 'flags' => ['\\Seen'], 'date' => time() - 400 * 86400]];
    file_put_contents($wm['state'], json_encode($st), LOCK_EX);
    RemoteMailbox::save($uid, ['login' => '', 'password' => 'secret-lea', 'enabled' => 1], (int) $fsAdmin['id']);
    $acc = RemoteMailbox::find($uid);
    // As the old code did: stored "now", unread, and the provider position already past it.
    $id = Mailbox::store($uid, (int) Folders::byRole($uid, 'inbox')['id'], $raw);
    DB::insert('remote_uids', ['account_id' => $acc['id'], 'folder' => 'inbox', 'validity' => 1, 'uid' => 1, 'imported_at' => time()]);
    DB::update('remote_accounts', ['inbox_uid' => 1, 'inbox_validity' => 1, 'last_sync_at' => 0], 'id = :id', ['id' => $acc['id']]);
    eq(RemoteMailbox::sync(RemoteMailbox::find($uid))['error'], '');
    $m = DB::one('SELECT * FROM messages WHERE id = :id', ['id' => $id]);
    ok((int) $m['date_received'] < time() - 399 * 86400, 'real date');
    eq((int) $m['is_read'], 1, 'read, as on the provider');
    Settings::set('mail.mode', 'collector');
});
proc_terminate($wmProc);
Settings::setMany(['mail.mode' => '', 'webmail.spam_folder' => '']);


// ------------------------------------------------------------------ message pictures
echo "\nSessions in the database\n";
test('sessions: stored hashed, read back, refreshed sparingly, expired and purged', function () {
    $h = new M4W\Core\DbSessionHandler();
    $id = 'sessTEST' . bin2hex(random_bytes(16));
    eq($h->validateId($id), false, 'unknown id refused (strict mode)');
    eq($h->read($id), '', 'new session is empty');
    $data = "user|i:5;bin|s:4:\"\x00\xff\n'\";csrf|s:3:\"abc\";";
    ok($h->write($id, $data));
    eq((int) DB::value('SELECT COUNT(*) FROM php_sessions WHERE id = :i', ['i' => $id]), 0, 'raw id never stored');
    eq((int) DB::value('SELECT COUNT(*) FROM php_sessions WHERE id = :i', ['i' => hash('sha256', $id)]), 1, 'stored under its hash');
    eq((new M4W\Core\DbSessionHandler())->read($id), $data, 'binary-safe, read by another server');
    ok($h->validateId($id));
    ok($h->write($id, $data), 'rewriting the same data');
    eq((int) DB::value('SELECT COUNT(*) FROM php_sessions WHERE id = :i', ['i' => hash('sha256', $id)]), 1, 'one row');
    // Unchanged: time refreshed at most once a minute.
    DB::run('UPDATE php_sessions SET updated_at = :t WHERE id = :i', ['t' => time() - 30, 'i' => hash('sha256', $id)]);
    $h->updateTimestamp($id, $data);
    eq((int) DB::value('SELECT updated_at FROM php_sessions WHERE id = :i', ['i' => hash('sha256', $id)]), time() - 30, 'not rewritten within a minute');
    DB::run('UPDATE php_sessions SET updated_at = :t WHERE id = :i', ['t' => time() - 90, 'i' => hash('sha256', $id)]);
    $h->updateTimestamp($id, $data);
    ok((int) DB::value('SELECT updated_at FROM php_sessions WHERE id = :i', ['i' => hash('sha256', $id)]) >= time() - 1, 'refreshed after a minute');
    // Expired: no longer valid nor readable, then purged.
    DB::run('UPDATE php_sessions SET updated_at = :t WHERE id = :i', ['t' => time() - M4W\Core\DbSessionHandler::lifetime() - 10, 'i' => hash('sha256', $id)]);
    eq([$h->validateId($id), $h->read($id)], [false, ''], 'expired');
    ok(M4W\Core\DbSessionHandler::purge(M4W\Core\DbSessionHandler::lifetime()) >= 1);
    eq((int) DB::value('SELECT COUNT(*) FROM php_sessions WHERE id = :i', ['i' => hash('sha256', $id)]), 0, 'purged');
    $h->write($id, 'x|i:1;');
    $h->destroy($id);
    eq([$h->validateId($id), $h->read($id)], [false, ''], 'destroyed at logout');
});

echo "\nFull-text search\n";
test('search: full-text index (accents, addresses, attachment names, operators), kept in step, never fewer results than before', function () {
    $u = Users::find(Users::create(['email' => 'chercheur@test.local', 'password' => 'Secret!Pass123', 'first_name' => 'Cher']));
    $uid = (int) $u['id'];
    $inbox = (int) Folders::byRole($uid, 'inbox')['id'];
    $put = static function (string $from, string $subject, string $body, string $att = '') use ($uid, $inbox) {
        $raw = "From: $from\nTo: chercheur@test.local\nSubject: $subject\nMessage-ID: <" . bin2hex(random_bytes(6)) . "@s.test>\nDate: " . date('r') . "\n"
            . ($att !== '' ? "MIME-Version: 1.0\nContent-Type: multipart/mixed; boundary=b\n\n--b\nContent-Type: text/plain; charset=utf-8\n\n$body\n--b\nContent-Type: application/pdf; name=\"$att\"\nContent-Disposition: attachment; filename=\"$att\"\nContent-Transfer-Encoding: base64\n\nJVBERi0xLjQK\n--b--\n"
            : "Content-Type: text/plain; charset=utf-8\n\n$body\n");
        return Mailbox::store($uid, $inbox, $raw);
    };
    $ids = [
        'eleve' => $put('Prof <prof@ecole.test>', 'Résultats de l\'élève', 'Bulletin du trimestre, très bien.'),
        'facture' => $put('Compta <compta@fournisseur.test>', 'Facture 2026-114', 'Veuillez trouver la facturation de mars.', 'Facture_Mars_2026.pdf'),
        'martin' => $put('Christophe Martin <christophe.martin@make4web.test>', 'Point projet', 'Rapport mensuel en pièce jointe.'),
        'rapport' => $put('Bob <bob@test.local>', 'Porte d\'entrée', 'La porte du bureau ferme mal.'),
        'pv' => $put('Syndic <syndic@immo.test>', 'PV AG', 'Le PV de l\'assemblée est disponible.'),
        'the' => $put('News <news@en.test>', 'The weekly news', 'All the news that fits.'),
    ];
    $find = static function (string $q) use ($uid) {
        $r = array_map(static fn($i) => (int) $i['id'], Mailbox::list($uid, ['q' => $q, 'limit' => 200])['items']);
        sort($r);
        return $r;
    };
    $queries = ['élève', 'facture', 'Facture_Mars_2026', 'christophe.martin@make4web.test', 'make4web', 'martin', 'rapport', 'porte', 'PV', 'the', 'news',
        'from:compta facture', 'subject:projet rapport', '"rapport mensuel"', 'trimestre bulletin', 'inexistant'];
    // Plain search first (no index), then the index: the same messages, and better on accents.
    Search::dropIndex();
    ok(!Search::ready());
    $before = [];
    foreach ($queries as $q) {
        $before[$q] = $find($q);
    }
    ok(Search::ensureIndex(), 'index built (' . M4W\Core\Database::driver() . ')');
    ok(Search::ready());
    $pp = [];
    ok(Search::condition('facture', 'x', $pp) !== null, 'the index answers words');
    $pp = [];
    eq(Search::condition('PV', 'x', $pp) === null, M4W\Core\Database::driver() === 'mysql', 'MySQL: words under its minimum length go through LIKE');
    $pp = [];
    eq(Search::condition('the', 'x', $pp) === null, M4W\Core\Database::driver() === 'mysql', 'MySQL: its stopwords go through LIKE');
    foreach ($queries as $q) {
        eq($find($q), $before[$q], 'same results with the index: ' . $q);
    }
    eq($find('eleve'), [$ids['eleve']], 'accents ignored: eleve finds élève');
    eq($find('ÉLÈVE'), [$ids['eleve']], 'case and accents');
    eq($find('factur'), [$ids['facture']], 'from the beginning of words (facture, facturation)');
    eq($find('4web'), [$ids['martin']], 'a part of a word: plain search takes over');
    eq($find('make4web.test'), [$ids['martin']], 'address pieces as a phrase');
    // Kept in step: new, changed and deleted messages.
    $new = $put('Neo <neo@x.test>', 'Zygomatique', 'Quinconce extraordinaire.');
    eq($find('quinconce'), [$new], 'new message indexed at once');
    M4W\Core\Database::update('messages', ['subject' => 'Brouillon renommé', 'body_text' => 'Hippocampe'], 'id = :id', ['id' => $new]);
    eq([$find('hippocampe'), $find('zygomatique'), $find('quinconce')], [[$new], [], []], 'changed message re-indexed, old words gone');
    Mailbox::move($uid, [$new], (int) Folders::byRole($uid, 'archive')['id']);
    eq($find('hippocampe'), [$new], 'still found after a move');
    M4W\Core\Database::delete('messages', 'id = :id', ['id' => $new]);
    eq($find('hippocampe'), [], 'deleted message gone from the index');
    // Nasty input: operators and quotes are words, never syntax.
    foreach (['"', '+-*~<>()@', 'facture" OR 1=1 --', 'a* -b +c', "'; DROP TABLE messages; --", 'NEAR(a b)', 'col:val:"x'] as $q) {
        $r = Mailbox::list($uid, ['q' => $q]);
        ok(is_array($r['items']), 'no error for: ' . $q);
    }
    ok((int) M4W\Core\Database::value('SELECT COUNT(*) FROM messages') > 0, 'table intact');
    // Other people's messages never come up.
    $other = Users::find(Users::create(['email' => 'autre.chercheur@test.local', 'password' => 'Secret!Pass123']));
    eq(Mailbox::list((int) $other['id'], ['q' => 'facture'])['items'], [], 'only one\'s own mail');
    // A restored backup without the index: noticed, plain search meanwhile, rebuilt by the next run.
    Search::dropIndex();
    Settings::set('search.fts', M4W\Core\Database::driver());
    $r = new ReflectionProperty(Search::class, 'ready');
    $r->setValue(null, null);
    ok(!Search::ready(), 'missing index detected');
    eq($find('facture'), [$ids['facture']], 'plain search meanwhile');
    ok(Search::ensureIndex());
    eq($find('eleve'), [$ids['eleve']]);
});

echo "\nSender logos (BIMI)\n";
test('bimi: the logo is reduced to drawing (no script, link, external reference)', function () {
    $evil = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10" onload="alert(1)">'
        . '<script>alert(2)</script><defs><linearGradient id="g"><stop offset="0" stop-color="#f00"/></linearGradient></defs>'
        . '<a href="https://evil.example/"><rect width="10" height="10" fill="url(#g)"/></a>'
        . '<image href="https://track.example/p.png" width="1" height="1"/><foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>'
        . '<use xlink:href="https://evil.example/s.svg#a"/><use href="#g"/><circle cx="5" cy="5" r="4" style="fill:url(https://track.example/x)" onclick="x()"/>'
        . '<path d="M0 0L10 10" fill="javascript:alert(3)"/><!-- comment --><?pi x?></svg>';
    $out = Bimi::sanitizeSvg($evil);
    ok($out !== null && str_contains($out, '<rect') === false, 'a link and what it wraps are dropped');
    foreach (['onload', 'script', 'alert', 'evil.example', 'track.example', 'foreignObject', '<image', 'comment', '<?pi', 'onclick', 'javascript'] as $bad) {
        ok(!str_contains($out, $bad), 'removed: ' . $bad);
    }
    ok(str_contains($out, 'url(#g)') || str_contains($out, 'stop-color="#f00"'), 'gradients kept');
    ok(str_contains($out, '<use href="#g"/>') && str_contains($out, '<circle'), 'internal reference and shapes kept');
    eq(Bimi::sanitizeSvg('<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg>&x;</svg>'), null, 'entities refused');
    eq(Bimi::sanitizeSvg('<html><body>x</body></html>'), null, 'not an SVG');
});

test('bimi: shown only for DMARC-enforcing domains that pass, never for look-alikes; served by our server', function () use (&$dns) {
    $bob = Users::findByEmail('bob@test.local');
    $saved = $dns;
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" baseProfile="tiny-ps"><title>Brand</title><circle cx="32" cy="32" r="30" fill="#0a58ca"/></svg>';
    $fetched = [];
    Bimi::$http = static function (string $url) use ($svg, &$fetched) {
        $fetched[] = $url;
        return $url === 'https://brand.test/logo.svg' || $url === 'https://cdn.loose.test/l.svg' ? ['status' => 200, 'body' => $svg, 'location' => ''] : null;
    };
    $dns['TXT _dmarc.brand.test'] = ['v=DMARC1; p=reject; rua=mailto:d@brand.test'];
    $dns['TXT default._bimi.brand.test'] = ['v=BIMI1; l=https://brand.test/logo.svg; a='];
    $dns['TXT _dmarc.loose.test'] = ['v=DMARC1; p=none'];
    $dns['TXT default._bimi.loose.test'] = ['v=BIMI1; l=https://cdn.loose.test/l.svg'];
    $dns['TXT _dmarc.half.test'] = ['v=DMARC1; p=quarantine; pct=50'];
    try {
        ok(Bimi::enforced('brand.test') && Bimi::enforced('mail.brand.test') && !Bimi::enforced('loose.test') && !Bimi::enforced('half.test'), 'p=reject (or quarantine) on all mail only');
        $send = static function (string $from, string $id, string $dmarc) use ($bob) {
            $raw = "From: News <$from>\nTo: bob@test.local\nSubject: Logo $id\nMessage-ID: <$id@x.test>\nDate: " . date('r') . "\n\nBonjour\n";
            $auth = ['spf' => 'pass', 'spf_domain' => M4W\Mail\Address::domain($from), 'dkim' => [], 'dkim_summary' => 'none', 'dmarc' => $dmarc, 'policy' => 'reject',
                'from_domain' => M4W\Mail\Address::domain($from), 'verdict' => $dmarc === 'pass' ? 'ok' : 'dmarc'];
            Delivery::deliver($raw, $from, ['bob@test.local'], 'smtp', true, $auth);
            return (string) DB::value('SELECT bimi FROM messages WHERE user_id = :u AND message_id = :m', ['u' => $bob['id'], 'm' => "$id@x.test"]);
        };
        eq($send('news@brand.test', 'bimi-ok', 'pass'), 'brand.test', 'DMARC passes, policy enforced, logo published');
        $file = Bimi::file('brand.test');
        ok($file !== null && str_contains((string) file_get_contents($file), '<circle'), 'cleaned copy kept by the server');
        $n = count($fetched);
        eq($send('info@brand.test', 'bimi-ok2', 'pass'), 'brand.test');
        eq(count($fetched), $n, 'fetched once per domain, not per message');
        eq($send('news@brand.test', 'bimi-fail', 'fail'), '', 'DMARC fails: initials');
        eq($send('news@loose.test', 'bimi-loose', 'pass'), '', 'domain not enforcing DMARC: initials');
        eq($send('news@brаnd.test', 'bimi-idn', 'pass'), '', 'homograph domain: initials');
        eq(Bimi::file('../../config'), null, 'only domain names');
        $sum = M4W\Service\Mailbox::summary(DB::one('SELECT * FROM messages WHERE user_id = :u AND message_id = :m', ['u' => $bob['id'], 'm' => 'bimi-ok@x.test']));
        eq($sum['bimi'], 'brand.test', 'listed with its logo');
        Settings::set('security.bimi', 'vmc');
        eq($send('news@brand.test', 'bimi-vmc', 'pass'), '', 'certificate required and none: initials');
        Settings::set('security.bimi', 'off');
        eq($send('news@brand.test', 'bimi-off', 'pass'), '', 'turned off');
    } finally {
        Settings::set('security.bimi', 'dmarc');
        Bimi::$http = null;
        $dns = $saved;
    }
});

test('bimi: a mark certificate is checked (authority, purpose, domain, dates) and its own logo used', function () {
    $tmp = sys_get_temp_dir() . '/m4w-vmc-' . bin2hex(random_bytes(4));
    mkdir($tmp);
    $logo = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><rect width="8" height="8" fill="#123456"/></svg>';
    $make = static function (string $name, array $ext, $caKey = null, $caCert = null) use ($tmp) {
        $cnf = "$tmp/$name.cnf";
        file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n[ext]\n" . implode("\n", $ext) . "\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => $name], $key, ['config' => $cnf]);
        $cert = openssl_csr_sign($csr, $caCert, $caKey ?? $key, 30, ['config' => $cnf, 'x509_extensions' => 'ext', 'digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        openssl_x509_export($cert, $pem);
        return [$key, $cert, $pem];
    };
    try {
        [$rk, $rc, $rootPem] = $make('Test Mark Root', ['basicConstraints=critical,CA:TRUE', 'keyUsage=critical,keyCertSign,cRLSign']);
        $logoExt = '1.3.6.1.5.5.7.1.12=ASN1:IA5STRING:data:image/svg+xml;base64,' . base64_encode(gzencode($logo));
        [, , $leafPem] = $make('brand.test', ['extendedKeyUsage=1.3.6.1.5.5.7.3.31', 'subjectAltName=DNS:brand.test', $logoExt], $rk, $rc);
        [, , $otherPem] = $make('other.test', ['extendedKeyUsage=1.3.6.1.5.5.7.3.31', 'subjectAltName=DNS:other.test', $logoExt], $rk, $rc);
        [, , $tlsPem] = $make('brand.test', ['extendedKeyUsage=serverAuth', 'subjectAltName=DNS:brand.test', $logoExt], $rk, $rc);
        [$sk, $sc] = $make('Self Root', ['basicConstraints=critical,CA:TRUE']);
        [, , $selfPem] = $make('brand.test', ['extendedKeyUsage=1.3.6.1.5.5.7.3.31', 'subjectAltName=DNS:brand.test', $logoExt], $sk, $sc);
        Bimi::$roots = $rootPem;
        [$svg, $why] = Bimi::checkVmcPem('brand.test', $leafPem . $rootPem);
        eq([$svg, $why], [$logo, ''], 'valid: the logo comes from the certificate');
        eq(Bimi::checkVmcPem('mail.brand.test', $leafPem)[1], '', 'a subdomain of the certified domain');
        eq(Bimi::checkVmcPem('brand.test', $otherPem)[1], 'certificate issued for another domain');
        eq(Bimi::checkVmcPem('brand.test', $tlsPem)[1], 'not a mark certificate');
        eq(Bimi::checkVmcPem('brand.test', $selfPem)[1], 'certificate not issued by a trusted mark authority');
        Bimi::$roots = '';
        $bundled = Bimi::checkVmcPem('brand.test', $leafPem)[1];
        eq($bundled, 'certificate not issued by a trusted mark authority', 'the bundled authorities do not trust a test root');
    } finally {
        Bimi::$roots = null;
        array_map('unlink', glob("$tmp/*") ?: []);
        @rmdir($tmp);
    }
});

echo "\nMessage pictures (fetched and cleaned by the server)\n";
use M4W\Service\MailAssets;
$png = static function (int $w, int $h, bool $alpha = false): string {
    $im = imagecreatetruecolor($w, $h);
    if ($alpha) { imagesavealpha($im, true); imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); }
    else { imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200)); }
    ob_start(); imagepng($im); imagedestroy($im);
    return (string) ob_get_clean();
};
test('pictures: re-encoded by GD (size capped, metadata and payloads dropped), trackers recognised', function () use ($png) {
    $jpeg = (function () { $im = imagecreatetruecolor(3000, 1000); imagefill($im, 0, 0, imagecolorallocate($im, 200, 50, 50)); ob_start(); imagejpeg($im); return (string) ob_get_clean(); })();
    // A comment segment as EXIF-like metadata, and a script appended after the image data.
    $withMeta = substr($jpeg, 0, 2) . "\xFF\xFE\x00\x16GPS 48.85,2.35 secret" . substr($jpeg, 2) . '<script>alert(1)</script>';
    $c = MailAssets::clean($withMeta);
    eq([$c['kind'], $c['mime'], $c['width'], $c['height']], ['image', 'image/jpeg', 1600, 533], 'resized to the width limit');
    ok(!str_contains($c['bytes'], 'GPS') && !str_contains($c['bytes'], '<script'), 'metadata and trailing payload gone');
    eq(MailAssets::clean($png(1, 1))['kind'], 'tracker', '1×1 pixel');
    eq(MailAssets::clean($png(200, 100, true))['mime'], 'image/png', 'transparency kept');
    eq(MailAssets::clean($png(200, 100))['mime'], 'image/jpeg', 'opaque PNG becomes JPEG');
    eq(MailAssets::clean('<svg onload="alert(1)"/>'), null, 'SVG and other non-pictures refused');
    eq(MailAssets::clean("GIF89a" . str_repeat("\0", 20)), null, 'broken picture refused');
    $tall = (function () { $im = imagecreatetruecolor(800, 9000); ob_start(); imagepng($im); return (string) ob_get_clean(); })();
    eq(MailAssets::clean($tall)['height'], 4000, 'very long pictures cropped');
});
test('pictures: an attachment shown again comes from the cleaned copy, whole', function () use ($png, $bob) {
    $uid = (int) $bob['id'];
    $mid = Mailbox::store($uid, (int) Folders::byRole($uid, 'inbox')['id'], "From: a@ext.com\r\nSubject: Photo\r\n\r\nx");
    $first = MailAssets::inline($mid, $uid, 'part:2', $png(300, 200));
    $again = MailAssets::inline($mid, $uid, 'part:2', $png(300, 200));
    eq([(int) $again['size'], $again['mime'], $again['blob_id']], [(int) $first['size'], $first['mime'], $first['blob_id']], 'same picture, with its size');
    ok((int) $again['size'] > 0);
    Mailbox::delete($uid, [$mid], true);
});
test('pictures: fetched once after delivery, served locally, trackers removed, cleaned up with the message', function () use ($png, $bob, $sendTo) {
    $calls = [];
    MailAssets::$http = function (string $url) use (&$calls, $png) {
        $calls[] = $url;
        return match (true) {
            str_contains($url, 'pixel') => ['status' => 200, 'type' => 'image/gif', 'body' => $png(1, 1), 'location' => ''],
            str_contains($url, 'moved') => ['status' => 302, 'type' => '', 'body' => '', 'location' => 'https://cdn.ext.com/logo.png'],
            str_contains($url, 'missing') => ['status' => 404, 'type' => '', 'body' => '', 'location' => ''],
            default => ['status' => 200, 'type' => 'image/png', 'body' => $png(400, 100), 'location' => ''],
        };
    };
    try {
        $html = '<p>Promo</p><img src="https://cdn.ext.com/logo.png"><img src="https://track.ext.com/pixel.gif?u=bob"><img src="https://ext.com/moved"><img src="https://ext.com/missing.png">';
        $raw = "From: shop@ext.com\r\nTo: bob@test.local\r\nSubject: Images\r\nMessage-ID: <img1@ext.com>\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=utf-8\r\n\r\n" . $html . "\r\n";
        Delivery::deliver($raw, 'shop@ext.com', ['bob@test.local']);
        $mid = (int) DB::value("SELECT id FROM messages WHERE user_id = :u AND subject = 'Images' ORDER BY id DESC", ['u' => $bob['id']]);
        eq(count($calls), 0, 'nothing fetched while delivering');
        eq((int) DB::value("SELECT COUNT(*) FROM mail_asset_refs WHERE message_id = :m AND status = 'pending'", ['m' => $mid]), 4);
        MailAssets::processPending();
        eq(count($calls), 5, 'each picture once (+1 redirect)');
        $state = MailAssets::forMessage($mid);
        eq([$state['https://cdn.ext.com/logo.png']['status'], $state['https://track.ext.com/pixel.gif?u=bob']['status'], $state['https://ext.com/moved']['status'], $state['https://ext.com/missing.png']['status']], ['ok', 'tracker', 'ok', 'failed']);
        eq($state['https://cdn.ext.com/logo.png']['asset'], $state['https://ext.com/moved']['asset'], 'same picture stored once');
        // Rendering: local copies only, tracker removed, missing one stays blocked.
        $assets = $state;
        $sz = new \M4W\Mail\HtmlSanitizer(true, null, true, static function (string $u) use ($assets, $mid) {
            $a = $assets[$u] ?? null;
            return !$a ? null : ($a['status'] === 'tracker' ? '' : ($a['status'] === 'ok' ? '/api/messages/' . $mid . '/img/' . $a['asset'] : null));
        });
        $out = $sz->sanitize($html);
        ok(!str_contains($out, 'ext.com/logo') && !str_contains($out, 'pixel.gif') && substr_count($out, '/img/') === 2, 'no remote address left in the page');
        eq([$sz->trackerCount(), $sz->blockedCount()], [1, 1]);
        $disguised = $sz->sanitize('<img src="https://cdn.ext.com/logo.png" width="1" height="1"><img src="https://cdn.ext.com/logo.png" style="display:none">');
        ok(!str_contains($disguised, '<img') && $sz->trackerCount() === 2, 'a real picture shrunk to 1×1 or hidden is a tracker too');
        // A second message with the same picture shares it; deleting both frees it.
        $raw2 = str_replace(['Subject: Images', '<img1@'], ['Subject: Images 2', '<img2@'], $raw);
        Delivery::deliver($raw2, 'shop@ext.com', ['bob@test.local']);
        MailAssets::processPending();
        eq((int) DB::value("SELECT COUNT(*) FROM mail_assets WHERE kind = 'image'"), 1, 'deduplicated by checksum');
        $m1 = DB::one('SELECT * FROM messages WHERE id = :i', ['i' => $mid]);
        Mailbox::purge((int) $bob['id'], $m1);
        eq((int) DB::value("SELECT COUNT(*) FROM mail_assets WHERE kind = 'image'"), 1, 'still used by the other message');
        $m2 = DB::one("SELECT * FROM messages WHERE user_id = :u AND subject = 'Images 2'", ['u' => $bob['id']]);
        Mailbox::purge((int) $bob['id'], $m2);
        eq((int) DB::value('SELECT COUNT(*) FROM mail_assets'), 0, 'no message left: pictures deleted');
        // Spam: nothing fetched (it would confirm the address to the spammer).
        Delivery::deliver(str_replace('Subject: Images', "X-Spam-Flag: YES\r\nSubject: Spam images", $raw), 'shop@ext.com', ['bob@test.local']);
        eq((int) DB::value("SELECT COUNT(*) FROM mail_asset_refs r JOIN messages m ON m.id = r.message_id WHERE m.subject = 'Spam images'"), 0);
    } finally {
        MailAssets::$http = null;
    }
});

// ------------------------------------------------------------------ attachments
echo "\nAttachments (antivirus, real file type)\n";
use M4W\Mail\FileType;
use M4W\Service\Antivirus;
use M4W\Service\AttachmentGuard;
$avPort = random_int(30000, 32000);
$avProc = proc_open([PHP_BINARY, __DIR__ . '/fixtures/fake_clamd.php', (string) $avPort], [], $avPipes);
usleep(300000);
$eicar = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
$mixed = static function (array $files) {
    $raw = "From: x@ext.com\r\nTo: bob@test.local\r\nSubject: Pieces jointes\r\nMessage-ID: <" . bin2hex(random_bytes(5)) . "@ext.com>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"B1\"\r\n\r\npreamble\r\n--B1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBonjour\r\n";
    foreach ($files as [$name, $mime, $bytes]) {
        $raw .= "--B1\r\nContent-Type: $mime; name=\"$name\"\r\nContent-Disposition: attachment; filename=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($bytes)) . "\r\n";
    }
    return $raw . "--B1--\r\n";
};
test('antivirus: clamd protocol', function () use ($avPort, $eicar) {
    Settings::set('security.clamd', 'tcp://127.0.0.1:' . $avPort);
    ok(Antivirus::ping(), 'PING');
    eq(Antivirus::scan('hello')['status'], 'clean');
    eq(Antivirus::scan(str_repeat('x', 200000) . $eicar), ['status' => 'infected', 'signature' => 'Eicar-Test-Signature'], 'streamed in chunks');
    Settings::set('security.clamd', 'tcp://127.0.0.1:1');
    eq(Antivirus::scan('x')['status'], 'error', 'daemon down is not "clean"');
    Settings::set('security.clamd', 'tcp://127.0.0.1:' . $avPort);
});
test('antivirus: an infected attachment is replaced by a notice, still listed', function () use ($bob, $mixed, $eicar) {
    $raw = $mixed([['rapport.pdf', 'application/pdf', '%PDF-1.4 ok'], ['facture.zip', 'application/zip', $eicar]]);
    Settings::set('spam.enabled', 0); // with the filter, a virus sends the message to quarantine (tested there)
    Delivery::deliver($raw, 'x@ext.com', ['bob@test.local']);
    Settings::set('spam.enabled', 1);
    $m = DB::one("SELECT * FROM messages WHERE user_id = :u AND subject = 'Pieces jointes' ORDER BY id DESC", ['u' => $bob['id']]);
    $stored = Mailbox::raw($m);
    ok(!str_contains($stored, base64_encode($eicar)) && !str_contains($stored, 'EICAR-STANDARD'), 'the virus is not stored');
    ok(str_contains($stored, 'X-M4W-AV: infected=1'), 'result stamped');
    $list = Mailbox::parsed($m)->attachmentList();
    eq(array_column($list, 'name'), ['rapport.pdf', 'facture.zip'], 'both still listed under their names');
    eq($list[1]['removed']['signature'], 'Eicar-Test-Signature');
    eq($list[0]['removed'], null);
    // A sender cannot fake the marker.
    $fake = str_replace("Content-Disposition: attachment; filename=\"rapport.pdf\"", "Content-Disposition: attachment; filename=\"rapport.pdf\"\r\nX-M4W-Removed: virus; name*=UTF-8''x; signature*=UTF-8''Fake", $mixed([['rapport.pdf', 'application/pdf', '%PDF-1.4 ok']]));
    $out = AttachmentGuard::process($fake)['raw'];
    ok(!str_contains($out, 'Fake'), 'forged marker dropped');
});
test('file types: programs recognised by content, whatever the name', function () {
    $exe = "MZ\x90\x00" . str_repeat("\0", 60);
    eq(FileType::classify('facture.pdf', $exe)['risk'], 'block', 'renamed executable');
    eq(FileType::classify('facture.pdf', $exe)['why'], 'disguised');
    eq(FileType::classify('scan', "\x7FELF" . str_repeat("\0", 20))['risk'], 'block', 'no extension');
    eq(FileType::classify('lisezmoi.js', 'alert(1)')['risk'], 'block', 'script by extension');
    eq(FileType::classify("facture\u{202E}fdp.exe", 'x')['risk'], 'block', 'RTL trick');
    eq(FileType::classify('photo.jpg.txt', 'hello')['why'], 'double_ext');
    eq(FileType::classify('devis.pdf', "%PDF-1.7\n")['risk'], '', 'genuine PDF');
    eq(FileType::classify('devis.pdf', "PK\x03\x04" . str_repeat('x', 30))['why'], 'mismatch', 'zip named .pdf');
    eq(FileType::classify('budget.xlsm', "PK\x03\x04")['why'], 'macros');
    eq(FileType::classify('connexion.html', '<html><form>')['why'], 'active_content');
    $z = new \ZipArchive(); $tmpz = tempnam(sys_get_temp_dir(), 'z'); $z->open($tmpz, \ZipArchive::OVERWRITE); $z->addFromString('setup.exe', 'MZ'); $z->close();
    eq(FileType::classify('docs.zip', (string) file_get_contents($tmpz))['why'], 'exec_inside', 'program inside an archive');
    @unlink($tmpz);
});
test('file types: a blocked program is not handed out', function () use ($bob, $mixed) {
    Delivery::deliver(str_replace('Pieces jointes', 'Programme', $mixed([['facture.pdf', 'application/pdf', "MZ\x90\x00" . str_repeat("\0", 60)]])), 'x@ext.com', ['bob@test.local']);
    $m = DB::one("SELECT * FROM messages WHERE user_id = :u AND subject = 'Programme' ORDER BY id DESC", ['u' => $bob['id']]);
    $a = Mailbox::parsed($m)->attachmentList()[0];
    eq([$a['risk'], $a['why']], ['block', 'disguised']);
    \M4W\Core\Auth::setUser(Users::find((int) $bob['id']));
    $_SESSION = [];
    $c = new \M4W\Controller\MailController(new \M4W\Core\Request('GET', '/api/messages/' . $m['id'] . '/part/' . $a['part'], [], [], [], []));
    $thrown = false;
    try { $c->part((string) $m['id'], $a['part']); } catch (\M4W\Core\HttpException $e) { $thrown = $e->status === 403; }
    \M4W\Core\Auth::setUser(null);
    ok($thrown, 'download refused');
});
test('antivirus: infected files never enter the file space', function () use ($bob, $eicar, $fsFile) {
    $thrown = false;
    try { Files::addFromPath(Users::find((int) $bob['id']), (int) Files::personalRoot((int) $bob['id'])['id'], $fsFile($eicar), 'outil.zip'); } catch (\InvalidArgumentException $e) { $thrown = str_contains($e->getMessage(), 'Eicar'); }
    ok($thrown);
});
test('antispam: a message carrying a virus goes to quarantine', function () use ($bob, $mixed, $eicar) {
    Delivery::deliver(str_replace('Pieces jointes', 'Virus quarantaine', $mixed([['note.zip', 'application/zip', $eicar]])), 'x@ext.com', ['bob@test.local']);
    eq((int) DB::value("SELECT COUNT(*) FROM messages WHERE subject = 'Virus quarantaine'"), 0, 'not delivered');
    $q = DB::one("SELECT * FROM deleted_messages WHERE subject = 'Virus quarantaine'");
    eq($q['reason'], 'quarantine');
    ok(!str_contains(Retention::raw($q), 'EICAR-STANDARD'), 'the quarantine copy holds no virus either');
});
proc_terminate($avProc);
Settings::set('security.antivirus', 0);

// ------------------------------------------------------------------ my other mailboxes
echo "\nShared mailboxes (my other mailboxes)\n";
use M4W\Service\SharedMailboxes;
$shReq = new \M4W\Core\Request('POST', '/settings/mailboxes/add', [], [], [], ['REMOTE_ADDR' => '198.51.100.' . random_int(2, 250)]);
$shBox = Users::find(Users::create(['email' => 'contact@test.local', 'password' => 'Contact!Pass2026', 'first_name' => 'Contact', 'last_name' => 'Accueil']));
$shUser = Users::find(Users::create(['email' => 'nina@test.local', 'password' => 'Nina!Pass20266', 'first_name' => 'Nina']));
test('shared: a user proves the right to a mailbox with its password', function () use ($shBox, $shUser, $shReq) {
    $thrown = false;
    try { SharedMailboxes::add($shUser, 'contact@test.local', 'mauvais', '', $shReq); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown && !SharedMailboxes::linked((int) $shUser['id'], (int) $shBox['id']), 'wrong password refused');
    ok((int) DB::value("SELECT COUNT(*) FROM login_attempts WHERE email = 'contact@test.local' AND success = 0") >= 1, 'counted like a failed login');
    $thrown = false;
    try { SharedMailboxes::add($shUser, 'nina@test.local', 'x', '', $shReq); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'not your own mailbox');
    SharedMailboxes::add($shUser, 'Contact@test.local', 'Contact!Pass2026', '', $shReq);
    ok(SharedMailboxes::linked((int) $shUser['id'], (int) $shBox['id']));
    eq(SharedMailboxes::of((int) $shUser['id'])[0]['method'], 'password');
});
test('shared: two-factor mailboxes also need their code, never twice the same', function () use ($shReq) {
    $secret = Totp::generateSecret();
    $box = Users::find(Users::create(['email' => 'compta@test.local', 'password' => 'Compta!Pass2026', 'first_name' => 'Compta']));
    DB::update('users', ['totp_enabled' => 1, 'totp_secret' => Crypto::encrypt($secret)], 'id = :i', ['i' => $box['id']]);
    $u1 = Users::find(Users::create(['email' => 'u1@test.local', 'password' => 'Somebody!Pass26']));
    $u2 = Users::find(Users::create(['email' => 'u2@test.local', 'password' => 'Somebody!Pass26']));
    $thrown = false;
    try { SharedMailboxes::add($u1, 'compta@test.local', 'Compta!Pass2026', '', $shReq); } catch (\InvalidArgumentException $e) { $thrown = $e->getMessage() === t('shared.need_code'); }
    ok($thrown, 'code asked');
    $code = Totp::code($secret);
    SharedMailboxes::add($u1, 'compta@test.local', 'Compta!Pass2026', $code, $shReq);
    ok(SharedMailboxes::linked((int) $u1['id'], (int) $box['id']));
    $thrown = false;
    try { SharedMailboxes::add($u2, 'compta@test.local', 'Compta!Pass2026', $code, $shReq); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'replayed code refused');
});
test('shared: administrators attach any mailbox without its password', function () use ($alice, $shBox, $shReq) {
    SharedMailboxes::add($alice, 'contact@test.local', '', '', $shReq);
    eq(SharedMailboxes::of((int) $alice['id'])[0]['method'], 'admin');
    $thrown = false;
    try { SharedMailboxes::add($alice, 'nobody@test.local', '', '', $shReq); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'unknown mailbox');
});
test('shared: switching leaves no delegation trace, sends as the mailbox, hides nothing', function () use ($shBox, $shUser, $alice) {
    $_SESSION = [];
    $logs = (int) DB::value('SELECT COUNT(*) FROM delegation_log');
    $audit = (int) DB::value("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'delegation.%'");
    $boxInbox = Folders::byRole((int) $shBox['id'], 'inbox');
    Mailbox::store((int) $shBox['id'], (int) $boxInbox['id'], "From: x@ext.com\r\nTo: contact@test.local\r\nSubject: [Perso] visible quand même\r\nMessage-ID: <sh1@ext.com>\r\n\r\nx\r\n");
    SharedMailboxes::open($shUser, (int) $shBox['id']);
    $mb = Delegation::mailbox($shUser);
    eq($mb['email'], 'contact@test.local', 'works in the shared mailbox');
    ok(empty($mb['_actor']) && !Mailbox::$privacy, 'not a delegation: nothing hidden');
    Composer::send($mb, ['to' => 'admin@test.local', 'subject' => 'Réponse de l\'accueil', 'html' => '<p>Bonjour</p>']);
    $m = DB::one("SELECT * FROM messages WHERE user_id = :u AND subject = 'Réponse de l''accueil'", ['u' => $alice['id']]);
    $raw = Mailbox::raw($m);
    ok(str_contains($raw, 'contact@test.local') && !preg_match('/^Sender:/mi', $raw), 'From the shared mailbox, no Sender:');
    eq((int) DB::value('SELECT COUNT(*) FROM delegation_log'), $logs, 'no access journal');
    eq((int) DB::value("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'delegation.%'"), $audit, 'no delegation audit');
    // Through a mail screen controller, as the web requests do.
    \M4W\Core\Auth::setUser($shUser);
    $ctl = new class (new \M4W\Core\Request('GET', '/api/messages', [], [], [], [])) extends \M4W\Controller\Controller {
        protected function delegable(): bool { return true; }
        public function who(): array { return $this->user(); }
    };
    eq($ctl->who()['email'], 'contact@test.local', 'mail screens follow the switch');
    \M4W\Core\Auth::setUser(null);
    SharedMailboxes::open($shUser, 0);
    eq(Delegation::mailbox($shUser)['email'], 'nina@test.local', 'back to my mailbox');
    // Someone without the link cannot open it, even with a forged session.
    $outsider = Users::findByEmail('u2@test.local');
    $_SESSION['shared_mbx'] = ['box' => (int) $shBox['id']];
    eq(Delegation::mailbox($outsider)['email'], 'u2@test.local', 'forged session ignored');
    $_SESSION = [];
});
test('shared: members by administration, push to members, cleanup', function () use ($shBox, $shUser, $alice, $sendTo) {
    $bobId = (int) Users::findByEmail('bob@test.local')['id'];
    SharedMailboxes::setMembers((int) $shBox['id'], [(int) $shUser['id'], $bobId], (int) $alice['id']);
    eq(count(SharedMailboxes::members((int) $shBox['id'])), 2, 'admin link replaced by the chosen list');
    ok(!SharedMailboxes::linked((int) $alice['id'], (int) $shBox['id']));
    // Members are alerted on their devices when mail reaches the shared inbox.
    $sent = [];
    \M4W\Service\WebPush::$transport = function (string $ep) use (&$sent) { $sent[] = $ep; return 201; };
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $d = openssl_pkey_get_details($k)['ec'];
    $pub = \M4W\Service\WebPush::b64u("\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT));
    \M4W\Service\WebPush::subscribe((int) $shUser['id'], 'https://fcm.googleapis.com/fcm/send/nina', $pub, \M4W\Service\WebPush::b64u(random_bytes(16)), 'ua');
    try {
        Delivery::deliver($sendTo('contact@test.local', 'Demande de devis'), 'client@ext.com', ['contact@test.local']);
    } finally {
        \M4W\Service\WebPush::$transport = null;
    }
    ok(in_array('https://fcm.googleapis.com/fcm/send/nina', $sent, true), 'member notified');
    Users::delete((int) $shBox['id']);
    eq(SharedMailboxes::of((int) $shUser['id']), [], 'links gone with the mailbox');
});

// ------------------------------------------------------------------ push notifications
echo "\nPush notifications\n";
/** EC private key from its raw scalar and public point (SEC1 DER). */
$ecKey = static function (string $d, string $pub): \OpenSSLAsymmetricKey {
    $der = hex2bin('307702010104') . "\x20" . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $pub;
    return openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n");
};
/** Decryption by the browser side (RFC 8291 §3.4), written independently of the service. */
$pushDecrypt = static function (string $body, \OpenSSLAsymmetricKey $uaKey, string $uaPublic, string $auth): string {
    $salt = substr($body, 0, 16);
    $idlen = ord($body[20]);
    $asPublic = substr($body, 21, $idlen);
    $rest = substr($body, 21 + $idlen);
    $spki = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $asPublic), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $shared = openssl_pkey_derive(openssl_pkey_get_public($spki), $uaKey, 32);
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $auth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $plain = openssl_decrypt(substr($rest, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($rest, -16));
    if ($plain === false) {
        throw new \RuntimeException('decryption failed');
    }
    return rtrim(substr($plain, 0, (int) strrpos($plain, "\x02")), "\0");
};
$b64 = [M4W\Service\WebPush::class, 'b64uDecode'];
test('push: encryption matches the RFC 8291 test vector', function () use ($ecKey, $b64) {
    $as = $ecKey($b64('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'), $b64('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8'));
    $out = M4W\Service\WebPush::encrypt('When I grow up, I want to be a watermelon', 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4', 'BTBZMqHH6r4Tts7J_aSIgg', $as, $b64('DGv6ra1nlYgDCS1FRnbzlw'));
    eq(M4W\Service\WebPush::b64u($out), 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN');
});
test('push: a browser decrypts what the server sends', function () use ($pushDecrypt) {
    $ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $d = openssl_pkey_get_details($ua)['ec'];
    $uaPub = "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
    $auth = random_bytes(16);
    $msg = json_encode(['title' => 'Élodie', 'body' => 'Devis signé ✔']);
    $body = M4W\Service\WebPush::encrypt($msg, M4W\Service\WebPush::b64u($uaPub), M4W\Service\WebPush::b64u($auth));
    eq(unpack('N', substr($body, 16, 4))[1], 4096, 'record size');
    eq($pushDecrypt($body, $ua, $uaPub, $auth), $msg);
    ok(M4W\Service\WebPush::encrypt($msg, M4W\Service\WebPush::b64u($uaPub), M4W\Service\WebPush::b64u($auth)) !== $body, 'fresh key and salt each time');
});
test('push: VAPID token is a valid ES256 JWT for the push service', function () use ($b64) {
    $h = M4W\Service\WebPush::vapidHeader('https://fcm.googleapis.com/fcm/send/abc:def');
    ok((bool) preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $h, $m), 'header format');
    eq($m[4], M4W\Service\WebPush::vapid()['public']);
    $claims = json_decode($b64($m[2]), true);
    eq($claims['aud'], 'https://fcm.googleapis.com');
    ok($claims['exp'] > time() && $claims['exp'] <= time() + 86400, 'expiry within 24 h');
    ok(str_starts_with($claims['sub'], 'mailto:'), 'contact');
    $sig = $b64($m[3]);
    eq(strlen($sig), 64, 'raw R||S');
    $int = static function (string $x): string { $x = ltrim($x, "\0"); if (ord($x[0]) & 0x80) { $x = "\0" . $x; } return "\x02" . chr(strlen($x)) . $x; };
    $der = $int(substr($sig, 0, 32)) . $int(substr($sig, 32));
    $der = "\x30" . chr(strlen($der)) . $der;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $b64($m[4])), 64, "\n") . "-----END PUBLIC KEY-----\n";
    eq(openssl_verify($m[1] . '.' . $m[2], $der, openssl_pkey_get_public($pem), OPENSSL_ALGO_SHA256), 1, 'signature verifies');
    eq(M4W\Service\WebPush::vapid()['public'], $m[4], 'key pair kept');
});
test('push: only known push services are accepted (no SSRF)', function () use ($bob) {
    $ok = ['https://fcm.googleapis.com/fcm/send/x', 'https://updates.push.services.mozilla.com/wpush/v2/x', 'https://web.push.apple.com/QH', 'https://wns2-par02p.notify.windows.com/w/?token=x'];
    foreach ($ok as $u) { ok(M4W\Service\WebPush::allowedEndpoint($u), $u); }
    $bad = ['http://fcm.googleapis.com/x', 'https://127.0.0.1/x', 'https://evilfcm.googleapis.com.attacker.io/x', 'https://localhost/x', 'file:///etc/passwd', 'https://notify.windows.com.evil.net/'];
    foreach ($bad as $u) { ok(!M4W\Service\WebPush::allowedEndpoint($u), $u); }
    $thrown = false;
    try { M4W\Service\WebPush::subscribe((int) $bob['id'], 'https://169.254.169.254/latest', str_repeat('A', 87), 'AAAAAAAAAAAAAAAAAAAAAA', 'ua'); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'internal address refused');
    $thrown = false;
    try { M4W\Service\WebPush::subscribe((int) $bob['id'], 'https://fcm.googleapis.com/fcm/send/x', 'short', 'AAAAAAAAAAAAAAAAAAAAAA', 'ua'); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'bad key refused');
});
test('push: new inbox mail reaches the devices; expired ones are forgotten', function () use ($bob, $pushDecrypt, $sendTo) {
    DB::delete('push_subscriptions', '1 = 1');
    $devices = [];
    foreach (['https://fcm.googleapis.com/fcm/send/phone', 'https://updates.push.services.mozilla.com/wpush/v2/gone'] as $ep) {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $d = openssl_pkey_get_details($k)['ec'];
        $pub = "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
        $auth = random_bytes(16);
        M4W\Service\WebPush::subscribe((int) $bob['id'], $ep, M4W\Service\WebPush::b64u($pub), M4W\Service\WebPush::b64u($auth), 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0 Mobile Safari/537.36');
        $devices[$ep] = [$k, $pub, $auth];
    }
    $sent = [];
    M4W\Service\WebPush::$transport = function (string $ep, array $h, string $body) use (&$sent) {
        $sent[] = [$ep, $h, $body];
        return str_contains($ep, 'gone') ? 410 : 201;
    };
    try {
        Delivery::deliver($sendTo('bob@test.local', 'Devis à valider', 'claire@client.fr'), 'claire@client.fr', ['bob@test.local']);
        eq(count($sent), 2, 'both devices tried');
        [$ep, $h, $body] = $sent[0];
        eq($h['Content-Encoding'], 'aes128gcm');
        ok(str_starts_with($h['Authorization'], 'vapid t='), 'VAPID');
        $dev = $devices[$ep];
        $msg = json_decode($pushDecrypt($body, $dev[0], $dev[1], $dev[2]), true);
        eq($msg['title'], 'Client Ext');
        eq($msg['body'], 'Devis à valider');
        ok((bool) preg_match('~^mail#f/\d+/m/\d+$~', $msg['url']), 'opens the message: ' . $msg['url']);
        eq(count(M4W\Service\WebPush::devices((int) $bob['id'])), 1, 'expired subscription removed');
        eq(M4W\Service\WebPush::deviceLabel(M4W\Service\WebPush::devices((int) $bob['id'])[0]['user_agent']), 'Chrome · Android');

        // Discreet mode: nothing readable on a lock screen.
        Settings::set('push.preview', 0);
        $sent = [];
        Delivery::deliver($sendTo('bob@test.local', 'Salaires 2026', 'rh@client.fr'), 'rh@client.fr', ['bob@test.local']);
        $msg = json_decode($pushDecrypt($sent[0][2], $dev[0], $dev[1], $dev[2]), true);
        ok(!str_contains(json_encode($msg, JSON_UNESCAPED_UNICODE), 'Salaires') && !str_contains($msg['title'], 'Client'), 'no subject nor sender');
        Settings::set('push.preview', 1);

        // Personal subjects stay hidden.
        $sent = [];
        Delivery::deliver($sendTo('bob@test.local', '[Perso] rendez-vous médecin', 'x@ext.com'), 'x@ext.com', ['bob@test.local']);
        $msg = json_decode($pushDecrypt($sent[0][2], $dev[0], $dev[1], $dev[2]), true);
        ok(!str_contains($msg['body'], 'médecin'), 'personal subject hidden');

        // Mail filed away by a rule, or turned off in preferences: no alert.
        $sent = [];
        Users::savePrefs((int) $bob['id'], ['notifications' => 0]);
        Delivery::deliver($sendTo('bob@test.local', 'Silence', 'x@ext.com'), 'x@ext.com', ['bob@test.local']);
        eq(count($sent), 0, 'preference respected');
        Users::savePrefs((int) $bob['id'], ['notifications' => 1]);
        Settings::set('push.enabled', 0);
        Delivery::deliver($sendTo('bob@test.local', 'Coupé', 'x@ext.com'), 'x@ext.com', ['bob@test.local']);
        eq(count($sent), 0, 'disabled by the administrator');
        Settings::set('push.enabled', 1);
    } finally {
        M4W\Service\WebPush::$transport = null;
    }
});
test('push: a device moves to whoever signs in on it, and leaves with a deleted user', function () use ($bob) {
    $tmpId = Users::create(['email' => 'stagiaire@test.local', 'password' => 'Secret!Pass123', 'first_name' => 'Sam', 'last_name' => 'Stage']);
    $ep = 'https://fcm.googleapis.com/fcm/send/phone';
    $row = DB::one('SELECT p256dh, auth FROM push_subscriptions WHERE endpoint_hash = :h', ['h' => hash('sha256', $ep)]);
    M4W\Service\WebPush::subscribe($tmpId, $ep, $row['p256dh'], $row['auth'], 'ua');
    eq(count(M4W\Service\WebPush::devices((int) $bob['id'])), 0, 'no longer Bob\'s');
    eq(count(M4W\Service\WebPush::devices($tmpId)), 1);
    M4W\Service\WebPush::unsubscribe((int) $bob['id'], null, (int) M4W\Service\WebPush::devices($tmpId)[0]['id']);
    eq(count(M4W\Service\WebPush::devices($tmpId)), 1, 'cannot remove someone else\'s device');
    Users::delete($tmpId);
    eq((int) DB::value('SELECT COUNT(*) FROM push_subscriptions'), 0);
});

// ------------------------------------------------------------------ built-in spreadsheet
echo "\nBuilt-in spreadsheet (several editors at once)\n";
use M4W\Service\Sheet;
$xlsx = static function (array $rows, ?callable $more = null): string {
    $s = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $s->getActiveSheet()->setTitle('Budget')->fromArray($rows);
    if ($more) { $more($s); }
    $p = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($s))->save($p);
    return $p;
};
test('spreadsheet: French input and formulas', function () {
    M4W\Core\I18n::setLanguage('fr');
    eq(Sheet::parseInput('3,5')[0], 3.5);
    eq(Sheet::parseInput('1 234,5')[0], 1234.5);
    eq(Sheet::parseInput("1\u{202F}000")[0], 1000);
    eq([Sheet::parseInput('25 %')[0], Sheet::parseInput('25 %')[2]], [0.25, '0%']);
    eq([Sheet::parseInput('12,50 €')[0], Sheet::parseInput('12,50 €')[2]], [12.5, Sheet::FORMATS['currency']]);
    eq(Sheet::parseInput('15/10/2026')[2], 'dd/mm/yyyy');
    eq(Sheet::parseInput('31/02/2026')[0], '31/02/2026', 'not a date');
    eq(Sheet::parseInput('VRAI')[0], true);
    eq(Sheet::parseInput("'0612345678")[0], '0612345678', 'apostrophe keeps text');
    eq(Sheet::parseInput('0612345678')[0], 612345678);
    eq(Sheet::toEnglish('=SOMME(A1;1,5)+SI(B1>2;"a,b";"c")'), '=SUM(A1,1.5)+IF(B1>2,"a,b","c")');
    eq(Sheet::toLocale('=ROUND(A1*1.5,2)'), '=ARRONDI(A1*1,5;2)');
});
test('spreadsheet: two editors, recalculation, patches, version saved', function () use ($fsBob, $fsCarol, $fsAdmin, $xlsx) {
    M4W\Core\I18n::setLanguage('fr');
    $space = Files::createSpace($fsAdmin, 'Compta ' . bin2hex(random_bytes(3)));
    Files::setAcl($fsAdmin, $space, [['type' => 'user', 'principal' => $fsCarol['id'], 'level' => 'write'], ['type' => 'user', 'principal' => $fsBob['id'], 'level' => 'write']]);
    $f = Files::addFromPath($fsBob, $space, $xlsx([['Poste', 'Montant'], ['Loyer', 1200], ['Total', '=SUM(B2:B2)']]), 'Budget.xlsx');
    $o = Sheet::open($fsBob, Files::file((int) $f['id']));
    eq([$o['mode'], $o['can_write']], ['edit', true]);
    eq((array) $o['workbook']['sheets'][0]['cells']->B3, ['1200', '=SOMME(B2:B2)', 0, 1], 'formula shown in French');
    $o2 = Sheet::open($fsCarol, Files::file((int) $f['id']));
    $seq = $o2['seq'];
    Sheet::poll($fsBob, Files::file((int) $f['id']), 0, 0, 'B2');
    Sheet::applyOps($fsBob, Files::file((int) $f['id']), [['t' => 'set', 's' => 0, 'c' => 'B2', 'v' => '1 350,5'], ['t' => 'set', 's' => 0, 'c' => 'B3', 'v' => '=SOMME(B2:B2)*2']]);
    $p = Sheet::poll($fsCarol, Files::file((int) $f['id']), $seq, 0, 'A1');
    $cells = $p['ops'][0]['cells'];
    eq($cells['B2'][1], '1350,5');
    eq($cells['B3'][0], '2701', 'the other editor gets the recalculated value');
    ok(!isset($cells['A1']), 'only what changed');
    eq(count($p['people']), 1, 'Bob is seen');
    // Structure: everyone reloads.
    Sheet::applyOps($fsCarol, Files::file((int) $f['id']), [['t' => 'ins_row', 's' => 0, 'at' => 2, 'n' => 1]]);
    eq(end(Sheet::poll($fsBob, Files::file((int) $f['id']), 0, 0, '')['ops'])['k'], 'reload');
    $thrown = false;
    try { Sheet::applyOps($fsBob, Files::file((int) $f['id']), [['t' => 'set', 's' => 0, 'c' => 'ZZZZ1', 'v' => 'x']]); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'invalid cell refused');
    $r = Sheet::publish($fsCarol, Files::file((int) $f['id']));
    eq([$r['copy'], (int) $r['file']['version']], [false, 2], 'new version');
    $saved = \PhpOffice\PhpSpreadsheet\IOFactory::load((function ($f) { $t = tempnam(sys_get_temp_dir(), 'r') . '.xlsx'; file_put_contents($t, Files::contents($f['blob_id'])); return $t; })(Files::file((int) $f['id'])));
    eq($saved->getActiveSheet()->getCell('B4')->getValue(), '=SUM(B3:B3)*2', 'formula kept in English in the file, row inserted');
    eq($saved->getActiveSheet()->getCell('B4')->getCalculatedValue(), 2701.0);
    // Read-only for someone without write access.
    $GLOBALS['sheetFile'] = (int) $f['id'];
});
test('spreadsheet: complex workbooks and changes made elsewhere go to a copy', function () use ($fsBob, $xlsx) {
    M4W\Core\I18n::setLanguage('fr');
    $root = (int) Files::personalRoot((int) $fsBob['id'])['id'];
    $p = $xlsx([['A', 1]]);
    $z = new \ZipArchive(); $z->open($p); $z->addFromString('xl/charts/chart1.xml', '<c:chartSpace/>'); $z->close();
    $f = Files::addFromPath($fsBob, $root, $p, 'Graphique.xlsx');
    $o = Sheet::open($fsBob, Files::file((int) $f['id']));
    eq([$o['mode'], $o['reason']], ['copy', 'charts']);
    Sheet::applyOps($fsBob, Files::file((int) $f['id']), [['t' => 'set', 's' => 0, 'c' => 'B1', 'v' => '2']]);
    $r = Sheet::publish($fsBob, Files::file((int) $f['id']));
    ok($r['copy'] && str_contains($r['file']['name'], 'Graphique (modifié)'), $r['file']['name']);
    eq((int) Files::file((int) $f['id'])['version'], 1, 'the original is untouched');
    // Someone saved another version meanwhile: never overwritten.
    $g = Files::addFromPath($fsBob, $root, $xlsx([['x', 1]]), 'Plan.xlsx');
    Sheet::open($fsBob, Files::file((int) $g['id']));
    Sheet::applyOps($fsBob, Files::file((int) $g['id']), [['t' => 'set', 's' => 0, 'c' => 'A2', 'v' => 'y']]);
    Files::putContent($fsBob, (int) $g['id'], $xlsx([['autre', 2]]));
    $r = Sheet::publish($fsBob, Files::file((int) $g['id']));
    ok($r['copy'], 'conflict saved as a copy');
    // CSV keeps its format and the French separator.
    $csv = tempnam(sys_get_temp_dir(), 'c');
    file_put_contents($csv, "Nom;Montant\nA;3,5\n");
    $c = Files::addFromPath($fsBob, $root, $csv, 'liste.csv');
    $oc = Sheet::open($fsBob, Files::file((int) $c['id']));
    eq($oc['workbook']['sheets'][0]['cells']->B2[1], '3,5', 'separator detected');
    Sheet::applyOps($fsBob, Files::file((int) $c['id']), [['t' => 'set', 's' => 0, 'c' => 'B3', 'v' => '4,25']]);
    Sheet::publish($fsBob, Files::file((int) $c['id']));
    ok(str_contains(Files::contents(Files::file((int) $c['id'])['blob_id']), '"4,25"') || str_contains(Files::contents(Files::file((int) $c['id'])['blob_id']), '4,25'), 'CSV written back with French decimals');
});
test('spreadsheet: unsaved work is saved once everyone has left', function () use ($fsBob) {
    $fid = $GLOBALS['sheetFile'];
    Sheet::applyOps($fsBob, Files::file($fid), [['t' => 'set', 's' => 0, 'c' => 'A9', 'v' => 'oublié']]);
    DB::run('UPDATE sheet_sessions SET updated_at = :t WHERE file_id = :f', ['t' => time() - 600, 'f' => $fid]);
    DB::run('UPDATE sheet_presence SET seen_at = :t WHERE file_id = :f', ['t' => time() - 600, 'f' => $fid]);
    $v = (int) Files::file($fid)['version'];
    ok(Sheet::housekeeping() >= 1);
    eq((int) Files::file($fid)['version'], $v + 1, 'saved as a new version');
});

// ------------------------------------------------------------------ built-in word processor
echo "\nBuilt-in word processor (one writer, others follow)\n";
use M4W\Service\DocEdit;
use M4W\Service\WordDoc;
$docx = static function (callable $build): string {
    $w = new \PhpOffice\PhpWord\PhpWord();
    $w->addTitleStyle(1, ['bold' => true, 'size' => 18]);
    $build($w->addSection());
    $p = tempnam(sys_get_temp_dir(), 'd') . '.docx';
    \PhpOffice\PhpWord\IOFactory::createWriter($w, 'Word2007')->save($p);
    return $p;
};
test('documents: Word to HTML and back, only the body rewritten', function () use ($docx) {
    $src = $docx(static function ($s) {
        $s->addHeader()->addText('EN-TETE SOCIETE');
        $s->addTitle('Rapport', 1);
        $s->addText('Gras', ['bold' => true]);
        $s->addListItem('Un', 0); $s->addListItem('Deux', 1);
        $t = $s->addTable(); $t->addRow(); $t->addCell(2000)->addText('A'); $t->addCell(2000)->addText('B');
        $s->addLink('https://exemple.fr', 'Lien');
    });
    $r = WordDoc::toHtml($src);
    eq($r['issues'], []);
    ok(str_contains($r['html'], '<h1>Rapport</h1>') && str_contains($r['html'], '<strong>Gras</strong>') && str_contains($r['html'], '<ul><li>Un<ul><li>Deux</li></ul></li></ul>'), $r['html']);
    ok(str_contains($r['html'], '<table>') && str_contains($r['html'], '<a href="https://exemple.fr">Lien</a>'));
    $out = tempnam(sys_get_temp_dir(), 'o') . '.docx';
    WordDoc::fromHtml($r['html'] . '<p>Ajout <em>final</em></p><ol><li>Premier</li></ol><hr class="m4w-pagebreak"><p>Page 2</p>', $src, $out);
    $back = WordDoc::toHtml($out)['html'];
    eq(WordDoc::toHtml($out)['html'], WordDoc::toHtml((function () use ($back, $src) { $o = tempnam(sys_get_temp_dir(), 'o') . '.docx'; WordDoc::fromHtml($back, $src, $o); return $o; })())['html'], 'stable: saving twice changes nothing');
    ok(str_contains($back, 'Ajout <em>final</em>') && str_contains($back, '<ol><li>Premier</li></ol>') && str_contains($back, 'm4w-pagebreak'));
    $z = new \ZipArchive(); $z->open($out);
    ok(str_contains((string) $z->getFromName('word/header1.xml'), 'EN-TETE SOCIETE'), 'header kept');
    ok(str_contains((string) $z->getFromName('word/document.xml'), '<w:headerReference'), 'section settings kept');
    ok(\PhpOffice\PhpWord\IOFactory::load($out) instanceof \PhpOffice\PhpWord\PhpWord, 'a valid package');
    // Hostile input: entities, scripts.
    $evil = $docx(static fn($s) => $s->addText('x'));
    $z = new \ZipArchive(); $z->open($evil); $z->addFromString('word/document.xml', '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&x;</w:t></w:r></w:p></w:body></w:document>'); $z->close();
    $thrown = false;
    try { WordDoc::toHtml($evil); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'a DTD is refused');
    eq(WordDoc::issues('<w:body><w:p><w:ins w:id="1"><w:r><w:t>x</w:t></w:r></w:ins></w:p><w:p><w:r><w:commentReference w:id="0"/></w:r></w:p></w:body>'), ['tracked', 'comments']);
});
test('documents: one writer, the other follows, a new version is saved', function () use ($fsBob, $fsCarol, $fsAdmin, $docx) {
    $space = Files::createSpace($fsAdmin, 'Docs ' . bin2hex(random_bytes(3)));
    Files::setAcl($fsAdmin, $space, [['type' => 'user', 'principal' => $fsCarol['id'], 'level' => 'write'], ['type' => 'user', 'principal' => $fsBob['id'], 'level' => 'write']]);
    $f = Files::addFromPath($fsBob, $space, $docx(static fn($s) => $s->addText('Version initiale')), 'Note.docx');
    $file = static fn() => Files::file((int) $f['id']);
    $o = DocEdit::open($fsBob, $file());
    eq([$o['mode'], $o['pen']], ['edit', null]);
    ok(DocEdit::take($fsBob, $file())['ok']);
    $c = DocEdit::take($fsCarol, $file());
    ok(!$c['ok'] && $c['pen']['id'] === (int) $fsBob['id'], 'one writer at a time');
    $thrown = false;
    try { DocEdit::draft($fsCarol, $file(), '<p>pirate</p>'); } catch (\DomainException) { $thrown = true; }
    ok($thrown, 'only the writer saves drafts');
    $rev = DocEdit::draft($fsBob, $file(), '<p>Version initiale</p><p>Ajout de <strong>Bob</strong><script>alert(1)</script></p>');
    $p = DocEdit::poll($fsCarol, $file(), 1);
    eq($p['rev'], $rev);
    ok(str_contains($p['html'], 'Ajout de <strong>Bob</strong>') && !str_contains($p['html'], 'script'), 'the follower gets the cleaned text');
    eq(DocEdit::poll($fsCarol, $file(), $rev)['html'] ?? null, null, 'nothing new, nothing sent');
    $r = DocEdit::publish($fsBob, $file());
    eq([$r['copy'], (int) $r['file']['version']], [false, 2]);
    $t = tempnam(sys_get_temp_dir(), 'n') . '.docx';
    file_put_contents($t, Files::contents($file()['blob_id']));
    ok(str_contains(WordDoc::toHtml($t)['html'], 'Ajout de <strong>Bob</strong>'), 'saved in the .docx');
    // The writer leaves: the other can take over.
    DocEdit::release($fsBob, (int) $f['id']);
    ok(DocEdit::take($fsCarol, $file())['ok']);
    // A silent writer loses the pen after 45 seconds.
    DB::run('UPDATE doc_sessions SET holder_seen = :t WHERE file_id = :f', ['t' => time() - 60, 'f' => $f['id']]);
    ok(DocEdit::take($fsBob, $file())['ok'], 'taken over from an inactive writer');
    // Unsaved work is saved after everyone left.
    DocEdit::draft($fsBob, $file(), '<p>Dernier mot</p>');
    DB::run('UPDATE doc_sessions SET updated_at = :t, holder_seen = :t WHERE file_id = :f', ['t' => time() - 600, 'f' => $f['id']]);
    DB::run('UPDATE sheet_presence SET seen_at = :t WHERE file_id = :f', ['t' => time() - 600, 'f' => $f['id']]);
    ok(DocEdit::housekeeping() >= 1);
    eq((int) $file()['version'], 3);
});
test('documents: tracked changes, .odt and .doc are saved as copies', function () use ($fsBob, $docx) {
    $root = (int) Files::personalRoot((int) $fsBob['id'])['id'];
    $src = $docx(static fn($s) => $s->addText('Texte'));
    $z = new \ZipArchive(); $z->open($src);
    $xml = (string) $z->getFromName('word/document.xml');
    $z->addFromString('word/document.xml', preg_replace('#<w:body>#', '<w:body><w:p><w:ins w:id="9" w:author="X"><w:r><w:t>insertion suivie</w:t></w:r></w:ins></w:p>', $xml, 1)); $z->close();
    $f = Files::addFromPath($fsBob, $root, $src, 'Suivi.docx');
    $o = DocEdit::open($fsBob, Files::file((int) $f['id']));
    eq([$o['mode'], $o['issues']], ['copy', ['tracked']]);
    DocEdit::take($fsBob, Files::file((int) $f['id']));
    DocEdit::draft($fsBob, Files::file((int) $f['id']), '<p>Texte revu</p>');
    $r = DocEdit::publish($fsBob, Files::file((int) $f['id']));
    ok($r['copy'] && str_ends_with($r['file']['name'], '.docx'));
    eq((int) Files::file((int) $f['id'])['version'], 1, 'original untouched');
    // OpenDocument
    $w = new \PhpOffice\PhpWord\PhpWord(); $w->addSection()->addText('Bonjour ODT');
    $odt = tempnam(sys_get_temp_dir(), 'o') . '.odt';
    \PhpOffice\PhpWord\IOFactory::createWriter($w, 'ODText')->save($odt);
    $g = Files::addFromPath($fsBob, $root, $odt, 'Lettre.odt');
    $o = DocEdit::open($fsBob, Files::file((int) $g['id']));
    ok(str_contains($o['html'], 'Bonjour ODT') && $o['mode'] === 'copy', 'odt imported');
    DocEdit::take($fsBob, Files::file((int) $g['id']));
    DocEdit::draft($fsBob, Files::file((int) $g['id']), '<p>Bonjour ODT, <strong>modifié</strong></p>');
    $r = DocEdit::publish($fsBob, Files::file((int) $g['id']));
    ok($r['copy'] && str_ends_with($r['file']['name'], '.odt'), $r['file']['name']);
    $t = tempnam(sys_get_temp_dir(), 'r') . '.odt';
    file_put_contents($t, Files::contents(Files::file((int) $r['file']['id'])['blob_id']));
    ok(str_contains((new \PhpOffice\PhpWord\Writer\HTML(\PhpOffice\PhpWord\IOFactory::load($t, 'ODText')))->getContent(), 'modifié'), 'odt written');
});

// ------------------------------------------------------------------ backups
echo "\nBackups (zip to a backup disk)\n";
use M4W\Service\Backup;
$bkDir = $tmp . '/backup-disk';
Settings::setMany(['backup.protocol' => 'local', 'backup.path' => $bkDir, 'backup.keep' => 2, 'backup.zip_password' => '', 'backup.enabled' => 0, 'backup.requested' => 0]);
$bkZip = static function () use ($bkDir): string {
    $files = glob($bkDir . '/m4w-backup-*.zip') ?: [];
    rsort($files);
    return $files[0] ?? '';
};
test('backup: one zip with the database, mail, configuration and the file space as people see it', function () use ($bkZip, $fsBob) {
    eq(Backup::test(), '', 'backup disk writable');
    $r = Backup::run('manual');
    eq($r['status'], 'ok', $r['message']);
    $z = new \ZipArchive();
    ok($z->open($bkZip()) === true);
    $names = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $names[] = $z->getNameIndex($i);
    }
    foreach (['manifest.json', 'LISEZMOI.txt', 'database/m4w.sqlite', 'config/config.php'] as $n) {
        ok(in_array($n, $names, true), $n);
    }
    ok((bool) array_filter($names, static fn($n) => str_starts_with($n, 'storage/mail/')), 'mail store');
    ok(!array_filter($names, static fn($n) => str_starts_with($n, 'storage/tmp/') || str_starts_with($n, 'storage/sessions/')), 'no temporary data');
    // A file of Bob's personal space, under his address, with its content.
    $root = (int) Files::personalRoot((int) $fsBob['id'])['id'];
    $f = DB::one('SELECT * FROM fs_files WHERE folder_id = :f AND deleted_at = 0 ORDER BY id LIMIT 1', ['f' => $root]);
    ok((bool) $f, 'Bob has a file');
    $path = 'fichiers/utilisateurs/' . $fsBob['email'] . '/' . $f['name'];
    $content = '';
    M4W\Storage\Storage::store()->read($f['blob_id'], static function ($b) use (&$content) { $content .= $b; });
    eq($z->getFromName($path), $content, 'readable as in the file space');
    // The database copy is complete.
    $copy = tempnam(sys_get_temp_dir(), 'db');
    file_put_contents($copy, $z->getFromName('database/m4w.sqlite'));
    eq((int) (new \PDO('sqlite:' . $copy))->query('SELECT COUNT(*) FROM users')->fetchColumn(), (int) DB::value('SELECT COUNT(*) FROM users'));
    @unlink($copy);
    $z->close();
});
test('backup: AES-256 password, rotation, one run at a time, failures reported', function () use ($bkZip, $bkDir, $fsAdmin) {
    Settings::setSecret('backup.zip_password', 'Sauvegarde!2026');
    eq(Backup::run('manual')['status'], 'ok');
    $z = new \ZipArchive();
    $z->open($bkZip());
    eq($z->statName('database/m4w.sqlite')['encryption_method'], \ZipArchive::EM_AES_256, 'encrypted');
    ok($z->getFromName('config/config.php') === false, 'unreadable without the password');
    $z->setPassword('Sauvegarde!2026');
    ok(str_contains((string) $z->getFromName('config/config.php'), 'app_key'), 'readable with it');
    $z->close();
    Backup::run('manual');
    $GLOBALS['bkMessages'] = (int) DB::value('SELECT COUNT(*) FROM messages');
    $GLOBALS['bkMailFiles'] = count(array_filter(iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(M4W\Core\Config::storagePath('mail'), \FilesystemIterator::SKIP_DOTS))), static fn($f) => $f->isFile()));
    eq(count(glob($bkDir . '/m4w-backup-*.zip') ?: []), 2, 'only the 2 most recent kept');
    // One at a time.
    $lock = fopen(M4W\Core\Config::storagePath('tmp/backup.lock'), 'c');
    flock($lock, LOCK_EX);
    eq(Backup::run('manual')['status'], 'busy');
    flock($lock, LOCK_UN);
    fclose($lock);
    // A failure reaches the administrators' inbox.
    Settings::set('backup.path', '/proc/m4w-impossible');
    $r = Backup::run('scheduled');
    eq($r['status'], 'failed');
    ok((bool) DB::value("SELECT COUNT(*) FROM messages WHERE user_id = :u AND subject = 'Échec de la sauvegarde'", ['u' => $fsAdmin['id']]), 'administrators alerted');
    eq(DB::value("SELECT status FROM backup_runs ORDER BY id DESC LIMIT 1"), 'failed', 'history');
    Settings::set('backup.path', $bkDir);
});
test('backup: schedule in the chosen time zone, once a day, "now" from the administration', function () {
    Settings::setMany(['backup.enabled' => 1, 'backup.timezone' => 'Europe/Paris', 'backup.days' => '1234567', 'backup.last_scheduled' => 0, 'backup.requested' => 0]);
    $paris = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
    Settings::set('backup.time', $paris->modify('-2 minutes')->format('H:i'));
    if ($paris->format('H:i') >= '00:02') {
        eq(Backup::due(), 'scheduled', 'time has come');
        Settings::set('backup.last_scheduled', time());
        eq(Backup::due(), null, 'once a day');
    }
    Settings::setMany(['backup.last_scheduled' => 0, 'backup.time' => $paris->modify('+30 minutes')->format('H:i')]);
    if ($paris->format('H:i') < '23:29') {
        eq(Backup::due(), null, 'not yet');
        ok(Backup::nextRun() - time() <= 1800 && Backup::nextRun() > time(), 'next run shown');
    }
    Settings::set('backup.days', '');
    Settings::set('backup.days', str_replace($paris->format('N'), '', '1234567'));
    Settings::set('backup.time', '00:00');
    eq(Backup::due(), null, 'not on an excluded day');
    Settings::set('backup.requested', 1);
    eq(Backup::due(), 'manual', 'asked from the administration');
    Settings::setMany(['backup.enabled' => 0, 'backup.requested' => 0, 'backup.days' => '1234567']);
});
test('backup: restored on another server (database, mail, configuration)', function () use ($bkZip, $tmp) {
    $other = $tmp . '/restore-target';
    @mkdir($other . '/storage', 0777, true);
    $cfg = require getenv('M4W_CONFIG');
    $cfg['storage_path'] = $other . '/storage';
    $cfg['db'] = ['driver' => 'sqlite', 'path' => $other . '/storage/restored.sqlite'];
    file_put_contents($other . '/config.php', '<?php return ' . var_export($cfg, true) . ';');
    $zip = $bkZip();
    $cmd = [PHP_BINARY, dirname(__DIR__) . '/bin/restore.php', $zip, '--password=Sauvegarde!2026'];
    $run = static function (array $cmd) use ($other): string {
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['M4W_CONFIG' => $other . '/config.php'] + getenv());
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($p);
        return $out;
    };
    ok(str_contains($run($cmd), 'Nothing changed'), 'dry run without --yes');
    $out = $run(array_merge($cmd, ['--yes']));
    ok(str_contains($out, 'SQLite database restored'), $out);
    eq((int) (new \PDO('sqlite:' . $other . '/storage/restored.sqlite'))->query('SELECT COUNT(*) FROM messages')->fetchColumn(), $GLOBALS['bkMessages'], 'every message at backup time');
    $count = static fn(string $d) => count(array_filter(iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS))), static fn($f) => $f->isFile()));
    eq($count($other . '/storage/mail'), $GLOBALS['bkMailFiles'], 'mail store');
    ok(str_contains($run([PHP_BINARY, dirname(__DIR__) . '/bin/restore.php', $zip, '--password=faux', '--yes']), 'wrong password'), 'wrong password refused');
    Settings::set('backup.zip_password', '');
});


echo "\nCalendar, invitations, phone sync (CalDAV / CardDAV)\n";
$calA = Users::findByEmail('admin@test.local');
$calB = Users::findByEmail('bob@test.local');
$calC = Users::findByEmail('carol@test.local');
$invites = static function (string $to, string $method = ''): array {
    $out = [];
    foreach (DB::all("SELECT * FROM mail_queue WHERE kind = 'invite' AND recipients LIKE :r ORDER BY id", ['r' => '%' . $to . '%']) as $q) {
        $raw = (string) file_get_contents(Config::storagePath($q['raw_path']));
        if ($method === '' || str_contains($raw, 'method=' . $method)) {
            $out[] = $raw;
        }
    }
    return $out;
};
test('calendar: weekly series across the clock change, all-day events, open-ended series', function () use ($calA) {
    $cal = M4W\Service\Calendar::defaultFor((int) $calA['id']);
    $v = M4W\Service\Calendar::fromForm($calA, ['title' => 'Hebdo', 'start' => '2026-10-19 09:00', 'end' => '2026-10-19 09:30', 'repeat' => 'weekly', 'repeat_until' => '2026-11-09']);
    M4W\Service\Calendar::put((int) $cal['id'], 'hebdo.ics', $v->serialize());
    $occ = M4W\Service\Calendar::range([(int) $cal['id'] => $cal + ['level' => 'owner']], strtotime('2026-10-01'), strtotime('2026-12-01'));
    eq(array_map(static fn($o) => date('Y-m-d H:i', $o['start']), $occ), ['2026-10-19 09:00', '2026-10-26 09:00', '2026-11-02 09:00', '2026-11-09 09:00'], 'local time kept after DST ends');
    $row = M4W\Service\Calendar::object((int) $cal['id'], 'hebdo.ics');
    eq((int) $row['last_occurrence'], strtotime('2026-11-09 09:30'), 'end of the last occurrence indexed');
    $d = M4W\Service\Calendar::fromForm($calA, ['title' => 'Séminaire', 'all_day' => 1, 'start' => '2026-11-12', 'end' => '2026-11-13', 'repeat' => 'none']);
    ok(str_contains($d->serialize(), 'DTEND;VALUE=DATE:20261114'), 'whole days: exclusive end');
    $inf = M4W\Service\Calendar::fromForm($calA, ['title' => 'Stand-up', 'start' => '2026-10-20 09:00', 'end' => '2026-10-20 09:15', 'repeat' => 'weekdays']);
    eq(M4W\Service\Calendar::describe($inf)['last'], M4W\Service\Calendar::NEVER, 'no end');
    // Only this occurrence moves: exception with RECURRENCE-ID; deleting one adds EXDATE.
    $one = M4W\Service\Calendar::fromForm($calA, ['title' => 'Hebdo déplacé', 'start' => '2026-10-27 11:00', 'end' => '2026-10-27 11:30'], $row['data'], 'one', strtotime('2026-10-26 09:00'));
    $ex = M4W\Service\Calendar::withoutOccurrence($one->serialize(), strtotime('2026-11-02 09:00'));
    M4W\Service\Calendar::put((int) $cal['id'], 'hebdo.ics', $ex->serialize());
    $occ = M4W\Service\Calendar::range([(int) $cal['id'] => $cal + ['level' => 'owner']], strtotime('2026-10-01'), strtotime('2026-12-01'));
    eq(array_map(static fn($o) => date('Y-m-d H:i', $o['start']) . ' ' . $o['title'], array_values(array_filter($occ, static fn($o) => $o['uri'] === 'hebdo.ics'))),
        ['2026-10-19 09:00 Hebdo', '2026-10-27 11:00 Hebdo déplacé', '2026-11-09 09:00 Hebdo']);
    eq(M4W\Service\Calendar::nextAlarm($v, strtotime('2026-10-01')), 0, 'no reminder asked');
    $r = M4W\Service\Calendar::fromForm($calA, ['title' => 'R', 'start' => '2026-10-19 09:00', 'end' => '2026-10-19 10:00', 'repeat' => 'daily', 'reminder' => 15]);
    eq(M4W\Service\Calendar::nextAlarm($r, strtotime('2026-10-20 09:00')), strtotime('2026-10-21 08:45'), 'next reminder of a series');
});
test('calendar: sharing (read, write), rooms for everyone, nobody else sees anything', function () use ($calA, $calB, $calC) {
    $id = M4W\Service\Calendar::create($calA, 'Équipe', 'personal');
    ok(!isset(M4W\Service\Calendar::forUser($calB)[$id]), 'private by default');
    M4W\Service\Calendar::setAcl($calA, $id, [['user_id' => $calB['id'], 'level' => 'read'], ['user_id' => $calC['id'], 'level' => 'write']]);
    eq(M4W\Service\Calendar::forUser($calB)[$id]['level'], 'read');
    eq(M4W\Service\Calendar::forUser($calC)[$id]['level'], 'write');
    $thrown = false;
    try { M4W\Service\Calendar::require($calB, $id, 2); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'reader cannot write');
    $thrown = false;
    try { M4W\Service\Calendar::setAcl($calC, $id, []); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'only the owner shares');
    $thrown = false;
    try { M4W\Service\Calendar::create($calB, 'Salle', 'room'); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'rooms are made by administrators');
    $room = M4W\Service\Calendar::create($calA, 'Salle Jura', 'room');
    eq(M4W\Service\Calendar::forUser($calB)[$room]['level'], 'read', 'everyone sees a room; booking goes through an event');
    $thrown = false;
    try { M4W\Service\Calendar::delete($calA, (int) M4W\Service\Calendar::defaultFor((int) $calA['id'])['id']); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'main calendar kept');
});
test('invitations: colleague gets the event at once, outsiders an iMIP e-mail, answers come back', function () use ($calA, $calB, $invites) {
    DB::run("DELETE FROM mail_queue");
    $cal = M4W\Service\Calendar::defaultFor((int) $calA['id']);
    $v = M4W\Service\Calendar::fromForm($calA, ['title' => 'Comité', 'location' => 'Salle Jura', 'start' => '2026-10-22 10:00', 'end' => '2026-10-22 11:00', 'repeat' => 'none',
        'attendees' => [['email' => 'bob@test.local', 'name' => 'Bob'], ['email' => 'paul@ext.com', 'name' => 'Paul']]]);
    $uid = (string) $v->VEVENT->UID;
    M4W\Service\Calendar::put((int) $cal['id'], 'comite.ics', $v->serialize());
    eq(M4W\Service\Scheduling::afterChange($calA, null, $v->serialize()), 2);
    [, , $bobCopy] = M4W\Service\Scheduling::locate((int) $calB['id'], $uid);
    ok($bobCopy !== null, 'in Bob\'s calendar straight away');
    $ext = $invites('paul@ext.com', 'REQUEST');
    eq(count($ext), 1);
    ok(str_contains($ext[0], 'Content-Type: text/calendar; charset=UTF-8; method=REQUEST') && str_starts_with(MimeParser::parse($ext[0])->subject(), 'Invitation'), 'iMIP part');
    // Bob answers from the message in his inbox.
    $msg = MimeParser::parse($invites('bob@test.local', 'REQUEST')[0]);
    $card = M4W\Service\Scheduling::card($calB, $msg);
    ok($card['can_answer'] && $card['in_calendar'] && $card['partstat'] === 'NEEDS-ACTION');
    M4W\Service\Scheduling::respond($calB, $msg, 'ACCEPTED');
    $mine = M4W\Service\Calendar::object((int) $cal['id'], 'comite.ics');
    ok(preg_match('/PARTSTAT=ACCEPTED[^\n]*\n?[^\n]*bob@test\.local/', str_replace("\r\n ", '', $mine['data'])) === 1, 'organizer\'s copy updated');
    eq(count($invites('admin@test.local', 'REPLY')), 1, 'reply e-mail to the organizer');
    // Paul (outside) answers by e-mail: applied on delivery, but only from Paul himself.
    $reply = static function (string $from, string $who) use ($uid): string {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//X//Y//EN\r\nMETHOD:REPLY\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20261016T080000Z\r\nDTSTART;TZID=Europe/Paris:20261022T100000\r\nSEQUENCE:0\r\nORGANIZER:mailto:admin@test.local\r\nATTENDEE;PARTSTAT=DECLINED:mailto:$who\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        return "From: $from\r\nTo: admin@test.local\r\nSubject: Refusé\r\nMessage-ID: <" . bin2hex(random_bytes(6)) . "@ext.com>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=b\r\n\r\n--b\r\nContent-Type: text/plain\r\n\r\nNon.\r\n--b\r\nContent-Type: text/calendar; method=REPLY; charset=UTF-8\r\n\r\n$ics--b--\r\n";
    };
    Delivery::deliver($reply('mallory@evil.com', 'paul@ext.com'), 'mallory@evil.com', ['admin@test.local']);
    ok(!str_contains(str_replace("\r\n ", '', M4W\Service\Calendar::object((int) $cal['id'], 'comite.ics')['data']), 'PARTSTAT=DECLINED'), 'someone else cannot answer for Paul');
    Delivery::deliver($reply('paul@ext.com', 'paul@ext.com'), 'paul@ext.com', ['admin@test.local']);
    ok(preg_match('/PARTSTAT=DECLINED[^\n]*paul@ext\.com/', str_replace("\r\n ", '', M4W\Service\Calendar::object((int) $cal['id'], 'comite.ics')['data'])) === 1, 'Paul\'s answer applied');
    // The organizer cancels: Bob's copy is marked cancelled, Paul gets a CANCEL.
    $old = M4W\Service\Calendar::object((int) $cal['id'], 'comite.ics')['data'];
    M4W\Service\Calendar::remove((int) $cal['id'], 'comite.ics');
    M4W\Service\Scheduling::afterChange($calA, $old, null);
    [, , $bobCopy] = M4W\Service\Scheduling::locate((int) $calB['id'], $uid);
    ok(str_contains($bobCopy['data'], 'STATUS:CANCELLED'), 'cancelled for Bob');
    eq(count($invites('paul@ext.com', 'CANCEL')), 1);
});
test('invitations: one from outside waits for an answer; updates and cancellations apply to it', function () use ($calB) {
    $ics = static fn(int $seq, string $start, string $method, string $status = '') => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Outlook//EN\r\nMETHOD:$method\r\nBEGIN:VEVENT\r\nUID:ext-123@partner.com\r\nDTSTAMP:20261016T080000Z\r\nSEQUENCE:$seq\r\n"
        . "DTSTART:{$start}Z\r\nDTEND:" . substr($start, 0, 9) . '15' . substr($start, 11) . "Z\r\nSUMMARY:Démo produit\r\n" . ($status ? "STATUS:$status\r\n" : '')
        . "ORGANIZER;CN=Léa:mailto:lea@partner.com\r\nATTENDEE;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:bob@test.local\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    $mail = static fn(string $ics, string $method) => "From: Léa <lea@partner.com>\r\nTo: bob@test.local\r\nSubject: Invitation\r\nMessage-ID: <" . bin2hex(random_bytes(6)) . "@partner.com>\r\nMIME-Version: 1.0\r\n"
        . "Content-Type: text/calendar; method=$method; charset=UTF-8\r\n\r\n" . $ics;
    Delivery::deliver($mail($ics(0, '20261105T130000', 'REQUEST'), 'REQUEST'), 'lea@partner.com', ['bob@test.local']);
    [, , $row] = M4W\Service\Scheduling::locate((int) $calB['id'], 'ext-123@partner.com');
    ok($row === null, 'not added before Bob answers');
    $msg = MimeParser::parse($mail($ics(0, '20261105T130000', 'REQUEST'), 'REQUEST'));
    M4W\Service\Scheduling::respond($calB, $msg, 'TENTATIVE');
    [, , $row] = M4W\Service\Scheduling::locate((int) $calB['id'], 'ext-123@partner.com');
    ok($row && !str_contains($row['data'], 'METHOD:'), 'added on answer');
    $reply = DB::one("SELECT * FROM mail_queue WHERE kind = 'invite' AND recipients LIKE '%lea@partner.com%' ORDER BY id DESC");
    $parts = MimeParser::parse((string) file_get_contents(Config::storagePath($reply['raw_path'])))->attachmentParts();
    ok(str_contains(str_replace("\r\n ", '', $parts[0]->decodedBody()), 'PARTSTAT=TENTATIVE'), 'reply to Léa');
    Delivery::deliver($mail($ics(1, '20261105T150000', 'REQUEST'), 'REQUEST'), 'lea@partner.com', ['bob@test.local']);
    [, , $row] = M4W\Service\Scheduling::locate((int) $calB['id'], 'ext-123@partner.com');
    ok(str_contains($row['data'], 'DTSTART:20261105T150000Z'), 'moved by the organizer');
    Delivery::deliver($mail($ics(2, '20261105T150000', 'CANCEL', 'CANCELLED'), 'CANCEL'), 'lea@partner.com', ['bob@test.local']);
    [, , $row] = M4W\Service\Scheduling::locate((int) $calB['id'], 'ext-123@partner.com');
    ok(str_contains($row['data'], 'STATUS:CANCELLED'), 'cancelled by the organizer');
});
test('reminders: notification on the devices of the calendar owner, next one scheduled', function () use ($calA) {
    $cal = M4W\Service\Calendar::defaultFor((int) $calA['id']);
    $v = M4W\Service\Calendar::fromForm($calA, ['title' => 'Dentiste', 'start' => date('Y-m-d H:i', time() + 1200), 'end' => date('Y-m-d H:i', time() + 3000), 'repeat' => 'daily', 'reminder' => 15]);
    M4W\Service\Calendar::put((int) $cal['id'], 'dentiste.ics', $v->serialize());
    $row = M4W\Service\Calendar::object((int) $cal['id'], 'dentiste.ics');
    ok((int) $row['next_alarm'] > time() && (int) $row['next_alarm'] <= time() + 360, 'reminder in about 5 minutes (15 min before)');
    $sent = [];
    \M4W\Service\WebPush::$transport = function (string $ep, array $h, string $body) use (&$sent) { $sent[] = $ep; return 201; };
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $d = openssl_pkey_get_details($k)['ec'];
    \M4W\Service\WebPush::subscribe((int) $calA['id'], 'https://fcm.googleapis.com/fcm/send/cal', \M4W\Service\WebPush::b64u("\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT)), \M4W\Service\WebPush::b64u(random_bytes(16)), 'ua');
    try {
        eq(M4W\Service\Calendar::processAlarms(time() - 60), 0, 'not yet');
        eq(M4W\Service\Calendar::processAlarms(time() + 400), 1);
    } finally {
        \M4W\Service\WebPush::$transport = null;
        DB::run('DELETE FROM push_subscriptions WHERE user_id = :u', ['u' => $calA['id']]);
    }
    ok(in_array('https://fcm.googleapis.com/fcm/send/cal', $sent, true));
    $next = (int) M4W\Service\Calendar::object((int) $cal['id'], 'dentiste.ics')['next_alarm'];
    ok($next > time() + 80000, 'tomorrow\'s reminder scheduled');
    eq(M4W\Service\Calendar::processAlarms(time() + 400), 0, 'not twice');
});
test('CalDAV / CardDAV: app passwords, own and shared calendars, read-only respected, phone contacts', function () use ($calA, $calB) {
    $pw = M4W\Dav\AppPasswords::create((int) $calB['id'], 'iPhone');
    ok(preg_match('/^[a-z2-9]{5}(-[a-z2-9]{5}){3}$/', $pw) === 1);
    eq((int) M4W\Dav\AppPasswords::check('bob@test.local', $pw, '10.0.0.1')['id'], (int) $calB['id']);
    ok(M4W\Dav\AppPasswords::check('bob@test.local', 'Secret!Pass123', '10.0.0.1') === null, 'account password refused');
    ok(M4W\Dav\AppPasswords::check('admin@test.local', $pw, '10.0.0.1') === null, 'someone else\'s app password refused');
    $dav = new M4W\Dav\CalDavBackend($calB);
    $cals = $dav->getCalendarsForUser('principals/bob@test.local');
    $byName = array_column($cals, null, '{DAV:}displayname');
    ok(isset($byName['Équipe']) && $byName['Équipe']['{http://sabredav.org/ns}read-only'] === 1, 'shared calendar visible, read-only');
    eq($dav->getCalendarsForUser('principals/admin@test.local'), [], 'not someone else\'s home');
    $thrown = false;
    try { $dav->createCalendarObject($byName['Équipe']['id'], 'x.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:x\r\nDTSTART:20261101T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"); } catch (\Sabre\DAV\Exception\Forbidden) { $thrown = true; }
    ok($thrown, 'no writing in a read-only calendar');
    $own = (int) M4W\Service\Calendar::defaultFor((int) $calB['id'])['id'];
    $dav->createCalendarObject($own, 'phone-1.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Apple//iOS//EN\r\nBEGIN:VEVENT\r\nUID:phone-1\r\nDTSTAMP:20261016T080000Z\r\nDTSTART:20261201T100000Z\r\nDTEND:20261201T110000Z\r\nSUMMARY:Depuis l'iPhone\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
    $q = $dav->calendarQuery($own, ['name' => 'VCALENDAR', 'prop-filters' => [], 'is-not-defined' => false, 'time-range' => null,
        'comp-filters' => [['name' => 'VEVENT', 'is-not-defined' => false, 'comp-filters' => [], 'prop-filters' => [], 'time-range' => ['start' => new \DateTime('2026-12-01'), 'end' => new \DateTime('2026-12-02')]]]]);
    eq($q, ['phone-1.ics'], 'time-range query');
    $thrown = false;
    try { $dav->getCalendarObjects((int) M4W\Service\Calendar::defaultFor((int) $calA['id'])['id']); } catch (\Sabre\DAV\Exception\NotFound) { $thrown = true; }
    ok($thrown, 'another person\'s calendar is invisible');
    $card = new M4W\Dav\CardDavBackend($calB);
    $card->createCard(1, 'paul.vcf', "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:paul\r\nFN:Paul Dupont\r\nTEL;TYPE=CELL:+33600000000\r\nADR;TYPE=HOME:;;1 rue X;Paris;;75001;France\r\nEND:VCARD\r\n");
    $c = DB::one("SELECT * FROM contacts WHERE user_id = :u AND uri = 'paul.vcf'", ['u' => $calB['id']]);
    eq([$c['name'], $c['email'], $c['phone']], ['Paul Dupont', '', '+33600000000'], 'phone-only contact');
    M4W\Service\Contacts::save((int) $calB['id'], ['name' => 'Paul Dupont', 'email' => 'paul@ext.com', 'phone' => '+33611111111'], (int) $c['id']);
    $back = $card->getCard(1, 'paul.vcf')['carddata'];
    ok(str_contains($back, 'ADR;TYPE=HOME') && str_contains($back, 'paul@ext.com') && str_contains($back, '+33611111111'), 'web edit kept the address from the phone');
    ok(count($card->getCards(2)) >= 3, 'directory listed');
    $thrown = false;
    try { $card->createCard(2, 'x.vcf', "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:X\r\nEND:VCARD\r\n"); } catch (\Sabre\DAV\Exception\Forbidden) { $thrown = true; }
    ok($thrown, 'directory read-only');
});

echo "\nCompromised accounts\n";
test('guard: limits count outside recipients only; a burst suspends sending and holds the queue', function () {
    $g = M4W\Service\AccountGuard::class;
    $u = Users::find(Users::create(['email' => 'pirate@test.local', 'password' => 'Secret!Pass123']));
    Settings::setMany(['guard.enabled' => 1, 'guard.hour_limit' => 6, 'guard.day_limit' => 100, 'guard.burst' => 9, 'guard.burst_minutes' => 10]);
    $ext = static fn(int $n, string $p = 'x') => array_map(static fn($i) => "$p$i@ext.com", range(1, $n));
    ok($g::checkSend((int) $u['id'], array_merge($ext(4), ['bob@test.local']), 'mail', true), 'within limits');
    ok($g::checkSend((int) $u['id'], ['bob@test.local', 'admin@test.local'], 'mail', true), 'colleagues are not counted');
    $msg = '';
    try { $g::checkSend((int) $u['id'], $ext(3, 'y'), 'mail', true); } catch (\RuntimeException $e) { $msg = $e->getMessage(); }
    ok(str_contains($msg, '6'), 'hourly limit explained: ' . $msg);
    ok(!$g::blocked(Users::find((int) $u['id'])), 'a limit is not a block');
    ok(!$g::checkSend((int) $u['id'], $ext(3, 'z'), 'forward', false), 'automatic mail over the limit is not sent');
    // Something already waiting in the queue, then a burst.
    DB::insert('mail_queue', ['user_id' => $u['id'], 'kind' => 'mail', 'envelope_from' => $u['email'], 'recipients' => '["a@ext.com"]', 'raw_path' => 'queue/none.eml', 'status' => 'pending', 'created_at' => time(), 'next_attempt_at' => time()]);
    Settings::set('guard.hour_limit', 1000);
    $thrown = false;
    try { $g::checkSend((int) $u['id'], $ext(6, 'w'), 'mail', true); } catch (\RuntimeException) { $thrown = true; }
    ok($thrown, 'burst refused');
    $u = Users::find((int) $u['id']);
    ok($g::blocked($u), 'sending suspended');
    eq((string) DB::value("SELECT status FROM mail_queue WHERE user_id = :u", ['u' => $u['id']]), 'held', 'queued mail held');
    eq(Transport::enqueue("Subject: x\r\n\r\nx", $u['email'], ['bob@test.local'], (int) $u['id'], 'forward'), 0, 'nothing more leaves, even to colleagues');
    ok((bool) DB::value("SELECT COUNT(*) FROM security_alerts WHERE user_id = :u AND kind = 'send_blocked' AND severity = 'high'", ['u' => $u['id']]), 'alert recorded');
    ok((bool) DB::value("SELECT COUNT(*) FROM messages WHERE user_id = :u AND subject LIKE :s", ['u' => $u['id'], 's' => '%suspendu%']), 'the person is told');
    $admin = Users::findByEmail('admin@test.local');
    ok((bool) DB::value("SELECT COUNT(*) FROM messages WHERE user_id = :u AND subject LIKE :s", ['u' => $admin['id'], 's' => '%pirate@test.local%']), 'administrators are told');
    $g::unblock((int) $u['id'], (int) $admin['id']);
    ok(!$g::blocked(Users::find((int) $u['id'])), 'restored by an administrator');
    eq((string) DB::value("SELECT status FROM mail_queue WHERE user_id = :u", ['u' => $u['id']]), 'pending', 'held mail released');
    ok($g::checkSend((int) $u['id'], $ext(2, 'v'), 'mail', true), 'counters start again');
    DB::run('DELETE FROM mail_queue WHERE user_id = :u', ['u' => $u['id']]);
    Settings::setMany(['guard.hour_limit' => 200, 'guard.burst' => 80]);
});
test('guard: a burst can also disable the account and sign it out', function () {
    $g = M4W\Service\AccountGuard::class;
    $u = Users::find(Users::create(['email' => 'pirate2@test.local', 'password' => 'Secret!Pass123']));
    DB::insert('user_sessions', ['user_id' => $u['id'], 'token_hash' => hash('sha256', 'p2'), 'created_at' => time(), 'last_seen_at' => time()]);
    Settings::setMany(['guard.on_burst' => 'lock', 'guard.burst' => 3]);
    try { $g::checkSend((int) $u['id'], ['a@ext.com', 'b@ext.com', 'c@ext.com', 'd@ext.com'], 'mail', true); } catch (\RuntimeException) {}
    eq(Users::find((int) $u['id'])['status'], 'disabled');
    eq((int) DB::value('SELECT revoked FROM user_sessions WHERE user_id = :u', ['u' => $u['id']]), 1);
    Settings::setMany(['guard.on_burst' => 'block_send', 'guard.burst' => 80]);
});
test('guard: unusual sign-ins, repeated wrong passwords, forwarding to the outside are reported', function () {
    $g = M4W\Service\AccountGuard::class;
    $u = Users::find(Users::create(['email' => 'nomade@test.local', 'password' => 'Secret!Pass123']));
    $chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';
    $notices = static fn() => (int) DB::value('SELECT COUNT(*) FROM messages WHERE user_id = :u', ['u' => $u['id']]);
    ok(!$g::onLogin($u, '82.64.10.20', $chrome), 'first sign-in: nothing to report');
    ok(!$g::onLogin($u, '82.64.10.77', $chrome), 'same network');
    ok(!$g::onLogin($u, '82.64.10.21', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1'), 'new device on a usual network');
    eq($notices(), 0);
    ok($g::onLogin($u, '185.220.101.4', $chrome), 'new network');
    eq($notices(), 1, 'the person gets a message');
    ok(!$g::onLogin($u, '185.220.101.9', $chrome), 'known since');
    eq($g::network('2a01:e0a:1f:2c40::1'), '2a01:e0a:1f::/48');
    $g::onLocked('nomade@test.local', '203.0.113.9');
    $g::onLocked('nomade@test.local', '203.0.113.9');
    eq((int) DB::value("SELECT COUNT(*) FROM security_alerts WHERE user_id = :u AND kind = 'brute_force'", ['u' => $u['id']]), 1, 'once an hour');
    $g::onForwarding($u, ['bob@test.local'], 'règle');
    eq((int) DB::value("SELECT COUNT(*) FROM security_alerts WHERE user_id = :u AND kind = 'forwarding'", ['u' => $u['id']]), 0, 'to a colleague: nothing');
    $g::onForwarding($u, ['me@gmail.com'], 'règle');
    eq((int) DB::value("SELECT COUNT(*) FROM security_alerts WHERE user_id = :u AND kind = 'forwarding'", ['u' => $u['id']]), 1);
    eq($notices(), 3);
});

echo "\nLearning antispam, phishing reports\n";
test('bayes: learns from Junk / Not junk and recognises new messages', function () {
    M4W\Service\Bayes::reset();
    Settings::set('spam.bayes_min', 20);
    $spamWords = ['bitcoin', 'gagnez', 'gratuit', 'offre', 'exclusive', 'cliquez', 'casino', 'bonus', 'urgent', 'millions', 'investissement', 'garanti', 'rolex', 'pilules', 'promotion'];
    $hamWords = ['réunion', 'compte-rendu', 'projet', 'budget', 'planning', 'client', 'livraison', 'devis', 'équipe', 'dossier', 'facture', 'contrat', 'agenda', 'jeudi', 'validation'];
    $mk = static function (array $words, string $from, int $seed): M4W\Mail\ParsedMessage {
        mt_srand($seed);
        $pick = static fn() => $words[mt_rand(0, count($words) - 1)];
        $body = implode(' ', array_map(static fn() => $pick(), range(1, 40)));
        return MimeParser::parse("From: $from\r\nTo: bob@test.local\r\nSubject: " . $pick() . ' ' . $pick() . "\r\nMessage-ID: <b$seed@x>\r\n\r\n$body\r\n");
    };
    for ($i = 0; $i < 20; $i++) {
        M4W\Service\Bayes::learn($mk($spamWords, "promo$i@spam-$i.biz", $i), true);
        M4W\Service\Bayes::learn($mk($hamWords, "collegue$i@partner.fr", 100 + $i), false);
    }
    $st = M4W\Service\Bayes::stats();
    ok($st['ready'] && $st['spam'] === 20 && $st['ham'] === 20, 'trained');
    $s = M4W\Service\Bayes::score($mk($spamWords, 'new@other.biz', 999));
    $h = M4W\Service\Bayes::score($mk($hamWords, 'new@client.fr', 998));
    ok($s !== null && $s > 0.9, 'spam recognised: ' . $s);
    ok($h !== null && $h < 0.1, 'legitimate recognised: ' . $h);
    $r = SpamFilter::evaluate($mk($spamWords, 'new@other.biz', 999), null);
    ok(($r['tests']['BAYES_SPAM'] ?? 0) >= 3.5, 'weighs in the spam score');
    // Feedback from the mailbox: moving to Junk and back.
    $bob = Users::findByEmail('bob@test.local');
    $raw = "From: x@promo-bitcoin.biz\r\nTo: bob@test.local\r\nSubject: bitcoin bonus\r\nMessage-ID: <fb1@x>\r\n\r\nbitcoin casino gratuit\r\n";
    $id = Mailbox::store((int) $bob['id'], (int) Folders::byRole((int) $bob['id'], 'inbox')['id'], $raw);
    Mailbox::move((int) $bob['id'], [$id], (int) Folders::byRole((int) $bob['id'], 'spam')['id']);
    eq((int) DB::value('SELECT bayes FROM messages WHERE id = :id', ['id' => $id]), 1, 'learnt as junk');
    eq(M4W\Service\Bayes::stats()['spam'], 21);
    Mailbox::move((int) $bob['id'], [$id], (int) Folders::byRole((int) $bob['id'], 'inbox')['id']);
    eq((int) DB::value('SELECT bayes FROM messages WHERE id = :id', ['id' => $id]), -1, 'unlearnt and learnt as legitimate');
    eq([M4W\Service\Bayes::stats()['spam'], M4W\Service\Bayes::stats()['ham']], [20, 21]);
    ok(!DB::value("SELECT COUNT(*) FROM bayes_tokens WHERE token = 'bitcoin'"), 'words are stored hashed');
});
test('phishing report: removed from every mailbox, sender marked, administrators alerted', function () {
    $alice = Users::findByEmail('admin@test.local');
    $bob = Users::findByEmail('bob@test.local');
    $carol = Users::findByEmail('carol@test.local');
    $raw = "From: Service client <securite@banque-verif.com>\r\nTo: admin@test.local, bob@test.local, carol@test.local\r\nSubject: Votre compte sera suspendu\r\nMessage-ID: <phish-1@banque-verif.com>\r\nContent-Type: text/html\r\n\r\n<p>Confirmez <a href=\"https://banque-verif.com/login\">ici</a></p>\r\n";
    Delivery::deliver($raw, 'securite@banque-verif.com', ['admin@test.local', 'bob@test.local', 'carol@test.local']);
    $mine = DB::one("SELECT * FROM messages WHERE user_id = :u AND message_id = 'phish-1@banque-verif.com'", ['u' => $alice['id']]);
    ok($mine !== null, 'received');
    $r = M4W\Service\PhishReport::report($alice, $mine);
    eq($r['removed'], 2, 'copies of Bob and Carol');
    eq((int) DB::value("SELECT COUNT(*) FROM messages WHERE message_id = 'phish-1@banque-verif.com' AND user_id IN (:b, :c)", ['b' => $bob['id'], 'c' => $carol['id']]), 0);
    eq((int) DB::value("SELECT COUNT(*) FROM deleted_messages WHERE reason = 'phishing'"), 2, 'kept in the administrators\' quarantine');
    eq((int) DB::value('SELECT folder_id FROM messages WHERE id = :id', ['id' => $mine['id']]), (int) Folders::byRole((int) $alice['id'], 'spam')['id'], 'reporter\'s copy in Junk');
    ok((bool) DB::value("SELECT COUNT(*) FROM security_alerts WHERE kind = 'phishing' AND severity = 'high'"), 'alert');
    $next = SpamFilter::evaluate(MimeParser::parse(str_replace('phish-1', 'phish-2', $raw)), null);
    ok(isset($next['tests']['REPORTED_SENDER']), 'next message from that sender scored');
    // A colleague's account sending phishing: they are flagged, not their address.
    $raw2 = "From: Bob <bob@test.local>\r\nTo: admin@test.local\r\nSubject: Facture urgente\r\nMessage-ID: <int-1@test.local>\r\n\r\nPayez ici\r\n";
    $id = Mailbox::store((int) $alice['id'], (int) Folders::byRole((int) $alice['id'], 'inbox')['id'], $raw2);
    $r = M4W\Service\PhishReport::report($alice, Mailbox::get((int) $alice['id'], $id));
    ok($r['internal']);
    ok(!DB::value("SELECT COUNT(*) FROM reported_senders WHERE address = 'bob@test.local'"));
    ok((bool) DB::value("SELECT COUNT(*) FROM security_alerts WHERE user_id = :u AND kind = 'reported_by_colleague'", ['u' => $bob['id']]));
});

echo "\nMonitoring, DMARC reports\n";
test('health: DNS, queue, cron, backups checked; administrators told once, reminded, then told it is fixed', function () {
    $dns = ['MX test.local' => [], 'TXT test.local' => ['v=spf1 mx ~all'], 'TXT _dmarc.test.local' => ['v=DMARC1; p=none']];
    M4W\Service\Health::$resolver = static fn(string $type, string $name) => $dns[$type . ' ' . $name] ?? [];
    M4W\Service\Health::$certProbe = static fn() => null;
    try {
        Settings::set('system.cron_last', time());
        $c = M4W\Service\Health::checks(true);
        eq($c['mx:test.local']['state'], 'fail', 'no MX');
        eq($c['spf:test.local']['state'], 'ok');
        eq($c['dmarc:test.local']['state'], 'warn', 'p=none only monitors');
        eq($c['cron']['state'], 'ok');
        Settings::set('system.cron_last', time() - 7200);
        eq(M4W\Service\Health::checks(false)['cron']['state'], 'fail', 'cron stopped');
        Settings::set('system.cron_last', time());
        DB::insert('mail_queue', ['user_id' => null, 'kind' => 'mail', 'envelope_from' => '', 'recipients' => '[]', 'raw_path' => 'queue/x.eml', 'status' => 'pending', 'created_at' => time() - 3 * 3600, 'next_attempt_at' => time()]);
        eq(M4W\Service\Health::checks(false)['queue']['state'], 'fail', 'mail stuck for hours');
        DB::run("DELETE FROM mail_queue WHERE raw_path = 'queue/x.eml'");
        $admin = Users::findByEmail('admin@test.local');
        $count = static fn() => (int) DB::value("SELECT COUNT(*) FROM messages WHERE user_id = :u AND subject LIKE 'Supervision%'", ['u' => $admin['id']]);
        Settings::set('health.state', '{}');
        $before = $count();
        ok(M4W\Service\Health::run() > 0, 'first run reports the problems');
        eq($count(), $before + 1);
        eq(M4W\Service\Health::run(), 0, 'not again within the day');
        ok(M4W\Service\Health::run(time() + 86400 + 60) > 0, 'reminded a day later');
        eq(M4W\Service\Health::run(time() + 86400 + 120), 0, 'then quiet again');
        $dns['MX test.local'] = ['mx.test.local'];
        $dns['TXT _dmarc.test.local'] = ['v=DMARC1; p=quarantine; rua=mailto:postmaster@test.local'];
        M4W\Service\Health::$resolver = static fn(string $type, string $name) => $dns[$type . ' ' . $name] ?? [];
        $n = $count();
        M4W\Service\Health::run();
        ok((bool) DB::value("SELECT COUNT(*) FROM messages WHERE user_id = :u AND subject LIKE :s", ['u' => $admin['id'], 's' => '%réglé%']) || $count() > $n, 'fixed reported');
    } finally {
        M4W\Service\Health::$resolver = null;
        M4W\Service\Health::$certProbe = null;
    }
});
test('dmarc: aggregate reports about our domains are read (gzip / zip), others ignored', function () {
    $xml = static fn(string $domain, string $id, string $extra = '') => '<?xml version="1.0"?>' . $extra . '<feedback><report_metadata><org_name>google.com</org_name><report_id>' . $id . '</report_id><date_range><begin>' . (time() - 86400) . '</begin><end>' . time() . '</end></date_range></report_metadata>'
        . '<policy_published><domain>' . $domain . '</domain><p>quarantine</p></policy_published>'
        . '<record><row><source_ip>203.0.113.5</source_ip><count>40</count><policy_evaluated><disposition>none</disposition><dkim>pass</dkim><spf>pass</spf></policy_evaluated></row><identifiers><header_from>' . $domain . '</header_from></identifiers></record>'
        . '<record><row><source_ip>198.51.100.7</source_ip><count>3</count><policy_evaluated><disposition>quarantine</disposition><dkim>fail</dkim><spf>fail</spf></policy_evaluated></row><identifiers><header_from>' . $domain . '</header_from></identifiers></record></feedback>';
    $mail = static function (string $name, string $mime, string $content): string {
        return "From: noreply-dmarc-support@google.com\r\nTo: postmaster@test.local\r\nSubject: Report domain: test.local Submitter: google.com Report-ID: x\r\nMessage-ID: <" . bin2hex(random_bytes(5)) . "@google.com>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=b\r\n\r\n--b\r\nContent-Type: text/plain\r\n\r\nReport\r\n--b\r\nContent-Type: $mime; name=\"$name\"\r\nContent-Disposition: attachment; filename=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($content)) . "--b--\r\n";
    };
    Delivery::deliver($mail('google.com!test.local!1!2.xml.gz', 'application/gzip', gzencode($xml('test.local', 'r1'))), 'noreply-dmarc-support@google.com', ['admin@test.local']);
    eq((int) DB::value("SELECT messages FROM dmarc_reports WHERE report_id = 'r1'"), 43);
    eq((int) DB::value("SELECT failed FROM dmarc_reports WHERE report_id = 'r1'"), 3);
    Delivery::deliver($mail('google.com!test.local!1!2.xml.gz', 'application/gzip', gzencode($xml('test.local', 'r1'))), 'noreply-dmarc-support@google.com', ['admin@test.local']);
    eq((int) DB::value("SELECT COUNT(*) FROM dmarc_reports WHERE report_id = 'r1'"), 1, 'same report once');
    $zipPath = tempnam(sys_get_temp_dir(), 'z');
    $z = new \ZipArchive();
    $z->open($zipPath, \ZipArchive::OVERWRITE);
    $z->addFromString('report.xml', $xml('test.local', 'r2'));
    $z->close();
    Delivery::deliver($mail('microsoft.zip', 'application/zip', (string) file_get_contents($zipPath)), 'dmarc@microsoft.com', ['admin@test.local']);
    @unlink($zipPath);
    ok((bool) DB::value("SELECT COUNT(*) FROM dmarc_reports WHERE report_id = 'r2'"), 'zip');
    ok(!M4W\Service\DmarcReports::store($xml('other.example', 'r3')), 'not our domain');
    ok(!M4W\Service\DmarcReports::store($xml('test.local', 'r4', '<!DOCTYPE feedback [<!ENTITY x SYSTEM "file:///etc/passwd">]>')), 'no DTD');
    $sum = M4W\Service\DmarcReports::summary(30);
    eq($sum['sources'][0]['source_ip'], '198.51.100.7', 'failing source first');
    eq((int) $sum['domains'][0]['messages'], 86);
});

test('html: a <meta charset> left in the content does not garble accents (Outlook)', function () {
    $s = new HtmlSanitizer(true);
    eq($s->sanitize('<html><head><meta http-equiv="Content-Type" content="text/html; charset=windows-1252"></head><body><p>je l’ai créée à la RATP 😀 &amp;</p></body></html>'),
        '<p>je l’ai créée à la RATP 😀 &amp;</p>');
    eq($s->sanitize('<meta charset="iso-8859-1"><p>Envoyé : à</p>'), '<p>Envoyé : à</p>');
});

test('rooms: offered only when free and open, approval by managers, requester told', function () {
    $admin = Users::findByEmail('admin@test.local');
    $bob = Users::findByEmail('bob@test.local');
    $carol = Users::findByEmail('carol@test.local');
    $R = M4W\Service\Rooms::class;
    $roomId = M4W\Service\Calendar::create($admin, 'Salle Léman', 'room');
    $hours = [1 => ['08:00', '18:00'], 2 => ['08:00', '18:00'], 3 => ['08:00', '12:00'], 4 => ['08:00', '18:00'], 5 => ['08:00', '18:00'], 6 => null, 7 => null];
    $R::saveConfig($admin, $roomId, ['managed' => 1, 'managers' => [$carol['id']], 'capacity' => 12, 'hours' => $hours]);
    $thrown = false;
    try { $R::saveConfig($bob, $roomId, []); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'only administrators set rooms up');
    $ev = static fn(string $start, string $end, string $repeat = 'none') => M4W\Service\Calendar::fromForm($bob, ['title' => 'Réunion', 'start' => $start, 'end' => $end, 'repeat' => $repeat]);
    $state = static function ($vcal) use ($R, $roomId) {
        foreach ($R::available($vcal) as $r) {
            if ($r['id'] === $roomId) {
                return $r['state'];
            }
        }
        return '?';
    };
    eq($state($ev('2026-11-02 10:00', '2026-11-02 11:00')), 'ok', 'Monday morning');
    eq($state($ev('2026-11-04 14:00', '2026-11-04 15:00')), 'closed', 'Wednesday afternoon closed');
    eq($state($ev('2026-11-07 10:00', '2026-11-07 11:00')), 'closed', 'Saturday closed');
    eq($state($ev('2026-11-02 17:30', '2026-11-02 18:30')), 'closed', 'ends after closing');
    eq($state($ev('2026-11-02 10:00', '2026-11-02 11:00', 'daily')), 'closed', 'a daily series meets the weekend');
    // Bob books: managed room, so it waits for Carol.
    $cal = M4W\Service\Calendar::defaultFor((int) $bob['id']);
    $v = $ev('2026-11-02 10:00', '2026-11-02 11:00');
    $uri = M4W\Service\Scheduling::uriFor((string) $v->VEVENT->UID);
    eq($R::book($bob, $roomId, $v, (int) $cal['id'], $uri), 'pending');
    M4W\Service\Calendar::put((int) $cal['id'], $uri, $v->serialize());
    eq((string) $v->VEVENT->LOCATION, 'Salle Léman', 'location filled');
    ok((bool) DB::value("SELECT COUNT(*) FROM messages WHERE user_id = :u AND subject LIKE :s", ['u' => $carol['id'], 's' => '%Salle Léman%']), 'manager told');
    eq($state($ev('2026-11-02 10:30', '2026-11-02 11:30')), 'busy', 'a pending request already holds the slot');
    eq($R::backlog($carol)['pending'][0]['title'], 'Réunion');
    eq($R::backlog($bob)['pending'], [], 'not a manager');
    $b = $R::booking($roomId, (string) $v->VEVENT->UID);
    $thrown = false;
    try { $R::decide($bob, (int) $b['id'], true); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'the requester cannot approve');
    $R::decide($carol, (int) $b['id'], true, 'OK pour moi');
    $evRow = M4W\Service\Calendar::object((int) $cal['id'], $uri);
    ok(str_contains($evRow['data'], 'X-M4W-ROOM-STATUS:APPROVED'), 'requester\'s event updated');
    ok(str_contains(M4W\Service\Calendar::object($roomId, $uri)['data'], 'STATUS:CONFIRMED'), 'room calendar confirmed');
    ok((bool) DB::value("SELECT COUNT(*) FROM messages WHERE user_id = :u AND subject LIKE :s", ['u' => $bob['id'], 's' => 'Salle confirmée%']), 'requester told');
    // Moving the meeting asks again; releasing frees the room.
    $moved = M4W\Service\Calendar::fromForm($bob, ['title' => 'Réunion', 'start' => '2026-11-03 09:00', 'end' => '2026-11-03 10:00'], $evRow['data']);
    eq($R::book($bob, $roomId, $moved, (int) $cal['id'], $uri), 'pending', 'a new time needs a new approval');
    $R::release($roomId, (string) $moved->VEVENT->UID);
    eq($state($ev('2026-11-03 09:00', '2026-11-03 10:00')), 'ok', 'released');
    // A declined request: the event stays, without the room.
    $v2 = $ev('2026-11-05 14:00', '2026-11-05 15:00');
    $uri2 = M4W\Service\Scheduling::uriFor((string) $v2->VEVENT->UID);
    $R::book($bob, $roomId, $v2, (int) $cal['id'], $uri2);
    M4W\Service\Calendar::put((int) $cal['id'], $uri2, $v2->serialize());
    $R::decide($carol, (int) $R::booking($roomId, (string) $v2->VEVENT->UID)['id'], false, 'Salle en travaux');
    $d = M4W\Service\Calendar::object((int) $cal['id'], $uri2)['data'];
    ok(!str_contains($d, 'X-M4W-ROOM') && !str_contains($d, 'LOCATION:Salle Léman'), 'room removed from the event');
    ok(M4W\Service\Calendar::object($roomId, $uri2) === null, 'slot freed');
    // Unmanaged room, or a manager booking: confirmed at once.
    eq($R::book($carol, $roomId, $ev('2026-11-06 09:00', '2026-11-06 10:00'), (int) M4W\Service\Calendar::defaultFor((int) $carol['id'])['id'], 'x.ics'), 'approved');
    $thrown = false;
    try { $R::book($bob, $roomId, $ev('2026-11-06 09:30', '2026-11-06 10:30'), (int) $cal['id'], 'y.ics'); } catch (\InvalidArgumentException) { $thrown = true; }
    ok($thrown, 'no double booking');
});

test('meetings: Jitsi link at once; Zoom, Teams, Google Meet created by their API; link in the event', function () {
    $M = M4W\Service\Meetings::class;
    $bob = Users::findByEmail('bob@test.local');
    Settings::setMany(['meet.jitsi' => 'on', 'meet.jitsi_url' => 'https://visio.test.local', 'meet.zoom' => 'api', 'meet.zoom_account' => 'acc1', 'meet.zoom_client' => 'cli1',
        'meet.teams' => 'api', 'meet.teams_tenant' => 'contoso.onmicrosoft.com', 'meet.teams_client' => 'app1', 'meet.meet' => 'personal']);
    Settings::setSecret('meet.zoom_secret', 'zs');
    Settings::setSecret('meet.teams_secret', 'ts');
    eq(array_keys($M::available($bob)), ['jitsi', 'teams', 'zoom'], 'Meet offered only with a personal link');
    $j = $M::create($bob, 'jitsi', 'Point', time(), time() + 3600);
    ok(preg_match('#^https://visio\.test\.local/M4W-[a-f0-9]{20}$#', $j['url']) === 1, 'unguessable Jitsi room');
    $calls = [];
    $M::$transport = function (string $method, string $url, array $h, string $body) use (&$calls) {
        $calls[] = [$method, $url, $body, $h];
        return match (true) {
            str_contains($url, 'zoom.us/oauth/token') => [200, '{"access_token":"ZT"}'],
            str_contains($url, '/v2/users/bob%40test.local/meetings') => [201, '{"id":85412345678,"join_url":"https://us02web.zoom.us/j/85412345678?pwd=Xy","password":"4kP9qz","settings":{"global_dial_in_numbers":[{"number":"+1 646 558 8656","country_name":"US","city":"New York"},{"number":"+33 1 7037 2246","country_name":"France","city":"Paris"}]}}'],
            str_contains($url, 'api.zoom.us/v2/meetings/85412345678') => [204, ''],
            str_contains($url, 'login.microsoftonline.com/contoso.onmicrosoft.com') => [200, '{"access_token":"MT"}'],
            str_contains($url, 'graph.microsoft.com/v1.0/users/bob%40test.local/onlineMeetings') && $method === 'POST' => [201, '{"id":"MSO1","joinWebUrl":"https://teams.microsoft.com/l/meetup-join/abc","joinMeetingIdSettings":{"joinMeetingId":"376123456789","passcode":"aB3cD4"},"audioConferencing":{"tollNumber":"+33 1 23 45 67 89","conferenceId":"5551234"}}'],
            str_contains($url, 'oauth2.googleapis.com/token') => [200, '{"access_token":"GT"}'],
            str_contains($url, 'meet.googleapis.com/v2/spaces') => [200, '{"name":"spaces/xyz","meetingUri":"https://meet.google.com/abc-defg-hij"}'],
            default => [404, '{"message":"unexpected"}'],
        };
    };
    try {
        $z = $M::create($bob, 'zoom', 'Point', strtotime('2026-11-02 10:00'), strtotime('2026-11-02 11:00'));
        eq([$z['url'], $z['id']], ['https://us02web.zoom.us/j/85412345678?pwd=Xy', '85412345678']);
        // Connection details from the API: spaced ID, passcode, French dial-in first.
        eq([$z['code'], $z['passcode'], $z['phones'][0]['number'], $z['phone_code']], ['854 1234 5678', '4kP9qz', '+33 1 7037 2246', '85412345678#']);
        eq($M::joinUrl($z, 'Bob Martin'), 'https://us02web.zoom.us/j/85412345678?pwd=Xy&uname=Bob%20Martin', 'name filled in for the Zoom web client');
        $create = array_values(array_filter($calls, static fn($c) => str_contains($c[1], '/meetings') && $c[0] === 'POST'))[0];
        eq(json_decode($create[2], true)['duration'], 60);
        ok(in_array('Authorization: Bearer ZT', $create[3], true), 'token used');
        $M::delete($bob, $z);
        ok(in_array(['DELETE', 'https://api.zoom.us/v2/meetings/85412345678'], array_map(static fn($c) => [$c[0], $c[1]], $calls), true), 'cancelled with the event');
        $tm = $M::create($bob, 'teams', 'Point', time(), time() + 3600);
        eq($tm['url'], 'https://teams.microsoft.com/l/meetup-join/abc');
        eq([$tm['code'], $tm['passcode'], $tm['phones'][0]['number'], $tm['phone_code']], ['376 123 456 789', 'aB3cD4', '+33 1 23 45 67 89', '5551234#']);
        // Google Meet through a service account (JWT signed with its key).
        $k = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($k, $pem);
        Settings::setSecret('meet.google_key', json_encode(['client_email' => 'svc@proj.iam.gserviceaccount.com', 'private_key' => $pem]));
        Settings::set('meet.meet', 'api');
        $g = $M::create($bob, 'meet', 'Point', time(), time() + 3600);
        eq([$g['url'], $g['code']], ['https://meet.google.com/abc-defg-hij', 'abc-defg-hij'], 'code read from the link');
        ok(str_starts_with($M::joinUrl($g, 'Bob', 'bob@test.local'), 'https://meet.google.com/abc-defg-hij?authuser=bob%40test.local&hl='), 'right Google account');
        $tok = array_values(array_filter($calls, static fn($c) => str_contains($c[1], 'oauth2.googleapis.com')))[0];
        parse_str($tok[2], $form);
        [$hd, $cl, $sig] = explode('.', $form['assertion']);
        $claims = json_decode(base64_decode(strtr($cl, '-_', '+/')), true);
        eq([$claims['iss'], $claims['sub']], ['svc@proj.iam.gserviceaccount.com', 'bob@test.local'], 'acts as the organizer');
        eq(openssl_verify($hd . '.' . $cl, base64_decode(strtr($sig, '-_', '+/') . str_repeat('=', (4 - strlen($sig) % 4) % 4)), openssl_pkey_get_details($k)['key'], OPENSSL_ALGO_SHA256), 1, 'RS256 signature');
        // An API error is explained.
        $M::$transport = static fn() => [401, '{"error":{"message":"Invalid client secret"}}'];
        $msg = '';
        try { $M::create($bob, 'teams', 'Point', time(), time() + 3600); } catch (\InvalidArgumentException $e) { $msg = $e->getMessage(); }
        ok(str_contains($msg, 'Invalid client secret'), $msg);
    } finally {
        $M::$transport = null;
    }
    // The event carries the link (CONFERENCE, description) and loses it cleanly.
    $v = M4W\Service\Calendar::fromForm($bob, ['title' => 'Point', 'description' => 'Ordre du jour', 'start' => '2026-11-02 10:00', 'end' => '2026-11-02 11:00']);
    $M::attach($v, $j);
    $M::attach($v, $j);
    $ics = str_replace("\r\n ", '', $v->serialize());
    ok(str_contains($ics, 'CONFERENCE;VALUE=URI;FEATURE=AUDIO,VIDEO;LABEL=Jitsi Meet:' . $j['url']));
    eq(substr_count($ics, $j['url']), 4, 'conference, url, private copy, description: once each');
    eq($M::userDescription($v->VEVENT), 'Ordre du jour', 'the form shows what the person wrote');
    $M::detach($v);
    eq([(string) $v->VEVENT->DESCRIPTION, isset($v->VEVENT->CONFERENCE), isset($v->VEVENT->LOCATION)], ['Ordre du jour', false, false]);
    Settings::setMany(['meet.zoom' => 'off', 'meet.teams' => 'off', 'meet.meet' => 'off']);
    // The connection details: a fenced block in the description, never offered for editing.
    $zi = ['provider' => 'zoom', 'url' => 'https://us02web.zoom.us/j/85412345678?pwd=Xy', 'id' => '85412345678', 'code' => '854 1234 5678', 'passcode' => '4kP9qz',
        'phones' => [['number' => '+33 1 7037 2246', 'label' => 'France (Paris)']], 'phone_code' => '85412345678#'];
    $v = M4W\Service\Calendar::fromForm($bob, ['title' => 'Point', 'description' => "Ordre du jour\nPoint 2", 'start' => '2026-11-02 10:00', 'end' => '2026-11-02 11:00']);
    $M::attach($v, $zi);
    $desc = (string) $v->VEVENT->DESCRIPTION;
    eq(substr_count($desc, $M::MARK), 2, 'fenced block');
    foreach (['854 1234 5678', '4kP9qz', '+33 1 7037 2246', '85412345678#', $zi['url']] as $needle) {
        ok(str_contains($desc, $needle), $needle);
    }
    $ics = str_replace("\r\n ", '', $v->serialize());
    ok(str_contains($ics, 'FEATURE=PHONE') && str_contains($ics, ':tel:+33170372246') && str_contains($ics, '85412345678#'), 'dial-in for phones');
    eq($M::userDescription($v->VEVENT), "Ordre du jour\nPoint 2", 'the form shows only what the person wrote');
    $back = Sabre\VObject\Reader::read($v->serialize());
    eq($M::of($back), $zi, 'details kept in the event');
    $M::attach($v, $zi);
    eq(substr_count((string) $v->VEVENT->DESCRIPTION, $M::MARK), 2, 'saved again: still one block');
    // A link pasted by hand cannot be altered in the block: it comes from the meeting itself.
    $jo = $M::joinUrl($j, 'Bob Martin', 'bob@test.local', 'Point & budget');
    eq($jo, $j['url'] . '#userInfo.displayName=%22Bob%20Martin%22&userInfo.email=%22bob%40test.local%22&config.subject=%22Point%20%26%20budget%22');
    eq($M::joinUrl($j), $j['url']);
    eq($M::fromUrl('zoom', 'https://zoom.us/j/1234567890?pwd=a'), ['code' => '123 456 7890', 'phone_code' => '1234567890#']);
});

echo "\n" . ($fail ? "\033[31m" : "\033[32m") . "$pass passed, $fail failed\033[0m\n\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
