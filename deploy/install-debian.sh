#!/usr/bin/env bash
# Make4WebMail — server preparation for Debian (12+) and Ubuntu (22.04+).
#
# Installs and checks everything the webmail needs, then the scheduled task:
#   PHP ≥ 8.1 and its extensions, ClamAV (daemon + signature updates), cron,
#   optionally nginx/PHP-FPM or Apache, MariaDB client driver, the SMTP daemon service.
#
#   sudo deploy/install-debian.sh [options]
#
#   --dir=PATH         application folder (default: the folder holding this script's parent)
#   --user=NAME        user running PHP (default: www-data)
#   --web=nginx|apache|none   web server to install (default: none, PHP CLI only)
#   --mysql            also install the MySQL/MariaDB PHP driver
#   --smtpd            install and start the built-in inbound SMTP daemon (systemd)
#   --no-clamav        skip the antivirus (not recommended: attachments will not be scanned)
#   --tests            run the test suite at the end
#   --check            only check, install nothing
#   --domain=… --admin=… --password=… [--name=…] [--brand=…]
#                      also run the application installer if it is not installed yet
#
# Safe to run again: every step checks what is already there.
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    exec sudo -E bash "$0" "$@"
fi

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUN_USER=www-data
WEB=none
MYSQL=0
SMTPD=0
CLAMAV=1
TESTS=0
CHECK_ONLY=0
APP_ARGS=()
for arg in "$@"; do
    case "$arg" in
        --dir=*) DIR="$(cd "${arg#*=}" && pwd)" ;;
        --user=*) RUN_USER="${arg#*=}" ;;
        --web=*) WEB="${arg#*=}" ;;
        --mysql) MYSQL=1 ;;
        --smtpd) SMTPD=1 ;;
        --no-clamav) CLAMAV=0 ;;
        --tests) TESTS=1 ;;
        --check) CHECK_ONLY=1 ;;
        --domain=*|--admin=*|--password=*|--name=*|--brand=*|--lang=*) APP_ARGS+=("$arg") ;;
        -h|--help) sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $arg (see --help)" >&2; exit 64 ;;
    esac
done
case "$WEB" in nginx|apache|none) ;; *) echo "--web must be nginx, apache or none" >&2; exit 64 ;; esac

green() { printf '\033[32m%s\033[0m\n' "$*"; }
yellow() { printf '\033[33m%s\033[0m\n' "$*"; }
red() { printf '\033[31m%s\033[0m\n' "$*" >&2; }
step() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
# Start and enable a service, with or without systemd (containers).
svc() {
    if [[ -d /run/systemd/system ]]; then
        systemctl enable "$1" >/dev/null 2>&1 || true
        systemctl restart "$1"
    else
        service "$1" restart
    fi
}

[[ -f "$DIR/bin/cron.php" && -d "$DIR/app" ]] || { red "No Make4WebMail application in $DIR (use --dir=)"; exit 1; }
id "$RUN_USER" >/dev/null 2>&1 || { red "User $RUN_USER does not exist (use --user=)"; exit 1; }

step "System"
. /etc/os-release
echo "$PRETTY_NAME — application in $DIR, run as $RUN_USER"
case "${ID:-}:${ID_LIKE:-}" in
    debian:*|ubuntu:*|*:*debian*) ;;
    *) red "This script is for Debian or Ubuntu."; exit 1 ;;
esac
command -v apt-get >/dev/null || { red "apt-get not found"; exit 1; }

php_ok() { command -v php >/dev/null && php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);'; }

if [[ $CHECK_ONLY -eq 0 ]]; then
    export DEBIAN_FRONTEND=noninteractive
    step "Packages"
    apt-get update -q
    if ! apt-cache show php-cli 2>/dev/null | grep -qE '^Version: 2:8\.([1-9]|[1-9][0-9])'; then
        red "This release ships PHP < 8.1. Upgrade to Debian 12 / Ubuntu 22.04 or later, or add a PHP ≥ 8.1 repository, then run again."
        exit 1
    fi
    PKGS=(ca-certificates cron php-cli php-sqlite3 php-mbstring php-xml php-curl php-gd php-zip php-intl)
    [[ $MYSQL -eq 1 ]] && PKGS+=(php-mysql)
    [[ $CLAMAV -eq 1 ]] && PKGS+=(clamav clamav-daemon clamav-freshclam)
    case "$WEB" in
        nginx) PKGS+=(nginx php-fpm) ;;
        apache) PKGS+=(apache2 libapache2-mod-php) ;;
    esac
    apt-get install -y -q --no-install-recommends "${PKGS[@]}"
    green "Installed: ${PKGS[*]}"
fi

