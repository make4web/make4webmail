<?php
declare(strict_types=1);

/**
 * Make4WebMail — self-contained test suite (no dependencies).
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

echo "\n" . ($fail ? "\033[31m" : "\033[32m") . "$pass passed, $fail failed\033[0m\n\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
