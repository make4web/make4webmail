#!/bin/sh
# Builds the Android app and puts it in apk/make4webmail.apk (served by the webmail at
# /app/android.apk). Needs a JDK 17+ and the Android SDK (ANDROID_HOME, or sdk.dir in
# local.properties). The version is the webmail's (app/bootstrap.php).
set -e
cd "$(dirname "$0")"
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
V=$(grep -o "M4W_VERSION', '[^']*" ../app/bootstrap.php | cut -d"'" -f3)
CODE=$(echo "$V" | awk -F. '{ c = $5 > 99 ? 99 : $5; printf "%d", ($3 % 100) * 1000000 + $4 * 100 + c }')
printf '{"version":"%s","code":%s}\n' "$V" "$CODE" > apk/version.json
echo "apk/make4webmail.apk: $(grep -o "M4W_VERSION', '[^']*" ../app/bootstrap.php | cut -d"'" -f3)"
