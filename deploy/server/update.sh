#!/usr/bin/env bash
# Updates AutoDeploy on the server: new code, composer, front-end build, migrations, caches,
# PHP-FPM reload and queue worker restart. Run as root, after install.sh:
#
#   sudo bash /var/www/autodeploy/deploy/server/update.sh
#
# Guide: docs/07-instalare-pe-server.md
# shellcheck source-path=SCRIPTDIR

set -Eeuo pipefail
umask 022

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lib.sh
. "$SCRIPT_DIR/lib.sh"
trap 'on_error "$LINENO" "$BASH_COMMAND"' ERR

usage() {
    cat <<'EOF'
Usage: sudo bash deploy/server/update.sh [options]

  --branch NAME      branch to pull (default: the one saved by install.sh)
  --source DIR       copy the code from this folder instead of git pull
  --no-pull          keep the code as it is (only composer, build, migrations, caches, restart)
  --skip-build       no npm build (e.g. you copied public/build from your PC)
  --no-fpm-reload    do not reload PHP-FPM (it clears OPcache for every pool of that PHP version)
  --force            update even while a deploy is queued or running (the worker restarts after it)
  -h, --help         this help

The settings (app folder, user, PHP, ...) come from /etc/autodeploy/install.conf.
EOF
}

case " $* " in
    *" -h "* | *" --help "*) usage; exit 0 ;;
esac
require_root
load_conf || die "$CONF_FILE not found: install AutoDeploy with install.sh first."

PULL=1
BUILD=1
FPM_RELOAD=1
FORCE=0
normalize_args "$@"
set -- "${ARGS[@]+"${ARGS[@]}"}"
while [ $# -gt 0 ]; do
    case $1 in
        --branch) need_value "$@"; BRANCH=$2; shift 2 ;;
        --source) need_value "$@"; SOURCE_DIR=$(cd "$2" && pwd); shift 2 ;;
        --no-pull) PULL=0; shift ;;
        --skip-build) BUILD=0; shift ;;
        --no-fpm-reload) FPM_RELOAD=0; shift ;;
        --force) FORCE=1; shift ;;
        -h | --help) usage; exit 0 ;;
        *) die "Unknown option: $1 (see --help)" ;;
    esac
done

[ -f "$APP_DIR/artisan" ] || die "$APP_DIR has no AutoDeploy (APP_DIR in $CONF_FILE)."
[ -x "$PHP_BIN" ] || die "PHP not found: $PHP_BIN (PHP_BIN in $CONF_FILE; run install.sh with --php to change it)."
PHP_VERSION=$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
FPM_SERVICE=${FPM_SERVICE:-php$PHP_VERSION-fpm}
WORKER_UNIT=$SITE_NAME-queue
DB_FILE=$(db_file)
WAS_DOWN=0
PUT_DOWN=0

ACTIVE=$(active_deployments "$DB_FILE")
if [ "$ACTIVE" -gt 0 ] && [ "$FORCE" != 1 ]; then
    die "$ACTIVE deploy(s) queued or running. Wait until they end (or use --force: the worker then restarts after its job)."
fi

# Maintenance mode while the code, the packages and the database change; always lifted at the end.
bring_up() {
    if [ "$PUT_DOWN" = 1 ]; then
        artisan up >/dev/null 2>&1 || warn "Could not end maintenance mode: sudo $SITE_NAME-artisan up"
        PUT_DOWN=0
    fi
}
trap bring_up EXIT

step "Maintenance mode"
if [ -f "$APP_DIR/storage/framework/down" ]; then
    WAS_DOWN=1
    ok "Already in maintenance mode (left like that at the end)"
else
    artisan down --retry=15 >/dev/null
    PUT_DOWN=1
    ok "Down while updating (pages answer 503 for a minute)"
fi

step "Code"
OLD_COMMIT=
NEW_COMMIT=
if [ "$PULL" = 0 ]; then
    ok "Kept as it is (--no-pull)"
elif [ -n "${SOURCE_DIR:-}" ]; then
    sync_source "$SOURCE_DIR"
    ok "Copied from $SOURCE_DIR"
elif [ -d "$APP_DIR/.git" ]; then
    OLD_COMMIT=$(as_app git -C "$APP_DIR" rev-parse --short HEAD)
    as_app git -C "$APP_DIR" fetch --prune origin "$BRANCH"
    if [ "$(as_app git -C "$APP_DIR" rev-parse --abbrev-ref HEAD)" != "$BRANCH" ]; then
        as_app git -C "$APP_DIR" checkout -q "$BRANCH"
    fi
    as_app git -C "$APP_DIR" merge --ff-only -q FETCH_HEAD ||
        die "git could not fast-forward $APP_DIR to origin/$BRANCH (local changes or commits there?): see git -C $APP_DIR status"
    NEW_COMMIT=$(as_app git -C "$APP_DIR" rev-parse --short HEAD)
    if [ "$OLD_COMMIT" = "$NEW_COMMIT" ]; then
        ok "Already on the latest commit of $BRANCH ($NEW_COMMIT)"
    else
        ok "$OLD_COMMIT -> $NEW_COMMIT"
        as_app git -C "$APP_DIR" log -n 20 --format='    %h %s' "$OLD_COMMIT..$NEW_COMMIT"
    fi
else
    die "$APP_DIR is not a git checkout and install.sh saved no source folder: use --source DIR or --no-pull."
fi
ensure_storage_dirs

step "PHP packages (composer install --no-dev)"
rm -f "$APP_DIR/bootstrap/cache/config.php" "$APP_DIR/bootstrap/cache/packages.php" "$APP_DIR/bootstrap/cache/services.php"
composer_run install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist
ok "vendor/ ready"

step "Front-end (npm ci && npm run build)"
if [ "$BUILD" = 1 ]; then
    build_frontend
else
    ok "Skipped (--skip-build)"
fi

step "Migrations and caches"
artisan migrate --force
artisan optimize
fix_permissions
ok "Migrated and cached"

step "PHP-FPM and queue worker"
if [ "$FPM_RELOAD" = 1 ] && systemctl is-active -q "$FPM_SERVICE"; then
    systemctl reload "$FPM_SERVICE"
    ok "Reloaded $FPM_SERVICE (OPcache cleared)"
fi
if [ "${NO_WORKER:-0}" != 1 ] && systemctl cat "$WORKER_UNIT" >/dev/null 2>&1; then
    artisan queue:restart >/dev/null
    if [ "$ACTIVE" -gt 0 ]; then
        ok "The worker takes the new code after the running deploy (queue:restart)"
    else
        systemctl restart "$WORKER_UNIT"
        ok "Restarted $WORKER_UNIT"
    fi
else
    artisan queue:restart >/dev/null
    ok "queue:restart sent (no systemd worker)"
fi

step "Done"
if [ "$WAS_DOWN" = 0 ]; then
    bring_up
    ok "Out of maintenance mode"
else
    warn "Still in maintenance mode, as before the update: sudo $SITE_NAME-artisan up"
fi
if [ -n "${DOMAIN:-}" ] && [ "${NO_APACHE:-0}" != 1 ]; then
    STATUS=$(health_status "$DOMAIN")
    if [ "$STATUS" = 200 ]; then
        ok "https://$DOMAIN/up answers 200"
    else
        warn "https://$DOMAIN/up answered $STATUS (logs: $APP_DIR/storage/logs/, /var/log/apache2/$SITE_NAME-error.log)"
    fi
fi
