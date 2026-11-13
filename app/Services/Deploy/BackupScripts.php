<?php

namespace App\Services\Deploy;

use App\Enums\BackupKind;
use App\Models\Project;
use App\Services\Remote\Shell;

/**
 * The bash scripts of the database backups. They run on the server like the deploy steps
 * (DeployScripts::wrap() adds the same variables) and read the database settings from the
 * .env on the server: the password never passes through AutoDeploy, is never printed, and
 * reaches the dump tools only through MYSQL_PWD / PGPASSWORD.
 *
 * Files: $DEPLOY_PATH/backups/db/<project>-<time>-<kind>.sql.gz (folder 700, files 600, owned
 * by the SSH user). They live outside releases/ and shared/, so a rollback or the release
 * cleanup never touches them.
 *
 *   mysql / mariadb   mysqldump --single-transaction … | gzip -6     restore: drop the tables, gunzip | mysql
 *   pgsql             pg_dump --no-owner --no-privileges | gzip -6    restore: drop + import in one transaction
 *   sqlite            gzip -6 -c <database file>                       restore: gunzip into a new file, then mv
 */
class BackupScripts
{
    public const string BACKUP_MARKER = '__AUTODEPLOY_BACKUP__';

    /**
     * Free disk space (MB) that must stay on the disk of the backups folder after a backup.
     * A backup needs this much plus the size of the database (an upper bound of the .sql.gz).
     */
    public const int MIN_FREE_MB = 1024;

    public function __construct(private readonly Project $project) {}

    /**
     * Dumps the database into a new .sql.gz and prints its path and size after BACKUP_MARKER.
     * $inRelease: read a relative sqlite path against the new release (a deploy), not current.
     */
    public function backup(BackupKind $kind, bool $inRelease = false): string
    {
        return implode("\n", [
            'KIND='.Shell::quote($kind->value),
            'MIN_FREE_MB='.self::MIN_FREE_MB,
            $inRelease ? 'DB_APP_DIR="$RELEASE_PATH"' : 'DB_APP_DIR="$CURRENT_PATH"',
            self::DATABASE_SCRIPT,
            self::BACKUP_SCRIPT,
        ]);
    }

    /** Checks that a backup file is still on the server and is a complete gzip file. */
    public function check(string $file): string
    {
        return implode("\n", [
            'BACKUP='.Shell::quote($file),
            'if [ ! -f "$BACKUP" ]; then',
            '  echo "✖ $BACKUP is no longer on the server, so it cannot be restored. Nothing was changed."',
            '  exit 1',
            'fi',
            'echo "Backup: $BACKUP ($(du -h "$BACKUP" | cut -f1))"',
            'if ! gzip -t "$BACKUP"; then',
            '  echo "✖ The backup file is damaged (gzip -t failed). Nothing was changed."',
            '  exit 1',
            'fi',
            'echo "✔ The file is complete (gzip -t)."',
        ]);
    }

    /** Puts a backup back into the project's database (the tables that are there now are dropped first). */
    public function restore(string $file): string
    {
        return implode("\n", [
            'BACKUP='.Shell::quote($file),
            'DB_APP_DIR="$CURRENT_PATH"',
            self::DATABASE_SCRIPT,
            self::RESTORE_SCRIPT,
        ]);
    }

    /** "php artisan down" in the live release, when there is one. Never fails the restore. */
    public function maintenanceOn(): string
    {
        return implode("\n", [
            'if [ -f "$CURRENT_PATH/artisan" ]; then',
            '  cd "$CURRENT_PATH"',
            '  echo "$ php artisan down --retry=15"',
            '  if $PHP artisan down --retry=15; then',
            '    echo "The site answers 503 (maintenance) until the restore ends."',
            '  else',
            '    echo "WARNING: php artisan down failed, the site stays up during the restore."',
            '  fi',
            'else',
            '  echo "No live release yet (no current/artisan): no maintenance mode."',
            'fi',
        ]);
    }

