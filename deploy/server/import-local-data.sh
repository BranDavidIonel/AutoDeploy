#!/usr/bin/env bash
# Moves the data of the AutoDeploy on your PC (servers, projects, deploy history, users) to the
# AutoDeploy installed on this server by install.sh: the SQLite database plus the same APP_KEY
# (the server secrets in the database are encrypted with it). Run as root.
#
#   1. On the PC, with AutoDeploy stopped, copy database\database.sqlite and .env to the server.
#   2. sudo bash /var/www/autodeploy/deploy/server/import-local-data.sh \
#          --sqlite /root/autodeploy-import/database.sqlite --env-file /root/autodeploy-import/.env --local-server 1
#
# The database of this server is backed up first. Guide: docs/07-instalare-pe-server.md
# shellcheck source-path=SCRIPTDIR

set -Eeuo pipefail
umask 027

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lib.sh
. "$SCRIPT_DIR/lib.sh"
trap 'on_error "$LINENO" "$BASH_COMMAND"' ERR

usage() {
    cat <<'EOF'
Usage: sudo bash deploy/server/import-local-data.sh --sqlite FILE (--env-file FILE | --app-key KEY) [options]

  --sqlite FILE        database/database.sqlite of the PC install (AutoDeploy stopped on the PC)
  --env-file FILE      .env of the PC install: only APP_KEY is read from it
  --app-key KEY        or the APP_KEY itself (base64:...)
  --builds DIR         storage/app/private/builds of the PC install: the saved builds come along
  --local-server ID    point that server record to root@127.0.0.1 with the key made by
                       install.sh --setup-local-ssh (Servers page: the number in the address)
  --clear-queue        the PC database still has queued / running deploys: mark them failed and
                       empty the queue (otherwise the import stops)
  --yes                replace the data of this server's AutoDeploy even if it has servers / projects
  -h, --help           this help

Afterwards check every project: Windows folders, PHP paths and the build strategy of the PC do not fit
the server (see docs/07-instalare-pe-server.md).
EOF
}

case " $* " in
    *" -h "* | *" --help "*) usage; exit 0 ;;
esac
require_root
load_conf || die "$CONF_FILE not found: install AutoDeploy with install.sh first."

SQLITE=
ENV_FILE_PC=
APP_KEY_NEW=
BUILDS_DIR=
LOCAL_SERVER=
CLEAR_QUEUE=0
YES=0
normalize_args "$@"
set -- "${ARGS[@]+"${ARGS[@]}"}"
while [ $# -gt 0 ]; do
    case $1 in
        --sqlite) need_value "$@"; SQLITE=$2; shift 2 ;;
        --env-file) need_value "$@"; ENV_FILE_PC=$2; shift 2 ;;
        --app-key) need_value "$@"; APP_KEY_NEW=$2; shift 2 ;;
        --builds) need_value "$@"; BUILDS_DIR=$2; shift 2 ;;
        --local-server) need_value "$@"; LOCAL_SERVER=$2; shift 2 ;;
        --clear-queue) CLEAR_QUEUE=1; shift ;;
        --yes) YES=1; shift ;;
        -h | --help) usage; exit 0 ;;
        *) die "Unknown option: $1 (see --help)" ;;
    esac
done

ENV_FILE=$APP_DIR/.env
DB_FILE=$(db_file)
WORKER_UNIT=$SITE_NAME-queue
[ -f "$APP_DIR/artisan" ] && [ -f "$ENV_FILE" ] || die "AutoDeploy is not installed in $APP_DIR (run install.sh first)."

# ---------------------------------------------------------------- checks (nothing is changed yet)
step "Checking the PC database"
[ -n "$SQLITE" ] || die "--sqlite is required (see --help)."
[ -f "$SQLITE" ] || die "Not found: $SQLITE"
[ "$(head -c 15 "$SQLITE")" = "SQLite format 3" ] || die "$SQLITE is not a SQLite database."
if [ -s "$SQLITE-wal" ]; then
    die "$SQLITE-wal exists: AutoDeploy was still running on the PC. Stop it, copy database.sqlite again."
