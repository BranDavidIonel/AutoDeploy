#!/usr/bin/env bash
# AutoDeploy installer for an Ubuntu server (22.04 / 24.04) with Apache 2.4 and PHP-FPM. Run as root.
#
#   git clone https://github.com/<you>/AutoDeploy.git /var/www/autodeploy
#   AUTODEPLOY_ADMIN_PASSWORD='...' bash /var/www/autodeploy/deploy/server/install.sh \
#       --domain deploy.example.com --admin-email you@example.com --setup-local-ssh --certbot
#
# Safe to run again: it only changes what is missing or different. Without options it reuses the
# settings saved in /etc/autodeploy/install.conf. Guide: docs/07-instalare-pe-server.md
# shellcheck source-path=SCRIPTDIR

set -Eeuo pipefail
umask 022

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lib.sh
. "$SCRIPT_DIR/lib.sh"
TEMPLATES=$SCRIPT_DIR/templates
trap 'on_error "$LINENO" "$BASH_COMMAND"' ERR

usage() {
    cat <<'EOF'
Usage: sudo bash deploy/server/install.sh [options]

Where and how:
  --app-dir DIR          install folder (default /var/www/autodeploy)
  --domain NAME          host name of the AutoDeploy interface, e.g. deploy.example.com
                         (without it the Apache site is skipped)
  --php BIN              PHP command line binary (default /usr/bin/php8.4)
  --user NAME            system user that runs AutoDeploy (default autodeploy)
  --composer BIN         Composer (default: /usr/local/bin/composer or composer from the PATH)
  --site-name NAME       name of the Apache site, PHP-FPM pool and queue service (default autodeploy)

Code (used only when the app folder has no code yet):
  --repo URL             git repository to clone (https URL of a public repository: no key needed)
  --branch NAME          branch to clone (default main)
  --source DIR           copy the code from this folder (default: the checkout this script is in)

Settings:
  --admin-email EMAIL    admin login; the password comes from AUTODEPLOY_ADMIN_PASSWORD in the
                         environment (never a flag), otherwise a random one is printed once.
                         With AUTODEPLOY_ADMIN_PASSWORD set, a later run resets that password.
  --app-key KEY          use this APP_KEY (base64:...), e.g. the one of your old install
                         (also INSTALL_APP_KEY in the environment)
  --build / --skip-build force / skip the front-end build (default: only when public/build is missing)

Server pieces:
  --setup-local-ssh      key pair for SSH to root@127.0.0.1 + restricted line in /root/.ssh/authorized_keys
                         (how AutoDeploy runs its commands as root; see docs/07)
  --ssh-port PORT        SSH port of this server (default: from sshd, else 22)
  --certbot              get a Let's Encrypt certificate for --domain (webroot, Apache untouched)
  --certbot-email EMAIL  email for a new Let's Encrypt account (also accepts the Let's Encrypt terms)
  --ssl-cert FILE        existing certificate (default /etc/letsencrypt/live/<domain>/fullchain.pem)
  --ssl-key FILE         its private key (default /etc/letsencrypt/live/<domain>/privkey.pem)
  --install-packages     apt-get install the PHP extensions and tools that are missing
  --fpm-pool-dir DIR     PHP-FPM pool folder (default /etc/php/<version>/fpm/pool.d)
  --fpm-service NAME     PHP-FPM service (default php<version>-fpm)
  --fpm-bin BIN          PHP-FPM binary, for the config test (default /usr/sbin/php-fpm<version>)
  --fpm-socket PATH      socket of the pool (default /run/php/php<version>-fpm-<site-name>.sock)
  --no-apache            do not write the Apache site
  --no-worker            no systemd queue worker (AutoDeploy then starts one itself when needed)
  --force                go on when the domain is already used by another Apache site, or when
                         --app-key would replace a different key of an install that has data
  -h, --help             this help

Every option above can also come from install.conf of an earlier run, or from the environment as
INSTALL_<OPTION> (e.g. INSTALL_APP_DIR, INSTALL_DOMAIN, INSTALL_PHP, INSTALL_USER, INSTALL_REPO).
EOF
}

# ---------------------------------------------------------------- settings
APP_DIR=/var/www/autodeploy
DOMAIN=
PHP_BIN=/usr/bin/php8.4
APP_USER=autodeploy
APP_HOME=
COMPOSER=
SITE_NAME=autodeploy
REPO_URL=
BRANCH=main
SOURCE_DIR=
FPM_POOL_DIR=
FPM_SERVICE=
FPM_BIN=
FPM_SOCKET=
SSL_CERT=
SSL_KEY=
SSH_PORT=
LOCAL_SSH_KEY=
NO_APACHE=0
NO_WORKER=0
WORKER_UNIT=

# Defaults of this run: the previous run, then the environment, then the options.
load_conf || true
APP_DIR=${INSTALL_APP_DIR:-$APP_DIR}
DOMAIN=${INSTALL_DOMAIN:-$DOMAIN}
PHP_BIN=${INSTALL_PHP:-$PHP_BIN}
APP_USER=${INSTALL_USER:-$APP_USER}
COMPOSER=${INSTALL_COMPOSER:-$COMPOSER}
SITE_NAME=${INSTALL_SITE_NAME:-$SITE_NAME}
REPO_URL=${INSTALL_REPO:-$REPO_URL}
BRANCH=${INSTALL_BRANCH:-$BRANCH}
SOURCE_DIR=${INSTALL_SOURCE:-$SOURCE_DIR}

