# Make4WebMail

Make4WebMail est un webmail professionnel en PHP qui fonctionne seul : le stockage des messages, le serveur SMTP de réception et le client SMTP d'envoi sont intégrés. Il gère les règles de tri, les réponses d'absence, le transfert, les signatures centralisées, un espace de fichiers partagé avec droits par dossier, l'administration des utilisateurs et la personnalisation graphique.

Chaque instance est dédiée à un client : une installation correspond à une organisation, avec sa propre base, son propre stockage et sa propre personnalisation. Rien n'est partagé entre deux instances.

Aucune dépendance Composer ni npm n'est nécessaire à l'exécution. jQuery 4, Bootstrap 5.3, Bootstrap Icons, AOS, Jodit 4 (éditeur WYSIWYG, licence MIT, avec le français), la police Inter et qrcode-generator sont fournis dans `public/assets/vendor/`. Une surcouche CSS (`public/assets/css/m4w.css`) personnalise Bootstrap, et les couleurs, l'arrondi et la police choisis par l'administrateur sont injectés via `/theme.css`.

---

## Fonctionnalités

### Messagerie (utilisateurs)
- Interface en trois volets inspirée de Gmail et d'Outlook. Le volet de lecture peut être placé à droite, en bas ou masqué. Deux densités sont disponibles (confortable ou compacte), avec un thème clair, sombre ou automatique.
- Affichage par conversation, séparateurs par date, sélection multiple (y compris par Maj + clic), actions au survol, menu contextuel et glisser-déposer vers les dossiers.
- Les actions comme archiver, supprimer, déplacer ou signaler comme indésirable peuvent être annulées depuis la notification qui suit.
- Recherche instantanée avec opérateurs (`from:`, `to:`, `subject:`, `has:attachment`, `is:unread`, `is:starred`, `before:`, `after:`, `larger:`) et formulaire de recherche avancée.
- Lecture sécurisée : le HTML est assaini côté serveur puis affiché dans une iframe isolée (sandbox + CSP stricte). Les images distantes et les pixels de suivi sont bloqués par défaut, avec un bouton « Afficher les images ». Le désabonnement en un clic (RFC 8058) est pris en charge.
- Pièces jointes : vignettes, aperçu (images, PDF, texte), téléchargement à l'unité ou en archive ZIP.
- **Antivirus et vrai type de fichier** : chaque pièce jointe reçue passe par ClamAV (clamd, protocole INSTREAM, rien n'est écrit sur disque). Un fichier infecté est remplacé par une notice : il reste listé, barré, « Retiré par l'antivirus » avec la signature détectée, et ne peut ressortir par aucun chemin (téléchargement, ZIP, transfert, .eml, Fichiers). Le type est reconnu d'après le contenu (signatures binaires) : un programme renommé en .pdf, sans extension, en double extension ou avec un caractère RTL est bloqué ; macros, archives contenant des programmes ou chiffrées, HTML/SVG et noms trompeurs demandent une confirmation. Les fichiers déposés dans l'espace Fichiers sont aussi analysés.
- Rédaction :
  - éditeur WYSIWYG Jodit auto-hébergé : polices, tailles, couleurs, listes, alignement, liens, images (collées, déposées ou importées, envoyées en pièces jointes inline), tableaux, caractères spéciaux, code source ;
  - destinataires sous forme de pastilles, avec autocomplétion sur les contacts et l'annuaire ;
  - Cc et Cci ;
  - glisser-déposer de fichiers et collage d'images ;
  - brouillons enregistrés automatiquement ;
  - annulation d'envoi et envoi programmé ;
  - priorité haute et accusé de lecture ;
  - alerte « pièce jointe oubliée ? » ;
  - transfert en ligne ou en pièce jointe, et « modifier comme nouveau ».
- **Signature centralisée et imposée** : un seul modèle actif, défini par l'administrateur, s'applique à tous les utilisateurs et à tous les messages, réponses comprises. L'utilisateur ne peut ni le modifier, ni en choisir un autre ; il en voit seulement l'aperçu. La signature est injectée côté serveur au moment de l'envoi, avant la citation, avec le logo embarqué en CID.
- **Règles de messages entrants** :
  - conditions : expéditeur, destinataires, objet, corps, en-tête, taille, pièce jointe, priorité ;
  - actions : déplacer, copier, marquer comme lu, suivre, mettre à la corbeille, transférer, répondre automatiquement, supprimer, arrêter le traitement ;
  - ordre modifiable par glisser-déposer, exécution sur les messages existants, création pré-remplie depuis un message.
- **Réponse d'absence** (RFC 3834) : période de validité, une réponse par expéditeur et par intervalle, limitation à l'interne ou aux contacts. Les listes de diffusion et les messages automatiques ne reçoivent jamais de réponse.
- **Transfert automatique** vers une ou plusieurs adresses, avec ou sans copie locale, et protection contre les boucles.
- Contacts personnels (collectés automatiquement à l'envoi), import et export vCard/CSV, annuaire de l'entreprise.
- Récupération de comptes IMAP externes, raccourcis clavier (touche `?`), notifications de bureau et manifeste PWA.
- Double authentification TOTP avec codes de secours, gestion des sessions actives et historique des connexions.
- Interface en français et en anglais.

### Fichiers (espace partagé)
- **Mes fichiers** (privé, invisible même pour les administrateurs), **Partagés avec moi**, **Espaces d'équipe** et **Corbeille**.
- Droits par dossier, hérités par tout le contenu : **Lecture** (consulter, télécharger), **Modification** (ajouter, renommer, déplacer, supprimer), **Gestion** (modifier aussi les accès). Ils s'accordent à une personne, à un service (champ « service » des utilisateurs) ou à tout le monde. Les administrateurs gèrent tous les espaces d'équipe.
- **Tableur intégré, à plusieurs** (sans serveur bureautique) : xlsx, xls, ods, csv.
  - Formules Excel calculées par le serveur (PhpSpreadsheet, plus de 400 fonctions), écrites à la française (`=SOMME(A1;B1)`, `1,5`) et enregistrées en anglais dans le fichier ; saisie « 1 234,5 », « 25 % », « 12 € », « 15/10/2026 » reconnue.
  - Mise en forme (gras, couleurs, alignement, formats nombre, monétaire, date, %, bordures, fusion), lignes et colonnes, feuilles, redimensionnement, copier-coller avec Excel, annuler/rétablir, somme de la sélection.
  - Plusieurs personnes modifient en même temps : chacun voit les cellules des autres se mettre à jour, avec leur nom et leur curseur.
  - Le travail est conservé sur le serveur à chaque frappe (copie de travail, incluse dans les sauvegardes). « Enregistrer » crée une nouvelle version ; un classeur laissé ouvert est enregistré automatiquement deux minutes après le départ du dernier éditeur.
  - Ce que l'éditeur ne sait pas conserver (graphiques, tableaux croisés, macros, ancien format .xls) part dans une copie, comme une version enregistrée ailleurs entre-temps : l'original n'est jamais écrasé.
  - Si un serveur Collabora / ONLYOFFICE est configuré, il prend le relais ; le tableur intégré reste proposé.
- **Traitement de texte intégré** (sans serveur bureautique) : docx, odt, doc.
  - Page façon traitement de texte : titres, gras, couleurs, tailles, listes à puces et numérotées imbriquées, alignement, liens, images, tableaux, sauts de page, collage depuis Word.
  - Fidélité : seul le corps de `word/document.xml` est réécrit ; styles, en-têtes et pieds de page (numéros de page compris), numérotation, mise en page et propriétés du .docx restent ceux de l'original. Validé avec LibreOffice.
  - À plusieurs : une personne écrit à la fois, les autres suivent ses changements en direct et voient qui écrit ; la main se passe avec « Terminer », et un rédacteur inactif la perd après 45 secondes.
  - Le texte est conservé sur le serveur au fil de la frappe ; « Enregistrer » crée une nouvelle version, et un document laissé ouvert est enregistré deux minutes après le départ de chacun.
  - Ce que l'éditeur ne sait pas conserver (suivi des modifications, commentaires, zones de texte, notes de bas de page, table des matières automatique, équations, plusieurs sections), ainsi que les .odt et .doc, part dans une copie : l'original n'est jamais écrasé.
- **Aperçu intégré** sans télécharger : images, PDF, audio, vidéo, et rendu HTML côté serveur des .docx, .xlsx, .pptx, .odt, CSV et fichiers texte. Ce rendu n'utilise aucun service externe ; il est protégé contre les bombes zip et les entités XML, et servi sous CSP `sandbox`. Les anciens formats (.doc, .xls, .ppt) passent par le serveur bureautique s'il y en a un.
- **Édition en ligne collaborative** (Word, Excel, PowerPoint, OpenDocument, texte) par un serveur **Collabora Online** ou **ONLYOFFICE Docs**, via le protocole WOPI. Make4WebMail est l'hôte WOPI :
  - jetons d'accès aléatoires, conservés hachés, propres à un document et à une personne, valables 10 h ;
  - droits revérifiés à chaque appel, et un lecteur n'écrit jamais ;
  - verrous WOPI, et refus d'écraser une version enregistrée entre-temps (`X-COOL-WOPI-Timestamp`) ;
  - chaque enregistrement devient une version.
  
  Plusieurs personnes qui ouvrent le même document le modifient ensemble, ce qui a été vérifié avec Collabora Online 26.04. Sans serveur bureautique, un éditeur intégré modifie les fichiers texte, avec détection des conflits.
- **Compresser (ZIP)** une sélection de fichiers et dossiers, l'archive étant créée dans le dossier courant.
- **Date de péremption obligatoire** : 1 an par défaut, 3 ans au plus, réglable par l'administrateur.
  - Rappels à l'auteur 1 mois, 1 semaine et 2 jours avant, avec un lien pour changer la date.
  - Sans action, le document est archivé : il n'est plus visible ni partagé, et il est envoyé par e-mail à son auteur, ou à un administrateur si l'auteur n'a plus de compte.
  - Les administrateurs peuvent restaurer, télécharger ou supprimer les documents archivés.
- **Pas de lien public** : les liens ne valent que pour les utilisateurs de la messagerie. Un fichier trop gros pour un e-mail déclenche une proposition de services de transfert (SwissTransfer, Smash, WeTransfer, Grosfichiers ; liste réglable).
- **Partage d'un fichier seul**, sans son dossier : en lecture (consulter, télécharger, copier, joindre à un message), à une personne, un service ou tout le monde, par la personne qui gère le dossier. Le fichier apparaît dans « Partagés avec moi » ; les fichiers voisins et le dossier restent privés, et l'accès disparaît si le dossier part à la corbeille.
- Import par glisser-déposer de fichiers et de dossiers entiers, avec progression. Un fichier du même nom peut devenir une nouvelle version (historique consultable et restaurable) ou être conservé à côté.
- Vue liste ou vignettes, tri, sélection multiple, menu contextuel, déplacement par glisser-déposer (y compris sur le fil d'Ariane et la barre latérale), copie, renommage, recherche, aperçu (images, PDF, texte, audio, vidéo), téléchargement d'un dossier en ZIP.
- Intégration à la messagerie : **joindre depuis Fichiers** dans la fenêtre de rédaction, **enregistrer dans Fichiers** une pièce jointe reçue, « Envoyer par e-mail » depuis Fichiers.
- Stockage en base de données (blocs de 1 Mo) derrière une interface `BlobStore` : passer sur disque local se fait dans `config/config.php` (`'files' => ['storage' => 'local', 'path' => '/srv/files']`), et un stockage S3 ne demande qu'une nouvelle classe.
- Administration : taille maximale, quota total, nombre de versions conservées, durée de la corbeille, création d'espaces par les utilisateurs.

### Agenda, invitations et synchronisation des téléphones
- Agenda jour / semaine / mois / liste, glisser-déposer pour déplacer ou allonger un événement, répétitions (quotidienne, jours ouvrés, hebdomadaire, mensuelle, annuelle, avec date de fin), « cette occurrence ou toute la série », rappels envoyés en notification sur les appareils.
- Agendas multiples, **partagés** en lecture ou en modification avec des collègues ou toute l'organisation ; import / export `.ics`.
- **Salles** créées par les administrateurs, avec horaires d'ouverture hebdomadaires, places, équipement et validation facultative par des gestionnaires : un événement ne propose que les salles libres et ouvertes (toutes occurrences), les demandes à valider forment une file, le demandeur est prévenu de la décision.
- **Visioconférence** dans les événements : Jitsi Meet (sans compte), Microsoft Teams, Google Meet et Zoom (réunion créée par leur API au nom de l'organisateur, ou lien personnel) ; lien « Rejoindre » dans l'événement, l'invitation et la carte d'invitation.
- **Invitations** au standard iTIP / iMIP : les collègues trouvent l'événement directement dans leur agenda, les personnes extérieures reçoivent un e-mail qu'Outlook, Gmail, Apple et Thunderbird savent accepter ; les réponses, modifications et annulations sont appliquées automatiquement (expéditeur vérifié). Une invitation reçue s'affiche en tête du message avec Accepter / Peut-être / Refuser et les conflits d'horaire ; disponibilité des collègues vérifiée avant l'envoi.
- **CalDAV / CardDAV** (`/dav/`, découverte `/.well-known/caldav`) : agenda, contacts et annuaire de l'entreprise synchronisés dans les deux sens avec iPhone (profil à installer en un geste), Android (DAVx⁵), Thunderbird. Connexion par **mots de passe d'application** (un par appareil, révocables), HTTPS obligatoire.

### Délégation des boîtes aux lettres
Dans un cadre professionnel, les boîtes aux lettres appartiennent à l'organisation : en cas d'absence ou d'urgence, il faut pouvoir les gérer.
- **Par défaut, les administrateurs peuvent ouvrir n'importe quelle boîte**, depuis la liste des utilisateurs ou la fiche d'un utilisateur. L'administrateur reste connecté sous son identité : seuls les écrans de messagerie passent sur la boîte ouverte (bandeau « Boîte de … » et bouton « Quitter » dans la barre du haut).
- **Délégués nommés** : l'administrateur peut désigner pour chaque boîte des délégués (assistant, collègue qui assure l'intérim). Ils retrouvent ces boîtes dans le menu de leur compte.
- Dans la boîte ouverte, on peut lire, classer, répondre et envoyer, et gérer la réponse d'absence, le transfert, les règles, la signature et les dossiers. Les messages partent avec le `From:` de la boîte et un en-tête `Sender:` qui nomme la personne qui les a envoyés, y compris pour les envois programmés.
- **Traçabilité** :
  - chaque ouverture est journalisée avec son motif (obligatoire pour les administrateurs, réglable), l'adresse IP, la durée et le nombre de messages envoyés ;
  - chaque action est inscrite au journal d'audit ;
  - l'utilisateur voit ce journal dans « Paramètres → Sécurité » et reçoit un message dans sa boîte à chaque ouverture (réglable).
- **Vie privée** (recommandations de la CNIL) : les messages dont l'objet commence par `[Perso]`, `[Privé]`, `[Personnel]`, `Perso :`… et les dossiers nommés « Perso », « Personnel » ou « Privé » (avec leurs sous-dossiers) sont invisibles et inaccessibles pour les délégués.
- Réglages dans « Administration → Sécurité » : accès des administrateurs, motif obligatoire, information de l'utilisateur, masquage des éléments personnels. Retirer un délégué ou désactiver l'accès des administrateurs coupe immédiatement les sessions déléguées en cours.

### Mes autres boîtes e-mail (boîtes partagées)
- Une personne peut rattacher d'autres boîtes à son compte (contact@, support@, une boîte partagée à plusieurs) et basculer de l'une à l'autre depuis le menu de son avatar. La rubrique « Mes autres boîtes e-mail », placée avant les délégations, affiche le nombre de non-lus.
- Pour rattacher une boîte, l'utilisateur fournit son mot de passe, ainsi que son code TOTP si elle a la double authentification ; un code n'est jamais accepté deux fois. Un échec compte comme une connexion ratée (verrouillage, ralentissement). Les administrateurs n'ont pas besoin du mot de passe, et peuvent désigner les membres d'une boîte depuis sa fiche.
- Ce n'est pas une délégation : pas de motif, pas de journal d'accès, pas de notification au titulaire, pas d'en-tête `Sender:`, rien de masqué. L'envoi se fait au nom de la boîte, avec sa signature. Les droits sont revérifiés à chaque requête, et les notifications push préviennent aussi les membres.

### Messages supprimés (seconde corbeille)
- Quand un message est supprimé définitivement, une copie complète (.eml compressé) est conservée dans le stockage de l'instance. C'est le cas de la corbeille vidée, de la suppression directe, de la purge automatique à 30 jours, d'une règle « supprimer » ou de la suppression d'un compte.
- La copie note qui a supprimé, quand, depuis quelle adresse IP, pour quel motif et depuis quel dossier d'origine. Elle est invisible pour les utilisateurs et ne compte pas dans leur quota.
- « Administration → Messages supprimés » :
  - recherche par boîte, motif, état et période ;
  - aperçu assaini, sans images distantes ;
  - téléchargement .eml ;
  - restauration à l'unité ou en lot, dans le dossier d'origine (ou la boîte de réception), ou dans une autre boîte, par exemple après le départ d'un collaborateur ;
  - destruction d'une copie.
- Chaque consultation, téléchargement, restauration ou destruction est inscrit au journal d'audit.
- Les messages marqués personnels (`[Perso]`, `[Privé]`…) restent masqués : objet caché, contenu non consultable, restauration uniquement dans la boîte de leur propriétaire.
- Réglages : activation, durée de conservation (365 jours par défaut, 0 = illimité, destruction automatique par la tâche planifiée), conservation des comptes supprimés et des indésirables purgés.
- Les brouillons remplacés à l'envoi ne sont pas conservés : ce n'est pas une suppression.

### Notice d'utilisation intégrée
- Page `/help`, accessible depuis le menu du compte, la grille des applications, la fenêtre des raccourcis clavier (touche `?`) et la console d'administration.
- Elle reprend la charte de l'instance (nom, couleurs, thème clair ou sombre), s'adapte au mobile et s'imprime proprement (bouton « Imprimer », FAQ dépliée).
- Les valeurs citées (délai d'annulation d'envoi, tailles maximales, durées de corbeille et de conservation, options activées) sont lues dans la configuration de l'instance.
- Quinze chapitres pour les utilisateurs, avec recherche dans la notice et suivi du chapitre en cours.
- Les douze chapitres d'administration ne sont générés côté serveur que pour les administrateurs : un utilisateur ne reçoit jamais ce contenu.
- Le texte est en français (`app/views/help/index.php`).

### Quatre modes de fonctionnement
Le mode se choisit dans « Administration → Serveur de messagerie ».

| Mode | Boîtes | Réception | Envoi | Prérequis |
|---|---|---|---|---|
| **Serveur autonome** | dans Make4WebMail | MX vers ce serveur (port 25) | directement aux MX des destinataires | port 25 entrant/sortant, DNS inverse, SPF/DKIM/DMARC |
| **Serveur + relais** | dans Make4WebMail | MX vers ce serveur (port 25) | relais SMTP (Brevo, Mailjet, Amazon SES, Scaleway TEM…) | un relais qui accepte tout le domaine |
| **Webmail** | chez le fournisseur (OVH, Infomaniak, Gandi, IONOS…) | relève IMAP de chaque boîte | SMTP du fournisseur, avec la boîte de l'expéditeur | mot de passe de chaque boîte saisi par l'administrateur |
| **Boîte collectrice** | dans Make4WebMail | une seule boîte chez le fournisseur, qui reçoit toutes les adresses par redirection | SMTP de cette boîte, au nom de chaque utilisateur | une boîte, une redirection par adresse ; limite d'envoi partagée (OVH : 200/h) |

En mode webmail :
- Le mot de passe de chaque boîte chez le fournisseur est **découplé** du mot de passe du webmail. Il est saisi dans la fiche utilisateur (ou en 8ᵉ colonne de l'import CSV) et conservé chiffré.
- Le serveur peut donc travailler seul : la tâche planifiée et chaque consultation relèvent les boîtes. Les messages relevés suivent le même circuit de distribution que dans les autres modes (règles, absence, transfert, seconde corbeille, notifications).
- Tout ce qui part au nom d'un utilisateur emprunte le SMTP du fournisseur avec sa boîte : messages, réponses d'absence et de règle, transferts, envois d'un délégué (en-tête `Sender:` en plus). Les transferts sont réécrits « Auteur (via Utilisateur) » avec `Reply-To` vers l'auteur, car un fournisseur refuse d'envoyer au nom d'un tiers.
- Les messages entre collègues passent par le fournisseur (pas de distribution locale) et la signature DKIM est laissée au fournisseur.
- Les messages relevés peuvent être conservés chez le fournisseur (sauvegarde) ou supprimés après 0, 7, 30 ou 90 jours. Comme les utilisateurs ne connaissent pas le mot de passe de leur boîte, ils consultent leur courrier sur téléphone avec l'application Make4WebMail (voir ci-dessous) et non avec une application IMAP. Un dossier indésirables du fournisseur peut être relevé vers les Indésirables.
- Un test de bout en bout (`tests/run.php`) vérifie le mode webmail contre un faux fournisseur IMAP/SMTP sur de vraies sockets. Ce faux fournisseur, comme les vrais, refuse tout expéditeur autre que la boîte authentifiée.

En mode boîte collectrice :
- Une seule boîte chez le fournisseur (par ex. `mail@domaine`) reçoit, par des redirections `a@ → mail@`, le courrier de tous les utilisateurs. Make4WebMail la relève et distribue chaque message à son destinataire, retrouvé grâce aux traces du fournisseur (`X-Original-To`, `Delivered-To`, `Received … for`), puis aux champs À et Cc. Les doublons sont écartés, les avis de non-remise reviennent à leur expéditeur, et ce qui ne peut pas être attribué va dans une boîte de rattrapage.
- Tout part par le SMTP de cette boîte, avec l'adresse de l'utilisateur comme expéditeur. Les messages entre collègues restent internes et ne consomment pas le quota.
- **Limite horaire du fournisseur** (OVH : 200 destinataires par heure), comptée sur une heure glissante. Au-delà, les messages attendent dans la file et partent seuls, dans l'ordre d'arrivée. Un refus de dépassement de la part du fournisseur suspend les envois pendant 15 minutes.
- Les administrateurs voient une jauge (tableau de bord, Serveur de messagerie) avec la répartition par tranche de 5 minutes et les plus gros expéditeurs. Une pastille orange (≥ 80 %) puis rouge (« Envois bloqués », avec l'heure de reprise) s'affiche en haut de chaque écran. L'expéditeur d'un message retardé est prévenu.

**Boîte personnelle imposée** (fiche utilisateur, dans tous les modes) : l'administrateur peut imposer à un compte sa propre boîte IMAP/SMTP chez le fournisseur. Son courrier y est relevé et part par son SMTP, sans signature DKIM locale et avec réécriture des transferts, comme en mode webmail. Une copie reçue aussi par un autre chemin n'est importée qu'une fois.
- En mode boîte collectrice, ses adresses ne sont plus à rediriger.
- Sa boîte a sa propre limite horaire. Une fois atteinte, ou si le fournisseur refuse pour dépassement, les envois **débordent sur la boîte collectrice** (option) : les deux limites s'additionnent. Sans débordement, les messages attendent sa boîte.

### Application mobile et notifications push
- Make4WebMail s'installe comme une application (PWA) sur iPhone, Android et ordinateur : manifeste, icônes (dont l'icône « maskable » d'Android et l'icône d'écran d'accueil iOS), affichage plein écran.
- **Notifications Web Push** à l'arrivée d'un message non lu dans la boîte de réception, même navigateur fermé. Toucher la notification ouvre le message. Implémentation native en PHP, sans dépendance ni service tiers : clés VAPID (RFC 8292) générées à l'installation, contenu chiffré de bout en bout jusqu'au navigateur (RFC 8291, aes128gcm), vérifié dans les tests avec le vecteur officiel de la RFC.
- Chaque utilisateur active les notifications appareil par appareil (Paramètres → Profil & préférences), envoie un test et retire un appareil perdu. Sur iPhone, l'interface explique qu'il faut d'abord installer l'application sur l'écran d'accueil (iOS 16.4+).
- L'administrateur peut couper les notifications ou passer en **mode discret** (ni expéditeur ni objet sur l'écran verrouillé). Les objets marqués personnels ne sont jamais affichés.
- Seuls les services push des navigateurs sont contactés (Google, Mozilla, Apple, Microsoft) : liste blanche contre la SSRF, abonnements expirés oubliés automatiquement.
- HTTPS est obligatoire (exigence des navigateurs).

### Administration
- **Supervision** : tâche planifiée, file d'envoi, disque, sauvegarde, antivirus, certificat HTTPS, DNS (MX, SPF, DKIM, DMARC) vérifiés chaque heure ; alertes aux administrateurs (et à une adresse extérieure) avec rappel quotidien ; lecture automatique des **rapports DMARC** agrégés (taux d'authentification, serveurs non autorisés).
- Tableau de bord : statistiques, trafic sur 14 jours, checklist de configuration, activité récente.
- **Utilisateurs** :
  - création, modification, désactivation et suppression ;
  - quotas et rôles ;
  - changement de mot de passe forcé, réinitialisation de la 2FA ;
  - actions groupées et import CSV.
- Domaines hébergés et alias.
- **Modèles de signature** : éditeur HTML avec aperçu en direct pour n'importe quel utilisateur, variables (`{{display_name}}`, `{{job_title}}`, `{{phone}}`, `{{logo}}`…) et sections conditionnelles (`{{#mobile}}…{{/mobile}}`). Un seul modèle est actif pour tous ; les autres sont des brouillons, activables en un clic. Il y a toujours un modèle actif tant qu'il en existe un.
- **Fichiers** : limites, conservation et liste des espaces d'équipe.
- **Personnalisation** (propre à l'instance) avec aperçu en direct :
  - nom et accroche, nom de l'entreprise à côté du logo (option) ;
  - logo clair et logo sombre, favicon, image de la page de connexion, image de fond du volet de lecture vide (remplir, centrer ou motif) ;
  - couleurs principale et secondaire ;
  - style du menu latéral (clair, sombre, couleur de marque), arrondi, police ;
  - message de connexion et CSS personnalisé.
- **Serveur de messagerie** : relais SMTP (STARTTLS/SSL, AUTH PLAIN/LOGIN/CRAM-MD5) ou remise directe aux MX, distribution locale, signature DKIM (génération de clés et enregistrement DNS fourni), réception, aide DNS (MX/SPF/DMARC), envoi de test.
- **Politique de sécurité** :
  - mots de passe ;
  - verrouillage après échecs ;
  - durée des sessions ;
  - 2FA obligatoire pour les administrateurs ;
  - restriction de l'administration par IP ;
  - blocage des images distantes ;
  - transfert externe autorisé ou limité à une liste de domaines ;
  - taille maximale des pièces jointes.
- Journaux : flux de messages, file d'attente (avec relance et suppression) et audit.

### Sécurité
- **Antispam qui apprend** (bayésien, partagé par l'organisation, empreintes de mots seulement) à partir des « Indésirable » / « Pas un spam », et bouton **Signaler un hameçonnage** : message retiré de toutes les boîtes (copies restaurables par l'administrateur), expéditeur marqué, administrateurs alertés, collègue au compte compromis repéré.
- **Comptes compromis** : limites d'envoi par personne (destinataires extérieurs par heure et par jour), suspension automatique de l'envoi en cas de rafale (messages en attente retenus, administrateurs et personne prévenus), alertes de connexion depuis un réseau inhabituel, de mots de passe erronés, de transfert vers l'extérieur et de changement de mot de passe, bouton « Ce n'était pas moi ».
- Mots de passe hachés en Argon2id, comparaisons à temps constant et anti-force brute (par compte et par IP).
- Sessions durcies : cookie `HttpOnly`/`SameSite`/`Secure`, régénération à la connexion, table de sessions révocables, expiration sur inactivité et durée absolue.
- Jeton CSRF sur toutes les requêtes qui modifient des données. CSP stricte sans script inline, `X-Frame-Options`, `nosniff`, `Referrer-Policy: no-referrer` et HSTS en HTTPS.
- Assainisseur HTML par liste blanche (DOM), testé contre une vingtaine de vecteurs XSS connus. Iframe en sandbox sans scripts.
- Secrets (mot de passe SMTP, clé DKIM, comptes IMAP, secret TOTP) chiffrés au repos avec libsodium.
- Messages et pièces jointes stockés hors de la racine web, fichiers créés avec `umask 027`. Requêtes préparées partout. Protection contre l'injection d'en-têtes et de commandes SMTP/IMAP, le SSRF (comptes IMAP, désabonnement : URL stricte, IP publique validée et connexion épinglée) et le relais ouvert (le démon SMTP n'accepte que les domaines hébergés).
- **Authentification des messages entrants** : SPF, vérification DKIM et alignement DMARC. Un message qui se fait passer pour un domaine hébergé sans venir de ses serveurs, ou qui échoue DMARC avec une politique `quarantine`/`reject`, va dans les indésirables avec un bandeau d'alerte, ne déclenche ni règle ni réponse automatique, et n'est jamais traité comme un message « envoyé par soi ».
- La signature DKIM n'est apposée que sur les messages dont l'expéditeur appartient au domaine signataire : les transferts conservent la signature d'origine et ne peuvent pas servir à usurper le domaine.
- Codes TOTP non rejouables ; désactiver la 2FA demande le mot de passe et un code. Verrouillage par compte et par adresse IP (un tiers ne peut pas bloquer un compte), ralentissement progressif en cas d'attaque distribuée, temps de réponse identique que le compte existe ou non.
- Espace fichiers : contrôle des droits côté serveur à chaque requête (404 si l'élément n'est pas visible), noms nettoyés, téléchargements en `attachment` sauf types sûrs, CSP `sandbox` sur tout fichier servi.
- **Anti-hameçonnage** : chaque lien d'un message reçu affiche sa vraie adresse au survol et passe, au clic, par une vérification (`/go`, signée HMAC : jamais de redirection ouverte). Site signalé (Google Safe Browsing ou Web Risk avec la clé de l'administrateur, flux public URLhaus d'abuse.ch sans clé, domaines bloqués) : bloqué et journalisé. Lien trompeur (texte affichant un autre site, adresse IP, raccourcisseur, punycode, `utilisateur@hôte`) : page montrant la vraie destination avant de continuer. Sinon, redirection immédiate sans `Referer`. Bandeaux d'alerte pour le nom d'un collègue sur une adresse extérieure (fraude au président) et pour un domaine qui imite le vôtre ; ces signaux comptent aussi dans le score antispam.
- **Filtre antispam à seuils** (Administration → Antispam) : score par tests pondérés (SPF/DKIM/DMARC, score du filtre amont qui ne peut qu'augmenter, en-têtes anormaux, vocabulaire, liens trompeurs, IP ou raccourcis, demandes d'identifiants, pièces jointes dangereuses ou infectées, listes noires DNS en option, bonus « correspondant habituel » si l'authentification est bonne). Trois seuils réglables : *suspect* (indésirables, raisons affichées au lecteur), *quarantaine* (retenu, libéré par un administrateur), *supprimé* (copie conservée pour audit). Listes d'expéditeurs autorisés et bloqués, outil de test sur une source collée. Un message indésirable n'est ni transféré ni soumis aux règles ou réponses automatiques.
- **Adresses jetables et « no-reply »** : liste de près de 10 000 domaines jetables (projet libre disposable-email-domains, mise à jour chaque semaine), complétée et assouplie par l'administrateur. Le courrier venant d'une adresse jetable (enveloppe, From, Reply-To ou Sender) est refusé par défaut : `550` dès la commande `MAIL FROM` avec le démon intégré, abandon journalisé sans avis de non-remise pour le courrier relevé ou transmis par Postfix. Il peut aussi être classé en indésirables. Aucune réponse automatique (absence, règles) ne part vers une adresse jetable ou « no-reply » (`noreply`, `ne-pas-repondre`, `mailer-daemon`…), ce qui économise le quota d'envoi, et un bandeau prévient l'utilisateur avant qu'il ne réponde. `php bin/disposable.php adresse…` indique comment une adresse est traitée.
- Antivirus ClamAV (réglable dans « Administration → Sécurité », état du démon affiché) ; marquages internes `X-M4W-AV`/`X-M4W-Removed` envoyés par un tiers supprimés à la réception.
- Démon SMTP : limites de connexions (globale et par IP), refus des commandes envoyées en clair après `STARTTLS`.
- En-têtes HTTP : CSP stricte, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, COOP/CORP, HSTS (nginx/Apache aussi sur les fichiers statiques), pas de `X-Powered-By` ni de version du serveur.

---

## Licences tierces
- Navigateur (`public/assets/vendor/`) : MIT (jQuery, Bootstrap, Bootstrap Icons, AOS, Jodit, qrcode-generator), OFL (police Inter).
- PHP (`app/vendor/`, sources seules, chargées par `app/vendor/autoload.php`) : MIT (PhpSpreadsheet, PHPOffice Math, markbaker/matrix et complex, ZipStream, composer/pcre, psr/simple-cache, phpseclib pour le SFTP, paragonie/constant_time_encoding), BSD-3-Clause (sabre/dav, sabre/vobject, sabre/http, sabre/xml, sabre/event, sabre/uri pour l'agenda et CalDAV / CardDAV), MIT (psr/log) et **LGPL-3.0 pour PHPWord**.

La LGPL autorise l'usage de PHPWord dans un produit propriétaire ou un SaaS, à condition de le livrer sans modification, avec sa licence, et de laisser l'utilisateur le remplacer par une autre version (c'est le cas : ses sources sont dans `app/vendor/phpoffice/phpword`). Le webmail peut donc être distribué ou exploité sous licence propriétaire en conservant les fichiers de licence (`public/assets/vendor/*/LICENSE*`, `app/vendor/*/*/LICENSE*`).


## Prérequis
ClamAV (`clamav-daemon`, recommandé) et PHP ≥ 8.1 avec les extensions `pdo_sqlite` (ou `pdo_mysql`), `sodium`, `mbstring`, `openssl`, `dom`, `fileinfo`, `iconv`, et éventuellement `zip`, `intl` et `pcntl`. SQLite ne demande aucune configuration ; MySQL et MariaDB sont aussi pris en charge.

## Installation

### Debian / Ubuntu : script automatique
Sur un serveur Debian 12+ ou Ubuntu 22.04+, un seul script installe et vérifie tout : PHP et ses extensions, ClamAV (démon et mises à jour des signatures, test EICAR), la tâche planifiée `/etc/cron.d/make4webmail`, les droits sur `storage/` et `config/`, et en option le serveur web, le pilote MySQL et le démon SMTP.
```bash
sudo deploy/install-debian.sh --web=nginx --domain=example.com --admin=admin@example.com --password='Mot2Passe!Solide' --tests
```
Options : `--dir=` (dossier de l'application), `--user=` (utilisateur PHP, `www-data` par défaut), `--web=nginx|apache|none`, `--mysql`, `--smtpd`, `--no-clamav`, `--tests` (lance la suite de tests), `--check` (vérifie sans rien installer). Le script peut être relancé sans risque. `php bin/check.php` refait la vérification à tout moment.

Sinon, étapes manuelles :

### 1. Fichiers
Déposez le projet sur le serveur, par exemple dans `/var/www/make4webmail`, et faites pointer la racine web vers `public/`. Le serveur web doit pouvoir écrire dans `storage/` et `config/`.

### 2. Assistant
- **Web** : ouvrez le site, l'assistant `/install` s'affiche.
- **Ligne de commande** :
  ```bash
  php bin/install.php --domain=example.com --admin=admin@example.com --password='Mot2Passe!Solide' --name="Prénom Nom" --brand="Acme Mail"
  ```

### 3. Serveur web
- nginx : voir `deploy/nginx.conf`.
- Pour l'espace fichiers, alignez `upload_max_filesize` et `post_max_size` (PHP), `client_max_body_size` (nginx) et la taille maximale choisie dans « Administration → Fichiers ». En base MySQL, prévoyez `max_allowed_packet` ≥ 4 Mo.
- Apache : les fichiers `.htaccess` sont fournis.
- En développement : `php -S 0.0.0.0:8080 -t public public/router.php`.

### 4. Tâche planifiée
La tâche planifiée gère la file d'envoi, les envois programmés, les comptes externes et la purge de la corbeille et des indésirables après 30 jours. Le fichier `deploy/crontab` contient la ligne à ajouter :
```
* * * * * www-data php /var/www/make4webmail/bin/cron.php
```
Avec PHP-FPM, la file est aussi traitée après chaque requête. Un cron reste recommandé.

Comment la tâche planifiée travaille :

- **La file d'envoi** (`mail_queue`) est vidée dans l'ordre d'arrivée. Ce que l'envoi y ajoute (une règle qui transfère ou redirige, une réponse automatique) part dans le même passage : une chaîne de règles A → B → C → D n'attend pas une minute par étape.
- **Chaque tâche est isolée** : une erreur est journalisée (journal d'erreurs PHP) et les tâches suivantes s'exécutent quand même.
- **Une tâche qui fait tomber le processus** (erreur fatale, mémoire) est repérée au passage suivant, une minute plus tard. Au bout de 3 fois de suite, elle est mise de côté une heure, journalisée (journal d'audit `cron.parked`) et signalée dans Supervision.
- **Un message dont l'envoi fait tomber le processus** est remis dans la file, puis mis de côté (`parked`) à la 2ᵉ fois. Il est journalisé (`queue.parked`), visible dans Administration › Journaux › File d'envoi, d'où on peut le relancer, et les autres messages continuent de partir.

> **Pas dans la crontab de root.** Mettez la ligne dans `/etc/cron.d/make4webmail`, avec l'utilisateur du serveur web (`www-data`) en 6e colonne, et non dans `crontab -e` en root : un fichier créé par root (messages reçus, envoyés) n'est pas lisible par le serveur web, et le message s'affiche vide. Les commandes lancées en root prennent d'elles-mêmes le compte des fichiers de l'application, la page Supervision signale une tâche lancée en root ou des fichiers illisibles, et `deploy/install-debian.sh` retire une ancienne ligne d'une crontab personnelle. Pour lancer une commande à la main : `sudo -u www-data php bin/cron.php -v`. Pour réparer : `chown -R www-data:www-data storage config`.

### 5. Réception du courrier
Choisissez l'un des deux modes :
- **Démon intégré** : `php bin/smtpd.php --listen=0.0.0.0:2525` (service systemd dans `deploy/m4w-smtpd.service`). Redirigez le port 25 vers 2525 et faites pointer le MX de vos domaines vers ce serveur. STARTTLS s'active si `inbound.tls_cert` et `inbound.tls_key` sont renseignés dans `config/config.php`.
- **Derrière Postfix ou Exim** : utilisez le transport « pipe » `bin/deliver.php` (voir `deploy/postfix-master.cf`).

### 6. Envoi
Dans « Administration → Serveur de messagerie », renseignez le relais SMTP de votre fournisseur. Si vous laissez le champ vide, la messagerie remet directement les messages aux serveurs MX, ce qui demande un port 25 sortant ouvert et un DNS inverse correct. Générez la clé DKIM et publiez les enregistrements DNS proposés.

## Sauvegarde et restauration
Administration → Fichiers → **Sauvegarde** : chaque jour, à l'heure choisie (3 h, heure de Paris, par défaut ; jours et fuseau réglables), un zip est envoyé en **SFTP, FTPS, FTP** ou dans un **dossier local** (NAS monté). Il contient :
- `database/` : la base complète (copie SQLite cohérente, ou dump SQL pour MySQL) ;
- `storage/` : le courrier, la file d'envoi, les pièces en cours et les listes ;
- `config/config.php` : la configuration, avec la clé qui déchiffre les secrets enregistrés ;
- `fichiers/utilisateurs/<adresse>/…`, `fichiers/espaces/<nom>/…`, `fichiers/archives/…` : l'espace Fichiers tel qu'on le voit, lisible sans l'application.

Fonctionnement :
- Mot de passe du zip en option, chiffré en AES-256.
- Rotation : les N dernières sauvegardes sont gardées.
- Envoi sous un nom temporaire, renommé une fois complet.
- Historique, test de connexion et bouton « Sauvegarder maintenant ». Un échec est signalé dans la boîte des administrateurs.
- La sauvegarde tourne dans sa propre tâche planifiée (`bin/backup.php --if-due`, installée par le script Debian) : elle ne retarde jamais le courrier.
- Il faut sur le serveur un espace libre au moins égal à la taille de la sauvegarde ; la page l'indique.

Restauration, sur le même serveur ou un nouveau (installé sans lancer l'assistant) :
```bash
sudo -u www-data php bin/restore.php m4w-backup-2026-10-15-030000-12.zip --password='…'          # aperçu, rien n'est modifié
sudo -u www-data php bin/restore.php m4w-backup-2026-10-15-030000-12.zip --password='…' --yes    # restauration
```
L'option `--config` reprend aussi la configuration sur un serveur déjà configuré. `php bin/backup.php --now | --test | --list` permet d'agir en ligne de commande.

## Outils en ligne de commande
| Commande | Rôle |
|---|---|
| `bin/install.php` | installation |
| `bin/user.php create\|password\|enable\|disable\|delete\|list` | gestion des comptes |
| `bin/smtpd.php [--max-clients=50 --max-per-ip=5]` | serveur SMTP de réception |
| `bin/deliver.php` | agent de distribution locale (pipe MTA) |
| `bin/cron.php [-v]` | maintenance planifiée |

## Édition en ligne : serveur bureautique
Exemple avec Collabora Online (CODE) sur le même hôte que Make4WebMail :
```bash
docker run -d --name collabora -p 127.0.0.1:9980:9980 \
  -e "aliasgroup1=https://mail.exemple.fr" \
  -e "extra_params=--o:ssl.enable=false --o:ssl.termination=true --o:welcome.enable=false" \
  collabora/code
```
Publiez-le ensuite derrière votre proxy HTTPS (par ex. `https://office.exemple.fr`) et indiquez cette adresse dans Administration → Fichiers → Édition en ligne. Le bouton « Tester » lit les formats proposés par le serveur. ONLYOFFICE Docs, avec WOPI activé, se branche de la même façon.

## Tests
```bash
php tests/run.php   # 161 tests : MIME, XSS, règles, agenda et invitations, CalDAV / CardDAV, absence, transfert, SMTP réels, DKIM, SPF/DMARC, fichiers et droits, délégation, seconde corbeille, modes webmail et boîte collectrice, notifications push (chiffrement RFC 8291, VAPID), TOTP, quotas…
php tests/seed.php  # données de démonstration (développement)
```

## Architecture
```
app/
  Core/        App (routeur + middlewares), Auth, Session, Csrf, Crypto, Totp, Database, Settings, I18n, View
  Mail/        MimeParser, MimeBuilder, HtmlSanitizer, SmtpClient, ImapClient, DkimSigner, MailAuth (SPF/DKIM/DMARC), Address, Charset
  Storage/     BlobStore (interface), DatabaseStore, LocalStore, Storage (choix du stockage)
  Service/     Mailbox, Delivery, RuleEngine, Vacation, Forwarding, Composer, Transport (file d'envoi),
               Signatures, Branding, Contacts, Users, Folders, Fetcher, Installer, Files (espace partagé et droits),
               Calendar (agendas, occurrences, rappels), Scheduling (invitations iTIP / iMIP),
               Delegation (accès délégué aux boîtes, journal, éléments personnels), Retention (seconde corbeille), RemoteMailbox (mode webmail)
  Dav/         serveur CalDAV / CardDAV (sabre/dav) : principaux, agendas, carnets d'adresses, mots de passe d'application
  Controller/  Auth, Mail (API JSON), Compose, Contacts, Calendar, Files, Delegation, Retention, Help, Settings, Admin, Install, Asset
  views/       gabarits PHP (layouts, mail, settings, admin…)
  lang/        fr.php, en.php
public/        index.php (contrôleur frontal), assets/ (css, js, vendor)
bin/           smtpd, deliver, cron, install, user
storage/       base SQLite, messages .eml, pièces jointes temporaires, journaux (hors racine web)
```
Les messages sont stockés au format `.eml` brut, avec un index en base de données (métadonnées, extrait, texte pour la recherche). Les paramètres modifiables depuis l'administration sont en base ; `config/config.php` ne contient que la connexion à la base, la clé de chiffrement et les chemins.

## Numéro de version

`majeur.mineur.année.MMJJ.compteur`, par exemple `1.1.2026.1016.3` pour la troisième version publiée le 16 octobre 2026. Il s'affiche en bas du menu d'administration, dans l'aide et dans l'en-tête `X-Mailer`, et il est inscrit dans les sauvegardes.

Chaque publication (push) passe à la version suivante :

```sh
php bin/version.php bump    # même jour : compteur + 1 ; nouveau jour : compteur à 1
php bin/version.php minor   # version mineure suivante
php bin/version.php major   # version majeure suivante (mineure à 0)
php bin/version.php         # version actuelle
```
