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
      <li><strong>Lettres d'information</strong> : un bandeau propose de vous désabonner en un clic quand l'expéditeur le permet.</li>
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
    <p>Pendant votre absence, un rappel s'affiche en haut de la liste des dossiers. Par sécurité, il n'est jamais répondu aux listes de diffusion, aux messages automatiques ni aux messages signalés comme indésirables.</p>
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
      <li>La signature est fixée par l'administration : il n'est pas possible d'en choisir une autre ni d'y ajouter un texte personnel. <?= $go('settings/signature', 'Paramètres → Signature') ?> vous en montre l'aperçu.</li>
    </ul>
  </section>

  <section id="contacts" class="m4w-help-section">
    <h2><i class="bi bi-people"></i>Contacts et annuaire</h2>
    <ul>
      <li><?= $go('contacts', 'Mes contacts') ?> : vos contacts personnels. Les personnes à qui vous écrivez y sont ajoutées automatiquement (mention « auto »). Ajoutez, modifiez, mettez en favori ou supprimez-les.</li>
      <li><strong>Importer / exporter</strong> : au format vCard (.vcf) ou CSV, depuis le bouton <?= $ui('arrow-down-up', 'Importer / exporter') ?>.</li>
      <li><?= $go('contacts?tab=directory', 'Annuaire') ?> : tous les collaborateurs de l'organisation, avec leur fonction, leur service et leurs téléphones. <?= $ui('envelope', 'Écrire') ?> ouvre directement un message.</li>
    </ul>
  </section>

  <section id="files" class="m4w-help-section">
    <h2><i class="bi bi-folder2-open"></i>Fichiers partagés</h2>
    <p>L'application <?= $go('files', 'Fichiers') ?> (menu <?= $ui('grid-3x3-gap', '') ?> ou colonne de gauche de la messagerie) stocke et partage des documents au sein de l'organisation.</p>
    <dl class="m4w-help-dl">
      <dt><i class="bi bi-person-workspace"></i>Mes fichiers</dt><dd>Votre espace privé. Personne d'autre n'y a accès, pas même les administrateurs, sauf les dossiers que vous partagez.</dd>
      <dt><i class="bi bi-people"></i>Partagés avec moi</dt><dd>Les dossiers que vos collègues ont partagés avec vous.</dd>
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
    <p>Les droits s'appliquent au dossier et à tout ce qu'il contient. Le lien « copier le lien interne » ne fonctionne que pour les personnes qui ont déjà accès au dossier.</p>
    <h3>Avec la messagerie</h3>
    <p>En rédaction, <?= $ui('folder2-open', '') ?> joint un fichier de l'espace. Dans un message reçu, <?= $ui('folder-plus', '') ?> enregistre une pièce jointe dans le dossier de votre choix.</p>
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
      <li>« Envoyer un test » vérifie que l'appareil reçoit bien les alertes.</li>
      <li>Répétez l'opération sur chaque appareil : la liste « Appareils enregistrés » les montre tous, et vous pouvez en retirer un (téléphone perdu ou remplacé).</li>
    </ol>
    <p>Une notification est envoyée pour chaque nouveau message non lu qui arrive dans votre boîte de réception ; toucher la notification ouvre le message. Les messages rangés par une règle dans un autre dossier ne déclenchent pas d'alerte.</p>
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
    <p>Un modèle HTML, défini dans <?= $go('admin/signatures', 'Signatures') ?>, est rempli avec les informations de chaque utilisateur et ajouté à l'envoi. L'aperçu en direct permet de tester le rendu pour n'importe quel utilisateur.</p>
    <table class="table table-sm m4w-help-table">
      <thead><tr><th>Variable</th><th>Contenu</th></tr></thead>
      <tbody>
        <tr><td><code>{{display_name}}</code> <code>{{first_name}}</code> <code>{{last_name}}</code> <code>{{initials}}</code></td><td>identité</td></tr>
        <tr><td><code>{{job_title}}</code> <code>{{department}}</code></td><td>fonction et service</td></tr>
        <tr><td><code>{{email}}</code> <code>{{phone}}</code> <code>{{mobile}}</code></td><td>coordonnées</td></tr>
        <tr><td><code>{{company}}</code> <code>{{address}}</code> <code>{{website}}</code> <code>{{website_label}}</code></td><td>entreprise (Personnalisation)</td></tr>
        <tr><td><code>{{logo}}</code> <code>{{logo_url}}</code> <code>{{primary_color}}</code></td><td>logo (intégré au message) et couleur de la charte</td></tr>
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
    <h3>Réception</h3>
    <p>Le serveur de réception intégré (<code>bin/smtpd.php</code>) vérifie SPF, DKIM et DMARC. Les messages qui usurpent un de vos domaines, ou qui échouent DMARC chez l'expéditeur, sont placés dans les indésirables avec un bandeau d'alerte et ne déclenchent ni règle ni réponse automatique. Derrière Postfix (transport <code>bin/deliver.php</code>), ces contrôles sont à confier à Postfix et à son filtre. Les en-têtes <code>X-Spam-Flag</code> / <code>X-Spam-Status</code> posés par un antispam en amont (SpamAssassin, Rspamd) classent le message en indésirable si l'option est active.</p>
  </section>

  <section id="a-security" class="m4w-help-section admin">
    <h2><i class="bi bi-shield-check"></i>Politique de sécurité</h2>
    <p>Réglages de <?= $go('admin/security', 'Sécurité') ?> :</p>
    <ul>
      <li><strong>Mots de passe</strong> : longueur minimale et nombre de familles de caractères exigées.</li>
      <li><strong>Protection de la connexion</strong> : tentatives avant blocage et durée du blocage, durée d'inactivité et durée maximale d'une session, double authentification obligatoire pour les administrateurs, restriction de l'administration à certaines adresses IP (attention à ne pas vous exclure : votre adresse actuelle est affichée).</li>
      <li><strong>Délégation des boîtes</strong> : voir <a href="#a-delegation">ci-dessus</a>.</li>
      <li><strong>Messagerie</strong> : blocage des images distantes, transfert externe autorisé ou limité à une liste de domaines, taille maximale des pièces jointes.</li>
      <li><strong>Fonctions</strong> : complément de signature par l'utilisateur, comptes externes, délai d'annulation d'envoi, langue par défaut.</li>
    </ul>
    <p>En bas de page, la liste des adresses IP en échec de connexion permet de repérer une attaque et de débloquer les comptes.</p>
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
      <li><strong>Tâche planifiée</strong> : elle doit tourner chaque minute (<code>bin/cron.php</code>). Elle gère la file d'envoi, les envois programmés, les comptes externes, les purges (corbeilles, messages supprimés expirés) et les quotas. Le tableau de bord signale si elle ne tourne pas.</li>
      <li><strong>Sauvegardes</strong> : sauvegardez chaque nuit le dossier <code>storage/</code> (base de données, messages, pièces jointes, fichiers) et <code>config/config.php</code>, qui contient la clé de chiffrement. Sans cette clé, les secrets ne sont plus lisibles. Testez régulièrement une restauration.</li>
      <li><strong>Mises à jour</strong> : remplacez les fichiers de l'application en conservant <code>storage/</code> et <code>config/</code>. Les évolutions de la base s'appliquent automatiquement.</li>
      <li><strong>Outils en ligne de commande</strong> : <code>php bin/user.php list|create|password|enable|disable</code> (dépannage d'un compte administrateur), <code>php bin/cron.php -v</code> (exécution manuelle et détaillée de la maintenance).</li>
    </ul>
  </section>
<?php endif; ?>

  <footer class="m4w-help-footer">
    <span><?= $b ?> · Make4WebMail v<?= e(M4W_VERSION) ?></span>
    <a href="#start" class="ms-auto"><i class="bi bi-arrow-up me-1"></i>Haut de page</a>
  </footer>
</div>