    /** "php artisan up" in the live release (also run when a restore fails). */
    public function maintenanceOff(): string
    {
        return implode("\n", [
            'if [ -f "$CURRENT_PATH/artisan" ]; then',
            '  cd "$CURRENT_PATH"',
            '  echo "$ php artisan up"',
            '  if ! $PHP artisan up; then',
            '    echo "✖ php artisan up failed: the site may still show the maintenance page. Run it by hand in $CURRENT_PATH."',
            '    exit 1',
            '  fi',
            'else',
            '  echo "No live release (no current/artisan): nothing to bring up."',
            'fi',
        ]);
    }

    /**
     * Deletes backup files (only files inside the backups folder) and prints what is left.
     * Exits 1 when a file could not be removed.
     *
     * @param  array<int, string>  $files
     */
    public function remove(array $files): string
    {
        $folder = $this->project->backupsPath().'/';
        $lines = ['BACKUPS="$DEPLOY_PATH/backups/db"', 'FAILED=no'];

        foreach ($files as $file) {
            if (! str_starts_with($file, $folder) || str_contains(substr($file, strlen($folder)), '/')) {
                continue;
            }

            $quoted = Shell::quote($file);
            $lines[] = "if rm -f {$quoted}; then echo ".Shell::quote('Removed '.basename($file)).'; else echo '.Shell::quote('WARNING: could not remove '.$file).'; FAILED=yes; fi';
        }

        return implode("\n", [
            ...$lines,
            'if [ -d "$BACKUPS" ]; then',
            '  COUNT="$(find "$BACKUPS" -maxdepth 1 -type f -name \'*.sql.gz\' | wc -l | tr -d \' \')"',
            '  echo "Database backups on the server: $COUNT, $(du -sh "$BACKUPS" | cut -f1) in total ($BACKUPS)"',
            'fi',
            'if [ "$FAILED" = yes ]; then exit 1; fi',
        ]);
    }

    /**
     * The backup printed after BACKUP_MARKER by backup().
     *
     * @return array{file: string, size: int, connection: string, database: string}|null
     */
    public static function parseBackup(string $output): ?array
    {
        if (! preg_match('/'.self::BACKUP_MARKER.'\t([^\t\r\n]+)\t(\d+)\t([^\t\r\n]*)\t([^\r\n]*)/', $output, $matches)) {
            return null;
        }

        return [
            'file' => $matches[1],
            'size' => (int) $matches[2],
            'connection' => $matches[3],
            'database' => $matches[4],
        ];
    }

    /**
     * Reads the database settings from the .env (shared/.env, else the .env of the app folder)
     * and prepares the tools: my_client / my_dump (mysql, mariadb), pg_client / pg_dumper
     * (pgsql), DB_FILE (sqlite). The password stays in DB_PASSWORD and goes to the tools only
     * through the environment; nothing here prints it.
     */
    private const string DATABASE_SCRIPT = <<<'BASH'
        set -o pipefail
        umask 077
        APP_DIR="${DB_APP_DIR:-$CURRENT_PATH}"
        BACKUPS="$DEPLOY_PATH/backups/db"
        ENV_FILE="$SHARED_PATH/.env"
        if [ ! -f "$ENV_FILE" ]; then ENV_FILE="$APP_DIR/.env"; fi
        if [ ! -f "$ENV_FILE" ]; then
            echo "✖ No .env found ($SHARED_PATH/.env or $APP_DIR/.env), so AutoDeploy cannot tell which database to use."
            exit 1
        fi
        if [ ! -r "$ENV_FILE" ]; then
            echo "✖ $(whoami) cannot read $ENV_FILE."
            exit 1
        fi

