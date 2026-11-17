#!/bin/sh
# Builds the Android app and puts it in apk/make4webmail.apk (served by the webmail at
# /app/android.apk). Needs a JDK 17+ and the Android SDK (ANDROID_HOME, or sdk.dir in
# local.properties).
#
#   ./build.sh        the APK of the current version (version.txt)
#   ./build.sh bump   a new version first: the app changed (same day: counter + 1, else a new day;
#                     Paris time). Only then do the installed apps offer to update.
set -e
cd "$(dirname "$0")"
if [ "$1" = "bump" ]; then
  OLD=$(tr -d ' \n' < version.txt)
  TODAY_Y=$(TZ=Europe/Paris date +%Y); TODAY_MD=$(TZ=Europe/Paris date +%m%d)
  NEW=$(echo "${OLD%A}" | awk -F. -v y="$TODAY_Y" -v md="$TODAY_MD" '{ c = ($3 == y && $4 == md) ? $5 + 1 : 1; printf "%s.%s.%s.%s.%dA", $1, $2, y, md, c }')
  echo "$NEW" > version.txt
  echo "Android app: $OLD -> $NEW"
fi
# No signing key (it is never in the repository): a new one, kept in keystore/ (see README.md).
if [ ! -f keystore/keystore.properties ]; then
  mkdir -p keystore
  PASS=$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)
  keytool -genkeypair -keystore keystore/make4webmail.jks -alias make4webmail -keyalg RSA -keysize 2048 -validity 10000 \
    -storepass "$PASS" -keypass "$PASS" -dname "CN=Make4Web, O=Make4Web, C=FR" >/dev/null 2>&1
  printf 'storeFile=make4webmail.jks\nstorePassword=%s\nkeyAlias=make4webmail\nkeyPassword=%s\n' "$PASS" "$PASS" > keystore/keystore.properties
  chmod 600 keystore/keystore.properties keystore/make4webmail.jks
  echo "New signing key in keystore/: keep a copy of it safe."
fi
./gradlew assembleRelease --no-daemon -q
cp app/build/outputs/apk/release/app-release.apk apk/make4webmail.apk
# What the installed apps compare with (served at /app/android.json): the same code as Gradle's.
V=$(tr -d ' \n' < version.txt)
CODE=$(echo "${V%A}" | awk -F. '{ c = $5 > 99 ? 99 : $5; printf "%d", ($3 % 100) * 1000000 + $4 * 100 + c }')
printf '{"version":"%s","code":%s}\n' "$V" "$CODE" > apk/version.json
echo "apk/make4webmail.apk: $V"