APP_KEY_NEW=${INSTALL_APP_KEY:-}
ADMIN_EMAIL=${INSTALL_ADMIN_EMAIL:-${AUTODEPLOY_ADMIN_EMAIL:-}}
ADMIN_PASSWORD=${AUTODEPLOY_ADMIN_PASSWORD:-}
BUILD=auto
SETUP_LOCAL_SSH=0
CERTBOT=0
CERTBOT_EMAIL=
INSTALL_PACKAGES=0
FORCE=0

normalize_args "$@"
set -- "${ARGS[@]+"${ARGS[@]}"}"
while [ $# -gt 0 ]; do
    case $1 in
        --app-dir) need_value "$@"; APP_DIR=$2; shift 2 ;;
        --domain) need_value "$@"; DOMAIN=$2; shift 2 ;;
        --php) need_value "$@"; PHP_BIN=$2; shift 2 ;;
        --user) need_value "$@"; APP_USER=$2; shift 2 ;;
        --composer) need_value "$@"; COMPOSER=$2; shift 2 ;;
        --site-name) need_value "$@"; SITE_NAME=$2; shift 2 ;;
        --repo) need_value "$@"; REPO_URL=$2; shift 2 ;;
        --branch) need_value "$@"; BRANCH=$2; shift 2 ;;
        --source) need_value "$@"; SOURCE_DIR=$2; shift 2 ;;
        --admin-email) need_value "$@"; ADMIN_EMAIL=$2; shift 2 ;;
        --app-key) need_value "$@"; APP_KEY_NEW=$2; shift 2 ;;
        --build) BUILD=always; shift ;;
        --skip-build) BUILD=never; shift ;;
        --setup-local-ssh) SETUP_LOCAL_SSH=1; shift ;;
        --ssh-port) need_value "$@"; SSH_PORT=$2; shift 2 ;;
        --certbot) CERTBOT=1; shift ;;
        --certbot-email) need_value "$@"; CERTBOT_EMAIL=$2; shift 2 ;;
        --ssl-cert) need_value "$@"; SSL_CERT=$2; shift 2 ;;
        --ssl-key) need_value "$@"; SSL_KEY=$2; shift 2 ;;
        --install-packages) INSTALL_PACKAGES=1; shift ;;
        --fpm-pool-dir) need_value "$@"; FPM_POOL_DIR=$2; shift 2 ;;
        --fpm-service) need_value "$@"; FPM_SERVICE=$2; shift 2 ;;
        --fpm-bin) need_value "$@"; FPM_BIN=$2; shift 2 ;;
        --fpm-socket) need_value "$@"; FPM_SOCKET=$2; shift 2 ;;
        --no-apache) NO_APACHE=1; shift ;;
        --no-worker) NO_WORKER=1; shift ;;
        --force) FORCE=1; shift ;;
        -h | --help) usage; exit 0 ;;
        *) die "Unknown option: $1 (see --help)" ;;
    esac
done

APP_DIR=${APP_DIR%/}
# Always derived (not saved): the queue service follows the site name.
WORKER_UNIT=$SITE_NAME-queue
# Given values only; empty = derived from the PHP version / domain on every run (see save_settings).
FPM_POOL_DIR_ARG=$FPM_POOL_DIR FPM_SERVICE_ARG=$FPM_SERVICE FPM_BIN_ARG=$FPM_BIN FPM_SOCKET_ARG=$FPM_SOCKET
SSL_CERT_ARG=$SSL_CERT SSL_KEY_ARG=$SSL_KEY