fi

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
cp "$SQLITE" "$STAGE/database.sqlite"
STAGED=$STAGE/database.sqlite

[ "$(db_scalar "$STAGED" "pragma integrity_check")" = ok ] || die "$SQLITE is damaged (SQLite integrity check failed): copy it again."
TABLES=$(db_scalar "$STAGED" "select count(*) from sqlite_master where type = 'table' and name in ('users', 'servers', 'projects', 'deployments', 'jobs', 'migrations')")
[ "$TABLES" = 6 ] || die "$SQLITE does not look like an AutoDeploy database (tables missing)."

info "Users: $(db_scalar "$STAGED" 'select count(*) from users'), servers: $(db_scalar "$STAGED" 'select count(*) from servers'), projects: $(db_scalar "$STAGED" 'select count(*) from projects'), deploys: $(db_scalar "$STAGED" 'select count(*) from deployments')"
info "Servers (id, name, connection, user@host):"
db_rows "$STAGED" "select id, name, connection_type, username || '@' || coalesce(host, '-') from servers order by id" | sed 's/^/    /'
info "Projects (id, name, server, deploy folder):"
db_rows "$STAGED" "select id, name, server_id, deploy_path from projects order by id" | sed 's/^/    /'

# Migrations of the PC that this code does not have (the PC ran newer code).
UNKNOWN=0
while IFS= read -r migration; do
    [ -n "$migration" ] || continue
    if [ ! -f "$APP_DIR/database/migrations/$migration.php" ]; then
        warn "The PC database has the migration $migration, which the code on this server does not have."
        UNKNOWN=$((UNKNOWN + 1))
    fi
done < <(db_rows "$STAGED" "select migration from migrations order by id")
if [ "$UNKNOWN" -gt 0 ]; then
    warn "Update the code on this server (update.sh) to the version the PC runs, or the pages may fail."
fi

step "APP_KEY"
if [ -z "$APP_KEY_NEW" ] && [ -n "$ENV_FILE_PC" ]; then
    [ -f "$ENV_FILE_PC" ] || die "Not found: $ENV_FILE_PC"
    APP_KEY_NEW=$(env_get "$ENV_FILE_PC" APP_KEY)
    [ -n "$APP_KEY_NEW" ] || die "$ENV_FILE_PC has no APP_KEY."
fi
if [ -n "$APP_KEY_NEW" ]; then
    [[ $APP_KEY_NEW =~ ^base64:[A-Za-z0-9+/]{43}=$ ]] || die "The APP_KEY must look like base64:... (44 characters after base64:)."
fi

# Checks the key against the encrypted columns (servers: private_key, passphrase, password) without
# decrypting anything: the MAC of each value must match. Prints none / ok / bad.
key_check=$("$PHP_BIN" -d display_errors=stderr -d error_reporting=24575 -r '
    $db = new PDO("sqlite:" . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $values = [];
    foreach ($db->query("select private_key, passphrase, password from servers", PDO::FETCH_NUM) as $row) {
        foreach ($row as $value) {
            if ($value !== null && $value !== "") {
                $values[] = $value;
            }
        }
    }
    if ($values === []) {
        echo "none";
        exit(0);
    }
    $key = (string) $argv[2];
    if (str_starts_with($key, "base64:")) {
        $key = (string) base64_decode(substr($key, 7), true);
    }
    foreach ($values as $payload) {
        $p = json_decode((string) base64_decode($payload, true), true);
        if (! is_array($p) || ! isset($p["iv"], $p["value"])) {
            echo "bad";
            exit(0);
        }
        if (($p["mac"] ?? "") !== "") {
            $ok = hash_equals(hash_hmac("sha256", $p["iv"] . $p["value"], $key), $p["mac"]);
        } else {
            $ok = openssl_decrypt($p["value"], "aes-256-gcm", $key, 0, (string) base64_decode($p["iv"]), (string) base64_decode($p["tag"] ?? "")) !== false;
        }
        if (! $ok) {
            echo "bad";
            exit(0);
        }
    }
    echo "ok";
' -- "$STAGED" "${APP_KEY_NEW:-none}")

case $key_check in
    none)
        if [ -n "$APP_KEY_NEW" ]; then
            ok "No encrypted secrets in the database; the PC APP_KEY is used anyway"
        else
            ok "No encrypted secrets in the database: the APP_KEY of this server stays"
        fi
        ;;
    ok) ok "The APP_KEY opens the encrypted server secrets" ;;
    bad)
        if [ -z "$APP_KEY_NEW" ]; then
            die "The database has encrypted secrets (pasted keys / passwords): give the PC APP_KEY with --env-file or --app-key."
        fi
        die "This APP_KEY does not open the encrypted secrets of the database: use the .env of the same PC install."
        ;;
    *) die "Could not check the APP_KEY: $key_check" ;;
