# shellcheck shell=bash
# shellcheck disable=SC2016 # the PHP snippets are single-quoted on purpose
# Shared helpers of the AutoDeploy server scripts (install.sh, update.sh, import-local-data.sh).
# Sourced by them, never run on its own. Guide: docs/07-instalare-pe-server.md

# set -e also inside $(...) (bash >= 4.4).
shopt -s inherit_errexit 2>/dev/null || true

CONF_DIR=/etc/autodeploy
CONF_FILE=$CONF_DIR/install.conf
ACCESS_FILE=$CONF_DIR/apache-access.conf
RUN_ID=$(date +%Y%m%d-%H%M%S)
BACKUP_DIR=$CONF_DIR/backups/$RUN_ID

if [ -t 1 ]; then
    C_BOLD=$'\e[1m' C_GREEN=$'\e[32m' C_YELLOW=$'\e[33m' C_RED=$'\e[31m' C_RESET=$'\e[0m'
else
    C_BOLD='' C_GREEN='' C_YELLOW='' C_RED='' C_RESET=''
fi

step() { printf '\n%s==> %s%s\n' "$C_BOLD" "$*" "$C_RESET"; }
ok() { printf '%s✔%s %s\n' "$C_GREEN" "$C_RESET" "$*"; }
info() { printf '  %s\n' "$*"; }
warn() { printf '%s! %s%s\n' "$C_YELLOW" "$*" "$C_RESET" >&2; }
die() {
    printf '%s✖ %s%s\n' "$C_RED" "$*" "$C_RESET" >&2
    exit 1
}

# ERR trap: prints the command that failed and stops. Inside $(...) it only exits: the script
# itself then reports the line that used the result.
on_error() {
    local code=$? line=$1 command=$2
    if [ "${BASHPID:-$$}" = "$$" ]; then
        printf '%s✖ Stopped at line %s: "%s" failed (exit code %s).%s\n' "$C_RED" "$line" "$command" "$code" "$C_RESET" >&2
    fi
    exit "$code"
}

require_root() {
    [ "$(id -u)" = 0 ] || die "Run this script as root: sudo bash $0 $*"
}

# "--name=value" -> "--name" "value", so both forms work. Usage: normalize_args "$@"; set -- "${ARGS[@]}"
normalize_args() {
    ARGS=()
    local arg
    for arg in "$@"; do
        case $arg in
            --*=*) ARGS+=("${arg%%=*}" "${arg#*=}") ;;
            *) ARGS+=("$arg") ;;
        esac
    done
}

# Fails when an option has no value: need_value "$@" (inside the option loop).
need_value() {
    [ $# -ge 2 ] && [ -n "$2" ] || die "Option $1 needs a value (see --help)."
}

# Settings saved by install.sh (app dir, user, PHP, site name, ...). Returns 1 when there are none.
load_conf() {
    [ -f "$CONF_FILE" ] || return 1
    # shellcheck source=/dev/null
    . "$CONF_FILE"
}

# save_conf NAME... : writes the variables to $CONF_FILE (root only, no secrets in it).
save_conf() {
    local name
    install -d -m 0755 "$CONF_DIR"
    {
        echo "# AutoDeploy install settings, written by deploy/server/install.sh on $(date -R)."
        echo "# Read by install.sh (defaults of the next run), update.sh and import-local-data.sh."
        for name in "$@"; do
            printf '%s=%q\n' "$name" "${!name-}"
        done
    } > "$CONF_FILE.tmp"
    chmod 0600 "$CONF_FILE.tmp"
    mv -f "$CONF_FILE.tmp" "$CONF_FILE"
}

# Runs a command as the AutoDeploy user, in the app folder, with a clean environment
# (no variables of the root shell leak into artisan / composer / npm).
# Leading NAME=value arguments are extra environment variables: as_app FOO=1 php artisan ...
as_app() {
    (
        cd "$APP_DIR" 2>/dev/null || cd /
        exec runuser -u "$APP_USER" -- env -i \
            HOME="$APP_HOME" USER="$APP_USER" LOGNAME="$APP_USER" SHELL=/bin/bash LANG=C.UTF-8 \
            PATH="$APP_HOME/bin:/usr/local/bin:/usr/bin:/bin" \
            "$@"
    )
}

artisan() {
    as_app "$PHP_BIN" "$APP_DIR/artisan" --no-interaction "$@"
}

# Composer can be a phar / PHP script (run it with $PHP_BIN) or a wrapper script.
composer_run() {
    local first
    first=$(head -n 1 "$COMPOSER" 2>/dev/null || true)
    case $first in
        *php*) as_app "$PHP_BIN" "$COMPOSER" "$@" ;;
        *) as_app "$COMPOSER" "$@" ;;
    esac
}

# The first column of the first row of a query on a SQLite file ("" when there is none). Read-only.
db_scalar() {
    "$PHP_BIN" -d display_errors=stderr -d error_reporting=24575 -r '
        try {
            $db = new PDO("sqlite:" . $argv[1], null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
            ]);
            $value = $db->query($argv[2])->fetchColumn();
            echo $value === false || $value === null ? "" : $value;
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            exit(1);
        }
    ' -- "$1" "$2"
}

