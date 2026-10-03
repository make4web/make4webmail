<?php
declare(strict_types=1);

/**
 * Demo data (development only): php tests/seed.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use M4W\Core\Database as DB;
use M4W\Mail\MimeBuilder;
use M4W\Service\Delivery;
use M4W\Service\Users;

$domain = (string) DB::value('SELECT name FROM domains ORDER BY id LIMIT 1');
$admin = Users::findByEmail('admin@' . $domain);
DB::update('users', ['phone' => '+33 1 84 60 12 00', 'mobile' => '+33 6 12 34 56 78', 'department' => 'Direction'], 'id = :id', ['id' => $admin['id']]);
M4W\Core\Settings::setMany(['brand.website' => 'https://www.make4web.fr', 'brand.address' => '12 rue de la Paix, 75002 Paris']);

$people = [
    ['julie.bernard', 'Julie', 'Bernard', 'Responsable commerciale', 'Ventes'],
    ['thomas.petit', 'Thomas', 'Petit', 'Directeur technique', 'DSI'],
    ['sarah.lefevre', 'Sarah', 'Lefèvre', 'Chargée de communication', 'Marketing'],
];
foreach ($people as [$local, $fn, $ln, $job, $dep]) {
    if (!Users::findByEmail("$local@$domain")) {
        Users::create(['email' => "$local@$domain", 'password' => 'Demo!2026pass', 'first_name' => $fn, 'last_name' => $ln,
            'display_name' => "$fn $ln", 'job_title' => $job, 'department' => $dep, 'phone' => '+33 1 84 60 12 ' . random_int(10, 99)]);
    }
}

$mk = static function (array $from, string $to, string $subject, string $html, int $ago, array $opts = []) use ($domain): string {
    $b = new MimeBuilder();
    $b->from = $from;
    $b->to = [['email' => $to, 'name' => '']];
    if (!empty($opts['cc'])) {
        $b->cc = $opts['cc'];
    }
    $b->subject = $subject;
    $b->html = $html;
    $b->date = time() - $ago;
    $b->messageId = $opts['id'] ?? MimeBuilder::newMessageId(substr($from['email'], strpos($from['email'], '@') + 1));
    if (!empty($opts['reply'])) {
        $b->inReplyTo = $opts['reply'];
        $b->references = [$opts['reply']];
    }
    foreach ($opts['headers'] ?? [] as $k => $v) {
        $b->extraHeaders[$k] = $v;
    }
    $b->attachments = $opts['att'] ?? [];
    $b->inline = $opts['inline'] ?? [];
    $b->priority = $opts['priority'] ?? 3;
    return $b->build();
};

$me = $admin['email'];
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAMElEQVR42u3OMQEAAAgDoK1/aU3hHyQgm6pFIBAIBAKBQCAQCAQCgUAgEAgEAoFAIBA8eZQBAXtE3dQAAAAASUVORK5CYII=');
$msgs = [];
$msgs[] = [$mk(['email' => 'factures@bureau-vallee.fr', 'name' => 'Bureau Vallée'], $me, 'Votre facture F-2026-0912', '<p>Bonjour,</p><p>Veuillez trouver ci-joint votre facture <b>F-2026-0912</b> d\'un montant de <b>1 284,60 € TTC</b>.</p><p>Échéance : 30 jours.</p><p>Cordialement,<br>Service comptabilité</p>', 3600 * 2, ['att' => [['name' => 'Facture-F-2026-0912.pdf', 'mime' => 'application/pdf', 'content' => $pdf]]]), 'factures@bureau-vallee.fr'];
$tid = MimeBuilder::newMessageId('client-durand.fr');
$msgs[] = [$mk(['email' => 'marc.durand@client-durand.fr', 'name' => 'Marc Durand'], $me, 'Proposition commerciale — refonte du site', '<p>Bonjour Camille,</p><p>Merci pour notre échange de ce matin. Pourriez-vous nous transmettre la proposition détaillée pour la refonte du site, avec le planning et le budget ?</p><p>Nous aimerions démarrer début novembre.</p><p>Bien cordialement,<br>Marc Durand<br><span style="color:#64748b">Directeur général — Durand & Fils</span></p>', 3600 * 26, ['id' => $tid]), 'marc.durand@client-durand.fr'];
$msgs[] = [$mk(['email' => 'marc.durand@client-durand.fr', 'name' => 'Marc Durand'], $me, 'RE: Proposition commerciale — refonte du site', '<p>Re-bonjour,</p><p>Petite précision : notre comité se réunit jeudi, si vous pouvez nous l\'envoyer d\'ici là ce serait parfait.</p><p>Merci !<br>Marc</p><blockquote>Pourriez-vous nous transmettre la proposition détaillée…</blockquote>', 3600 * 5, ['reply' => $tid, 'priority' => 1]), 'marc.durand@client-durand.fr'];
$msgs[] = [$mk(['email' => 'newsletter@designweekly.io', 'name' => 'Design Weekly'], $me, '🎨 Les 10 tendances UI de la rentrée', '<table width="100%" style="background:#f4f4f7;padding:24px 0"><tr><td align="center"><table width="560" style="background:#fff;border-radius:12px;font-family:Arial;padding:28px"><tr><td><img src="https://picsum.photos/seed/m4w/504/200" width="504" style="border-radius:8px;display:block"><h1 style="font-size:24px;color:#111">Les 10 tendances UI de la rentrée</h1><p style="color:#444;line-height:1.6">Glassmorphism assagi, typographies variables, micro-interactions… On fait le point sur ce qui va marquer les interfaces cette saison.</p><p><a href="https://example.com/article" style="background:#6d28d9;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;display:inline-block">Lire l\'article</a></p><img src="https://tracker.example.com/open.gif?u=123" width="1" height="1"></td></tr></table></td></tr></table>', 3600 * 9, ['headers' => ['List-Unsubscribe' => '<https://designweekly.io/unsub?u=123>', 'List-Id' => 'Design Weekly <weekly.designweekly.io>']]), 'newsletter@designweekly.io'];
$msgs[] = [$mk(['email' => "thomas.petit@$domain", 'name' => 'Thomas Petit'], $me, 'Maintenance serveurs samedi', '<p>Bonjour à tous,</p><p>Une maintenance des serveurs est prévue <b>samedi de 8 h à 10 h</b>. La messagerie restera disponible, mais l\'intranet sera coupé.</p><ul><li>Mise à jour des certificats</li><li>Redémarrage des hyperviseurs</li><li>Tests de restauration des sauvegardes</li></ul><p>Merci de votre compréhension,<br>Thomas</p>', 3600 * 30, ['cc' => [['email' => "julie.bernard@$domain", 'name' => 'Julie Bernard']]]), "thomas.petit@$domain"];
$msgs[] = [$mk(['email' => "sarah.lefevre@$domain", 'name' => 'Sarah Lefèvre'], $me, 'Visuels salon Produrable', '<p>Hello Camille,</p><p>Voici la première version du kakemono pour le salon. Dis-moi ce que tu en penses 🙂</p><p><img src="cid:visuel1@m4w" width="64" height="64"></p><p>Sarah</p>', 3600 * 50, ['inline' => [['name' => 'apercu.png', 'mime' => 'image/png', 'content' => $png, 'cid' => 'visuel1@m4w']], 'att' => [['name' => 'kakemono-v1.png', 'mime' => 'image/png', 'content' => $png]]]), "sarah.lefevre@$domain"];
$msgs[] = [$mk(['email' => 'no-reply@banque-exemple.fr', 'name' => 'Ma Banque'], $me, 'Votre relevé de compte est disponible', '<p>Votre relevé de compte de septembre est disponible dans votre espace client.</p>', 86400 * 3), 'no-reply@banque-exemple.fr'];
$msgs[] = [$mk(['email' => 'winner@lottery-prize.biz', 'name' => 'International Lottery'], $me, 'Congratulations!!! You WON $1,000,000', '<p>Click here to claim your prize!!!</p>', 86400 * 2, ['headers' => ['X-Spam-Flag' => 'YES', 'X-Spam-Status' => 'Yes, score=12.3']]), 'winner@lottery-prize.biz'];
$msgs[] = [$mk(['email' => 'lea.moreau@agence-pixel.fr', 'name' => 'Léa Moreau'], $me, 'Point hebdo projet Atlas', '<p>Bonjour,</p><p>Je vous propose de décaler notre point hebdo à <b>mardi 14 h</b>. Est-ce que cela vous convient ?</p><p>Belle journée,<br>Léa</p>', 86400 * 4), 'lea.moreau@agence-pixel.fr'];
$msgs[] = [$mk(['email' => 'support@github.com', 'name' => 'GitHub'], $me, '[GitHub] A new SSH key was added to your account', '<p>Hey there! A new public key was added to your account. If you did not add this key, please remove it immediately.</p>', 86400 * 6, ['headers' => ['Auto-Submitted' => 'auto-generated']]), 'support@github.com'];
$msgs[] = [$mk(['email' => "julie.bernard@$domain", 'name' => 'Julie Bernard'], $me, 'Chiffres du trimestre', '<p>Camille,</p><p>Comme convenu, voici les chiffres du T3 : <b>+18 %</b> sur le CA, 42 nouveaux clients. Le détail est dans le tableur joint.</p><p>Julie</p>', 86400 * 8, ['att' => [['name' => 'CA-T3-2026.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'content' => str_repeat('x', 24000)]]]), "julie.bernard@$domain"];

foreach ($msgs as [$raw, $sender]) {
    Delivery::deliver($raw, $sender, [$me], 'seed');
}
// Older read messages
DB::run('UPDATE messages SET is_read = 1 WHERE user_id = :u AND date_received < :t', ['u' => $admin['id'], 't' => time() - 86400 * 2]);
foreach (DB::all('SELECT id, date_sent FROM messages WHERE user_id = :u', ['u' => $admin['id']]) as $m) {
    DB::update('messages', ['date_received' => $m['date_sent']], 'id = :id', ['id' => $m['id']]);
}
M4W\Service\Contacts::touchRecipients((int) $admin['id'], [['email' => 'marc.durand@client-durand.fr', 'name' => 'Marc Durand'], ['email' => 'lea.moreau@agence-pixel.fr', 'name' => 'Léa Moreau']]);
$fid = M4W\Service\Folders::create((int) $admin['id'], 'Clients', null, '#16a34a');
M4W\Service\Folders::create((int) $admin['id'], 'Fournisseurs', null, '#ea580c');
M4W\Service\Folders::create((int) $admin['id'], 'Projet Atlas', $fid, '');
echo "seeded\n";
