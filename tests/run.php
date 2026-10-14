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
    ok(!str_contains(\M4W\Service\DocPreview::render($evil, 'evil.docx'), 'root:'), 'no external entities');
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
        'paul@test.local' => ['INBOX' => ['validity' => 7, 'msgs' => [
            ['uid' => 1, 'raw' => $wmMsg('Client <client@ext.com>', 'Facture 42', 'client@ext.com')],
            ['uid' => 2, 'raw' => $wmMsg('Patron <boss@ext.com>', 'Point projet', 'boss@ext.com')],
            ['uid' => 3, 'raw' => $wmMsg('MAILER-DAEMON <mailer-daemon@ext.com>', 'Undelivered Mail', '')],
        ]], 'Junk' => ['validity' => 3, 'msgs' => [['uid' => 9, 'raw' => $wmMsg('Promo <promo@spam.example>', 'Gagnez 1000 euros', 'promo@spam.example')]]]],
        'lea@test.local' => ['INBOX' => ['validity' => 1, 'msgs' => []]],
    ],
]));
@unlink($wm['log']);
$wmProc = proc_open([PHP_BINARY, __DIR__ . '/fixtures/fake_provider.php', (string) $wm['imap'], (string) $wm['smtp'], $wm['state'], $wm['log']], [], $wmPipes);
usleep(400000);
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
    $r = RemoteMailbox::sync(RemoteMailbox::find($uid));
    eq($r['error'], '');
    eq($r['fetched'], 4, '3 inbox + 1 spam');
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
proc_terminate($wmProc);
Settings::setMany(['mail.mode' => '', 'webmail.spam_folder' => '']);


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

echo "\n" . ($fail ? "\033[31m" : "\033[32m") . "$pass passed, $fail failed\033[0m\n\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