# One line per row: the columns of a query separated by tabs. Read-only.
db_rows() {
    "$PHP_BIN" -d display_errors=stderr -d error_reporting=24575 -r '
        try {
            $db = new PDO("sqlite:" . $argv[1], null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
            ]);
            foreach ($db->query($argv[2], PDO::FETCH_NUM) as $row) {
                echo implode("\t", array_map(fn ($v) => str_replace(["\t", "\n"], " ", (string) $v), $row)), PHP_EOL;
            }
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            exit(1);
        }
    ' -- "$1" "$2"
}

# db_exec FILE SQL [PARAM...] : runs a statement with bound parameters, prints the changed rows.
db_exec() {
    "$PHP_BIN" -d display_errors=stderr -d error_reporting=24575 -r '
        try {
            $db = new PDO("sqlite:" . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $statement = $db->prepare($argv[2]);
            $statement->execute(array_slice($argv, 3));
            echo $statement->rowCount();
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            exit(1);
        }
    ' -- "$@"
}

# Deployments (also backups / restores) that are queued or running. 0 when the database is new.
active_deployments() {
    local db=$1 count
    if [ ! -s "$db" ]; then
        echo 0
        return 0
    fi
    count=$(db_scalar "$db" "select count(*) from deployments where status in ('pending', 'running')" 2>/dev/null) || count=0
    echo "${count:-0}"
}

# The SQLite file of the app: DB_DATABASE from .env, or database/database.sqlite.
db_file() {
    local file
    file=$(env_get "$APP_DIR/.env" DB_DATABASE)
    case $file in
        /*) echo "$file" ;;
        *) echo "$APP_DIR/database/database.sqlite" ;;
    esac
}

# env_get FILE KEY : the value of KEY in a .env file, without quotes ("" when missing).
env_get() {
    [ -f "$1" ] || return 0
    KEY=$2 awk '
        index($0, ENVIRON["KEY"] "=") == 1 { value = substr($0, length(ENVIRON["KEY"]) + 2) }
        END {
            sub(/\r$/, "", value)
            first = substr(value, 1, 1)
            if (length(value) >= 2 && first == substr(value, length(value), 1) && (first == "\"" || first == sprintf("%c", 39))) {
                value = substr(value, 2, length(value) - 2)
            }
            print value
        }
    ' "$1"
}

# env_set FILE KEY VALUE : replaces (or appends) KEY=VALUE, keeping the owner, mode and other lines.
env_set() {
    local file=$1 key=$2 value=$3 tmp
    case $value in
        *[[:space:]\"\'\$\\\#]*) die "The value for $key contains spaces or quotes: set it in $file by hand." ;;
    esac
    tmp=$(mktemp)
    KEY=$key VALUE=$value awk '
        BEGIN { key = ENVIRON["KEY"]; value = ENVIRON["VALUE"]; done = 0 }
        index($0, key "=") == 1 { if (!done) print key "=" value; done = 1; next }
        { print }
        END { if (!done) print key "=" value }
    ' "$file" > "$tmp" || die "Could not edit $file"
    cat "$tmp" > "$file"
    rm -f "$tmp"
}

# env_ensure FILE KEY VALUE : env_set only when the value differs, and says so.
env_ensure() {
    if [ "$(env_get "$1" "$2")" != "$3" ]; then
        env_set "$1" "$2" "$3"
        info "$2=$3 in $1"
    fi
}

# render TEMPLATE KEY=VALUE... : the template with every {{KEY}} replaced. Fails on a missing value.
render() {
    local template=$1 content pair
    shift
    [ -f "$template" ] || die "Template not found: $template"
    content=$(cat "$template")
    for pair in "$@"; do
        # Quoted pattern and replacement: taken literally (also "&" with bash 5.2 patsub_replacement).
        content=${content//"{{${pair%%=*}}}"/"${pair#*=}"}
    done
    if [[ $content =~ \{\{[A-Z_]+\}\} ]]; then
        die "Template $template: no value for ${BASH_REMATCH[0]}"
    fi
    printf '%s\n' "$content"
}

# Copies a file into this run's backup folder (once per run), before it is changed.
backup_file() {
    local file=$1
    [ -e "$file" ] || return 0
    [ -e "$BACKUP_DIR$file" ] && return 0
    install -d -m 0700 "$CONF_DIR/backups"
    mkdir -p "$BACKUP_DIR$(dirname "$file")" || die "Could not create the backup folder $BACKUP_DIR"
    cp -a "$file" "$BACKUP_DIR$file" || die "Could not back up $file"
}

# Puts back the copy made by backup_file in this run, or removes the file when it is new.
restore_file() {
    local file=$1
    if [ -e "$BACKUP_DIR$file" ]; then
        cp -a "$BACKUP_DIR$file" "$file"
    else
        rm -f "$file"
    fi
}

# write_file DEST MODE OWNER:GROUP CONTENT : writes the file when the content differs (old one backed up).
# Returns 0 when it changed, 1 when it was already up to date: call it with "if" or "|| true".
write_file() {
    local dest=$1 mode=$2 owner=$3 content=$4 tmp
    tmp=$(mktemp) || die "mktemp failed"
    printf '%s\n' "$content" > "$tmp"
    if [ -f "$dest" ] && cmp -s "$tmp" "$dest"; then
        rm -f "$tmp"
        chmod "$mode" "$dest" && chown "$owner" "$dest" || die "Could not set the owner / mode of $dest"
        return 1
    fi
    backup_file "$dest"
    install -D -m "$mode" -o "${owner%%:*}" -g "${owner#*:}" "$tmp" "$dest" || die "Could not write $dest"
    rm -f "$tmp"
    return 0
}

# Owner and modes of the app folder. Everything belongs to the AutoDeploy user and is closed to
# other users (the other sites' PHP, mod_php as www-data): .env, the database and bootstrap/cache
# hold APP_KEY and secrets. Only public/ is readable by Apache.
fix_permissions() {
    chown -R "$APP_USER:$APP_USER" "$APP_DIR"
    chmod -R u+rwX,g+rX,g-w,o-rwx "$APP_DIR"
    chmod o+x "$APP_DIR"
    if [ -d "$APP_DIR/public" ]; then
        chmod -R o+rX "$APP_DIR/public"
    fi
    if [ -f "$APP_DIR/.env" ]; then
        chmod 0640 "$APP_DIR/.env"
    fi
}

# The Laravel storage folders (they are not copied with --source).
ensure_storage_dirs() {
    local dir
    for dir in storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache; do
        install -d -m 0750 -o "$APP_USER" -g "$APP_USER" "$APP_DIR/$dir"
    done
}

# Copies the code from a folder (another checkout on this server) into the app folder.
# .env, the database, storage, vendor, node_modules and the build stay as they are.
sync_source() {
    local src=$1
    [ -f "$src/artisan" ] || die "$src does not contain AutoDeploy (no artisan file)."
    command -v rsync >/dev/null 2>&1 || die "rsync is needed to copy the code: apt-get install rsync"
    install -d -m 0751 -o "$APP_USER" -g "$APP_USER" "$APP_DIR"
    rsync -a --delete \
        --exclude=/.env --exclude=/vendor/ --exclude=/node_modules/ --exclude=/storage/ \
        --exclude='/database/*.sqlite*' --exclude=/database/backups/ --exclude='/bootstrap/cache/*.php' \
        --exclude=/public/build/ --exclude=/public/hot --exclude=/public/storage \
        --exclude=/resources/js/actions/ --exclude=/resources/js/routes/ --exclude=/resources/js/wayfinder/ \
        "$src/" "$APP_DIR/"
    ensure_storage_dirs
    chown -R "$APP_USER:$APP_USER" "$APP_DIR"
}

# 0 when Node is recent enough for the front-end build (vite-plus: ^22.18 || ^24.11 || >=26).
check_node() {
    local version major minor
    if ! version=$(as_app node -p 'process.versions.node' 2>/dev/null); then
        die "Node.js is not installed, but the front-end build needs it. Install Node 22 or 24 LTS (docs/07),
  or build on your PC, copy public/build to $APP_DIR/public/build and run again with --skip-build."
    fi
    major=${version%%.*}
    minor=${version#*.}
    minor=${minor%%.*}
    if [ "$major" -lt 22 ]; then
        die "Node $version is too old for the front-end build (needs 22.18+, 24.11+ or 26+). Install Node 22 or 24 LTS
  (docs/07), or build on your PC, copy public/build to $APP_DIR/public/build and run again with --skip-build."
    fi
    if ! { [ "$major" -eq 22 ] && [ "$minor" -ge 18 ]; } && ! { [ "$major" -eq 24 ] && [ "$minor" -ge 11 ]; } && [ "$major" -lt 26 ]; then
        warn "Node $version is not in the range the build tools ask for (^22.18 || ^24.11 || >=26); trying anyway."
    fi
    ok "Node $version"
}

# npm ci + npm run build as the AutoDeploy user. The Wayfinder plugin of the build runs
# "php artisan wayfinder:generate": $APP_HOME/bin/php makes "php" the right PHP version.
build_frontend() {
    check_node
    as_app npm ci --no-audit --no-fund
    as_app npm run build
    [ -f "$APP_DIR/public/build/manifest.json" ] || die "npm run build finished without public/build/manifest.json"
    ok "Front-end built (public/build)"
}

# Waits until a unix socket exists (PHP-FPM creates it after a reload).
wait_for_socket() {
    local socket=$1 i
    for i in 1 2 3 4 5 6 7 8 9 10; do
        [ -S "$socket" ] && return 0
        sleep 0.5
    done
    [ -S "$socket" ]
}

# HTTP status of https://DOMAIN/up asked on this server (the Laravel health route). "000" = no answer.
health_status() {
    local domain=$1
    command -v curl >/dev/null 2>&1 || { echo "000"; return 0; }
    curl -s -o /dev/null -w '%{http_code}' --max-time 20 --resolve "$domain:443:127.0.0.1" "https://$domain/up" || true
}
