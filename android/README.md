# Make4WebMail pour Android

L'application affiche la messagerie elle-même (les mêmes pages que dans le navigateur : tout ce
qui est fait pour le web y est aussitôt) et ajoute de vraies notifications : elles arrivent même
téléphone verrouillé, avec le son choisi dans Paramètres (les sons personnels aussi), sans
passer par les services de Google (pas de Firebase).

## Installer

- `apk/make4webmail.apk` : l'application, signée, prête à installer. La messagerie la sert aussi
  à l'adresse `/app/android.apk` (Paramètres › Téléphone et agenda › Application Android, avec
  un QR code).
- Au premier lancement : l'adresse de la messagerie, ou le second QR code de la même page
  (`m4wapp://open?url=…`). On se connecte : les notifications s'activent d'elles-mêmes ;
  Android demande l'autorisation d'afficher des notifications, puis de fonctionner sans
  restriction de batterie (pour être prévenu sans délai, téléphone en veille).

## Comment arrivent les notifications

Un service de l'application (« remote messaging ») garde une attente ouverte sur la messagerie
(`api/native/wait`, 50 s, renouvelée) : ce que la PWA reçoit par Web Push lui est remis au même
moment (`M4W\Service\NativePush`, file `native_queue`). L'application affiche la notification et
joue elle-même le son choisi, sauf téléphone en silencieux ou en Ne pas déranger. Application au
premier plan : les nouveaux messages sont annoncés par la page, comme dans le navigateur.

Une notification discrète « À l'écoute des nouveaux messages » reste affichée (Android l'exige
pour un service permanent) ; on peut la masquer dans les réglages des notifications d'Android
(catégorie « Connexion à la messagerie »).

Se déconnecter de la messagerie dans l'application arrête les notifications de ce téléphone.
Paramètres › Téléphone et agenda › Application Android : les téléphones, et « Déconnecter ».

## Construire

```sh
./build.sh        # → apk/make4webmail.apk
```

Il faut un JDK 17 ou plus et le SDK Android (`ANDROID_HOME`, ou `sdk.dir` dans
`local.properties`) : plateforme 35, build-tools 35. Le numéro de version est celui de la
messagerie (`app/bootstrap.php`) ; reconstruire l'APK après une mise à jour de l'application.

## Signature

La clé qui signe l'APK (`keystore/make4webmail.jks` et `keystore/keystore.properties` : chemin,
alias, mots de passe) n'est **pas** dans le dépôt : elle permettrait à n'importe qui de publier
une fausse mise à jour. Gardez-en une copie en lieu sûr : Android n'installe une nouvelle version
par-dessus l'ancienne que si elle est signée par la même clé.

Sans clé, `build.sh` en crée une nouvelle (il faudra alors désinstaller l'ancienne application
avant d'installer la nouvelle). `keystore/keystore.properties` :

```
storeFile=make4webmail.jks
storePassword=…
keyAlias=make4webmail
keyPassword=…
```

Pour le Play Store : activer la signature par Google Play (Google garde la clé de l'application,
celle-ci devient la clé d'envoi) et publier un « Android App Bundle » (`./gradlew bundleRelease`).
Le Play Store demande aussi de justifier le service permanent (type « remote messaging ») et la
demande d'exemption de batterie : une messagerie sans service de Google en est le cas prévu.