step "PHP"
php_ok || { red "PHP ≥ 8.1 is required (found: $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo none))"; exit 1; }
PHP_BIN="$(command -v php)"
echo "$($PHP_BIN -r 'echo PHP_VERSION;') ($PHP_BIN)"
if [[ $CHECK_ONLY -eq 0 ]]; then
    # Align upload limits with a reasonable attachment size for every installed SAPI.
    for ini_dir in /etc/php/*/{cli,fpm,apache2}/conf.d; do
        [[ -d "$ini_dir" ]] || continue
        if [[ ! -f "$ini_dir/90-make4webmail.ini" ]]; then
            printf '; Make4WebMail\nupload_max_filesize = 64M\npost_max_size = 72M\nmemory_limit = 256M\nexpose_php = Off\n' > "$ini_dir/90-make4webmail.ini"
        fi
    done
fi

if [[ $CLAMAV -eq 1 ]]; then
    step "Antivirus (ClamAV)"
    if [[ $CHECK_ONLY -eq 0 ]]; then
        mem_mb=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
        (( mem_mb < 1800 )) && yellow "Only ${mem_mb} MB of RAM: clamd needs about 1.2 GB to load its signatures."
        if ! ls /var/lib/clamav/main.c[lv]d >/dev/null 2>&1; then
            echo "Downloading the virus signatures (first time, a few minutes)…"
            systemctl stop clamav-freshclam 2>/dev/null || true
            freshclam --quiet || yellow "freshclam failed for now; the clamav-freshclam service will retry."
        fi
        svc clamav-freshclam || yellow "Could not start clamav-freshclam."
        svc clamav-daemon || yellow "Could not start clamav-daemon."
    fi
    SOCK=/var/run/clamav/clamd.ctl
    if ! ls /var/lib/clamav/main.c[lv]d >/dev/null 2>&1; then
        red "No virus signatures yet (download failed): clamd cannot start. Check the network access to database.clamav.net, run 'freshclam', then this script again."
    fi
    echo -n "Waiting for clamd ($SOCK)"
    for _ in $(seq 1 90); do
        ls /var/lib/clamav/main.c[lv]d >/dev/null 2>&1 || break
        [[ -S $SOCK ]] && break
        echo -n "."; sleep 2
    done
    echo
    if [[ -S $SOCK ]]; then
        green "clamd is listening."
    else
        red "clamd is not running: see 'journalctl -u clamav-daemon'. Attachments will be marked as not scanned."
    fi
fi

step "Permissions"
if [[ $CHECK_ONLY -eq 0 ]]; then
    install -d -o "$RUN_USER" -g "$RUN_USER" -m 750 "$DIR/storage"
    chown -R "$RUN_USER:$RUN_USER" "$DIR/storage" "$DIR/config"
    chmod 750 "$DIR/config"
    [[ -f "$DIR/config/config.php" ]] && chmod 640 "$DIR/config/config.php"
fi
sudo -u "$RUN_USER" test -w "$DIR/storage" && sudo -u "$RUN_USER" test -w "$DIR/config" \
    && green "storage/ and config/ writable by $RUN_USER" \
    || { red "storage/ or config/ not writable by $RUN_USER"; exit 1; }

if [[ ${#APP_ARGS[@]} -gt 0 && ! -f "$DIR/config/config.php" && $CHECK_ONLY -eq 0 ]]; then
    step "Application"
    sudo -u "$RUN_USER" "$PHP_BIN" "$DIR/bin/install.php" "${APP_ARGS[@]}"
fi

if [[ $CLAMAV -eq 0 && -f "$DIR/config/config.php" && $CHECK_ONLY -eq 0 ]]; then
    sudo -u "$RUN_USER" "$PHP_BIN" -r 'require $argv[1] . "/app/bootstrap.php"; M4W\Core\Settings::set("security.antivirus", 0);' "$DIR"
    yellow "Antivirus disabled (--no-clamav): attachments are not scanned. Re-enable it in Administration → Security once ClamAV is installed."
fi

step "Scheduled task"
CRON_FILE=/etc/cron.d/make4webmail
CRON_LINE="* * * * * $RUN_USER $PHP_BIN $DIR/bin/cron.php >/dev/null 2>&1"
if [[ $CHECK_ONLY -eq 0 ]]; then
    printf '# Make4WebMail scheduler: outgoing queue, scheduled sends, external accounts, images, file expiry, purge.\nSHELL=/bin/sh\nPATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n%s\n' "$CRON_LINE" > "$CRON_FILE"
    chmod 644 "$CRON_FILE"
    svc cron >/dev/null 2>&1 || yellow "Could not start cron."
fi
if grep -qF "$DIR/bin/cron.php" "$CRON_FILE" 2>/dev/null; then
    green "$CRON_FILE: $CRON_LINE"
    if [[ -f "$DIR/config/config.php" && $CHECK_ONLY -eq 0 ]]; then
        sudo -u "$RUN_USER" "$PHP_BIN" "$DIR/bin/cron.php" && green "First run OK." || red "bin/cron.php failed (see above)."
    fi
else
    red "No scheduled task in $CRON_FILE"
fi

if [[ $SMTPD -eq 1 && $CHECK_ONLY -eq 0 ]]; then
    step "Inbound SMTP daemon"
    sed -e "s#/var/www/make4webmail#$DIR#g" -e "s#/usr/bin/php#$PHP_BIN#" -e "s#^User=.*#User=$RUN_USER#" -e "s#^Group=.*#Group=$RUN_USER#" \
        "$DIR/deploy/m4w-smtpd.service" > /etc/systemd/system/m4w-smtpd.service
    systemctl daemon-reload
    systemctl enable --now m4w-smtpd
    green "m4w-smtpd listening on 2525 (redirect port 25 to it, see README)."
fi

case "$WEB" in
    nginx) step "nginx"; echo "Example site: $DIR/deploy/nginx.conf → /etc/nginx/sites-available/ (set server_name, root $DIR/public and certificates), then: nginx -t && systemctl reload nginx" ;;
    apache) step "Apache"; a2enmod rewrite headers >/dev/null 2>&1 || true; echo "Point the DocumentRoot to $DIR/public with AllowOverride All (.htaccess files are provided), then: systemctl reload apache2" ;;
esac

step "Check"
rc=0
sudo -u "$RUN_USER" "$PHP_BIN" "$DIR/bin/check.php" || rc=$?

if [[ $TESTS -eq 1 ]]; then
    step "Test suite"
    (cd "$DIR" && sudo -u "$RUN_USER" "$PHP_BIN" tests/run.php) || rc=1
fi

echo
if [[ $rc -eq 0 ]]; then
    green "Server ready."
else
    red "Some checks failed (see above)."
fi
exit $rc
