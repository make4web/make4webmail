# Make4Web Mail

Make4Web Mail est un webmail professionnel en PHP qui fonctionne seul : le stockage des messages, le serveur SMTP de réception et le client SMTP d'envoi sont intégrés. Il gère les règles de tri, les réponses d'absence, le transfert, les signatures centralisées, un espace de fichiers partagé avec droits par dossier, l'administration des utilisateurs et la personnalisation graphique.

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
- **Signature centralisée** : l'administrateur définit le modèle et l'utilisateur en voit l'aperçu. Elle est injectée côté serveur au moment de l'envoi, avant la citation, avec le logo embarqué en CID.
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
- Import par glisser-déposer de fichiers et de dossiers entiers, avec progression. Un fichier du même nom peut devenir une nouvelle version (historique consultable et restaurable) ou être conservé à côté.
- Vue liste ou vignettes, tri, sélection multiple, menu contextuel, déplacement par glisser-déposer (y compris sur le fil d'Ariane et la barre latérale), copie, renommage, recherche, aperçu (images, PDF, texte, audio, vidéo), téléchargement d'un dossier en ZIP.
- Intégration à la messagerie : **joindre depuis Fichiers** dans la fenêtre de rédaction, **enregistrer dans Fichiers** une pièce jointe reçue, « Envoyer par e-mail » depuis Fichiers.
- Stockage en base de données (blocs de 1 Mo) derrière une interface `BlobStore` : passer sur disque local se fait dans `config/config.php` (`'files' => ['storage' => 'local', 'path' => '/srv/files']`), et un stockage S3 ne demande qu'une nouvelle classe.
- Administration : taille maximale, quota total, nombre de versions conservées, durée de la corbeille, création d'espaces par les utilisateurs.

### Administration
- Tableau de bord : statistiques, trafic sur 14 jours, checklist de configuration, activité récente.
- **Utilisateurs** :
  - création, modification, désactivation et suppression ;
  - quotas et rôles ;
  - changement de mot de passe forcé, réinitialisation de la 2FA ;
  - actions groupées et import CSV.
- Domaines hébergés et alias.
- **Modèles de signature** : éditeur HTML avec aperçu en direct pour n'importe quel utilisateur, variables (`{{display_name}}`, `{{job_title}}`, `{{phone}}`, `{{logo}}`…) et sections conditionnelles (`{{#mobile}}…{{/mobile}}`). Un modèle par défaut est défini, et l'attribution peut se faire par service.
- **Fichiers** : limites, conservation et liste des espaces d'équipe.
- **Personnalisation** (propre à l'instance) avec aperçu en direct :
  - nom et accroche ;
  - logo clair et logo sombre, favicon, image de la page de connexion ;
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
- Démon SMTP : limites de connexions (globale et par IP), refus des commandes envoyées en clair après `STARTTLS`.
- En-têtes HTTP : CSP stricte, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, COOP/CORP, HSTS (nginx/Apache aussi sur les fichiers statiques), pas de `X-Powered-By` ni de version du serveur.

---

## Licences tierces
Toutes les bibliothèques vendorisées sont sous licence permissive : MIT (jQuery, Bootstrap, Bootstrap Icons, AOS, Jodit, qrcode-generator) ou OFL (police Inter). Le webmail peut donc être distribué ou exploité en SaaS sous licence propriétaire, à condition de conserver les fichiers de licence (`public/assets/vendor/*/LICENSE*`).

## Prérequis
PHP ≥ 8.1 avec les extensions `pdo_sqlite` (ou `pdo_mysql`), `sodium`, `mbstring`, `openssl`, `dom`, `fileinfo`, `iconv`, et éventuellement `zip`, `intl` et `pcntl`. SQLite ne demande aucune configuration ; MySQL et MariaDB sont aussi pris en charge.

## Installation

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

### 5. Réception du courrier
Choisissez l'un des deux modes :
- **Démon intégré** : `php bin/smtpd.php --listen=0.0.0.0:2525` (service systemd dans `deploy/m4w-smtpd.service`). Redirigez le port 25 vers 2525 et faites pointer le MX de vos domaines vers ce serveur. STARTTLS s'active si `inbound.tls_cert` et `inbound.tls_key` sont renseignés dans `config/config.php`.
- **Derrière Postfix ou Exim** : utilisez le transport « pipe » `bin/deliver.php` (voir `deploy/postfix-master.cf`).

### 6. Envoi
Dans « Administration → Serveur de messagerie », renseignez le relais SMTP de votre fournisseur. Si vous laissez le champ vide, la messagerie remet directement les messages aux serveurs MX, ce qui demande un port 25 sortant ouvert et un DNS inverse correct. Générez la clé DKIM et publiez les enregistrements DNS proposés.

## Outils en ligne de commande
| Commande | Rôle |
|---|---|
| `bin/install.php` | installation |
| `bin/user.php create\|password\|enable\|disable\|delete\|list` | gestion des comptes |
| `bin/smtpd.php [--max-clients=50 --max-per-ip=5]` | serveur SMTP de réception |
| `bin/deliver.php` | agent de distribution locale (pipe MTA) |
| `bin/cron.php [-v]` | maintenance planifiée |

## Tests
```bash
php tests/run.php   # 80 tests : MIME, XSS, règles, absence, transfert, SMTP réels, DKIM, SPF/DMARC, fichiers et droits, TOTP, quotas…
php tests/seed.php  # données de démonstration (développement)
```

## Architecture
```
app/
  Core/        App (routeur + middlewares), Auth, Session, Csrf, Crypto, Totp, Database, Settings, I18n, View
  Mail/        MimeParser, MimeBuilder, HtmlSanitizer, SmtpClient, ImapClient, DkimSigner, MailAuth (SPF/DKIM/DMARC), Address, Charset
  Storage/     BlobStore (interface), DatabaseStore, LocalStore, Storage (choix du stockage)
  Service/     Mailbox, Delivery, RuleEngine, Vacation, Forwarding, Composer, Transport (file d'envoi),
               Signatures, Branding, Contacts, Users, Folders, Fetcher, Installer, Files (espace partagé et droits)
  Controller/  Auth, Mail (API JSON), Compose, Contacts, Files, Settings, Admin, Install, Asset
  views/       gabarits PHP (layouts, mail, settings, admin…)
  lang/        fr.php, en.php
public/        index.php (contrôleur frontal), assets/ (css, js, vendor)
bin/           smtpd, deliver, cron, install, user
storage/       base SQLite, messages .eml, pièces jointes temporaires, journaux (hors racine web)
```
Les messages sont stockés au format `.eml` brut, avec un index en base de données (métadonnées, extrait, texte pour la recherche). Les paramètres modifiables depuis l'administration sont en base ; `config/config.php` ne contient que la connexion à la base, la clé de chiffrement et les chemins.