[[ $APP_DIR =~ ^/[A-Za-z0-9._/-]+$ ]] && [ "$APP_DIR" != / ] || die "--app-dir must be an absolute path without spaces: $APP_DIR"
[[ $APP_USER =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || die "--user is not a valid user name: $APP_USER"
case $APP_USER in
    root | www-data | nobody) die "--user must be a user of its own, not $APP_USER." ;;
esac
[[ $SITE_NAME =~ ^[a-z0-9][a-z0-9_-]*$ ]] || die "--site-name may contain only a-z, 0-9, - and _: $SITE_NAME"
[[ $PHP_BIN == /* ]] || die "--php must be an absolute path: $PHP_BIN"
if [ -n "$DOMAIN" ]; then
    DOMAIN=$(printf '%s' "$DOMAIN" | tr '[:upper:]' '[:lower:]')
    [[ $DOMAIN =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] || die "--domain is not a host name: $DOMAIN"
fi
if [ -n "$SSH_PORT" ]; then
    [[ $SSH_PORT =~ ^[0-9]+$ ]] || die "--ssh-port must be a number: $SSH_PORT"
fi
if [ -n "$APP_KEY_NEW" ]; then
    [[ $APP_KEY_NEW =~ ^base64:[A-Za-z0-9+/]{43}=$ ]] || die "--app-key must look like base64:... (44 characters after base64:), as APP_KEY in .env"
fi
if [ -n "$ADMIN_EMAIL" ]; then
    [[ $ADMIN_EMAIL =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]] || die "--admin-email is not an email address: $ADMIN_EMAIL"
fi

ENV_FILE=$APP_DIR/.env

# ---------------------------------------------------------------- steps

check_system() {
    step "Checking the system"
    require_root
    command -v systemctl >/dev/null 2>&1 || die "systemd is needed (systemctl not found)."
    command -v runuser >/dev/null 2>&1 || die "runuser is missing (util-linux)."

    if [ -f /etc/os-release ]; then
        # shellcheck source=/dev/null
        . /etc/os-release
        case "${ID:-}:${VERSION_ID:-}" in
            ubuntu:22.04 | ubuntu:24.04) ok "${PRETTY_NAME:-Ubuntu}" ;;
            *) warn "Made for Ubuntu 22.04 / 24.04, this is ${PRETTY_NAME:-unknown}: going on." ;;
        esac
    fi

    if [ "$INSTALL_PACKAGES" = 1 ]; then
        install_packages
    fi

    [ -x "$PHP_BIN" ] || die "PHP not found: $PHP_BIN. Install it (e.g. apt-get install php8.4-cli php8.4-fpm from the
  ondrej/php PPA, or run again with --install-packages) or pass --php."
    "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || die "AutoDeploy needs PHP 8.3 or newer: $PHP_BIN is $("$PHP_BIN" -r 'echo PHP_VERSION;')"
    PHP_VERSION=$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
    ok "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') ($PHP_BIN)"

    local loaded ext missing=() recommended=()
    loaded=$("$PHP_BIN" -m | tr '[:upper:]' '[:lower:]')
    for ext in ctype curl dom fileinfo filter mbstring openssl pdo_sqlite phar session tokenizer xml zip zlib; do
        grep -qx "$ext" <<<"$loaded" || missing+=("$ext")
    done
    for ext in pcntl bcmath gmp intl sodium; do
        grep -qx "$ext" <<<"$loaded" || recommended+=("$ext")
    done
    if [ ${#missing[@]} -gt 0 ]; then
        die "PHP extensions missing: ${missing[*]}. Install: apt-get install $(ext_packages "${missing[@]}")
  (or run again with --install-packages)"
    fi
    if [ ${#recommended[@]} -gt 0 ]; then
        warn "Recommended PHP extensions missing: ${recommended[*]} (pcntl: a stopped worker finishes its deploy first;
  bcmath / gmp / sodium: faster SSH). apt-get install $(ext_packages "${recommended[@]}")"
    fi
    ok "PHP extensions"

    command -v git >/dev/null 2>&1 || die "git is missing: apt-get install git"
    if [ -z "$COMPOSER" ]; then
        if [ -f /usr/local/bin/composer ]; then
            COMPOSER=/usr/local/bin/composer
        else
            COMPOSER=$(command -v composer || true)
        fi
    fi
    [ -n "$COMPOSER" ] && [ -f "$COMPOSER" ] || die "Composer not found. Install it (https://getcomposer.org/download/) or pass --composer."
    ok "Composer ($COMPOSER)"
}

# The apt package of each PHP extension (ondrej/php and Ubuntu naming).
ext_packages() {
    local ext packages=()
    for ext in "$@"; do
        case $ext in
            dom | xml | simplexml | xmlreader | xmlwriter) packages+=("php$PHP_VERSION-xml") ;;
            pdo_sqlite | sqlite3) packages+=("php$PHP_VERSION-sqlite3") ;;
            curl | mbstring | zip | bcmath | gmp | intl) packages+=("php$PHP_VERSION-$ext") ;;
            pcntl) packages+=("php$PHP_VERSION-cli") ;;
            *) packages+=("php$PHP_VERSION-common") ;;
        esac
    done
    printf '%s\n' "${packages[@]}" | sort -u | tr '\n' ' '
}

install_packages() {
    local version
    if [ -x "$PHP_BIN" ]; then
        version=$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
    elif [[ $(basename "$PHP_BIN") =~ ^php([0-9]+\.[0-9]+)$ ]]; then
        version=${BASH_REMATCH[1]}
    else
        die "--install-packages: cannot tell the PHP version from $PHP_BIN (expected .../php8.4)."
    fi
    step "Installing packages (PHP $version, git, rsync, curl)"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq --no-install-recommends \
        "php$version-cli" "php$version-fpm" "php$version-sqlite3" "php$version-mbstring" "php$version-xml" \
        "php$version-curl" "php$version-zip" "php$version-bcmath" "php$version-gmp" "php$version-intl" \
        git rsync curl unzip openssh-client
    ok "Packages installed"
}

setup_user() {
    step "System user $APP_USER"
    if id -u "$APP_USER" >/dev/null 2>&1; then
        ok "User $APP_USER exists"
    else
        useradd --system --user-group --create-home --home-dir "${APP_HOME:-/home/$APP_USER}" \
            --shell /usr/sbin/nologin --comment "AutoDeploy" "$APP_USER"
        ok "Created the user $APP_USER (no login shell)"
    fi
    APP_HOME=$(getent passwd "$APP_USER" | cut -d: -f6)
    [ -n "$APP_HOME" ] && [ "$APP_HOME" != / ] || die "The user $APP_USER has no home folder."

    # An existing home keeps its mode (it could be a site folder of an existing user).
    [ -d "$APP_HOME" ] || install -d -m 0750 -o "$APP_USER" -g "$APP_USER" "$APP_HOME"
    install -d -m 0750 -o "$APP_USER" -g "$APP_USER" "$APP_HOME/bin"
    install -d -m 0700 -o "$APP_USER" -g "$APP_USER" "$APP_HOME/.ssh"

    # "php" and "composer" of this install, first in the PATH of everything AutoDeploy runs.
    ln -sfn "$PHP_BIN" "$APP_HOME/bin/php"
    chown -h "$APP_USER:$APP_USER" "$APP_HOME/bin/php"
    local wrapper
    case $(head -n 1 "$COMPOSER" 2>/dev/null || true) in
        *php*) wrapper=$(printf '#!/bin/sh\nexec %q %q "$@"' "$PHP_BIN" "$COMPOSER") ;;
        *) wrapper=$(printf '#!/bin/sh\nexec %q "$@"' "$COMPOSER") ;;
    esac
    write_file "$APP_HOME/bin/composer" 0755 "$APP_USER:$APP_USER" "$wrapper" || true
    ok "Home $APP_HOME (bin/php -> $PHP_BIN)"
}

fetch_code() {
    step "Code in $APP_DIR"
    if [ -f "$APP_DIR/artisan" ]; then
        ok "AutoDeploy is already in $APP_DIR (later updates: deploy/server/update.sh)"
        chown -R "$APP_USER:$APP_USER" "$APP_DIR"
        ensure_storage_dirs
        return 0
    fi

    if [ -n "$REPO_URL" ]; then
        if [ -d "$APP_DIR" ] && [ -n "$(ls -A "$APP_DIR")" ]; then
            die "$APP_DIR is not empty and has no AutoDeploy in it: empty it or choose another --app-dir."
        fi
        install -d -m 0751 -o "$APP_USER" -g "$APP_USER" "$APP_DIR"
        as_app git clone --branch "$BRANCH" -- "$REPO_URL" "$APP_DIR"
        SOURCE_DIR=
        ok "Cloned $BRANCH"
    else
        local src
        src=${SOURCE_DIR:-$(cd "$SCRIPT_DIR/../.." && pwd)}
        [ -f "$src/artisan" ] || die "No code: $APP_DIR is empty, no --repo given and $src has no AutoDeploy."
        SOURCE_DIR=$(cd "$src" && pwd)
        sync_source "$SOURCE_DIR"
        ok "Copied from $SOURCE_DIR"
    fi
    ensure_storage_dirs
}

# APP_URL of this run: the HTTPS address when there is a domain.
app_url() {
    if [ -n "$DOMAIN" ]; then echo "https://$DOMAIN"; else echo "http://localhost"; fi
}

setup_env() {
    step "Settings (.env)"
    local auto_worker=false content current
    [ "$NO_WORKER" = 1 ] && auto_worker=true

    if [ ! -f "$ENV_FILE" ]; then
        content=$(render "$TEMPLATES/env.production" \
            APP_URL="$(app_url)" PHP_BIN="$PHP_BIN" ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}" \
            AUTO_WORKER="$auto_worker" WORKER_UNIT="$WORKER_UNIT" SITE_NAME="$SITE_NAME")
        write_file "$ENV_FILE" 0640 "$APP_USER:$APP_USER" "$content" || true
        ok "Created $ENV_FILE (production)"
    else
        ok "$ENV_FILE exists, kept (only the values below are adjusted)"
        if [ "$(env_get "$ENV_FILE" APP_DEBUG)" = true ] || [ "$(env_get "$ENV_FILE" APP_ENV)" != production ]; then
            warn "$ENV_FILE has APP_ENV=$(env_get "$ENV_FILE" APP_ENV) / APP_DEBUG=$(env_get "$ENV_FILE" APP_DEBUG):
  on a public server use APP_ENV=production and APP_DEBUG=false (error pages would show secrets)."
        fi
    fi

    [ -n "$DOMAIN" ] && env_ensure "$ENV_FILE" APP_URL "$(app_url)"
    [ -n "$ADMIN_EMAIL" ] && env_ensure "$ENV_FILE" AUTODEPLOY_ADMIN_EMAIL "$ADMIN_EMAIL"
    env_ensure "$ENV_FILE" AUTODEPLOY_AUTO_WORKER "$auto_worker"
    current=$(env_get "$ENV_FILE" AUTODEPLOY_PHP_BINARY)
    if [ -z "$current" ] || [ ! -x "$current" ]; then
        env_ensure "$ENV_FILE" AUTODEPLOY_PHP_BINARY "$PHP_BIN"
    fi
    if [ -n "$(env_get "$ENV_FILE" AUTODEPLOY_ADMIN_PASSWORD)" ]; then
        warn "AUTODEPLOY_ADMIN_PASSWORD is set in $ENV_FILE: the seeder would reset the admin password to it.
  Empty it once you can log in (the password is kept in the database)."
    fi

    if [ -n "$APP_KEY_NEW" ]; then
        current=$(env_get "$ENV_FILE" APP_KEY)
        if [ -n "$current" ] && [ "$current" != "$APP_KEY_NEW" ] && [ "$FORCE" != 1 ] && has_data; then
            die "$ENV_FILE already has another APP_KEY and the database has servers / users: replacing the key
  makes the saved secrets unreadable. Use import-local-data.sh to bring the old database with its key,
  or --force if you really want this key."
        fi
        env_ensure "$ENV_FILE" APP_KEY "$APP_KEY_NEW"
    fi
}

# True when the database already has users or servers.
has_data() {
    local db count
    db=$(db_file)
    [ -s "$db" ] || return 1
    count=$(db_scalar "$db" "select (select count(*) from users) + (select count(*) from servers)" 2>/dev/null) || return 1
    [ "${count:-0}" -gt 0 ]
}

install_php_packages() {
    step "PHP packages (composer install --no-dev)"
    # Caches of an earlier run (or of a dev copy with dev packages) would hide changes.
    rm -f "$APP_DIR/bootstrap/cache/config.php" "$APP_DIR/bootstrap/cache/packages.php" "$APP_DIR/bootstrap/cache/services.php"
    composer_run install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist
    ok "vendor/ ready"
}

setup_app_key() {
    if [ -z "$(env_get "$ENV_FILE" APP_KEY)" ]; then
        artisan key:generate --force
        ok "New APP_KEY in .env (keep a copy somewhere safe)"
    else
        ok "APP_KEY is set"
    fi
}

setup_database() {
    step "Database (SQLite) and migrations"
    local db connection
    connection=$(env_get "$ENV_FILE" DB_CONNECTION)
    if [ "${connection:-sqlite}" = sqlite ]; then
        db=$(db_file)
        if [ ! -f "$db" ]; then
            as_app touch "$db"
            ok "Created $db"
        fi
        chmod 0640 "$db"
    fi
    artisan migrate --force
    ok "Migrations done"
}

setup_admin() {
    step "Admin account"
    local db users email
    db=$(db_file)
    users=$(db_scalar "$db" "select count(*) from users" 2>/dev/null) || users=0
    email=${ADMIN_EMAIL:-$(env_get "$ENV_FILE" AUTODEPLOY_ADMIN_EMAIL)}

    users=${users:-0}

    if [ "$users" -gt 0 ] && [ -z "$ADMIN_PASSWORD" ]; then
        ok "The admin account exists ($users user(s)), password unchanged"
        return 0
    fi
    if [ -z "$email" ] || [ "$email" = admin@example.com ]; then
        if [ "$users" -gt 0 ]; then
            die "AUTODEPLOY_ADMIN_PASSWORD is set: also pass --admin-email of the account whose password to reset."
        fi
        warn "No admin account yet. Fine if you import the database of your PC next (import-local-data.sh);
  otherwise run again with --admin-email you@example.com (and AUTODEPLOY_ADMIN_PASSWORD='...' before it)."
        return 0
    fi
    # The seeder (DatabaseSeeder) reads AUTODEPLOY_ADMIN_EMAIL / _PASSWORD: the real environment wins over .env.
    as_app AUTODEPLOY_ADMIN_EMAIL="$email" AUTODEPLOY_ADMIN_PASSWORD="$ADMIN_PASSWORD" \
        "$PHP_BIN" "$APP_DIR/artisan" --no-interaction db:seed --force
    if [ "$users" -gt 0 ]; then
        ok "Password of $email set from AUTODEPLOY_ADMIN_PASSWORD"
    elif [ -n "$ADMIN_PASSWORD" ]; then
        ok "Admin $email with the password from AUTODEPLOY_ADMIN_PASSWORD"
    else
        ok "Admin $email (the random password is printed above, once)"
    fi
}

build_assets() {
    step "Front-end (npm ci && npm run build)"
    case $BUILD in
        never)
            [ -f "$APP_DIR/public/build/manifest.json" ] || warn "No public/build/manifest.json: the pages will not load until you build or copy public/build."
            ok "Skipped (--skip-build)"
            ;;
        auto)
            if [ -f "$APP_DIR/public/build/manifest.json" ]; then
                ok "public/build exists, skipped (--build to build again)"
            else
                build_frontend
            fi
            ;;
        always) build_frontend ;;
    esac
}

optimize_app() {
    step "Caches (artisan optimize)"
    artisan optimize
    fix_permissions
    ok "Cached; $APP_DIR belongs to $APP_USER and is closed to other users (public/ readable by Apache)"
}

setup_fpm() {
    step "PHP-FPM pool $SITE_NAME"
    FPM_POOL_DIR=${FPM_POOL_DIR:-/etc/php/$PHP_VERSION/fpm/pool.d}
    FPM_SERVICE=${FPM_SERVICE:-php$PHP_VERSION-fpm}
    FPM_BIN=${FPM_BIN:-/usr/sbin/php-fpm$PHP_VERSION}
    FPM_SOCKET=${FPM_SOCKET:-/run/php/php$PHP_VERSION-fpm-$SITE_NAME.sock}

    [ -d "$FPM_POOL_DIR" ] || die "PHP-FPM pool folder not found: $FPM_POOL_DIR. Install php$PHP_VERSION-fpm
  (or pass --fpm-pool-dir, --fpm-service, --fpm-bin and --fpm-socket for another PHP-FPM)."
    [ -x "$FPM_BIN" ] || die "PHP-FPM binary not found: $FPM_BIN (pass --fpm-bin)."

    local upload pool file web_user
    upload=$(env_get "$ENV_FILE" AUTODEPLOY_MAX_UPLOAD_MB)
    [[ $upload =~ ^[0-9]+$ ]] || upload=200
    web_user=$(sed -n 's/^export APACHE_RUN_USER=//p' /etc/apache2/envvars 2>/dev/null | tail -n 1)
    web_user=${web_user:-www-data}

    pool=$(render "$TEMPLATES/php-fpm-pool.conf" \
        POOL_NAME="$SITE_NAME" APP_USER="$APP_USER" APP_HOME="$APP_HOME" APP_DIR="$APP_DIR" \
        FPM_SOCKET="$FPM_SOCKET" WEB_SERVER_USER="$web_user" UPLOAD_MB="$upload" POST_MB="$((upload + 10))")
    file=$FPM_POOL_DIR/$SITE_NAME.conf

    if write_file "$file" 0644 root:root "$pool"; then
        local test_output
        if ! test_output=$("$FPM_BIN" -t 2>&1); then
            printf '%s\n' "$test_output" >&2
            restore_file "$file"
            die "PHP-FPM configuration test failed: $file put back as it was."
        fi
        if systemctl is-active -q "$FPM_SERVICE"; then
            systemctl reload "$FPM_SERVICE"
        else
            systemctl enable -q --now "$FPM_SERVICE"
        fi
        ok "Pool written: $file"
    else
        ok "Pool up to date: $file"
        systemctl is-active -q "$FPM_SERVICE" || systemctl enable -q --now "$FPM_SERVICE"
    fi
    wait_for_socket "$FPM_SOCKET" || die "PHP-FPM did not create $FPM_SOCKET (journalctl -u $FPM_SERVICE)."
    ok "PHP runs as $APP_USER on $FPM_SOCKET"
}

# Apache sites (other than ours) whose ServerName / ServerAlias match the domain, wildcards included.
apache_domain_users() {
    local own=$1 file files=()
    for file in /etc/apache2/sites-enabled/*; do
        [ -e "$file" ] || continue
        [ "$(readlink -f "$file")" = "$(readlink -f "$own" 2>/dev/null || echo "$own")" ] && continue
        files+=("$(readlink -f "$file")")
    done
    [ ${#files[@]} -gt 0 ] || return 0
    HOST=$DOMAIN awk '
        function matches(name, host,   suffix) {
            name = tolower(name)
            sub(/:[0-9]+$/, "", name)
            if (name == host) return 1
            if (substr(name, 1, 2) == "*.") {
                suffix = substr(name, 2)
                return length(host) > length(suffix) && substr(host, length(host) - length(suffix) + 1) == suffix
            }
            return 0
        }
        tolower($1) == "servername" || tolower($1) == "serveralias" {
            for (i = 2; i <= NF; i++) if (matches($i, ENVIRON["HOST"])) { print FILENAME ": " $1 " " $i; break }
        }
    ' "${files[@]}" | sort -u
}

apache_configtest() {
    local output
    if ! output=$(apache2ctl configtest 2>&1); then
        printf '%s\n' "$output" >&2
        return 1
    fi
}

setup_apache() {
    step "Apache site $SITE_NAME"
    if [ "$NO_APACHE" = 1 ]; then
        ok "Skipped (--no-apache)"
        return 0
    fi
    if [ -z "$DOMAIN" ]; then
        warn "No --domain: the Apache site is skipped. Run again with --domain deploy.example.com."
        return 0
    fi
    command -v apache2ctl >/dev/null 2>&1 || die "Apache 2 is not installed (apache2ctl not found). Use --no-apache to skip it."

    SITE_FILE=/etc/apache2/sites-available/$SITE_NAME.conf
    local users
    users=$(apache_domain_users "$SITE_FILE")
    if [ -n "$users" ]; then
        printf '%s\n' "$users" >&2
        [ "$FORCE" = 1 ] || die "$DOMAIN is already served by the Apache site(s) above. Choose another --domain
  (a new subdomain is best) or --force to add AutoDeploy anyway (Apache uses the first matching site)."
        warn "$DOMAIN is also in the site(s) above (--force)."
    fi

    apache_configtest || die "The Apache configuration is already broken (output above): fix it first, nothing was changed."

    APACHE_CHANGED=0
    local module
    for module in proxy proxy_fcgi rewrite headers; do
        if ! a2query -q -m "$module"; then
            a2enmod -q "$module" >/dev/null
            APACHE_CHANGED=1
            info "Enabled the Apache module $module"
        fi
    done

    ACME_ROOT=/var/www/letsencrypt
    install -d -m 0755 "$ACME_ROOT" "$ACME_ROOT/.well-known" "$ACME_ROOT/.well-known/acme-challenge"

    if [ ! -f "$ACCESS_FILE" ]; then
        local access
        access=$(render "$TEMPLATES/apache-access.conf" DOMAIN="$DOMAIN" SITE_NAME="$SITE_NAME")
        write_file "$ACCESS_FILE" 0644 root:root "$access" || true
        info "Created $ACCESS_FILE (access rules, all commented out)"
    fi

    SSL_CERT=${SSL_CERT:-/etc/letsencrypt/live/$DOMAIN/fullchain.pem}
    SSL_KEY=${SSL_KEY:-/etc/letsencrypt/live/$DOMAIN/privkey.pem}

    if [ ! -f "$SSL_CERT" ] || [ ! -f "$SSL_KEY" ]; then
        install_site http
        if [ "$CERTBOT" = 1 ]; then
            run_certbot
        else
            warn "No certificate for $DOMAIN yet ($SSL_CERT): only the HTTP redirect is live.
  Run again with --certbot (DNS of $DOMAIN must point here), or pass --ssl-cert / --ssl-key."
            return 0
        fi
    fi
    if ! a2query -q -m ssl; then
        a2enmod -q ssl >/dev/null
        APACHE_CHANGED=1
        info "Enabled the Apache module ssl"
    fi
    install_site https
}

# install_site http|https : writes and enables the site, tests the config, reloads Apache.
install_site() {
    local mode=$1 content enabled_now=0 changed=$APACHE_CHANGED
    content=$(render "$TEMPLATES/apache-vhost-http.conf" \
        DOMAIN="$DOMAIN" SITE_NAME="$SITE_NAME" ACME_ROOT="$ACME_ROOT" ACCESS_FILE="$ACCESS_FILE")
    if [ "$mode" = https ]; then
        content+=$'\n'$(render "$TEMPLATES/apache-vhost-https.conf" \
            DOMAIN="$DOMAIN" SITE_NAME="$SITE_NAME" APP_DIR="$APP_DIR" APP_USER="$APP_USER" \
            FPM_SOCKET="$FPM_SOCKET" SSL_CERT="$SSL_CERT" SSL_KEY="$SSL_KEY" ACCESS_FILE="$ACCESS_FILE")
    fi

    if write_file "$SITE_FILE" 0644 root:root "$content"; then
        changed=1
    fi
    if [ ! -e "/etc/apache2/sites-enabled/$SITE_NAME.conf" ]; then
        a2ensite -q "$SITE_NAME" >/dev/null
        enabled_now=1
        changed=1
    fi

    if [ "$changed" = 1 ]; then
        if ! apache_configtest; then
            [ "$enabled_now" = 1 ] && a2dissite -q "$SITE_NAME" >/dev/null
            restore_file "$SITE_FILE"
            die "Apache configuration test failed (output above): $SITE_FILE put back as it was, Apache not reloaded."
        fi
        systemctl reload apache2
        APACHE_CHANGED=0
    fi
    if [ "$mode" = https ]; then
        ok "https://$DOMAIN -> $APP_DIR/public (HTTP redirects to HTTPS)"
    else
        ok "http://$DOMAIN answers Let's Encrypt and redirects to HTTPS"
    fi
}

run_certbot() {
    step "Let's Encrypt certificate for $DOMAIN"
    command -v certbot >/dev/null 2>&1 || die "certbot is not installed: apt-get install certbot"
    if [ -z "$(getent ahosts "$DOMAIN" 2>/dev/null)" ]; then
        warn "$DOMAIN does not resolve yet: add its DNS record (A / AAAA to this server) first."
    fi
    local args=(certonly --webroot -w "$ACME_ROOT" -d "$DOMAIN" --cert-name "$DOMAIN"
        --non-interactive --keep-until-expiring --deploy-hook "systemctl reload apache2")
    if [ -n "$CERTBOT_EMAIL" ]; then
        args+=(--email "$CERTBOT_EMAIL" --agree-tos --no-eff-email)
    fi
    certbot "${args[@]}" || die "certbot could not get the certificate (output above). The DNS of $DOMAIN must point to
  this server and port 80 must be open; the first certificate on a new certbot account also needs
  --certbot-email. Then run install.sh again."
    SSL_CERT=/etc/letsencrypt/live/$DOMAIN/fullchain.pem
    SSL_KEY=/etc/letsencrypt/live/$DOMAIN/privkey.pem
    ok "Certificate in /etc/letsencrypt/live/$DOMAIN (renewed by certbot, Apache reloaded after each renewal)"
}

setup_worker() {
    step "Queue worker ($WORKER_UNIT.service)"
    if [ "$NO_WORKER" = 1 ]; then
        ok "Skipped (--no-worker): AutoDeploy starts a worker itself when a deploy is queued"
        return 0
    fi
    local unit file changed=0 active
    file=/etc/systemd/system/$WORKER_UNIT.service
    unit=$(render "$TEMPLATES/autodeploy-queue.service" \
        WORKER_UNIT="$WORKER_UNIT" APP_DIR="$APP_DIR" APP_USER="$APP_USER" APP_HOME="$APP_HOME" PHP_BIN="$PHP_BIN")
    if write_file "$file" 0644 root:root "$unit"; then
        changed=1
        systemctl daemon-reload
    fi
    systemctl enable -q "$WORKER_UNIT"

    if ! systemctl is-active -q "$WORKER_UNIT"; then
        systemctl start "$WORKER_UNIT"
        ok "Started"
    elif [ "$changed" = 1 ]; then
        active=$(active_deployments "$(db_file)")
        if [ "$active" -gt 0 ]; then
            warn "A deploy is running: restart the worker after it ends: systemctl restart $WORKER_UNIT"
        else
            systemctl restart "$WORKER_UNIT"
            ok "Restarted (new unit file)"
        fi
    else
        # New code / settings: the worker exits after its current job and systemd starts it again.
        artisan queue:restart >/dev/null
        ok "Running (asked to reload the code: queue:restart)"
    fi
    sleep 2
    systemctl is-active -q "$WORKER_UNIT" || die "The worker does not stay up: journalctl -u $WORKER_UNIT -n 50"
}

# Value of an sshd setting for a root login from 127.0.0.1 ("" when sshd cannot tell).
sshd_value() {
    command -v sshd >/dev/null 2>&1 || return 0
    { sshd -T -C "user=root,host=localhost,addr=127.0.0.1" 2>/dev/null || true; } |
        awk -v key="$1" '$1 == key && !found { print $2; found = 1 }'
}

setup_local_ssh() {
    step "SSH to root@127.0.0.1 (how AutoDeploy runs commands as root)"
    command -v ssh-keygen >/dev/null 2>&1 && command -v ssh >/dev/null 2>&1 ||
        die "ssh / ssh-keygen are missing: apt-get install openssh-client"

    local key=$APP_HOME/.ssh/${SITE_NAME}_root_ed25519 mark="autodeploy-local:$SITE_NAME"
    local authorized=/root/.ssh/authorized_keys line key_type key_blob tmp output permit
    if [ ! -f "$key" ]; then
        as_app ssh-keygen -q -t ed25519 -N '' -C "$mark" -f "$key"
        ok "Created the key pair $key"
    else
        ok "Key pair exists: $key"
    fi
    chown "$APP_USER:$APP_USER" "$key" "$key.pub"
    chmod 0600 "$key"
    chmod 0644 "$key.pub"

    read -r key_type key_blob _ < "$key.pub"
    # Only from this machine, and only commands / SFTP: no port, agent or X11 forwarding, no pty.
    line="from=\"127.0.0.1,::1\",restrict $key_type $key_blob $mark"
    install -d -m 0700 /root/.ssh
    [ -f "$authorized" ] || install -m 0600 /dev/null "$authorized"
    if grep -qxF -- "$line" "$authorized"; then
        ok "Already in $authorized"
    else
        backup_file "$authorized"
        tmp=$(mktemp)
        MARK=$mark awk '$NF != ENVIRON["MARK"]' "$authorized" > "$tmp"
        printf '%s\n' "$line" >> "$tmp"
        cat "$tmp" > "$authorized"
        rm -f "$tmp"
        ok "Added to $authorized: from=\"127.0.0.1,::1\",restrict ... $mark"
    fi

    SSH_PORT=${SSH_PORT:-$(sshd_value port)}
    SSH_PORT=${SSH_PORT:-22}
    LOCAL_SSH_KEY=$key

    if output=$(as_app ssh -i "$key" -p "$SSH_PORT" -o BatchMode=yes -o IdentitiesOnly=yes -o ConnectTimeout=10 \
        -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR root@127.0.0.1 'id -un' 2>&1) &&
        [ "$output" = root ]; then
        ok "Test: $APP_USER -> root@127.0.0.1:$SSH_PORT works"
    else
        warn "Test SSH as $APP_USER to root@127.0.0.1:$SSH_PORT failed: $output"
        permit=$(sshd_value permitrootlogin)
        [ -n "$permit" ] && info "sshd: PermitRootLogin $permit for 127.0.0.1 (needs prohibit-password or yes)"
        info "Check: sshd -T -C user=root,host=localhost,addr=127.0.0.1 | grep -Ei 'permitrootlogin|pubkey|allowusers|listenaddress'"
    fi
}

# shellcheck disable=SC2016 # "$0" / "$@" belong to the helper script
install_artisan_helper() {
    local helper=/usr/local/bin/$SITE_NAME-artisan content
    content=$(
        printf '#!/bin/sh\n'
        printf '# php artisan for AutoDeploy, as its own user (written by deploy/server/install.sh).\n'
        printf '# Never run artisan as root in %s: files it creates in storage/ would stay root-owned.\n' "$APP_DIR"
        printf 'set -e\n'
        printf '[ "$(id -u)" = 0 ] || exec sudo -- "$0" "$@"\n'
        printf 'cd %q\n' "$APP_DIR"
        printf 'exec runuser -u %q -- env -i HOME=%q USER=%q LOGNAME=%q LANG=C.UTF-8 PATH=%q %q artisan "$@"\n' \
            "$APP_USER" "$APP_HOME" "$APP_USER" "$APP_USER" "$APP_HOME/bin:/usr/local/bin:/usr/bin:/bin" "$PHP_BIN"
    )
    write_file "$helper" 0755 root:root "$content" || true
}

# Saves the settings for the next run and for update.sh / import-local-data.sh. Paths derived from the
# PHP version or the domain are saved empty, so a new --php / --domain derives them again.
save_settings() {
    local FPM_POOL_DIR=$FPM_POOL_DIR_ARG FPM_SERVICE=$FPM_SERVICE_ARG FPM_BIN=$FPM_BIN_ARG FPM_SOCKET=$FPM_SOCKET_ARG
    local SSL_CERT=$SSL_CERT_ARG SSL_KEY=$SSL_KEY_ARG
    save_conf APP_DIR APP_USER APP_HOME PHP_BIN COMPOSER SITE_NAME DOMAIN REPO_URL BRANCH SOURCE_DIR \
        FPM_POOL_DIR FPM_SERVICE FPM_BIN FPM_SOCKET SSL_CERT SSL_KEY SSH_PORT LOCAL_SSH_KEY NO_APACHE NO_WORKER
}

summary() {
    local status email url
    email=$(env_get "$ENV_FILE" AUTODEPLOY_ADMIN_EMAIL)
    [ "$email" = admin@example.com ] && email=
    url="no Apache site yet (run again with --domain)"
    [ -n "$DOMAIN" ] && [ "$NO_APACHE" != 1 ] && url="https://$DOMAIN"
    step "Done"
    if [ "$NO_APACHE" != 1 ] && [ -n "$DOMAIN" ] && [ -f "${SSL_CERT:-/nonexistent}" ]; then
        status=$(health_status "$DOMAIN")
        if [ "$status" = 200 ]; then
            ok "https://$DOMAIN/up answers 200"
        else
            warn "https://$DOMAIN/up answered $status (logs: /var/log/apache2/$SITE_NAME-error.log, $APP_DIR/storage/logs/)"
        fi
    fi
    cat <<EOF

  Interface:     $url
  Login:         ${email:-no admin yet (import your database, or run again with --admin-email)}
  App folder:    $APP_DIR (user $APP_USER)
  PHP-FPM pool:  $FPM_POOL_DIR/$SITE_NAME.conf -> $FPM_SOCKET
  Queue worker:  systemctl status $WORKER_UNIT   ·   journalctl -u $WORKER_UNIT -f
  Artisan:       sudo $SITE_NAME-artisan <command>   (never plain "php artisan" as root)
  Settings:      $CONF_FILE   ·   backups of changed files: $CONF_DIR/backups/

Next steps:
  1. Log in and change the admin password (My account), if it was printed above.
  2. Limit who can open AutoDeploy: $ACCESS_FILE (IP allowlist or extra password).
EOF
    if [ -n "$LOCAL_SSH_KEY" ]; then
        cat <<EOF
  3. Servers -> Add server (or edit the imported one):
       Connection: Remote server (SSH)   Host: 127.0.0.1   Port: ${SSH_PORT:-22}   User: root
       Authentication: Key file on this computer   Private key file: $LOCAL_SSH_KEY
     then "Test connection".
EOF
    else
        cat <<EOF
  3. Run again with --setup-local-ssh: AutoDeploy reaches this server as root over SSH to 127.0.0.1.
EOF
    fi
    cat <<EOF
  4. Moving from the AutoDeploy on your PC: bash $SCRIPT_DIR/import-local-data.sh --help
  5. Updates later: sudo bash $APP_DIR/deploy/server/update.sh
  Guide: docs/07-instalare-pe-server.md
EOF
}

# ---------------------------------------------------------------- run
PHP_VERSION=
SITE_FILE=
APACHE_CHANGED=0
ACME_ROOT=/var/www/letsencrypt

check_system
setup_user
fetch_code
setup_env
install_php_packages
setup_app_key
setup_database
setup_admin
build_assets
optimize_app
setup_fpm
setup_apache
if [ "$SETUP_LOCAL_SSH" = 1 ]; then
    setup_local_ssh
fi
setup_worker
install_artisan_helper
save_settings
summary
