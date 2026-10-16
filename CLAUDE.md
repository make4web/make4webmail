# Make4WebMail — règles pour Claude

## Règle obligatoire : numéro de version à chaque push

**Chaque push incrémente le numéro de version, sans exception** (correctif, style, documentation, ce fichier compris).

- Format : `majeur.mineur.année.MMJJ.compteur`, par exemple `1.1.2026.1016.3` = troisième version publiée le 16 octobre 2026.
- Avant chaque commit poussé : `php bin/version.php bump`. Le même jour, le compteur augmente de 1 ; un nouveau jour, il repart à 1 (heure de Paris).
- Mineure ou majeure suivante : uniquement sur demande explicite (`php bin/version.php minor` / `major`).
- Le numéro est dans `app/bootstrap.php` (`M4W_VERSION`). Ne pas le modifier à la main.
- Indiquer la nouvelle version dans le message de commit et dans la description de la PR.

## Avant de pousser

- `php tests/run.php` doit passer entièrement. Quand le changement touche la base (migration, requêtes), lancer aussi la suite sur MariaDB : c'est la base de production.
- Ne jamais pousser de changement non testé dans le navigateur quand il touche l'interface.
- Les migrations vont dans `app/Database/schema.php`, sous un nouveau numéro de version de schéma. Ne jamais modifier une migration existante.

## Langue

- L'interface et les échanges avec l'utilisateur sont en français.
- Toute chaîne visible existe en français et en anglais (`app/lang/fr.php`, `app/lang/en.php`).
