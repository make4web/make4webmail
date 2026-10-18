<?php
/**
 * In-app user guide (French). Administration chapters are only rendered for administrators.
 * @var bool $isAdmin @var array $cfg @var string $brand
 */
$kbd = static fn(string ...$keys): string => implode(' ', array_map(static fn($k) => '<kbd>' . e($k) . '</kbd>', $keys));
$ui = static fn(string $icon, string $label): string => '<span class="m4w-help-ui"><i class="bi bi-' . e($icon) . '" aria-hidden="true"></i>' . e($label) . '</span>';
$go = static fn(string $path, string $label): string => '<a href="' . e(url($path)) . '">' . e($label) . '</a>';
$b = e($brand);
$days = static fn(int $n): string => $n . ' jour' . ($n > 1 ? 's' : '');
// The scheduled task: the commands for this very server (its account, its paths).
$cronUser = function_exists('posix_geteuid') && ($pwu = @posix_getpwuid(posix_geteuid())) && $pwu['name'] !== 'root' ? (string) $pwu['name'] : 'www-data';
$cronPhp = PHP_SAPI === 'cli' || !str_contains(PHP_BINARY, 'fpm') ? (PHP_BINARY ?: '/usr/bin/php') : '/usr/bin/php';
$cronRoot = M4W_ROOT;
$cronInstall = "(sudo crontab -u $cronUser -l 2>/dev/null | grep -v '$cronRoot/bin/'\n"
    . " echo '* * * * * $cronPhp $cronRoot/bin/cron.php'\n"
    . " echo '* * * * * $cronPhp $cronRoot/bin/watch.php'\n"
    . " echo '* * * * * $cronPhp $cronRoot/bin/backup.php --if-due >> $cronRoot/storage/logs/backup.log 2>&1'\n"
    . ") | sudo crontab -u $cronUser -";
