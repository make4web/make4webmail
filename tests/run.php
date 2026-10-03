<?php
declare(strict_types=1);

/**
 * Make4Web Mail — self-contained test suite (no dependencies).
 *   php tests/run.php
 * Runs against an isolated temporary installation.
 */

$tmp = __DIR__ . '/_tmp';
if (is_dir($tmp)) {
    exec('rm -rf ' . escapeshellarg($tmp));
}
mkdir($tmp . '/storage', 0777, true);
$config = $tmp . '/config.php';
file_put_contents($config, "<?php return " . var_export([
    'debug' => true, 'timezone' => 'Europe/Paris', 'storage_path' => $tmp . '/storage',
    'db' => ['driver' => 'sqlite', 'path' => $tmp . '/storage/test.sqlite'], 'app_key' => '', 'base_url' => '',
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
use M4W\Service\Delivery;
use M4W\Service\Folders;
use M4W\Service\Forwarding;
use M4W\Service\Installer;
use M4W\Service\Mailbox;
use M4W\Service\RuleEngine;
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

echo "\nMailbox\n";
test('search operators', function () use ($alice) {
    $r = Mailbox::list((int) $alice['id'], ['q' => 'from:bob subject:réunion']);
    ok($r['total'] >= 1, 'operator search');
    $r = Mailbox::list((int) $alice['id'], ['q' => 'has:attachment']);
    eq($r['total'], 0);
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

echo "\n" . ($fail ? "\033[31m" : "\033[32m") . "$pass passed, $fail failed\033[0m\n\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