esac

step "Queue"
PC_JOBS=$(db_scalar "$STAGED" "select count(*) from jobs")
PC_ACTIVE=$(active_deployments "$STAGED")
if [ "$PC_JOBS" -gt 0 ] || [ "$PC_ACTIVE" -gt 0 ]; then
    if [ "$CLEAR_QUEUE" != 1 ]; then
        die "The PC database has $PC_ACTIVE queued / running deploy(s) and $PC_JOBS queued job(s): the worker here
  would run them. Let them finish on the PC (or cancel them) and copy the database again, or use --clear-queue."
    fi
    db_exec "$STAGED" "delete from jobs" >/dev/null
    db_exec "$STAGED" "update deployments set status = 'failed', error = ?, finished_at = coalesce(finished_at, current_timestamp) where status in ('pending', 'running')" \
        "Stopped: AutoDeploy was moved to the server before this ended." >/dev/null
    db_exec "$STAGED" "update deployment_steps set status = 'skipped' where status in ('pending', 'running')" >/dev/null
    ok "Queue emptied, $PC_ACTIVE unfinished deploy(s) marked failed"
else
    ok "Nothing queued"
fi
# Sessions, cache and the worker heartbeat of the PC mean nothing here.
for table in sessions cache cache_locks; do
    db_exec "$STAGED" "delete from $table" >/dev/null 2>&1 || true
done

if [ -n "$LOCAL_SERVER" ]; then
    step "Server #$LOCAL_SERVER -> root@127.0.0.1"
    [[ $LOCAL_SERVER =~ ^[0-9]+$ ]] || die "--local-server must be the number of the server (Servers page)."
    [ "$(db_scalar "$STAGED" "select count(*) from servers where id = $LOCAL_SERVER")" = 1 ] || die "The PC database has no server #$LOCAL_SERVER."
    [ -n "${LOCAL_SSH_KEY:-}" ] && [ -f "$LOCAL_SSH_KEY" ] || die "No local SSH key yet: run install.sh --setup-local-ssh first."
    info "Before: $(db_rows "$STAGED" "select name, connection_type, username || '@' || coalesce(host, '-') || ':' || port, auth_method from servers where id = $LOCAL_SERVER")"
    # The SSH host key stays the same (same sshd), so the saved fingerprint still matches.
    db_exec "$STAGED" "update servers set connection_type = 'ssh', host = '127.0.0.1', port = ?, username = 'root', auth_method = 'key_file', key_path = ?, private_key = null, passphrase = null, password = null, status = 'unknown', last_error = null, updated_at = current_timestamp where id = ?" \
        "${SSH_PORT:-22}" "$LOCAL_SSH_KEY" "$LOCAL_SERVER" >/dev/null
    info "After:  root@127.0.0.1:${SSH_PORT:-22}, key file $LOCAL_SSH_KEY"
fi

step "This server's AutoDeploy"
SERVER_ACTIVE=$(active_deployments "$DB_FILE")
[ "$SERVER_ACTIVE" -eq 0 ] || die "A deploy is queued or running here: wait until it ends."
if [ -s "$DB_FILE" ]; then
    HAS=$(db_scalar "$DB_FILE" "select (select count(*) from servers) + (select count(*) from projects)" 2>/dev/null) || HAS=0
    if [ "${HAS:-0}" -gt 0 ] && [ "$YES" != 1 ]; then
        die "The AutoDeploy on this server already has servers / projects: add --yes to replace them (a backup is kept)."
    fi