?>
<div class="m4w-help" data-help>
  <header class="m4w-help-hero">
    <div class="m4w-help-hero-icon"><i class="bi bi-book"></i></div>
    <div>
      <h1>Notice d'utilisation de Make4WebMail</h1>
      <p>Tout ce qu'il faut savoir pour utiliser <strong>Make4WebMail</strong><?= $brand !== 'Make4WebMail' ? ' (votre messagerie ' . $b . ')' : '' ?> au quotidien<?= $isAdmin ? ', et pour l\'administrer' : '' ?>. Les valeurs citées (délais, tailles, durées) sont celles configurées sur votre espace.</p>
    </div>
    <button type="button" class="btn btn-light m4w-help-print" data-help-print><i class="bi bi-printer me-1"></i>Imprimer</button>
  </header>

  <nav class="m4w-help-cards" aria-label="Accès rapide">
    <a href="#compose" class="m4w-help-card"><i class="bi bi-pencil-square"></i><span>Écrire un message</span></a>
    <a href="#vacation" class="m4w-help-card"><i class="bi bi-airplane"></i><span>Prévenir de mon absence</span></a>
    <a href="#calendar" class="m4w-help-card"><i class="bi bi-calendar3"></i><span>Organiser une réunion</span></a>
    <a href="#files" class="m4w-help-card"><i class="bi bi-folder2-open"></i><span>Partager des fichiers</span></a>
    <a href="#mobile" class="m4w-help-card"><i class="bi bi-phone"></i><span>Installer sur mon téléphone</span></a>
    <a href="#security" class="m4w-help-card"><i class="bi bi-shield-lock"></i><span>Sécuriser mon compte</span></a>
    <?php if ($isAdmin): ?><a href="#a-users" class="m4w-help-card admin"><i class="bi bi-person-gear"></i><span>Gérer les utilisateurs</span></a><?php endif; ?>
  </nav>

  <p class="m4w-help-empty d-none" data-help-noresult><i class="bi bi-search me-1"></i>Aucune rubrique ne correspond à votre recherche.</p>

  <!-- ===================================================================== UTILISATEURS -->
  <section id="start" class="m4w-help-section">
    <h2><i class="bi bi-rocket-takeoff"></i>Premiers pas</h2>
    <h3>Se connecter</h3>
    <p>Connectez-vous avec votre adresse e-mail complète et votre mot de passe. Si la double authentification est activée, saisissez ensuite le code à 6 chiffres affiché par votre application (Google Authenticator, Microsoft Authenticator, FreeOTP…). Si vous avez perdu votre téléphone, utilisez l'un de vos <strong>codes de secours</strong> : chaque code ne sert qu'une fois.</p>
    <div class="m4w-help-note warn"><i class="bi bi-exclamation-triangle"></i><div>Après <?= (int) $cfg['max_attempts'] ?> mots de passe erronés, la connexion est bloquée <?= (int) $cfg['lockout'] ?> minutes depuis l'appareil concerné. Patientez ou contactez votre administrateur.</div></div>
    <h3>L'écran principal</h3>
    <div class="m4w-help-layout" aria-hidden="true">
      <div class="lay-top">Barre de recherche · applications · votre compte</div>
      <div class="lay-side">Dossiers</div><div class="lay-list">Liste des messages</div><div class="lay-read">Lecture</div>
    </div>
    <ul>
      <li><strong>À gauche</strong> : le bouton <?= $ui('pencil-square', 'Nouveau message') ?>, vos dossiers (réception, suivis, brouillons, envoyés, archives, indésirables, corbeille) et vos dossiers personnels. Le chiffre indique les messages non lus.</li>
      <li><strong>Au centre</strong> : la liste des messages, regroupés par date, avec des filtres rapides <?= $ui('envelope', 'Non lus') ?> <?= $ui('star', 'Suivis') ?> <?= $ui('paperclip', 'Pièces jointes') ?>.</li>
      <li><strong>À droite</strong> : le message sélectionné. Le volet de lecture peut être placé à droite, en bas ou masqué.</li>
      <li><strong>En haut à droite</strong> : <?= $ui('gear', '') ?> les réglages rapides (thème clair ou sombre, densité, volet de lecture, conversations), <?= $ui('grid-3x3-gap', '') ?> les applications (Messagerie, Fichiers, Contacts, Paramètres…) et votre avatar (profil, absence, sécurité, cette notice).</li>
    </ul>
    <div class="m4w-help-note tip"><i class="bi bi-lightbulb"></i><div>Sur mobile, le menu des dossiers s'ouvre avec <?= $ui('list', '') ?> en haut à gauche. Vous pouvez aussi installer la messagerie sur l'écran d'accueil depuis le menu du navigateur (« Ajouter à l'écran d'accueil »).</div></div>
  </section>

  <section id="read" class="m4w-help-section">
    <h2><i class="bi bi-envelope-open"></i>Lire et organiser</h2>
    <h3>Lire un message</h3>
    <p>Cliquez sur un message pour l'ouvrir. En mode <strong>conversation</strong> (réglage rapide <?= $ui('gear', '') ?>), les réponses d'un même échange sont regroupées : cliquez sur un message replié pour le déplier.</p>
    <ul>
      <li><strong>Images bloquées</strong> : <?= $cfg['block_images'] ? 'par sécurité, les images distantes (souvent des traceurs publicitaires) ne sont pas chargées. Cliquez sur « Afficher les images » si vous faites confiance à l\'expéditeur.' : 'les images distantes sont affichées automatiquement.' ?></li>
      <li><strong>Pièces jointes</strong> : cliquez pour les prévisualiser (images, PDF, texte) ou sur <?= $ui('download', '') ?> pour les télécharger. Avec plusieurs pièces, « Tout télécharger » crée une archive ZIP. <?= $ui('folder-plus', '') ?> les enregistre dans vos <a href="#files">Fichiers</a>.</li>
      <li><strong>Pièce jointe « Retirée par l'antivirus »</strong> : le fichier contenait un virus. Il a été supprimé avant d'arriver dans votre boîte ; seul son nom reste affiché pour que vous sachiez qu'il existait. Si vous l'attendiez, demandez à l'expéditeur de vérifier son ordinateur.</li>
      <li><strong>« Programme bloqué »</strong> : le fichier est un programme (même renommé en .pdf ou sans extension). Il ne peut pas être ouvert ni téléchargé depuis la messagerie.</li>
      <li><strong>« À vérifier »</strong> : macros, archive protégée, page web, nom qui ne correspond pas au contenu… Une confirmation est demandée avant l'ouverture. Dans le doute, appelez l'expéditeur.</li>
      <li><strong>Lettres d'information</strong> : un bandeau propose de vous désabonner en un clic quand l'expéditeur le permet.</li>
      <li><strong>Liens</strong> : survolez un lien pour voir sa vraie adresse. Au clic, la messagerie le vérifie : un site signalé comme dangereux est bloqué, et un lien trompeur (le texte affiche une banque mais le lien mène ailleurs, adresse IP, lien raccourci, nom qui imite un autre site) affiche sa vraie destination avant de l'ouvrir.</li>
      <li><strong>Bandeau rouge « possible usurpation d'un collègue »</strong> : le nom affiché est celui d'un collègue, mais l'adresse vient de l'extérieur. C'est l'arnaque classique au faux dirigeant : ne faites aucun virement et ne transmettez aucune information sans vérifier par téléphone.</li>
      <li><strong>Bandeau « Cette adresse n'accepte pas de réponse »</strong> : l'expéditeur est une adresse « no-reply » ou jetable ; votre réponse ne serait lue par personne. Utilisez plutôt le site ou le contact indiqué dans le message.</li>
      <li><strong>Bandeau rouge « Attention, usurpation probable »</strong> : le message prétend venir de votre organisation ou d'un domaine connu, mais n'a pas été envoyé par ses serveurs. Ne répondez pas, ne cliquez sur aucun lien et prévenez votre administrateur.</li>
    </ul>
    <h3>Répondre, transférer, imprimer</h3>
    <p>Les boutons <?= $ui('reply', 'Répondre') ?> <?= $ui('reply-all', 'Répondre à tous') ?> <?= $ui('forward', 'Transférer') ?> sont en haut du message. Le menu <?= $ui('three-dots-vertical', '') ?> propose aussi : transférer en pièce jointe, modifier comme nouveau, imprimer, afficher la source, télécharger le .eml, créer une règle à partir de ce message.</p>
    <h3>Classer</h3>
    <ul>
      <li><strong>Sélection</strong> : cochez les messages, ou cliquez sur l'un puis <?= $kbd('Maj') ?> + clic sur un autre pour sélectionner toute la plage.</li>
      <li><strong>Actions</strong> : <?= $ui('archive', 'Archiver') ?> <?= $ui('trash3', 'Supprimer') ?> <?= $ui('folder-symlink', 'Déplacer') ?> <?= $ui('exclamation-octagon', 'Indésirable') ?> <?= $ui('envelope', 'Lu / non lu') ?> <?= $ui('star', 'Suivre') ?>, depuis la barre au-dessus de la liste, au survol d'un message ou par clic droit.</li>
      <li><strong>Glisser-déposer</strong> : faites glisser un ou plusieurs messages sur un dossier de la colonne de gauche.</li>
      <li><strong>Annuler</strong> : après chaque action, la notification en bas de l'écran propose « Annuler » pendant quelques secondes.</li>
      <li><strong>Dossiers</strong> : le <?= $ui('plus-lg', '') ?> à côté de « Mes dossiers » crée un dossier. Le menu <?= $ui('three-dots', '') ?> d'un dossier permet de le renommer, de le colorer, de créer un sous-dossier, de tout marquer comme lu ou de le supprimer (ses messages vont dans la corbeille).</li>
    </ul>
    <h3>Corbeille et indésirables</h3>
    <p>Un message supprimé va d'abord dans la <strong>Corbeille</strong>, d'où vous pouvez le remettre en place. Les messages de la corbeille et des indésirables sont effacés automatiquement après 30 jours. Le bouton « Vider » les efface immédiatement.</p>
    <?php if ($cfg['retention_on']): ?>
    <div class="m4w-help-note info"><i class="bi bi-info-circle"></i><div>Un message effacé de la corbeille n'est plus visible pour vous. En cas d'erreur grave, seul un administrateur peut le récupérer, pendant <?= $days((int) $cfg['retention']) ?> au maximum.</div></div>
    <?php endif; ?>
  </section>

  <section id="search" class="m4w-help-section">
    <h2><i class="bi bi-search"></i>Rechercher</h2>
    <p>Tapez dans la barre de recherche en haut (raccourci <?= $kbd('/') ?>) : les résultats s'affichent au fur et à mesure, dans tous les dossiers sauf la corbeille et les indésirables. Le bouton <?= $ui('sliders2', '') ?> ouvre la recherche avancée.</p>
    <table class="table table-sm m4w-help-table">
      <thead><tr><th>Opérateur</th><th>Exemple</th><th>Trouve</th></tr></thead>
      <tbody>
        <tr><td><code>from:</code></td><td><code>from:julie</code></td><td>les messages d'un expéditeur</td></tr>
        <tr><td><code>to:</code></td><td><code>to:compta@</code></td><td>les messages envoyés à quelqu'un</td></tr>
        <tr><td><code>subject:</code></td><td><code>subject:facture</code></td><td>un mot dans l'objet</td></tr>
        <tr><td><code>has:attachment</code></td><td></td><td>les messages avec pièce jointe</td></tr>
        <tr><td><code>is:unread</code> / <code>is:starred</code></td><td></td><td>les non lus / les suivis</td></tr>
        <tr><td><code>after:</code> / <code>before:</code></td><td><code>after:2026-01-01</code></td><td>une période</td></tr>
        <tr><td><code>larger:</code></td><td><code>larger:5M</code></td><td>les messages volumineux</td></tr>
      </tbody>
    </table>
    <p>Les opérateurs se combinent : <code>from:julie has:attachment after:2026-09-01</code>.</p>
  </section>

  <section id="compose" class="m4w-help-section">
    <h2><i class="bi bi-pencil-square"></i>Rédiger et envoyer</h2>
    <ol class="m4w-help-steps">
      <li>Cliquez sur <?= $ui('pencil-square', 'Nouveau message') ?> (ou <?= $kbd('C') ?>). La fenêtre de rédaction s'ouvre en bas à droite ; <?= $ui('arrows-angle-expand', '') ?> l'agrandit, <?= $ui('dash-lg', '') ?> la réduit pour consulter un autre message. Sur ordinateur, déplacez-la où vous voulez en la tirant par sa barre de titre ; un double-clic sur cette barre la passe en plein écran.</li>
      <li>Saisissez les destinataires : les suggestions viennent de vos contacts et de l'annuaire de l'entreprise. Validez avec <?= $kbd('Entrée') ?> ou une virgule. Les liens <strong>Cc</strong> et <strong>Cci</strong> affichent les champs copie et copie cachée.</li>
      <li>Écrivez votre message. <?= $ui('type', 'Aa') ?> affiche la barre de mise en forme : gras, couleurs, listes, alignement, liens, tableaux, police et taille.</li>
      <li>Cliquez sur <?= $ui('send', 'Envoyer') ?> ou appuyez sur <?= $kbd('Ctrl', 'Entrée') ?>.</li>
    </ol>
    <h3>Pièces jointes et images</h3>
    <ul>
      <li><?= $ui('paperclip', '') ?> joint un fichier de votre ordinateur. Vous pouvez aussi glisser des fichiers directement dans la fenêtre. Taille maximale : <strong><?= (int) $cfg['attach'] ?> Mo</strong> par fichier.</li>
      <li><?= $ui('folder2-open', '') ?> joint un fichier de l'<a href="#files">espace Fichiers</a>, sans le télécharger au préalable.</li>
      <li><?= $ui('image', '') ?> insère une image dans le texte. Une image copiée peut aussi être collée avec <?= $kbd('Ctrl', 'V') ?>.</li>
      <li>Si votre texte parle d'une « pièce jointe » et que vous n'en avez mis aucune, un rappel s'affiche avant l'envoi.</li>
    </ul>
    <h3>Signature</h3>
    <p>La signature de l'entreprise (nom, fonction, téléphone, logo) est <strong>ajoutée automatiquement à l'envoi</strong>, juste au-dessus du message cité. L'encadré « Signature ajoutée à l'envoi » vous la montre pendant la rédaction ; vous n'avez rien à copier. Voir aussi <a href="#signature">Signature</a>.</p>
    <h3>Options d'envoi</h3>
    <p>La flèche à droite de <?= $ui('send', 'Envoyer') ?> propose :</p>
    <ul>
      <li><strong>Envoi programmé</strong> : choisissez une date et une heure. Le message attend dans les Brouillons, avec une horloge, jusqu'à son envoi. Vous pouvez l'ouvrir pour le modifier ou annuler la programmation.</li>
      <li><strong>Priorité haute</strong> et <strong>accusé de lecture</strong>.</li>
    </ul>
    <?php if ($cfg['undo'] > 0): ?>
    <div class="m4w-help-note tip"><i class="bi bi-arrow-counterclockwise"></i><div><strong>Annuler un envoi</strong> : après avoir cliqué sur Envoyer, vous disposez de <?= (int) $cfg['undo'] ?> secondes pour cliquer sur « Annuler » dans la notification. Le message revient alors en rédaction.</div></div>
    <?php endif; ?>
    <h3>Brouillons</h3>
    <p>Votre message est enregistré automatiquement dans les Brouillons pendant que vous écrivez. Fermer la fenêtre <?= $ui('x-lg', '') ?> l'enregistre ; <?= $ui('trash3', '') ?> le supprime.</p>
  </section>

  <section id="rules" class="m4w-help-section">
    <h2><i class="bi bi-funnel"></i>Règles de tri</h2>
    <p>Les règles s'appliquent aux messages <strong>dès leur arrivée sur le serveur</strong>, même quand vous n'êtes pas connecté. Rendez-vous dans <?= $go('settings/rules', 'Paramètres → Règles') ?>.</p>
    <ol class="m4w-help-steps">
      <li>Cliquez sur <?= $ui('plus-lg', 'Nouvelle règle') ?> et donnez-lui un nom.</li>
      <li>Ajoutez une ou plusieurs <strong>conditions</strong> : expéditeur, destinataires, objet, contenu, en-tête, taille, présence d'une pièce jointe, priorité. Choisissez si <em>toutes</em> les conditions ou <em>au moins une</em> doivent être remplies.</li>
      <li>Ajoutez les <strong>actions</strong> : déplacer ou copier vers un dossier, marquer comme lu, suivre, mettre à la corbeille, transférer à une adresse, répondre automatiquement, supprimer, arrêter les règles suivantes.</li>
      <li>Enregistrez. Cochez « Appliquer aussi aux messages déjà présents dans la boîte de réception » pour trier tout de suite l'existant ; le menu <?= $ui('three-dots-vertical', '') ?> d'une règle → <?= $ui('play-circle', 'Exécuter maintenant') ?> la relance à tout moment.</li>
    </ol>
    <ul>
      <li>Les règles s'exécutent dans l'ordre de la liste : faites-les glisser avec <?= $ui('grip-vertical', '') ?> pour les réordonner.</li>
      <li>L'interrupteur active ou suspend une règle sans la supprimer ; le compteur indique combien de messages elle a traités.</li>
      <li>Raccourci : depuis un message, menu <?= $ui('three-dots-vertical', '') ?> → « Filtrer les messages similaires » crée une règle pré-remplie.</li>
    </ul>
  </section>

  <section id="vacation" class="m4w-help-section">
    <h2><i class="bi bi-airplane"></i>Réponse automatique (absence)</h2>
    <p>Dans <?= $go('settings/vacation', 'Paramètres → Réponse automatique') ?> :</p>
    <ol class="m4w-help-steps">
      <li>Activez la réponse automatique et, si vous le souhaitez, une <strong>période</strong> (début et fin) : elle s'activera et s'arrêtera toute seule.</li>
      <li>Rédigez l'objet et le message, ou partez d'un modèle proposé (congés, déplacement).</li>
      <li>Réglez l'intervalle : une même personne ne reçoit qu'une réponse par intervalle (par exemple une fois par jour).</li>
      <li>Option : ne répondre qu'aux personnes de l'organisation, ou qu'à vos contacts.</li>
    </ol>
    <p>Pendant votre absence, un rappel s'affiche en haut de la liste des dossiers. Par sécurité, il n'est jamais répondu aux listes de diffusion, aux messages automatiques, aux messages signalés comme indésirables, aux adresses « ne-pas-répondre » ni aux adresses jetables (yopmail, mailinator…).</p>
  </section>

  <section id="forwarding" class="m4w-help-section">
    <h2><i class="bi bi-forward"></i>Transfert automatique</h2>
    <p>Dans <?= $go('settings/forwarding', 'Paramètres → Transfert') ?>, indiquez une ou plusieurs adresses vers lesquelles tous vos messages seront renvoyés. Choisissez de <strong>garder une copie</strong> dans votre boîte (recommandé) ou non.</p>
    <?php if (!$cfg['ext_forward']): ?><div class="m4w-help-note warn"><i class="bi bi-exclamation-triangle"></i><div>La politique de votre organisation n'autorise le transfert qu'à l'intérieur de l'entreprise.</div></div><?php endif; ?>
    <p>Pour ne transférer qu'une partie des messages, utilisez plutôt une <a href="#rules">règle</a> avec l'action « Transférer à ».</p>
  </section>

  <section id="signature" class="m4w-help-section">
    <h2><i class="bi bi-pen"></i>Signature</h2>
    <p>Votre signature est générée à partir d'un modèle commun à l'entreprise et de vos informations (nom, fonction, service, téléphones). Elle est ajoutée <strong>à l'envoi</strong> ; elle n'apparaît donc pas dans le texte que vous rédigez, seulement dans l'encadré d'aperçu.</p>
    <ul>
      <li>Pour corriger une information (fonction, téléphone…), demandez à votre administrateur : elle est gérée dans votre fiche utilisateur.</li>
      <li>La signature est fixée par l'administration : il n'est pas possible d'en choisir une autre. <?= $go('settings/signature', 'Paramètres → Signature') ?> vous en montre l'aperçu.</li>
      <li><strong>Encart d'information</strong> : au-dessus de la signature, un encart peut annoncer une fermeture, des congés, une information du moment. Si l'administration le permet, vous pouvez dans <?= $go('settings/signature', 'Paramètres → Signature') ?> garder celui de l'entreprise, écrire le vôtre (par exemple « Absent jusqu'au 3 novembre ») ou n'en mettre aucun. Pensez à revenir à celui de l'entreprise quand votre information n'est plus d'actualité.</li>
    </ul>
  </section>

  <section id="calendar" class="m4w-help-section">
    <h2><i class="bi bi-calendar3"></i>Agenda et invitations</h2>
    <p>L'<?= $go('calendar', 'Agenda') ?> (menu <?= $ui('grid-3x3-gap', '') ?>) affiche vos rendez-vous par jour, semaine, mois ou en liste. Raccourcis : <?= $kbd('J') ?> jour, <?= $kbd('S') ?> semaine, <?= $kbd('M') ?> mois, <?= $kbd('L') ?> liste, <?= $kbd('T') ?> aujourd'hui, <?= $kbd('N') ?> nouvel événement, <?= $kbd('←') ?> <?= $kbd('→') ?> pour avancer ou reculer.</p>
    <h3>Créer et modifier</h3>
    <ul>
      <li>Cliquez sur un créneau (ou sur <?= $ui('plus-lg', 'Nouvel événement') ?>) : titre, horaires, répétition (tous les jours, du lundi au vendredi, chaque semaine…), lieu ou salle, rappel.</li>
      <li><strong>Glissez</strong> un événement pour le déplacer, tirez son bord inférieur pour changer sa durée. Pour un événement qui se répète, on vous demande si le changement vaut pour cette fois seulement ou pour toute la série.</li>
      <li>Les <strong>rappels</strong> arrivent en notification sur les appareils où vous les avez activées (voir « Sur téléphone et notifications »).</li>
    </ul>
    <h3>Inviter des participants</h3>
    <ul>
      <li>Ajoutez des personnes dans « Inviter des participants » : vos collègues voient aussitôt l'événement dans leur agenda (« à répondre »), et tout le monde, y compris à l'extérieur, reçoit une invitation par e-mail que Outlook, Gmail, Apple ou Thunderbird savent accepter.</li>
      <li>Si un collègue est déjà occupé à ce moment, un avertissement l'indique avant l'envoi.</li>
      <li>Les réponses mettent à jour votre événement (coche verte : accepté, croix rouge : refusé). Déplacer ou supprimer l'événement prévient automatiquement les participants.</li>
      <li>Une invitation reçue s'affiche en tête du message avec <?= $ui('check-lg', 'Accepter') ?> <?= $ui('question-lg', 'Peut-être') ?> <?= $ui('x-lg', 'Refuser') ?> ; votre réponse part à l'organisateur et l'événement s'ajoute à votre agenda. Les conflits avec vos autres rendez-vous sont signalés.</li>
    </ul>
    <h3>Réunion en ligne</h3>
    <p>Dans l'événement, « Visioconférence » ajoute une réunion Jitsi Meet, Teams, Google Meet ou Zoom (selon ce que l'administrateur a activé). Le lien est créé à l'enregistrement, figure dans l'invitation reçue par les participants (bouton « Rejoindre ») et se retrouve dans l'événement ; il suit l'événement s'il est déplacé et est supprimé avec lui.</p>
    <h3>Réserver une salle</h3>
    <ul>
      <li>Le champ « Salle » ne propose que les salles <strong>libres et ouvertes</strong> sur le créneau choisi (toutes les occurrences pour un événement répété) ; changer l'heure met la liste à jour.</li>
      <li>Certaines salles demandent une <strong>validation</strong> : la demande part aux gestionnaires de la salle, le créneau est retenu en attendant (sablier dans l'agenda), et vous recevez la réponse par message. Si l'événement est déplacé, une nouvelle validation est demandée.</li>
      <li>Les gestionnaires traitent les demandes dans <?= $go('calendar/rooms', 'Agenda → Salles et réservations') ?>.</li>
    </ul>
    <h3>Partager un agenda</h3>
    <ul>
      <li><?= $ui('plus-circle', 'Nouvel agenda') ?> crée un agenda supplémentaire (projet, équipe…). Le menu <?= $ui('three-dots', '') ?> d'un agenda permet de le partager avec des collègues ou toute l'organisation, en <strong>lecture</strong> ou en <strong>modification</strong>, de l'exporter (.ics) ou d'importer un fichier .ics.</li>
      <li>Les <strong>salles</strong> (créées par l'administrateur) sont visibles de tous : indiquez la salle comme lieu de la réunion ou créez l'événement dans son agenda. Une salle ne peut pas être réservée deux fois sur le même créneau.</li>
      <li>Décochez un agenda dans la colonne de gauche pour le masquer.</li>
    </ul>
    <h3>Sur le téléphone et dans Thunderbird</h3>
    <p>Dans <?= $go('settings/sync', 'Paramètres → Téléphone et agenda') ?>, créez un <strong>mot de passe d'application</strong> pour chaque appareil, puis suivez les instructions (profil à installer en un geste sur iPhone, DAVx⁵ sur Android, Thunderbird). Agenda, contacts et annuaire de l'entreprise se synchronisent dans les deux sens. Un appareil perdu se révoque en un clic, sans changer votre mot de passe.</p>
  </section>

  <section id="contacts" class="m4w-help-section">
    <h2><i class="bi bi-people"></i>Contacts et annuaire</h2>
    <ul>
      <li><?= $go('contacts', 'Mes contacts') ?> : vos contacts personnels. Les personnes à qui vous écrivez y sont ajoutées automatiquement (mention « auto »). Ajoutez, modifiez, mettez en favori ou supprimez-les.</li>
      <li><strong>Importer / exporter</strong> : au format vCard (.vcf) ou CSV, depuis le bouton <?= $ui('arrow-down-up', 'Importer / exporter') ?>.</li>
      <li><strong>Sur le téléphone</strong> : vos contacts et l'annuaire se synchronisent avec le carnet d'adresses du téléphone (voir « Agenda et invitations »).</li>
      <li><?= $go('contacts?tab=directory', 'Annuaire') ?> : tous les collaborateurs de l'organisation, avec leur fonction, leur service et leurs téléphones. <?= $ui('envelope', 'Écrire') ?> ouvre directement un message.</li>
    </ul>
  </section>

  <section id="files" class="m4w-help-section">
    <h2><i class="bi bi-folder2-open"></i>Fichiers partagés</h2>
    <p>L'application <?= $go('files', 'Fichiers') ?> (menu <?= $ui('grid-3x3-gap', '') ?> ou colonne de gauche de la messagerie) stocke et partage des documents au sein de l'organisation.</p>
    <dl class="m4w-help-dl">
      <dt><i class="bi bi-person-workspace"></i>Mes fichiers</dt><dd>Votre espace privé. Personne d'autre n'y a accès, pas même les administrateurs, sauf les dossiers que vous partagez.</dd>
      <dt><i class="bi bi-people"></i>Partagés avec moi</dt><dd>Les dossiers et les fichiers que vos collègues ont partagés avec vous.</dd>
      <dt><i class="bi bi-collection"></i>Espaces d'équipe</dt><dd>Des espaces communs (par exemple « Direction financière ») créés par <?= $cfg['user_spaces'] ? 'les utilisateurs ou les administrateurs' : 'les administrateurs' ?>.</dd>
      <dt><i class="bi bi-trash3"></i>Corbeille</dt><dd>Les éléments supprimés, restaurables pendant <?= $days((int) $cfg['files_trash']) ?>.</dd>
    </dl>
    <h3>Ajouter des fichiers</h3>
    <p>Faites glisser des fichiers, ou des dossiers entiers, depuis votre ordinateur dans la fenêtre, ou utilisez <?= $ui('plus-lg', 'Nouveau') ?> → Importer. Une fenêtre de progression s'affiche en bas à droite. Taille maximale : <strong><?= (int) $cfg['file'] ?> Mo</strong> par fichier.</p>
    <p>Si un fichier du même nom existe déjà, vous choisissez entre <strong>Remplacer</strong>, qui crée une nouvelle version, et <strong>Conserver les deux</strong>. Les <?= (int) $cfg['versions'] ?> versions précédentes restent consultables et restaurables depuis le menu <?= $ui('three-dots', '') ?> → <?= $ui('clock-history', 'Versions') ?>.</p>
    <h3>Manipuler</h3>
    <ul>
      <li>Double-clic : ouvrir un dossier ou prévisualiser un fichier (images, PDF, texte, audio, vidéo).</li>
      <li>Clic droit ou <?= $ui('three-dots', '') ?> : télécharger, envoyer par e-mail, renommer (<?= $kbd('F2') ?>), déplacer, copier, voir les versions, copier le lien, supprimer (<?= $kbd('Suppr') ?>).</li>
      <li>Faites glisser des éléments sur un dossier, sur le fil d'Ariane ou sur un espace de la colonne de gauche pour les déplacer.</li>
      <li><?= $ui('file-earmark-zip', 'Télécharger (ZIP)') ?> récupère un dossier complet.</li>
    </ul>
    <h3>Partager un dossier</h3>
    <ol class="m4w-help-steps">
      <li>Ouvrez le dossier, puis cliquez sur <?= $ui('person-plus', 'Partager') ?> (ou menu <?= $ui('three-dots', '') ?> d'un dossier → Partager).</li>
      <li>Ajoutez une personne, un <strong>service</strong> entier ou « Tout le monde ».</li>
      <li>Choisissez le niveau de droit, puis enregistrez.</li>
    </ol>
    <table class="table table-sm m4w-help-table">
      <thead><tr><th>Droit</th><th>Permet de</th></tr></thead>
      <tbody>
        <tr><td><span class="badge badge-soft-secondary">Lecture</span></td><td>voir, prévisualiser, télécharger, copier vers son propre espace</td></tr>
        <tr><td><span class="badge badge-soft-primary">Modification</span></td><td>en plus : importer, créer des dossiers, renommer, déplacer, supprimer, restaurer</td></tr>
        <tr><td><span class="badge badge-soft-warning">Gestion</span></td><td>en plus : modifier les accès du dossier</td></tr>
      </tbody>
    </table>
    <h3>Aperçu, modification en ligne et compression</h3>
    <ul>
      <li><strong>Aperçu</strong> : cliquez sur un fichier pour l'afficher sans le télécharger. Images, PDF, audio et vidéo, mais aussi documents Word (.docx), Excel (.xlsx), PowerPoint (.pptx), OpenDocument texte (.odt), CSV et fichiers texte.<?= M4W\Service\Office::enabled() ? ' Les anciens formats (.doc, .xls, .ppt) s\'affichent aussi, en lecture seule.' : '' ?></li>
      <?php if (M4W\Service\Office::enabled()): ?><li><strong>Modifier en ligne</strong> (menu <?= $ui('three-dots', '') ?> ou bouton <?= $ui('pencil-square', 'Modifier') ?> de l'aperçu) : le document s'ouvre dans un nouvel onglet. Si un collègue l'ouvre en même temps, vous le modifiez ensemble et voyez ses changements en direct. Chaque enregistrement crée une version, consultable et restaurable.</li><?php endif; ?>
      <li>Les fichiers texte (.txt, .md, .csv…) se modifient aussi avec l'éditeur intégré ; <?= $kbd('Ctrl', 'S') ?> enregistre. Si quelqu'un a enregistré entre-temps, rien n'est écrasé : vous êtes prévenu.</li>
      <li><?= $ui('file-earmark-zip', 'Compresser (ZIP)') ?> crée, dans le dossier ouvert, une archive des fichiers et dossiers sélectionnés.</li>
    </ul>
    <h3>Date de péremption</h3>
    <p>Chaque document a une date de péremption : <strong><?= (int) M4W\Service\Files::defaultExpiryDays() ?> jours</strong> après son dépôt par défaut, <?= (int) round(M4W\Service\Files::maxExpiryDays() / 365) ?> ans au plus. Un badge signale ceux qui expirent dans le mois. Vous êtes prévenu par e-mail <strong>un mois, une semaine et deux jours</strong> avant ; le lien du message ouvre directement la date à modifier (menu <?= $ui('three-dots', '') ?> → <?= $ui('hourglass-split', 'Date de péremption') ?>). Sans action, le document est archivé : il disparaît de l'espace Fichiers, n'est plus partagé, et vous est envoyé par e-mail. Un administrateur peut le restaurer.</p>
    <h3>Envoyer un fichier à l'extérieur</h3>
    <p>Les liens de l'espace Fichiers ne s'ouvrent que pour les utilisateurs de la messagerie : ils ne rendent jamais un document public. Pour envoyer un document à l'extérieur, utilisez « Envoyer par e-mail ». S'il dépasse la taille maximale d'un e-mail, <?= $b ?> vous propose des services de transfert (SwissTransfer, WeTransfer…) : déposez-y le fichier et collez le lien dans votre message.</p>
    <h3>Partager un seul fichier</h3>
    <p>Pour partager un fichier sans donner accès au reste du dossier, ouvrez son menu <?= $ui('three-dots', '') ?> → <?= $ui('person-plus', 'Partager ce fichier') ?>, ajoutez les personnes, un service ou « Tout le monde », puis enregistrez. Ils peuvent le consulter, le télécharger, le copier dans leurs fichiers et le joindre à un message, mais ni le modifier, ni le renommer, ni le supprimer. Ils le retrouvent dans « Partagés avec moi ». L'icône <?= $ui('people', '') ?> à côté du nom signale un fichier partagé. Si le dossier part à la corbeille, le fichier n'est plus visible.</p>
    <p>Les droits s'appliquent au dossier et à tout ce qu'il contient. Le lien « copier le lien interne » ne fonctionne que pour les personnes qui ont déjà accès au dossier.</p>
    <h3>Avec la messagerie</h3>
    <p>En rédaction, <?= $ui('folder2-open', '') ?> joint un fichier de l'espace. Dans un message reçu, <?= $ui('folder-plus', '') ?> enregistre une pièce jointe dans le dossier de votre choix.</p>
  <h3>Tableur intégré</h3>
    <p>Les classeurs (xlsx, xls, ods, csv) s'ouvrent avec <em>Modifier en ligne</em>, à plusieurs en même temps : les cellules modifiées par vos collègues apparaissent en direct, avec leur nom. Les formules s'écrivent comme dans Excel en français (<code>=SOMME(B2:B10)</code>, <code>=SI(C2>0;"oui";"non")</code>). Vos modifications sont conservées sur le serveur au fil de l'eau ; <strong>Enregistrer</strong> (Ctrl+S) en fait une nouvelle version du fichier. Raccourcis : Entrée / Tab pour valider, F2 pour modifier, Suppr pour effacer, Ctrl+C / Ctrl+V avec Excel, Ctrl+Z pour annuler. Double-cliquez sur le bord d'une colonne pour l'ajuster à son contenu.</p>
    <p>Un classeur avec graphiques, tableaux croisés ou macros, ou au format .xls, est enregistré dans une copie : l'original reste intact.</p>
    <h3>Traitement de texte intégré</h3>
    <p>Les documents Word (docx, ainsi que odt et doc) s'ouvrent avec <em>Modifier en ligne</em>. Une seule personne écrit à la fois : si un collègue est en train d'écrire, vous voyez ses changements en direct et son nom en haut de la page ; dès qu'il clique sur <strong>Terminer</strong> (ou après 45 secondes d'inactivité), le bouton <strong>Modifier</strong> vous rend la main. <strong>Enregistrer</strong> (Ctrl+S) crée une nouvelle version ; les en-têtes, pieds de page et la mise en page du document d'origine sont conservés.</p>
    <p>Un document avec suivi des modifications, commentaires, zones de texte ou notes de bas de page, ou au format .odt / .doc, est enregistré dans une copie : l'original reste intact.</p>
  </section>

  <section id="mobile" class="m4w-help-section">
    <h2><i class="bi bi-phone"></i>Sur téléphone et notifications</h2>
    <p><?= $b ?> s'installe comme une application sur votre téléphone, votre tablette ou votre ordinateur : une icône sur l'écran d'accueil, un affichage plein écran, sans passer par une boutique d'applications. Vous vous connectez avec les mêmes identifiants que sur ordinateur ; il n'y a rien d'autre à configurer.</p>
    <h3>Installer l'application</h3>
    <ul>
      <li><strong>iPhone et iPad</strong> (Safari) : ouvrez <?= $b ?>, touchez <?= $ui('box-arrow-up', 'Partager') ?>, puis « Sur l'écran d'accueil ». Ouvrez ensuite l'application depuis sa nouvelle icône.</li>
      <li><strong>Android</strong> (Chrome, Edge, Samsung Internet) : menu <?= $ui('three-dots-vertical', '⋮') ?> → « Installer l'application » ou « Ajouter à l'écran d'accueil ».</li>
      <li><strong>Ordinateur</strong> (Chrome, Edge) : cliquez sur l'icône d'installation à droite de la barre d'adresse.</li>
    </ul>
    <?php if ($cfg['push']): ?>
    <h3>Recevoir les notifications</h3>
    <ol class="m4w-help-steps">
      <li>Dans <?= $go('settings#mobile', 'Paramètres → Profil & préférences') ?>, rubrique « Notifications sur cet appareil », touchez <?= $ui('bell', 'Activer les notifications') ?> et acceptez la demande du navigateur. Un bandeau dans la messagerie vous le propose aussi.</li>
      <li>« Envoyer un test » vérifie que l'appareil reçoit bien les alertes. « Tester sur tous mes appareils » envoie une notification à tous à la fois. Un tableau montre pour chacun l'état de l'application (fermée, en arrière-plan, au premier plan), si la notification a été envoyée, reçue, affichée ou ouverte, et en combien de temps.</li>
      <li>Répétez l'opération sur chaque appareil : la liste « Appareils enregistrés » les montre tous, et vous pouvez en retirer un (téléphone perdu ou remplacé).</li>
    </ol>
    <p>Une notification est envoyée pour chaque nouveau message non lu qui arrive dans votre boîte de réception ; toucher la notification ouvre le message. Les messages rangés par une règle dans un autre dossier ne déclenchent pas d'alerte.</p>
    <p>Chaque appareil est prévenu de son côté, selon son état : si vous manquez la notification du PC, le téléphone a la sienne. Application fermée, c'est le téléphone qui joue le son de la notification. Sur Android, « Enregistrer ce son sur le téléphone » (Paramètres → Notifications) permet de lui donner le vôtre, dans les réglages de notification de l'application.</p>
    <div class="m4w-help-note info"><i class="bi bi-info-circle"></i><div>Sur iPhone et iPad, les notifications ne fonctionnent qu'une fois l'application installée sur l'écran d'accueil (iOS 16.4 ou plus récent). Si la demande d'autorisation a été refusée, réactivez-la dans les réglages du navigateur pour ce site.</div></div>
    <?php endif; ?>
  </section>

  <section id="security" class="m4w-help-section">
    <h2><i class="bi bi-shield-lock"></i>Sécurité du compte</h2>
    <p>Tout se passe dans <?= $go('settings/security', 'Paramètres → Sécurité') ?>.</p>
    <h3>Mot de passe</h3>
    <p>Choisissez un mot de passe long, que vous n'utilisez nulle part ailleurs. La jauge indique sa solidité. Changer de mot de passe déconnecte vos autres appareils.</p>
    <h3>Double authentification (recommandée)</h3>
    <ol class="m4w-help-steps">
      <li>Cliquez sur « Activer », puis scannez le QR code avec une application d'authentification.</li>
      <li>Saisissez le code à 6 chiffres affiché pour confirmer.</li>
      <li><strong>Notez vos codes de secours</strong> et rangez-les en lieu sûr : ils sont affichés une seule fois.</li>
    </ol>
    <p>Pour désactiver la double authentification, il faut votre mot de passe <em>et</em> un code de l'application.</p>
    <h3>Sessions et activité</h3>
    <p>La liste des appareils connectés permet de déconnecter un appareil perdu ou tous les autres. L'historique montre les dernières connexions réussies et échouées : si vous voyez une connexion inconnue, changez votre mot de passe et prévenez votre administrateur.</p>
    <h3>Message frauduleux ?</h3>
    <p>Un message vous demande vos identifiants, un paiement urgent, ou imite votre banque ou un collègue ? Ne cliquez sur rien et utilisez <?= $ui('shield-exclamation', 'Signaler un hameçonnage') ?> dans la barre du message : il disparaît de votre boîte et de celles de vos collègues, et l'administrateur est prévenu. Pour un simple message publicitaire, <?= $ui('exclamation-octagon', 'Indésirable') ?> suffit : le filtre apprend de vos choix.</p>
    <h3>Alertes automatiques</h3>
    <ul>
      <li>Une connexion depuis un <strong>réseau inhabituel</strong>, des <strong>mots de passe erronés</strong> à répétition, un <strong>transfert</strong> de vos messages vers une adresse extérieure ou un <strong>changement de mot de passe</strong> vous sont signalés par un message dans votre boîte (et une notification).</li>
      <li>La rubrique « Appareils et lieux de connexion habituels » liste d'où vous vous connectez. Un élément inconnu ? <?= $ui('x-octagon', 'Ce n\'était pas moi') ?> déconnecte toutes les autres sessions et vos appareils synchronisés, et prévient l'administrateur ; changez ensuite votre mot de passe.</li>
      <li>Les envois vers l'extérieur sont limités par heure et par jour. Si votre compte envoie soudain un grand nombre de messages, l'envoi est <strong>suspendu</strong> par précaution jusqu'à vérification par l'administrateur : vous lisez toujours votre courrier.</li>
    </ul>
    <div class="m4w-help-note warn"><i class="bi bi-shield-exclamation"></i><div>Personne, ni l'équipe informatique ni l'administrateur, ne vous demandera jamais votre mot de passe par e-mail ou par téléphone.</div></div>
  </section>

  <section id="privacy" class="m4w-help-section">
    <h2><i class="bi bi-lock"></i>Messages personnels et accès à votre boîte</h2>
    <p>Votre boîte aux lettres est un outil professionnel. <?= $cfg['deleg_admins'] ? 'En cas d\'absence ou d\'urgence, un administrateur peut l\'ouvrir pour assurer la continuité du service. Un collègue peut aussi être désigné comme délégué.' : 'En cas d\'absence ou d\'urgence, un délégué désigné par l\'administrateur peut l\'ouvrir pour assurer la continuité du service.' ?></p>
    <ul>
      <li>Chaque accès est enregistré avec son <strong>motif</strong>, sa date et sa durée. Vous pouvez consulter ce journal dans <?= $go('settings/security#delegation', 'Paramètres → Sécurité') ?>.</li>
      <?php if ($cfg['deleg_notify']): ?><li>Vous recevez un message « Accès à votre boîte aux lettres » à chaque ouverture.</li><?php endif; ?>
      <li>Les messages envoyés par un délégué partent avec votre adresse et indiquent qui les a réellement envoyés.</li>
    </ul>
    <?php if ($cfg['hide_personal']): ?>
    <div class="m4w-help-note info"><i class="bi bi-lock"></i><div>
      <strong>Pour qu'un message reste privé</strong>, faites commencer son objet par <code>[Perso]</code>, <code>[Privé]</code> ou <code>[Personnel]</code>, ou rangez-le dans un dossier nommé « Perso », « Personnel » ou « Privé ». Ces éléments sont invisibles pour les délégués et les administrateurs, y compris s'ils ont été supprimés.
    </div></div>
    <?php endif; ?>
  </section>

  <section id="shared" class="m4w-help-section">
    <h2><i class="bi bi-inboxes"></i>Mes autres boîtes e-mail</h2>
    <p>Une boîte partagée (contact@, support@, compta@…) ou une autre adresse qui vous revient peut être rattachée à votre compte : vous passez de l'une à l'autre sans vous reconnecter.</p>
    <ol class="m4w-help-steps">
      <li>Dans <?= $go('settings/mailboxes', 'Paramètres → Mes autres boîtes') ?>, saisissez l'adresse de la boîte et <strong>son mot de passe</strong> (et son code de double authentification si elle en a une) : c'est la preuve que vous y avez droit. Un administrateur peut aussi vous y donner accès directement ; il n'a pas besoin du mot de passe.</li>
      <li>Ouvrez le menu de votre avatar, en haut à droite : la rubrique <strong>Mes autres boîtes e-mail</strong> liste vos boîtes avec leurs messages non lus. Cliquez sur une boîte pour y travailler.</li>
      <li>Une pastille <span class="m4w-help-ui"><i class="bi bi-inboxes" aria-hidden="true"></i>Boîte contact@…</span> rappelle la boîte ouverte ; « Ma boîte » vous ramène à la vôtre.</li>
    </ol>
    <p>Dans une autre boîte, vous lisez, classez, répondez et envoyez <strong>au nom de cette boîte</strong>, avec sa signature, et gérez ses règles et sa réponse automatique. Ce n'est pas une délégation : pas de motif, pas de journal d'accès, pas de notification. Si vous avez activé les notifications, un nouveau message dans une de vos boîtes vous est aussi signalé ; le toucher ouvre la bonne boîte.</p>
  </section>

  <section id="delegated" class="m4w-help-section">
    <h2><i class="bi bi-person-badge"></i>Gérer une boîte déléguée</h2>
    <p>Si vous êtes délégué d'une boîte (par exemple pour remplacer un collègue absent), elle apparaît dans le menu de votre avatar, rubrique « Boîtes aux lettres déléguées ».</p>
    <ol class="m4w-help-steps">
      <li>Cliquez sur le nom de la personne, indiquez éventuellement un motif, puis « Ouvrir la boîte ».</li>
      <li>Un bandeau orange <span class="m4w-help-ui deleg">Boîte de …</span> rappelle en permanence que vous travaillez dans la boîte d'un autre. Vous pouvez lire, classer, répondre et envoyer, et gérer sa réponse d'absence, son transfert et ses règles.</li>
      <li>Cliquez sur « Quitter » dans le bandeau pour revenir à votre propre boîte.</li>
    </ol>
    <p>Vos propres paramètres de sécurité et vos fichiers restent les vôtres pendant ce temps ; ceux de la personne ne vous sont pas accessibles.</p>
  </section>

  <section id="shortcuts" class="m4w-help-section">
    <h2><i class="bi bi-keyboard"></i>Raccourcis clavier</h2>
    <p>Appuyez sur <?= $kbd('?') ?> dans la messagerie pour afficher cette liste à tout moment. Les raccourcis peuvent être désactivés dans <?= $go('settings', 'Paramètres → Profil & préférences') ?>.</p>
    <div class="row g-3">
      <div class="col-md-6"><table class="table table-sm m4w-help-table"><thead><tr><th colspan="2">Navigation</th></tr></thead><tbody>
        <tr><td><?= $kbd('J') ?> / <?= $kbd('K') ?></td><td>Message suivant / précédent</td></tr>
        <tr><td><?= $kbd('O') ?> ou <?= $kbd('Entrée') ?></td><td>Ouvrir le message</td></tr>
        <tr><td><?= $kbd('U') ?></td><td>Retour à la liste</td></tr>
        <tr><td><?= $kbd('/') ?></td><td>Rechercher</td></tr>
        <tr><td><?= $kbd('G') ?> puis <?= $kbd('I') ?> / <?= $kbd('S') ?> / <?= $kbd('D') ?></td><td>Réception / Envoyés / Brouillons</td></tr>
      </tbody></table></div>
      <div class="col-md-6"><table class="table table-sm m4w-help-table"><thead><tr><th colspan="2">Actions</th></tr></thead><tbody>
        <tr><td><?= $kbd('C') ?></td><td>Nouveau message</td></tr>
        <tr><td><?= $kbd('R') ?> / <?= $kbd('A') ?> / <?= $kbd('F') ?></td><td>Répondre / à tous / transférer</td></tr>
        <tr><td><?= $kbd('E') ?> · <?= $kbd('#') ?> · <?= $kbd('!') ?></td><td>Archiver · supprimer · indésirable</td></tr>
        <tr><td><?= $kbd('V') ?> · <?= $kbd('S') ?> · <?= $kbd('X') ?></td><td>Déplacer · suivre · sélectionner</td></tr>
        <tr><td><?= $kbd('Maj', 'I') ?> / <?= $kbd('Maj', 'U') ?></td><td>Marquer lu / non lu</td></tr>
        <tr><td><?= $kbd('Ctrl', 'Entrée') ?></td><td>Envoyer (en rédaction)</td></tr>
        <tr><td><?= $kbd('Échap') ?></td><td>Fermer / annuler</td></tr>
      </tbody></table></div>
    </div>
    <p>Dans Fichiers : <?= $kbd('Suppr') ?> supprimer, <?= $kbd('F2') ?> renommer, <?= $kbd('Ctrl', 'A') ?> tout sélectionner, <?= $kbd('Retour arrière') ?> dossier parent.</p>
  </section>

  <section id="faq" class="m4w-help-section">
    <h2><i class="bi bi-question-circle"></i>Questions fréquentes</h2>
    <details><summary>J'ai supprimé un message par erreur.</summary><p>Regardez dans la <strong>Corbeille</strong> : sélectionnez-le puis <?= $ui('inbox', 'Déplacer vers la réception') ?>. S'il n'y est plus, <?= $cfg['retention_on'] ? 'contactez votre administrateur, qui peut le récupérer pendant ' . $days((int) $cfg['retention']) . '.' : 'il ne peut malheureusement plus être récupéré.' ?></p></details>
    <details><summary>Un correspondant dit ne pas avoir reçu mon message.</summary><p>Vérifiez qu'il figure dans <strong>Envoyés</strong> et que l'adresse est correcte. S'il était programmé, il est encore dans les Brouillons. Si vous recevez un message « Échec de remise », transmettez-le à votre administrateur.</p></details>
    <details><summary>Je ne reçois pas un message attendu.</summary><p>Regardez dans <strong>Indésirables</strong>, puis vérifiez vos <a href="#rules">règles</a> : une règle peut l'avoir déplacé ou supprimé. Si le message est dans les indésirables, utilisez « Ce n'est pas un indésirable ».</p></details>
    <details><summary>Ma boîte est presque pleine.</summary><p>L'espace utilisé s'affiche en bas de la liste des dossiers. Videz la corbeille et supprimez les messages volumineux : la recherche <code>larger:5M</code> les retrouve. Vous pouvez aussi enregistrer les pièces jointes importantes dans vos <a href="#files">Fichiers</a> avant de supprimer le message.</p></details>
    <details><summary>Ma signature n'apparaît pas pendant que j'écris.</summary><p>C'est normal : elle est ajoutée au moment de l'envoi. Vérifiez-la dans l'encadré « Signature ajoutée à l'envoi » ou dans un message envoyé.</p></details>
    <details><summary>Comment consulter un autre compte de messagerie (Gmail, ancien fournisseur) ?</summary><p><?= $cfg['fetch'] ? 'Dans ' . $go('settings/accounts', 'Paramètres → Autres comptes') . ', ajoutez le compte IMAP : ses messages seront récupérés régulièrement dans votre boîte, avec vos règles si vous le souhaitez.' : 'Cette fonction n\'est pas activée par votre organisation.' ?></p></details>
    <?php if (M4W\Service\RemoteMailbox::active()): ?>
    <details><summary>Un encadré rouge dit que ma boîte n'est pas reliée, ou que la connexion échoue.</summary><p>Votre boîte aux lettres est hébergée chez le fournisseur de votre organisation, et Make4WebMail s'y connecte avec des identifiants saisis par l'administrateur. Si vous avez changé le mot de passe de votre boîte chez le fournisseur, ou si elle n'a pas encore été reliée, vous ne recevez ni n'envoyez plus de messages : prévenez votre administrateur. Votre mot de passe du webmail, lui, ne change pas.</p></details>
    <?php endif; ?>
    <details><summary>J'ai perdu mon téléphone avec la double authentification.</summary><p>Connectez-vous avec un code de secours, puis réactivez la double authentification sur votre nouveau téléphone. Sans code de secours, demandez à votre administrateur de la réinitialiser.</p></details>
  </section>

<?php if ($isAdmin): ?>
  <!-- ===================================================================== ADMINISTRATION -->
  <div class="m4w-help-divider"><span><i class="bi bi-shield-lock-fill me-1"></i>Administration — visible uniquement par les administrateurs</span></div>

  <section id="a-overview" class="m4w-help-section admin">
    <h2><i class="bi bi-speedometer2"></i>Vue d'ensemble</h2>
    <p>La console d'administration s'ouvre depuis le menu de votre avatar ou les applications : <?= $go('admin', 'Administration') ?>. Le <strong>tableau de bord</strong> affiche le nombre de comptes, le trafic des dernières 24 heures et sur 14 jours, l'espace utilisé, l'activité récente et une <strong>liste de vérification</strong> de la configuration (relais SMTP, DKIM, DNS, double authentification des administrateurs). Traitez en priorité les points signalés en orange.</p>
    <h3>Trois rôles</h3>
    <table class="table table-sm m4w-help-table">
      <thead><tr><th>Rôle</th><th>Ce qu'il peut faire</th></tr></thead>
      <tbody>
        <tr><td><strong>Super administrateur</strong></td><td>Tous les droits. C'est le compte créé à l'installation, pour qui installe et exploite la messagerie (par exemple le prestataire qui la gère pour son client) : utilisateurs, domaines et alias, serveur de messagerie, antispam, sécurité, personnalisation, fichiers, visioconférence, en plus de tout ce que fait l'administrateur. Il en reste toujours au moins un, actif.</td></tr>
        <tr><td><strong>Administrateur</strong></td><td>L'administration côté client, sans toucher à la configuration de l'outil : tableau de bord, signatures, messages supprimés, supervision, journaux. Il crée et règle les salles, ouvre les fichiers de chacun (« Fichiers de… » dans le volet Fichiers, consigné dans l'audit) et ajoute une autre boîte à son compte sans son mot de passe.</td></tr>
        <tr><td><strong>Utilisateur</strong></td><td>Utilise la messagerie, sans droit de configuration. Il peut ajouter une autre boîte à son compte avec le mot de passe de cette boîte (la boîte reçoit un message qui l'en prévient), et gérer une salle dont il est gestionnaire.</td></tr>
      </tbody>
    </table>
    <p>Le rôle se choisit dans la fiche de l'utilisateur. Les comptes sont gérés par le super administrateur ; il peut le permettre aux administrateurs dans <?= $go('admin/security', 'Sécurité') ?> (« Les administrateurs gèrent les comptes »). Ils gèrent alors les comptes utilisateur et administrateur, sans voir ceux des super administrateurs ni pouvoir donner ce rôle. À la mise à jour, le premier administrateur, celui qui a installé l'application, devient super administrateur ; les autres restent administrateurs.</p>
    <div class="m4w-help-note warn"><i class="bi bi-shield-exclamation"></i><div>Les administrateurs ont accès à des données sensibles : activez la double authentification sur votre compte, et imposez-la à tous les administrateurs dans <?= $go('admin/security', 'Sécurité') ?>.</div></div>
  </section>

  <section id="a-users" class="m4w-help-section admin">
    <h2><i class="bi bi-person-gear"></i>Utilisateurs</h2>
    <h3>Créer un compte</h3>
    <ol class="m4w-help-steps">
      <li><?= $go('admin/users/new', 'Utilisateurs → Nouvel utilisateur') ?> : saisissez le début de l'adresse et choisissez le domaine.</li>
      <li>Renseignez le prénom, le nom, la <strong>fonction</strong>, le <strong>service</strong> et les <strong>téléphones</strong> : ces champs alimentent la signature, l'annuaire et les droits par service de l'espace Fichiers.</li>
      <li>Définissez le rôle (utilisateur ou administrateur), le quota et un mot de passe (<?= $ui('magic', '') ?> en génère un). Laissez « Changer le mot de passe à la première connexion » coché.</li>
      <li>Transmettez les identifiants par un canal séparé (oralement, SMS), jamais dans le même e-mail que l'adresse.</li>
    </ol>
    <h3>Gérer les comptes</h3>
    <ul>
      <li><strong>Désactiver</strong> un compte bloque immédiatement la connexion et ferme ses sessions, sans rien supprimer : c'est le bon réflexe lors d'un départ.</li>
      <li><strong>Réinitialiser la double authentification</strong> (fiche → Informations) si l'utilisateur a perdu son téléphone et ses codes de secours.</li>
      <li><strong>Supprimer</strong> efface la boîte, les fichiers personnels et les contacts. <?= $cfg['retention_on'] ? 'Les messages restent récupérables dans « Messages supprimés » si l\'option est active.' : '' ?></li>
      <li>Les cases de la liste permettent d'agir sur plusieurs comptes à la fois : activer, désactiver, imposer un changement de mot de passe, attribuer un modèle de signature, supprimer. Le filtre de recherche porte sur le nom, l'adresse et le service.</li>
    </ul>
    <h3>Import CSV</h3>
    <p>Le bouton <?= $ui('filetype-csv', 'Import CSV') ?> crée des comptes en masse. Une ligne par utilisateur, séparateur <code>;</code> ou <code>,</code>, première ligne d'en-tête facultative :</p>
    <pre class="m4w-help-code">email;prenom;nom;fonction;service;telephone;mot_de_passe
julie.bernard@exemple.fr;Julie;Bernard;Responsable commerciale;Ventes;+33 1 84 60 12 82;
thomas.petit@exemple.fr;Thomas;Petit;Directeur technique;DSI;;</pre>
    <p>Sans mot de passe, un mot de passe aléatoire est généré et l'utilisateur doit le changer à la première connexion. Les lignes invalides (domaine non hébergé, adresse existante, mot de passe trop faible) sont ignorées et listées à la fin.</p>
  </section>

  <section id="a-domains" class="m4w-help-section admin">
    <h2><i class="bi bi-globe2"></i>Domaines et alias</h2>
    <ul>
      <li>Les <strong>domaines</strong> hébergés (<?= $go('admin/domains', 'Domaines & alias') ?>) déterminent les adresses que la messagerie accepte. Un domaine désactivé ne reçoit plus de courrier.</li>
      <li>Un <strong>alias</strong> est une adresse supplémentaire qui arrive dans une boîte existante (par exemple <code>contact@</code> → la boîte de Julie). L'utilisateur peut aussi <strong>envoyer</strong> avec cet alias, en le choisissant dans le champ « De ».</li>
      <li>Les alias se gèrent aussi depuis la fiche de chaque utilisateur.</li>
    </ul>
  </section>

  <section id="a-signatures" class="m4w-help-section admin">
    <h2><i class="bi bi-vector-pen"></i>Modèles de signature</h2>
    <p>Un modèle, défini dans <?= $go('admin/signatures', 'Signatures') ?>, est rempli avec les informations de chaque utilisateur et ajouté à l'envoi. L'aperçu en direct permet de tester le rendu pour n'importe quel utilisateur. La <strong>mise en page guidée</strong> se règle sans HTML :</p>
    <ul>
      <li><strong>Logo</strong> : celui de la Personnalisation, un autre propre au modèle, ou aucun ; sa hauteur ; sa position, à gauche ou à droite des coordonnées.</li>
      <li><strong>Séparateur vertical</strong> entre le logo et les coordonnées, ou aucun, dans la couleur principale ou une autre.</li>
      <li><strong>Site internet</strong> sur sa propre ligne, sous l'adresse e-mail (par défaut), ou à côté.</li>
      <li><strong>Réseaux sociaux</strong> : une petite icône par réseau (LinkedIn, Facebook, Instagram, X, YouTube…), côte à côte sous l'adresse postale ; un clic ouvre la page. Les icônes partent avec le message, comme le logo : elles s'affichent sans que le destinataire ait à autoriser les images.</li>
      <li><strong>Encart d'information</strong> : un texte affiché dans un encart au-dessus de la signature s'il n'est pas vide (fermetures, salon, nouvelle adresse). Vous choisissez si chacun peut le remplacer par le sien ou le retirer.</li>
    </ul>
    <p>« Écrire mon propre HTML » part de la mise en page guidée et permet une mise en page libre, avec les variables ci-dessous.</p>
    <table class="table table-sm m4w-help-table">
      <thead><tr><th>Variable</th><th>Contenu</th></tr></thead>
      <tbody>
        <tr><td><code>{{display_name}}</code> <code>{{first_name}}</code> <code>{{last_name}}</code> <code>{{initials}}</code></td><td>identité</td></tr>
        <tr><td><code>{{job_title}}</code> <code>{{department}}</code></td><td>fonction et service</td></tr>
        <tr><td><code>{{email}}</code> <code>{{phone}}</code> <code>{{mobile}}</code></td><td>coordonnées</td></tr>
        <tr><td><code>{{company}}</code> <code>{{address}}</code> <code>{{website}}</code> <code>{{website_label}}</code></td><td>entreprise (Personnalisation)</td></tr>
        <tr><td><code>{{logo}}</code> <code>{{logo_url}}</code> <code>{{primary_color}}</code> <code>{{separator_color}}</code></td><td>logo (intégré au message), couleur de la charte et du séparateur</td></tr>
        <tr><td><code>{{socials}}</code> <code>{{notice}}</code></td><td>icônes des réseaux sociaux ; encart d'information (placé de lui-même au-dessus de la signature si le modèle ne le place pas)</td></tr>
      </tbody>
    </table>
    <p><strong>Sections conditionnelles</strong> : <code>{{#mobile}}Mob. {{mobile}}{{/mobile}}</code> n'affiche la ligne que si le mobile est renseigné. <code>{{^mobile}}…{{/mobile}}</code> fait l'inverse.</p>
    <ul>
      <li>Un seul modèle est <strong>actif</strong> : il s'applique à tous les utilisateurs, à tous les messages, réponses et transferts compris. Les utilisateurs ne peuvent ni le modifier, ni en choisir un autre. Les autres modèles sont des brouillons ; « Appliquer à tous » en fait le modèle actif.</li>
    </ul>
  </section>

  <section id="a-branding" class="m4w-help-section admin">
    <h2><i class="bi bi-palette"></i>Personnalisation</h2>
    <p>Dans <?= $go('admin/branding', 'Personnalisation') ?>, avec aperçu en direct : nom et accroche, logo clair et logo pour le thème sombre, favicon, image de la page de connexion, image de fond du volet de lecture (affichée quand aucun message n'est ouvert : remplir, centrer ou motif répété), nom de l'entreprise à côté du logo, couleurs principale et secondaire, style du menu latéral (clair, sombre, couleur de marque), arrondi des angles, police, message d'accueil de la page de connexion, et CSS personnalisé pour les besoins avancés.</p>
    <div class="m4w-help-note tip"><i class="bi bi-lightbulb"></i><div>Fournissez un logo en PNG ou SVG sur fond transparent, et une variante claire pour le thème sombre. La couleur du texte posé sur vos couleurs (boutons, menu) s'adapte automatiquement pour rester lisible.</div></div>
  </section>

  <section id="a-calendar" class="m4w-help-section admin">
    <h2><i class="bi bi-calendar3"></i>Agenda : salles et synchronisation</h2>
    <ul>
      <li><strong>Salles</strong> : dans l'<?= $go('calendar', 'Agenda') ?>, <?= $ui('door-open', 'Nouvelle salle') ?> crée une salle (ou un véhicule, un vidéoprojecteur…). Dans <?= $go('calendar/rooms', 'Salles et réservations') ?>, réglez pour chacune les <strong>horaires d'ouverture</strong> de la semaine, le nombre de places, l'équipement et si les réservations sont <strong>soumises à validation</strong> (par ses gestionnaires, à défaut les administrateurs). Les utilisateurs réservent depuis leurs événements, uniquement sur des créneaux libres et ouverts.</li>
      <li><strong>Visioconférence</strong> : dans <?= $go('admin/meetings', 'Visioconférence') ?>, Jitsi Meet fonctionne sans compte ; Teams, Google Meet et Zoom créent la réunion au nom de l'organisateur une fois les identifiants de l'organisation saisis (application Entra ID, compte de service Google, application Zoom « Server-to-Server »), ou chacun utilise son lien personnel. « Créer une réunion d'essai » vérifie les identifiants.</li>
      <li><strong>CalDAV / CardDAV</strong> : les téléphones et Thunderbird se connectent à <code>/dav/</code> (découverte automatique par <code>/.well-known/caldav</code> et <code>/.well-known/carddav</code>) avec un mot de passe d'application, uniquement en HTTPS. Avec Apache, l'en-tête <code>Authorization</code> est transmis par le <code>.htaccess</code> fourni ; avec nginx, rien à faire.</li>
      <li>Les invitations sont envoyées par le serveur (même depuis un téléphone) et suivent les règles d'envoi habituelles (DKIM, file d'attente, limites).</li>
    </ul>
  </section>

  <section id="a-files" class="m4w-help-section admin">
    <h2><i class="bi bi-folder2-open"></i>Espace fichiers</h2>
    <ul>
      <li>Les administrateurs <strong>gèrent tous les espaces d'équipe</strong> : créer un espace avec <?= $ui('plus-lg', 'Nouveau') ?> → « Nouvel espace d'équipe », le partager (personnes, services, tout le monde), le renommer, le supprimer.</li>
      <li>Ils n'ont <strong>pas</strong> accès aux « Mes fichiers » des utilisateurs.</li>
      <li><?= $go('admin/files', 'Administration → Fichiers') ?> : taille maximale par fichier (actuellement <?= (int) $cfg['file'] ?> Mo), quota global, nombre de versions conservées (<?= (int) $cfg['versions'] ?>), durée de la corbeille (<?= $days((int) $cfg['files_trash']) ?>), création d'espaces par les utilisateurs, et liste des espaces.</li>
    </ul>
    <p>Bonne pratique : un espace par équipe ou par projet, partagé avec le <strong>service</strong> plutôt qu'avec chaque personne. Les nouveaux arrivants y ont ainsi accès dès que leur service est renseigné.</p>
  </section>

  <section id="a-delegation" class="m4w-help-section admin">
    <h2><i class="bi bi-person-badge"></i>Délégation des boîtes aux lettres</h2>
    <h3>Ouvrir la boîte d'un utilisateur</h3>
    <ol class="m4w-help-steps">
      <li>Dans <?= $go('admin/users', 'Utilisateurs') ?>, cliquez sur <?= $ui('box-arrow-in-right', '') ?> sur la ligne de la personne (ou « Ouvrir cette boîte aux lettres » dans sa fiche).</li>
      <li>Indiquez le <strong>motif</strong> : absence, urgence client, départ… Il est conservé dans le journal et visible par l'utilisateur.</li>
      <li>La messagerie bascule sur sa boîte. Le bandeau orange rappelle dans quelle boîte vous êtes ; « Quitter » vous ramène à la vôtre.</li>
    </ol>
    <p>Dans la boîte ouverte, vous pouvez lire, classer, répondre, envoyer (le destinataire voit l'adresse de la boîte et votre nom comme expéditeur réel), et régler sa <strong>réponse d'absence</strong>, son <strong>transfert</strong> et ses <strong>règles</strong>. Son mot de passe, sa double authentification et ses fichiers restent hors de portée.</p>
    <h3>Boîtes partagées, sans délégation</h3>
    <p>Pour une boîte que plusieurs personnes utilisent au quotidien (contact@, support@, compta@…), la délégation n'est pas le bon outil. Créez la boîte comme un utilisateur, puis, dans sa fiche, carte « Boîte partagée », cochez les personnes qui y travaillent : elles la retrouvent dans « Mes autres boîtes e-mail », sans motif, sans journal d'accès et sans notification. Vous pouvez aussi rattacher n'importe quelle boîte à votre propre compte depuis <?= $go('settings/mailboxes', 'Paramètres → Mes autres boîtes') ?>, sans son mot de passe. Les utilisateurs, eux, doivent prouver qu'ils y ont droit avec le mot de passe de la boîte (et son code de double authentification) ; un mauvais mot de passe compte comme un échec de connexion. Les ajouts et changements d'accès sont inscrits dans les journaux d'administration.</p>
    <h3>Désigner des délégués</h3>
    <p>Dans la fiche d'un utilisateur, carte « Délégation de la boîte », cochez les collègues qui pourront ouvrir sa boîte (assistant, remplaçant). Ils la retrouvent dans le menu de leur avatar. Décocher retire l'accès immédiatement, même si la boîte est ouverte.</p>
    <h3>Encadrement</h3>
    <ul>
      <li>Journal complet dans la fiche de l'utilisateur (« Accès délégués ») et dans <?= $go('admin/logs?tab=audit', 'Journaux → Audit') ?> : qui, quand, pourquoi, combien de messages envoyés.</li>
      <li>Réglages dans <?= $go('admin/security', 'Sécurité') ?> : accès des administrateurs à toutes les boîtes (<?= $cfg['deleg_admins'] ? 'activé' : 'désactivé' ?>), motif obligatoire, information de l'utilisateur, masquage des éléments personnels (<?= $cfg['hide_personal'] ? 'activé' : 'désactivé' ?>).</li>
    </ul>
    <div class="m4w-help-note warn"><i class="bi bi-bank"></i><div>Cadre légal (France) : l'employeur peut accéder à la messagerie professionnelle, mais pas aux messages identifiés comme personnels. Informez les salariés de cette politique (charte informatique) et gardez l'option de masquage des éléments personnels activée.</div></div>
  </section>

  <section id="a-deleted" class="m4w-help-section admin">
    <h2><i class="bi bi-trash3"></i>Messages supprimés (seconde corbeille)</h2>
    <p>Quand un message est supprimé définitivement, une copie complète est conservée <?= $cfg['retention_on'] ? 'pendant <strong>' . $days((int) $cfg['retention']) . '</strong>' : '<strong>(conservation actuellement désactivée)</strong>' ?>. Cela couvre la corbeille vidée, la suppression directe, la purge automatique, une règle « supprimer » et la suppression d'un compte. Les utilisateurs ne voient pas cette réserve.</p>
    <ol class="m4w-help-steps">
      <li>Ouvrez <?= $go('admin/deleted', 'Messages supprimés') ?> et filtrez par boîte, motif, état (restauré ou non) ou période, ou cherchez un objet ou un expéditeur.</li>
      <li>Ouvrez le message : en-têtes, pièces jointes et contenu (images distantes bloquées), téléchargement du .eml d'origine.</li>
      <li>Choisissez la boîte de destination et cliquez sur <?= $ui('arrow-counterclockwise', 'Restaurer') ?>. Le message revient dans son dossier d'origine s'il existe encore, sinon dans la boîte de réception. Plusieurs messages peuvent être restaurés en une fois depuis la liste.</li>
    </ol>
    <ul>
      <li>Après le départ d'un collaborateur, ses messages peuvent être restaurés dans la boîte de son successeur.</li>
      <li>Les messages marqués personnels ne sont ni lisibles ni téléchargeables. Ils peuvent seulement être rendus à leur propriétaire.</li>
      <li>Chaque consultation, téléchargement, restauration et destruction est inscrit au journal d'audit.</li>
      <li>Réglages (bouton <?= $ui('gear', 'Conservation') ?>) : activation, durée (0 = illimitée), conservation des comptes supprimés et des indésirables purgés.</li>
    </ul>
  </section>

  <section id="a-mail" class="m4w-help-section admin">
    <h2><i class="bi bi-hdd-network"></i>Serveur de messagerie</h2>
    <h3>Choisir le mode de fonctionnement</h3>
    <p>Le premier bloc de <?= $go('admin/mail', 'Serveur de messagerie') ?> fixe la façon dont Make4WebMail reçoit et envoie le courrier. Le mode actuel est : <strong><?= e(t('wm.mode_' . M4W\Service\RemoteMailbox::mode())) ?></strong>.</p>
    <table class="table table-sm m4w-help-table">
      <thead><tr><th>Mode</th><th>Les boîtes sont…</th><th>Réception</th><th>Envoi</th><th>Prérequis</th></tr></thead>
      <tbody>
        <tr><td><strong>Serveur autonome</strong></td><td>dans Make4WebMail</td><td>MX vers ce serveur, port 25</td><td>directement aux destinataires</td><td>port 25 entrant et sortant, DNS inverse, SPF/DKIM/DMARC, IP propre</td></tr>
        <tr><td><strong>Serveur + relais</strong></td><td>dans Make4WebMail</td><td>MX vers ce serveur, port 25</td><td>via un service d'envoi</td><td>un relais qui accepte tout le domaine (Brevo, Mailjet, Amazon SES, Scaleway TEM…)</td></tr>
        <tr><td><strong>Webmail</strong></td><td>chez votre fournisseur</td><td>relève IMAP de chaque boîte</td><td>SMTP du fournisseur, boîte par boîte</td><td>le mot de passe de chaque boîte, saisi dans la fiche de l'utilisateur</td></tr>
        <tr><td><strong>Boîte collectrice</strong></td><td>dans Make4WebMail</td><td>une seule boîte chez le fournisseur reçoit toutes les adresses par redirection</td><td>SMTP de cette boîte, au nom de chaque utilisateur</td><td>une boîte et son mot de passe, une redirection par adresse ; limite d'envoi partagée (OVH : 200 par heure)</td></tr>
      </tbody>
    </table>
    <h3>Mode webmail (boîtes chez OVH, Infomaniak, Gandi…)</h3>
    <ol class="m4w-help-steps">
      <li>Choisissez « Webmail sur boîtes existantes » et votre fournisseur : les serveurs IMAP et SMTP se remplissent tout seuls. Réglez la fréquence de relève et ce que deviennent les messages relevés chez le fournisseur (conservés comme sauvegarde, ou supprimés pour libérer de l'espace). Les mots de passe des boîtes restant inconnus des utilisateurs, ceux-ci consultent leur courrier sur téléphone avec l'application <?= $b ?> et ses notifications (voir <a href="#mobile">Sur téléphone</a>), et non avec une application de messagerie IMAP.</li>
      <li>Dans la fiche de chaque utilisateur, bloc « Boîte chez le fournisseur », saisissez le <strong>mot de passe de sa boîte</strong>. Il est distinct de son mot de passe du webmail, conservé chiffré et jamais réaffiché. « Tester la connexion » vérifie IMAP et SMTP. Pour relier un lot de comptes, ajoutez ce mot de passe en 8ᵉ colonne de l'import CSV.</li>
      <li>La pastille de chaque utilisateur dans la liste indique l'état : <span class="badge badge-soft-success">Reliée</span> <span class="badge badge-soft-warning">En attente</span> <span class="badge badge-soft-danger">Erreur</span> <span class="badge badge-soft-secondary">Non reliée</span>. Une boîte en erreur a généralement un mot de passe changé chez le fournisseur : mettez-le à jour.</li>
    </ol>
    <p>Comme le serveur détient les identifiants de chaque boîte, tout fonctionne même quand l'utilisateur n'est pas connecté : la tâche planifiée relève les boîtes, applique les <strong>règles</strong>, envoie les <strong>réponses d'absence</strong> et les <strong>transferts</strong>. Un délégué ou un administrateur envoie avec la boîte de la personne. Les messages entre collègues passent aussi par le fournisseur, pour que chacun les retrouve aussi sur son téléphone.</p>
    <div class="m4w-help-note info"><i class="bi bi-info-circle"></i><div>Les fournisseurs n'envoient qu'au nom de la boîte connectée. Un message transféré part donc depuis la boîte de l'utilisateur, avec le nom de l'auteur d'origine (« Client (via Julie) ») et son adresse en réponse. Les limites d'envoi du fournisseur s'appliquent à chaque boîte.</div></div>
    <h3 id="a-collector">Mode boîte collectrice (une seule boîte chez le fournisseur)</h3>
    <p>Utile quand le fournisseur facture ou limite les boîtes : une seule boîte, par exemple <code>mail@votre-domaine</code>, reçoit le courrier de tout le monde, et Make4WebMail le distribue.</p>
    <ol class="m4w-help-steps">
      <li>Créez la boîte collectrice chez le fournisseur. Dans <?= $go('admin/mail', 'Serveur de messagerie') ?>, choisissez « Boîte collectrice », votre fournisseur, puis saisissez l'adresse et le mot de passe de cette boîte. « Tester la connexion » vérifie IMAP et SMTP.</li>
      <li>Chez le fournisseur, créez une <strong>redirection</strong> de chaque adresse utilisée (utilisateurs et alias) vers la boîte collectrice, sans conserver de copie. La carte « Redirections à créer » donne la liste complète, à copier. Supprimez les vraies boîtes qui portent ces adresses : une boîte existante garderait le courrier pour elle.</li>
      <li>Préférez des redirections adresse par adresse à un « catch-all » : le catch-all reçoit aussi le spam envoyé à des adresses inventées.</li>
    </ol>
    <p><strong>Distribution.</strong> Make4WebMail retrouve le destinataire grâce aux traces laissées par le fournisseur (en-têtes <code>X-Original-To</code>, <code>Delivered-To</code>, lignes <code>Received … for</code>), sinon d'après les champs À et Cc. Un message reçu en plusieurs exemplaires n'est distribué qu'une fois à chacun. Un avis de non-remise revient à la personne qui avait envoyé le message. Ce qui ne peut pas être attribué (liste de diffusion, copie cachée sans trace) arrive dans la boîte choisie comme « Messages sans destinataire identifié », marqué non attribué : rien n'est perdu. Règles, absences, transferts, délégation et notifications fonctionnent comme dans les autres modes.</p>
    <p><strong>Envoi.</strong> Tout part par la boîte collectrice, avec l'adresse de l'utilisateur comme expéditeur. Les messages entre collègues sont distribués directement, sans passer par le fournisseur.</p>
    <h3>Limite d'envoi du fournisseur</h3>
    <p>Le fournisseur plafonne les envois de la boîte collectrice (OVH : <strong>200 par heure</strong>), et ce plafond est partagé par tous les utilisateurs, réponses automatiques et transferts compris. Chaque destinataire externe compte pour un. Make4WebMail tient le compte sur une heure glissante :</p>
    <ul>
      <li>la jauge <span class="m4w-help-ui"><i class="bi bi-speedometer2" aria-hidden="true"></i>Envois par la boîte collectrice</span> (tableau de bord et Serveur de messagerie) montre les envois de la dernière heure, leur répartition par tranche de 5 minutes et les plus gros expéditeurs ;</li>
      <li>dès 80 % de la limite, une pastille orange apparaît en haut de chaque écran des administrateurs ; elle passe au rouge, « Envois bloqués », quand la limite est atteinte, avec l'heure de reprise ;</li>
      <li>au-delà de la limite, rien n'est refusé : les messages attendent dans la file et partent seuls dès que la fenêtre le permet, dans l'ordre d'arrivée. L'expéditeur est prévenu (« votre message partira vers 15 h 42 ») ;</li>
      <li>si le fournisseur refuse lui-même un envoi pour dépassement, les envois sont suspendus un quart d'heure et son message est affiché dans la jauge.</li>
    </ul>
    <h3>Envoi en mode serveur ou relais</h3>
    <p>En mode relais, renseignez le serveur, le port (587 en STARTTLS ou 465 en SSL), l'identifiant et le mot de passe du service d'envoi. En mode autonome, la messagerie remet elle-même les messages aux serveurs des destinataires. Le bouton « Envoyer un test » valide la configuration, quel que soit le mode.</p>
    <h3>Authentification du domaine (modes serveur et relais)</h3>
    <ol class="m4w-help-steps">
      <li>Choisissez un sélecteur (par exemple <code>m4w</code>), générez la clé <strong>DKIM</strong> et publiez l'enregistrement TXT proposé (<code>sélecteur._domainkey.votre-domaine</code>).</li>
      <li>Publiez un enregistrement <strong>SPF</strong> autorisant votre serveur ou votre relais, par exemple <code>v=spf1 mx include:relais.exemple -all</code>.</li>
      <li>Publiez un enregistrement <strong>DMARC</strong>, en commençant par <code>v=DMARC1; p=none; rua=mailto:dmarc@votre-domaine</code>, puis passez à <code>p=quarantine</code> une fois les rapports vérifiés.</li>
    </ol>
    <p>La carte « Enregistrements DNS » de la page récapitule les valeurs à publier (MX, SPF, DKIM, DMARC).</p>
    <h3>Boîte personnelle imposée</h3>
    <p>Dans la fiche d'un utilisateur, « Imposer sa propre boîte IMAP/SMTP » relève son courrier dans sa boîte chez le fournisseur et le fait partir par son SMTP, quel que soit le mode. En mode boîte collectrice, ne redirigez pas ses adresses. Indiquez la limite d'envoi de sa boîte : une fois atteinte, ses messages peuvent partir par la boîte collectrice (les deux limites s'additionnent) ou attendre.</p>
    <h3>Réception</h3>
    <p>Le serveur de réception intégré (<code>bin/smtpd.php</code>) vérifie SPF, DKIM et DMARC. Les messages qui usurpent un de vos domaines, ou qui échouent DMARC chez l'expéditeur, sont placés dans les indésirables avec un bandeau d'alerte et ne déclenchent ni règle ni réponse automatique. Derrière Postfix (transport <code>bin/deliver.php</code>), ces contrôles sont à confier à Postfix et à son filtre. Les en-têtes posés par un antispam en amont (SpamAssassin, Rspamd, OVH) s'ajoutent au score du <a href="#a-spam">filtre antispam</a>.</p>
  </section>

  <section id="a-spam" class="m4w-help-section admin">
    <h2><i class="bi bi-funnel"></i>Filtre antispam</h2>
    <p>Dans <?= $go('admin/spam', 'Antispam') ?>, chaque message reçu obtient un score : authentification (SPF, DKIM, DMARC), avis du filtre de l'hébergeur, en-têtes anormaux, vocabulaire, liens trompeurs, demandes d'identifiants, pièces jointes dangereuses, listes noires DNS. Un correspondant à qui l'utilisateur a déjà écrit gagne un peu de confiance. Trois seuils décident :</p>
    <ul>
      <li><strong>Suspect</strong> : le message va dans les <em>Indésirables</em> de l'utilisateur, qui voit pourquoi en l'ouvrant. Il n'est pas transféré, ne déclenche ni règle ni réponse automatique.</li>
      <li><strong>Quarantaine</strong> : le message est retenu, l'utilisateur ne le voit pas. Le bouton « Quarantaine » ouvre la liste ; « Restaurer » le libère, non lu, dans la boîte de réception.</li>
      <li><strong>Supprimé</strong> : le message n'est jamais distribué ; une copie reste consultable dans les <?= $go('admin/deleted', 'Messages supprimés') ?> pendant la durée de conservation.</li>
    </ul>
    <p>Les <strong>expéditeurs autorisés</strong> passent toujours (sauf usurpation), les <strong>bloqués</strong> sont toujours supprimés. Pour régler les seuils, collez la source d'un message dans « Tester un message » : le détail du score s'affiche.</p>
    <p><strong>Apprentissage</strong> : chaque fois qu'un utilisateur déplace un message dans les Indésirables ou l'en sort (« Pas un spam »), le filtre apprend. Une fois assez d'exemples de chaque côté, il reconnaît les messages qui ressemblent à ce que l'organisation considère comme indésirable (jusqu'à +5 points) ou légitime (jusqu'à −3). Seules des empreintes de mots sont conservées.</p>
    <p><strong>Signalements d'hameçonnage</strong> : le bouton <?= $ui('shield-exclamation', 'Signaler un hameçonnage') ?> du lecteur retire le message de toutes les boîtes qui l'ont reçu (copies conservées dans les <?= $go('admin/deleted?reason=phishing', 'Messages supprimés') ?>, restaurables), vous alerte et marque l'expéditeur (+6 points sur ses prochains messages ; la liste « Expéditeurs signalés » permet d'en retirer un). Si l'expéditeur est un collègue, c'est son compte qui est signalé comme peut-être piraté.</p>
  </section>

  <section id="a-backup" class="m4w-help-section admin">
    <h2><i class="bi bi-cloud-arrow-up"></i>Sauvegarde</h2>
    <p>Dans <?= $go('admin/files', 'Fichiers') ?>, section « Sauvegarde », indiquez votre disque de sauvegarde (SFTP, FTPS, FTP ou dossier local), l'heure et les jours. Chaque nuit, un zip complet y est envoyé : base de données, courrier, configuration, et l'espace Fichiers rangé par utilisateur et par espace, lisible directement. Les plus anciennes sauvegardes sont supprimées au-delà du nombre choisi.</p>
    <ul>
      <li><strong>Tester la connexion</strong> écrit puis supprime un petit fichier sur le disque de sauvegarde.</li>
      <li><strong>Sauvegarder maintenant</strong> lance une sauvegarde dans la minute ; le résultat s'affiche dans l'historique. Un échec vous est aussi signalé par message.</li>
      <li><strong>Mot de passe du zip</strong> : recommandé, sinon quiconque lit le disque lit tout le courrier. Conservez-le en lieu sûr : sans lui, aucune restauration n'est possible.</li>
      <li><strong>Restaurer</strong> : sur le serveur, <code>php bin/restore.php fichier.zip --password=… --yes</code> (voir le fichier LISEZMOI.txt du zip).</li>
    </ul>
  </section>

  <section id="a-security" class="m4w-help-section admin">
    <h2><i class="bi bi-shield-check"></i>Politique de sécurité</h2>
    <p>Réglages de <?= $go('admin/security', 'Sécurité') ?> :</p>
    <ul>
      <li><strong>Mots de passe</strong> : longueur minimale et nombre de familles de caractères exigées.</li>
      <li><strong>Protection de la connexion</strong> : tentatives avant blocage et durée du blocage, durée d'inactivité et durée maximale d'une session, double authentification obligatoire pour les administrateurs, restriction de l'administration à certaines adresses IP (attention à ne pas vous exclure : votre adresse actuelle est affichée).</li>
      <li><strong>Délégation des boîtes</strong> : voir <a href="#a-delegation">ci-dessus</a>.</li>
      <li><strong>Liens et hameçonnage</strong> : vérification des liens au clic, clé Google Safe Browsing (ou Web Risk pour un usage commercial), flux public URLhaus mis à jour chaque jour, domaines bloqués.</li>
      <li><strong>Messagerie</strong> : blocage des images distantes, transfert externe autorisé ou limité à une liste de domaines, taille maximale des pièces jointes.</li>
      <li><strong>Fonctions</strong> : complément de signature par l'utilisateur, comptes externes, délai d'annulation d'envoi, langue par défaut.</li>
    </ul>
    <h3 id="a-guard">Comptes compromis</h3>
    <ul>
      <li><strong>Limites d'envoi</strong> par personne et par heure / par jour, en destinataires extérieurs (les collègues ne comptent pas). Au-delà, le message est refusé avec une explication.</li>
      <li><strong>Rafale suspecte</strong> (par défaut 80 destinataires extérieurs en 10 minutes) : l'envoi du compte est suspendu, ses messages en attente sont retenus, la personne et les administrateurs sont prévenus. Vous pouvez aussi choisir de désactiver le compte et de déconnecter toutes ses sessions.</li>
      <li>Dans « Comptes dont l'envoi est suspendu » : après avoir fait changer le mot de passe, <?= $ui('unlock', 'Rétablir l\'envoi') ?> relâche les messages retenus, ou supprimez-les s'il s'agit de spam.</li>
      <li>« Alertes de sécurité » regroupe les connexions inhabituelles, mots de passe erronés, transferts vers l'extérieur et « Ce n'était pas moi » ; marquez-les traitées une fois vérifiées.</li>
    </ul>
    <p>En bas de page, la liste des adresses IP en échec de connexion permet de repérer une attaque et de débloquer les comptes.</p>
  </section>

  <section id="a-health" class="m4w-help-section admin">
    <h2><i class="bi bi-heart-pulse"></i>Supervision</h2>
    <p><?= $go('admin/health', 'Supervision') ?> vérifie toutes les heures ce qui peut arrêter la messagerie sans bruit : tâche planifiée, file d'envoi bloquée, espace disque, dernière sauvegarde, antivirus, comptes chez le fournisseur, alertes de sécurité non traitées, expiration du certificat HTTPS et, pour chaque domaine, les enregistrements DNS MX, SPF, DKIM (la clé publiée doit être celle du serveur) et DMARC.</p>
    <ul>
      <li>Un point qui passe au rouge est signalé aux administrateurs, puis rappelé chaque jour, et un message confirme quand il est réglé. Le tableau de bord affiche aussi un bandeau.</li>
      <li>Indiquez une <strong>adresse d'alerte extérieure</strong> : si la messagerie elle-même est en panne, c'est la seule qui sera lue.</li>
      <li><strong>Rapports DMARC</strong> : ajoutez <code>rua=mailto:postmaster@votre-domaine</code> à l'enregistrement DMARC (avec une adresse de ce serveur). Les rapports quotidiens de Gmail, Microsoft, Yahoo… sont lus automatiquement : taux de messages authentifiés par domaine et serveurs qui envoient sans SPF ni DKIM (un service oublié à ajouter au SPF, ou une usurpation).</li>
    </ul>
  </section>

  <section id="a-logs" class="m4w-help-section admin">
    <h2><i class="bi bi-journal-text"></i>Journaux et suivi</h2>
    <ul>
      <li><strong>Flux de messages</strong> : chaque message entrant et sortant, avec son statut (remis, refusé, transféré, supprimé par une règle…). C'est le premier endroit à consulter quand un message « n'est pas arrivé ».</li>
      <li><strong>File d'attente</strong> : les messages en cours de remise et leurs erreurs. Un destinataire temporairement indisponible est retenté automatiquement ; <?= $ui('arrow-repeat', '') ?> force une nouvelle tentative.</li>
      <li><strong>Audit</strong> : connexions, modifications de comptes, accès délégués, opérations sur les fichiers et les messages supprimés, avec l'auteur, l'heure et l'adresse IP.</li>
    </ul>
  </section>

  <section id="a-ops" class="m4w-help-section admin">
    <h2><i class="bi bi-tools"></i>Exploitation</h2>
    <ul>
      <li><strong>Tâche planifiée</strong> : elle doit tourner chaque minute (<code>bin/cron.php</code>). Elle gère la file d'envoi, les envois programmés, les comptes externes, les purges (corbeilles, messages supprimés expirés) et les quotas. Le tableau de bord signale si elle ne tourne pas. Elle doit tourner <strong>sous le compte du serveur web</strong> (<code><?= e($cronUser) ?></code> ici), jamais dans la crontab de root : un message écrit par root n'est plus lisible par le serveur web et s'affiche vide.
        <ol class="mt-2">
          <li>Installer les trois lignes (maintenance, nouveaux messages chez le fournisseur dans la seconde, sauvegarde) dans la crontab de <code><?= e($cronUser) ?></code>, sans toucher à ses autres lignes ni créer de doublon :
            <pre class="m4w-help-code"><code><?= e($cronInstall) ?></code></pre></li>
          <li>Vérifier qu'elles y sont : <pre class="m4w-help-code"><code>sudo crontab -u <?= e($cronUser) ?> -l</code></pre></li>
          <li>La lancer une fois à la main pour voir ce qu'elle fait : <pre class="m4w-help-code"><code>sudo -u <?= e($cronUser) ?> <?= e($cronPhp) ?> <?= e(M4W_ROOT) ?>/bin/cron.php -v</code></pre></li>
          <li>Retirer une ancienne ligne de la crontab de root, s'il y en a une : <code>sudo crontab -l</code> pour la voir, <code>sudo crontab -e</code> pour la supprimer. Puis rendre les fichiers au serveur web : <code>sudo chown -R <?= e($cronUser) ?>: <?= e(M4W_ROOT) ?>/storage <?= e(M4W_ROOT) ?>/config</code>.</li>
        </ol>
        Pour modifier les lignes à la main : <code>sudo crontab -u <?= e($cronUser) ?> -e</code>. Autre possibilité : le fichier <code>/etc/cron.d/make4webmail</code> (installé par <code>deploy/install-debian.sh</code>), où le compte figure en 6<sup>e</sup> colonne. La page <?= $go('admin/health', 'Supervision') ?> indique la dernière exécution, et signale une tâche lancée en root.</li>
      <li><strong>Sauvegardes</strong> : sauvegardez chaque nuit le dossier <code>storage/</code> (base de données, messages, pièces jointes, fichiers) et <code>config/config.php</code>, qui contient la clé de chiffrement. Sans cette clé, les secrets ne sont plus lisibles. Testez régulièrement une restauration.</li>
      <li><strong>Mises à jour</strong> : depuis <?= $go('admin/update', 'Mise à jour') ?> (super administrateur), l'application se met à jour depuis son dépôt GitHub, quand vous cliquez. Connectez une fois le compte GitHub avec un jeton d'accès personnel en lecture seule sur le dépôt (GitHub n'accepte plus le mot de passe pour cela ; la page explique comment le créer). « Vérifier » montre la version disponible et les dernières modifications ; « Mettre à jour » sauvegarde d'abord les fichiers qui changent, puis copie seulement ceux de l'application (jamais les tests, les exemples, <code>storage/</code> ni <code>config/config.php</code>). « Revenir à … » remet la version précédente. Les évolutions de la base s'appliquent automatiquement. À la main, on peut aussi remplacer les fichiers en conservant <code>storage/</code> et <code>config/</code>.</li>
      <li id="a-limits"><strong>Limites de connexion chez le fournisseur</strong> : OVH, comme les autres fournisseurs, limite les connexions IMAP venant d'une même adresse (par seconde, par minute, simultanées sur une même boîte), sans publier ses chiffres. Toutes les instances de <?= $b ?> installées sur ce serveur partagent cette adresse, donc elles tiennent un registre commun (un fichier par serveur IMAP dans <code><?= e(M4W\Service\ImapLimits::dir()) ?></code>). Elles étalent ainsi les ouvertures de connexion, ne dépassent pas le nombre de connexions par boîte pour les relèves en arrière-plan, et se mettent en pause quand le fournisseur refuse une connexion : 5 minutes, puis le double à chaque nouveau refus, une heure au plus. Pendant ce temps, les utilisateurs continuent de lire leur courrier. La page <?= $go('admin/health', 'Supervision') ?> affiche une ligne par serveur IMAP : connexions ouvertes, nombre d'instances, pointes sur 24 h, refus reçus, pause en cours. Les limites se règlent dans <?= $go('admin/mail', 'Messagerie') ?>. Si les instances tournent sous des comptes différents, ou si le serveur web isole son dossier temporaire (PrivateTmp), indiquez le même dossier dans <code>config/config.php</code> de chaque instance : <code>'imap_ledger_dir' => '/var/lib/make4webmail-imap'</code>, un dossier accessible en écriture au serveur web.</li>
      <li><strong>Outils en ligne de commande</strong> : <code>php bin/user.php list|create|password|enable|disable</code> (dépannage d'un compte administrateur), <code>php bin/cron.php -v</code> (exécution manuelle et détaillée de la maintenance).</li>
    </ul>
  </section>
<?php endif; ?>

  <footer class="m4w-help-footer">
    <span><?= $b ?> · Make4WebMail v<?= e(M4W_VERSION) ?></span>
    <a href="#start" class="ms-auto"><i class="bi bi-arrow-up me-1"></i>Haut de page</a>
  </footer>
</div>