        # One value of the .env, read the way Laravel reads it: "export" in front, spaces around
        # "=", single or double quotes (\" \\ \$ \n inside double quotes), a "# comment" after an
        # unquoted value, Windows line ends. The first definition wins; nothing when it is missing.
        ENV_AWK='
        BEGIN { key = ENVIRON["ENV_KEY"]; q = sprintf("%c", 39); bs = sprintf("%c", 92); dq = sprintf("%c", 34) }
        {
            sub(/\r$/, ""); line = $0
            sub(/^[ \t]+/, "", line)
            if (line == "" || substr(line, 1, 1) == "#") next
            sub(/^export[ \t]+/, "", line)
            eq = index(line, "="); if (eq == 0) next
            name = substr(line, 1, eq - 1); sub(/[ \t]+$/, "", name)
            if (name != key) next
            v = substr(line, eq + 1); sub(/^[ \t]+/, "", v)
            first = substr(v, 1, 1)
            if (first == dq || first == q) {
                out = ""
                for (i = 2; i <= length(v); i++) {
                    c = substr(v, i, 1)
                    if (c == first) break
                    if (first == dq && c == bs && i < length(v)) {
                        i++; c = substr(v, i, 1)
                        if (c == "n") { c = "\n" } else if (c == "t") { c = "\t" } else if (c == "r") { c = "\r" } else if (c != dq && c != bs && c != "$") { c = bs c }
                    }
                    out = out c
                }
                v = out
            } else {
                sub(/[ \t]+#.*$/, "", v); sub(/[ \t]+$/, "", v)
            }
            printf "%s", v
            exit
        }'
        env_value() { ENV_KEY="$1" awk "$ENV_AWK" "$ENV_FILE"; }

        DB_URL="$(env_value DB_URL)"
        DB_CONNECTION="$(env_value DB_CONNECTION)"
        DB_HOST="$(env_value DB_HOST)"
        DB_PORT="$(env_value DB_PORT)"
        DB_DATABASE="$(env_value DB_DATABASE)"
        DB_USERNAME="$(env_value DB_USERNAME)"
        DB_PASSWORD="$(env_value DB_PASSWORD)"
        DB_SOCKET="$(env_value DB_SOCKET)"

        if [ -n "$DB_URL" ]; then
            echo "✖ $ENV_FILE sets DB_URL. AutoDeploy reads DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD:"
            echo "set those instead of DB_URL, or turn off the database backup in the project settings."
            exit 1
        fi
        if [ -z "$DB_CONNECTION" ]; then
            DB_CONNECTION=sqlite
            echo "DB_CONNECTION is not set in $ENV_FILE: sqlite, the Laravel default."
        fi

        case "$DB_CONNECTION" in
            mysql|mariadb)
                if [ "$DB_CONNECTION" = mariadb ]; then
                    DUMP="$(command -v mariadb-dump || command -v mysqldump || true)"
                    CLIENT="$(command -v mariadb || command -v mysql || true)"
                else
                    DUMP="$(command -v mysqldump || command -v mariadb-dump || true)"
                    CLIENT="$(command -v mysql || command -v mariadb || true)"
                fi
                DB_DATABASE="${DB_DATABASE:-laravel}"
                MY_ARGS=(--user="${DB_USERNAME:-root}")
                if [ -n "$DB_SOCKET" ]; then
                    MY_ARGS+=(--socket="$DB_SOCKET")
                    WHERE="socket $DB_SOCKET"
                else
                    MY_ARGS+=(--host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}")
                    WHERE="${DB_HOST:-127.0.0.1}:${DB_PORT:-3306}"
                fi
                ;;
            pgsql)
                DUMP="$(command -v pg_dump || true)"
                CLIENT="$(command -v psql || true)"
                DB_DATABASE="${DB_DATABASE:-laravel}"
                export PGHOST="${DB_HOST:-127.0.0.1}" PGPORT="${DB_PORT:-5432}" PGUSER="${DB_USERNAME:-root}" PGDATABASE="$DB_DATABASE"
                WHERE="$PGHOST:$PGPORT"
                ;;
            sqlite)
                DB_FILE="${DB_DATABASE:-database/database.sqlite}"
                # A relative path is relative to the app folder, like Laravel reads it.
                case "$DB_FILE" in /*|:memory:) ;; *) DB_FILE="$APP_DIR/$DB_FILE" ;; esac
                DB_DATABASE="$DB_FILE"
                WHERE="file"
                ;;
            *)
                echo "✖ DB_CONNECTION=$DB_CONNECTION: AutoDeploy backs up mysql, mariadb, pgsql and sqlite databases only."
                exit 1
                ;;
        esac

        # --no-defaults first: like Laravel, ignore option files (a ~/.my.cnf of the SSH user
        # would win over MYSQL_PWD). psql -X: no ~/.psqlrc. The password only in the environment.
        my_client() { MYSQL_PWD="$DB_PASSWORD" "$CLIENT" --no-defaults "${MY_ARGS[@]}" "$@"; }
        my_dump() { MYSQL_PWD="$DB_PASSWORD" "$DUMP" --no-defaults "${MY_ARGS[@]}" "$@"; }
        pg_client() { PGPASSWORD="$DB_PASSWORD" "$CLIENT" -X "$@"; }
        pg_dumper() { PGPASSWORD="$DB_PASSWORD" "$DUMP" "$@"; }

        need_tools() {
            if [ "$DB_CONNECTION" = sqlite ]; then return 0; fi
            if [ -n "$DUMP" ] && [ -n "$CLIENT" ]; then return 0; fi
            if [ "$DB_CONNECTION" = pgsql ]; then
                echo "✖ pg_dump / psql are not installed on the server. Install them: apt install postgresql-client"
            else
                echo "✖ mysqldump / mysql are not installed on the server. Install them: apt install default-mysql-client (or mariadb-client)"
            fi
            exit 1
        }

        # Size of the database in MB (data + indexes): an upper bound of the .sql.gz.
        db_size_mb() {
            case "$DB_CONNECTION" in
                mysql|mariadb) my_client -N -B -e "SELECT COALESCE(CEIL(SUM(data_length + index_length) / 1048576), 0) FROM information_schema.tables WHERE table_schema = DATABASE()" "$DB_DATABASE" 2>/dev/null | tail -n 1 ;;
                pgsql) pg_client -At -c "SELECT CEIL(pg_database_size(current_database()) / 1048576.0)::bigint" 2>/dev/null | tail -n 1 ;;
                sqlite) echo $(( ($(wc -c < "$DB_FILE") + 1048575) / 1048576 )) ;;
            esac
        }
        BASH;

    /** Uses KIND, MIN_FREE_MB and the variables of DATABASE_SCRIPT. */
    private const string BACKUP_SCRIPT = <<<'BASH'
        if ! mkdir -p "$BACKUPS"; then
            echo "✖ $(whoami) cannot create $BACKUPS. Nothing was changed."
            exit 1
        fi
        chmod 700 "$DEPLOY_PATH/backups" "$BACKUPS" || echo "WARNING: could not make $BACKUPS private (chmod 700)."
        # A backup that was stopped half way leaves a .part file, never a file that looks complete.
        rm -f "$BACKUPS"/*.part

        if [ "$DB_CONNECTION" = sqlite ] && { [ "$DB_FILE" = ":memory:" ] || [ ! -f "$DB_FILE" ]; }; then
            echo "No database file at $DB_FILE yet: nothing to back up."
            exit 0
        fi
        need_tools
        echo "Database: $DB_CONNECTION, $DB_DATABASE ($WHERE)"

        # Disk guard: the backup must leave MIN_FREE_MB free, so it never fills the disk of the site.
        ESTIMATE_MB="$(db_size_mb || true)"
        case "$ESTIMATE_MB" in ''|*[!0-9]*) ESTIMATE_MB=0 ;; esac
        NEED_MB=$((MIN_FREE_MB + ESTIMATE_MB))
        FREE_MB="$(df -Pm "$BACKUPS" 2>/dev/null | awk 'NR == 2 {print $4}' || true)"
        case "$FREE_MB" in ''|*[!0-9]*) FREE_MB="" ;; esac
        if [ -z "$FREE_MB" ]; then
            echo "WARNING: could not read the free disk space of $BACKUPS."
        elif [ "$FREE_MB" -lt "$NEED_MB" ]; then
            echo "✖ Not enough free disk space: $FREE_MB MB free, a backup needs $NEED_MB MB ($ESTIMATE_MB MB of data + $MIN_FREE_MB MB that must stay free)."
            echo "Free some space (older backups on the Backups tab, old releases, logs) or keep fewer backups. Nothing was changed."
            exit 1
        else
            echo "Disk: $FREE_MB MB free, $NEED_MB MB needed ($ESTIMATE_MB MB of data + $MIN_FREE_MB MB kept free)"
        fi

        STAMP="$(date +%Y%m%d-%H%M%S)"
        FILE="$BACKUPS/${PROJECT:-database}-$STAMP-$KIND.sql.gz"
        N=2
        while [ -e "$FILE" ]; do FILE="$BACKUPS/${PROJECT:-database}-$STAMP-$KIND-$N.sql.gz"; N=$((N + 1)); done
        # Written under a temporary name, renamed only when the dump worked and the gzip is valid.
        TMP="$FILE.part"
        STARTED="$(date +%s)"
        FAILED=no

        case "$DB_CONNECTION" in
            mysql|mariadb)
                OPTIONS=(--single-transaction --quick --routines --triggers --no-tablespaces --default-character-set=utf8mb4)
                # MySQL with GTIDs would add SET @@GLOBAL.GTID_PURGED (fails on restore): only for clients that know the option.
                if "$DUMP" --no-defaults --help 2>/dev/null | grep -e '--set-gtid-purged' >/dev/null; then OPTIONS+=(--set-gtid-purged=OFF); fi
                echo "\$ $(basename "$DUMP") ${OPTIONS[*]} $DB_DATABASE | gzip -6"
                if ! my_dump "${OPTIONS[@]}" "$DB_DATABASE" | gzip -6 > "$TMP"; then FAILED=yes; fi
                ;;
            pgsql)
                echo "\$ pg_dump --no-owner --no-privileges $DB_DATABASE | gzip -6"
                if ! pg_dumper --no-owner --no-privileges "$DB_DATABASE" | gzip -6 > "$TMP"; then FAILED=yes; fi
                ;;
            sqlite)
                if [ -s "$DB_FILE-wal" ]; then echo "WARNING: $DB_FILE-wal is not empty: the newest writes may still be there and not in the copy."; fi
                echo "\$ gzip -6 -c $DB_FILE"
                if ! gzip -6 -c "$DB_FILE" > "$TMP"; then FAILED=yes; fi
                ;;
        esac

        if [ "$FAILED" = yes ]; then
            rm -f "$TMP"
            echo "✖ The backup failed (see the error above). Nothing was changed: the database and the site are as they were."
            exit 1
        fi
        if ! gzip -t "$TMP"; then
            rm -f "$TMP"
            echo "✖ The backup file is damaged (gzip -t failed). Nothing was changed."
            exit 1
        fi
        mv -f "$TMP" "$FILE"
        chmod 600 "$FILE"
        SIZE="$(wc -c < "$FILE" | tr -d ' ')"
        echo "✔ Backup saved: $FILE ($(du -h "$FILE" | cut -f1), $(( $(date +%s) - STARTED )) s)"
        printf '%s\t%s\t%s\t%s\t%s\n' "__AUTODEPLOY_BACKUP__" "$FILE" "$SIZE" "$DB_CONNECTION" "$DB_DATABASE"
        BASH;

    /** Uses BACKUP and the variables of DATABASE_SCRIPT. */
    private const string RESTORE_SCRIPT = <<<'BASH'
        if [ ! -f "$BACKUP" ]; then
            echo "✖ $BACKUP is no longer on the server: nothing was restored."
            exit 1
        fi
        need_tools
        echo "Restoring $(basename "$BACKUP") ($(du -h "$BACKUP" | cut -f1)) into $DB_CONNECTION $DB_DATABASE ($WHERE)"
        STARTED="$(date +%s)"

        fail() {
            echo "✖ The restore failed: $1"
            echo "The database may be incomplete. The safety backup made right before this restore is on the Backups tab: restore it to go back."
            exit 1
        }

        case "$DB_CONNECTION" in
            mysql|mariadb)
                # Tables the backup does not have (made by newer migrations) would stay and break the
                # next deploy, so every table and view is dropped first.
                DROP_QUERY="SELECT CONCAT('DROP ', IF(table_type = 'VIEW', 'VIEW', 'TABLE'), ' IF EXISTS \`', REPLACE(table_name, '\`', '\`\`'), '\`;') FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_type = 'VIEW' DESC"
                if ! DROPS="$(my_client -N -B -e "$DROP_QUERY" "$DB_DATABASE")"; then fail "could not list the tables of $DB_DATABASE."; fi
                echo "Dropping the $(printf '%s\n' "$DROPS" | grep -c . || true) tables and views of $DB_DATABASE…"
                if ! { echo "SET FOREIGN_KEY_CHECKS=0;"; printf '%s\n' "$DROPS"; } | my_client "$DB_DATABASE"; then fail "could not drop the tables."; fi
                echo "Importing the backup…"
                if ! { echo "SET FOREIGN_KEY_CHECKS=0;"; gunzip -c "$BACKUP"; echo "SET FOREIGN_KEY_CHECKS=1;"; } | my_client --default-character-set=utf8mb4 "$DB_DATABASE"; then
                    fail "the import stopped (see the error above)."
                fi
                ;;
            pgsql)
                # PostgreSQL changes tables in a transaction: the drop and the import are one, so a
                # failing import leaves the database as it was.
                DROP_QUERY="SELECT format('DROP VIEW IF EXISTS %I.%I CASCADE;', schemaname, viewname) FROM pg_views WHERE schemaname = current_schema() UNION ALL SELECT format('DROP MATERIALIZED VIEW IF EXISTS %I.%I CASCADE;', schemaname, matviewname) FROM pg_matviews WHERE schemaname = current_schema() UNION ALL SELECT format('DROP TABLE IF EXISTS %I.%I CASCADE;', schemaname, tablename) FROM pg_tables WHERE schemaname = current_schema() UNION ALL SELECT format('DROP SEQUENCE IF EXISTS %I.%I CASCADE;', sequence_schema, sequence_name) FROM information_schema.sequences WHERE sequence_schema = current_schema()"
                if ! DROPS="$(pg_client -At -v ON_ERROR_STOP=1 -c "$DROP_QUERY")"; then fail "could not list the tables of $DB_DATABASE."; fi
                echo "Dropping the $(printf '%s\n' "$DROPS" | grep -c . || true) tables, views and sequences of $DB_DATABASE and importing the backup, in one transaction…"
                if ! { echo "BEGIN;"; printf '%s\n' "$DROPS"; gunzip -c "$BACKUP"; echo "COMMIT;"; } | pg_client -q -v ON_ERROR_STOP=1 -o /dev/null; then
                    echo "✖ The restore failed (see the error above). The transaction was rolled back: the database is as it was."
                    exit 1
                fi
                ;;
            sqlite)
                if [ "$DB_FILE" = ":memory:" ]; then fail "DB_DATABASE is :memory:, there is no database file to restore into."; fi
                mkdir -p "$(dirname "$DB_FILE")"
                NEW="$DB_FILE.restoring"
                if ! gunzip -c "$BACKUP" > "$NEW"; then rm -f "$NEW"; fail "could not unpack the backup."; fi
                # Same owner and mode as the file it replaces: the web server user must still write it.
                if [ -e "$DB_FILE" ]; then REF="$DB_FILE"; else REF="$(dirname "$DB_FILE")"; fi
                if ! chown --reference="$REF" "$NEW" 2>/dev/null; then
                    echo "WARNING: could not give the restored file the owner of $REF (needs root): check that the web server user can still write it."
                fi
                if [ "$REF" = "$DB_FILE" ]; then chmod --reference="$DB_FILE" "$NEW" 2>/dev/null || true; else chmod 664 "$NEW"; fi
                mv -f "$NEW" "$DB_FILE"
                # The journal files of the old database would not match the restored one.
                rm -f "$DB_FILE-wal" "$DB_FILE-shm" "$DB_FILE-journal"
                ;;
        esac
        echo "✔ Restored $(basename "$BACKUP") in $(( $(date +%s) - STARTED )) s"
        BASH;
}