fi
ok "Ready to replace $DB_FILE"

# ---------------------------------------------------------------- import
step "Importing"
if systemctl cat "$WORKER_UNIT" >/dev/null 2>&1; then
    systemctl stop "$WORKER_UNIT"
    info "Stopped $WORKER_UNIT"
fi
artisan down --retry=15 >/dev/null || true
bring_back() {
    artisan up >/dev/null 2>&1 || true
    if systemctl cat "$WORKER_UNIT" >/dev/null 2>&1; then
        systemctl start "$WORKER_UNIT" || warn "Could not start $WORKER_UNIT: journalctl -u $WORKER_UNIT -n 50"
    fi
    rm -rf "$STAGE"
}
trap bring_back EXIT

BACKUP=$APP_DIR/database/backups/before-import-$RUN_ID
install -d -m 0700 -o "$APP_USER" -g "$APP_USER" "$APP_DIR/database/backups" "$BACKUP"
for file in "$DB_FILE" "$DB_FILE-wal" "$DB_FILE-shm" "$ENV_FILE"; do
    if [ -f "$file" ]; then
        cp -a "$file" "$BACKUP/"
    fi
done
ok "Backup of the current database and .env: $BACKUP"

rm -f "$DB_FILE-wal" "$DB_FILE-shm" "$DB_FILE-journal"
install -m 0640 -o "$APP_USER" -g "$APP_USER" "$STAGED" "$DB_FILE"
ok "Database copied to $DB_FILE"

if [ -n "$APP_KEY_NEW" ]; then
    env_ensure "$ENV_FILE" APP_KEY "$APP_KEY_NEW"
    ok "APP_KEY of the PC install in $ENV_FILE"
fi

if [ -n "$BUILDS_DIR" ]; then
    [ -d "$BUILDS_DIR" ] || die "Not found: $BUILDS_DIR"
    install -d -m 0750 -o "$APP_USER" -g "$APP_USER" "$APP_DIR/storage/app/private/builds"
    cp -a "$BUILDS_DIR/." "$APP_DIR/storage/app/private/builds/"
    ok "Saved builds copied to storage/app/private/builds"
fi

rm -f "$APP_DIR/bootstrap/cache/config.php"
artisan migrate --force
artisan optimize
fix_permissions
ok "Migrated and cached"

MISSING=0
while IFS= read -r path; do
    [ -n "$path" ] || continue
    [ -f "$APP_DIR/storage/app/private/$path" ] || MISSING=$((MISSING + 1))
done < <(db_rows "$DB_FILE" "select path from builds")
if [ "$MISSING" -gt 0 ]; then
    warn "$MISSING saved build(s) have no file here (copy the PC folder storage/app/private/builds with --builds,
  or delete them on the Builds tab)."
fi

bring_back
trap - EXIT
ok "AutoDeploy is running again with the PC data"

if [ -n "$LOCAL_SERVER" ]; then
    step "Testing server #$LOCAL_SERVER (as $APP_USER, like the app)"
    artisan tinker --execute "\$r = app(App\\Services\\Servers\\ServerInspector::class)->inspect(App\\Models\\Server::findOrFail($LOCAL_SERVER)); echo (\$r['ok'] ? 'OK: ' : 'FAILED: ').\$r['message'].PHP_EOL;" ||
        warn "The test could not run: use \"Test connection\" on the server page."
fi

step "Next steps"
cat <<EOF
  1. Log in with the account of the PC install (same email and password).
  2. Servers: the server must be root@127.0.0.1 with the key file ${LOCAL_SSH_KEY:-made by install.sh --setup-local-ssh}
     ("Test connection"; if it says the host key changed: "Forget host key", then test again).
  3. Projects: the PC paths do not fit here (Windows local folder, PHP binary, build strategy "local"):
     adjust every project before its next deploy (docs/07-instalare-pe-server.md).
  4. Stop using the AutoDeploy on the PC: two copies would deploy to the same server.
  5. Delete the copied files, they contain secrets: $SQLITE${ENV_FILE_PC:+ and $ENV_FILE_PC}
EOF
