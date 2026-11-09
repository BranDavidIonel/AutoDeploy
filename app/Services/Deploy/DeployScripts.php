<?php

namespace App\Services\Deploy;

use App\Models\Deployment;
use App\Models\Project;
use App\Services\Remote\Shell;

/**
 * The bash scripts of every deploy step. Each one runs on the server with the
 * variables from variables() exported (also available in custom hooks):
 *
 *   $DEPLOY_PATH   /var/www/app
 *   $RELEASE       20261106183000
 *   $RELEASE_PATH  /var/www/app/releases/20261106183000
 *   $SHARED_PATH   /var/www/app/shared
 *   $CURRENT_PATH  /var/www/app/current   (symlink to the live release)
 *   $PHP, $COMPOSER_BIN, $BRANCH, $COMMIT, $WEB_USER, $PROJECT
 *
 * Layout on the server (like Envoyer / Deployer):
 *   releases/<release>/   one folder per deploy, the last N are kept
 *   shared/.env, shared/storage/   kept between releases, symlinked into each release
 *   current -> releases/<release>   what Nginx serves (root …/current/public)
 */
class DeployScripts
{
    public const string COMMIT_MARKER = '__AUTODEPLOY_COMMIT__';

    public const string RELEASES_MARKER = '__AUTODEPLOY_RELEASES__';

    public function __construct(
        private readonly Project $project,
        private readonly string $release,
    ) {}

    /** @return array<string, string> */
    public function variables(?Deployment $deployment = null): array
    {
        $project = $this->project;

        return [
            'PROJECT' => $project->slug,
            'DEPLOY_PATH' => $project->deployPath(),
            'RELEASE' => $this->release,
            'RELEASE_PATH' => $project->releasePath($this->release),
            'SHARED_PATH' => $project->sharedPath(),
            'CURRENT_PATH' => $project->currentPath(),
            'PHP' => $project->php_binary,
            'COMPOSER_BIN' => $project->composer_binary,
            'BRANCH' => (string) ($deployment->branch ?? $project->branch),
            'COMMIT' => (string) ($deployment->commit_hash ?? ''),
            'WEB_USER' => (string) $project->web_user,
        ];
    }

    /** Adds the common preamble: stderr into stdout, stop on errors, PATH, variables. */
    public function wrap(string $body, ?Deployment $deployment = null): string
    {
        return implode("\n", [
            'exec 2>&1',
            // Node installed with nvm is not on the PATH of non-interactive shells.
            'if [ -s "$HOME/.nvm/nvm.sh" ]; then . "$HOME/.nvm/nvm.sh" >/dev/null 2>&1 || true; fi',
            'export PATH="$HOME/.config/composer/vendor/bin:$HOME/.composer/vendor/bin:/usr/local/bin:$PATH"',
            'export COMPOSER_ALLOW_SUPERUSER=1 GIT_TERMINAL_PROMPT=0',
            'export GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=accept-new -o BatchMode=yes"',
            Shell::exports($this->variables($deployment)),
            'set -e',
            $body,
        ]);
    }

    public function prepare(): string
    {
        $lines = [
            'mkdir -p "$DEPLOY_PATH/releases" "$SHARED_PATH" "$DEPLOY_PATH/.autodeploy"',
            'if ! command -v git >/dev/null 2>&1; then',
            '  echo "git is not installed on the server (apt install git)."',
            '  exit 1',
            'fi',
            'if [ -e "$CURRENT_PATH" ] && [ ! -L "$CURRENT_PATH" ]; then',
            '  echo "$CURRENT_PATH exists and is a normal folder, not a link."',
            '  echo "Move it away (it is probably an old copy of the site) and deploy again."',
            '  exit 1',
            'fi',
        ];

        if (in_array('.env', $this->project->sharedFiles(), true)) {
            $lines[] = 'if [ ! -f "$SHARED_PATH/.env" ]; then';
            $lines[] = '  echo "Missing $SHARED_PATH/.env"';
            $lines[] = '  echo "Open the Environment tab in AutoDeploy (or Quick setup to import it), save the .env, then deploy again."';
            $lines[] = '  exit 1';
            $lines[] = 'fi';
        }

        return implode("\n", [
            ...$lines,
            'echo "Connected as $(whoami) on $(hostname)"',
            'echo "New release: $RELEASE"',
            'if command -v free >/dev/null 2>&1; then',
            '  free -m | awk \'/^Mem:/ {print "Memory: " $7 " MB available of " $2 " MB"}\'',
            'fi',
            'df -h "$DEPLOY_PATH" 2>/dev/null | awk \'NR==2 {print "Disk: " $4 " free of " $2}\' || true',
        ]);
    }

    /** Fetches only one commit (or the tip of the branch) into the new release folder. */
    public function clone(string $ref): string
    {
        $repo = Shell::quote($this->project->repository);
        $quotedRef = Shell::quote($ref);
        $isCommit = (bool) preg_match('/^[0-9a-f]{7,40}$/i', $ref);

        return implode("\n", [
            'rm -rf "$RELEASE_PATH"',
            'mkdir -p "$RELEASE_PATH"',
            'cd "$RELEASE_PATH"',
            'git -c init.defaultBranch=main init -q',
            "git remote add origin {$repo}",
            "echo \"Fetching {$ref} from {$this->project->repository}\"",
            "if ! git fetch --depth 1 --no-tags origin {$quotedRef}; then",
            '  echo ""',
            $isCommit
                ? '  echo "Could not fetch this commit. Make sure it is pushed to the repository, and that the server can read the repository (deploy key)."'
                : '  echo "Could not fetch the branch. Check the branch name and that the server can read the repository (deploy key)."',
            '  exit 1',
            'fi',
            'git -c advice.detachedHead=false checkout -q FETCH_HEAD',
            'echo "'.self::COMMIT_MARKER.'	$(git log -1 --format=\'%H%x09%an%x09%s\')"',
            'git log -1 --format=\'Commit %h by %an: %s\'',
        ]);
    }

    /** Moves shared folders / files out of the release and links them from shared/. */
    public function shared(): string
    {
        $lines = ['cd "$RELEASE_PATH"'];

        foreach ($this->project->sharedDirs() as $dir) {
            $q = Shell::quote(trim($dir, '/'));
            $lines[] = "S=\"\$SHARED_PATH\"/{$q}; R=\"\$RELEASE_PATH\"/{$q}";
            $lines[] = 'if [ ! -e "$S" ]; then';
            $lines[] = '  mkdir -p "$(dirname "$S")"';
            $lines[] = "  if [ -d \"\$R\" ]; then cp -a \"\$R\" \"\$S\"; echo \"Created shared/{$dir} (copied from the release)\"; else mkdir -p \"\$S\"; echo \"Created shared/{$dir}\"; fi";
            $lines[] = 'fi';
            $lines[] = 'rm -rf "$R"';
            $lines[] = 'mkdir -p "$(dirname "$R")"';
            $lines[] = 'ln -s "$S" "$R"';
            $lines[] = "echo \"Linked {$dir}\"";
        }

        if (in_array('storage', array_map(fn ($d) => trim($d, '/'), $this->project->sharedDirs()), true)) {
            // Laravel needs these folders; an empty storage/ from git may not have them.
            $lines[] = 'mkdir -p "$SHARED_PATH/storage/app/public" "$SHARED_PATH/storage/framework/cache/data" "$SHARED_PATH/storage/framework/sessions" "$SHARED_PATH/storage/framework/views" "$SHARED_PATH/storage/logs"';
        }

        foreach ($this->project->sharedFiles() as $file) {
            $q = Shell::quote(trim($file, '/'));
            $lines[] = "S=\"\$SHARED_PATH\"/{$q}; R=\"\$RELEASE_PATH\"/{$q}";
            $lines[] = 'if [ ! -e "$S" ]; then';
            $lines[] = '  mkdir -p "$(dirname "$S")"';
            $lines[] = '  touch "$S"';
            $lines[] = "  echo \"Created an empty shared/{$file}\"";
            $lines[] = 'fi';
            $lines[] = 'rm -f "$R"';
            $lines[] = 'mkdir -p "$(dirname "$R")"';
            $lines[] = 'ln -s "$S" "$R"';
            $lines[] = "echo \"Linked {$file}\"";
        }

        return implode("\n", $lines);
    }

    public function composer(): string
    {
        $project = $this->project;

        return implode("\n", [
            'cd "$RELEASE_PATH"',
            'if [ ! -f composer.json ]; then',
            '  echo "No composer.json in the project, nothing to install."',
            '  exit 0',
            'fi',
            'echo "$ '.$project->composer_binary.' install '.$project->composer_flags.'"',
            $project->composer_binary.' install '.$project->composer_flags,
        ]);
    }

    /** A custom command, run inside the release folder. Each command is printed (dimmed) right before it runs. */
    public function hook(string $script): string
    {
        return implode("\n", [
            'cd "$RELEASE_PATH"',
            'trap \'printf "\033[2m$ %s\033[0m\n" "$BASH_COMMAND"\' DEBUG',
            str_replace("\r\n", "\n", $script),
        ]);
    }

    public function serverBuild(): string
    {
        $project = $this->project;
        $output = Shell::quote($project->build_output);

        return implode("\n", [
            'cd "$RELEASE_PATH"',
            'if command -v free >/dev/null 2>&1; then',
            '  AVAILABLE=$(free -m | awk \'/^Mem:/ {print $7}\')',
            '  echo "Memory available: ${AVAILABLE} MB"',
            '  if [ "${AVAILABLE:-0}" -lt 1024 ]; then',
            '    echo "WARNING: less than 1 GB of RAM is free. If the build gets killed, switch the project to \"Build locally\" or \"Upload a saved build\"."',
            '  fi',
            'fi',
            'echo '.Shell::quote('$ '.$project->server_build_command),
            $project->server_build_command,
            "if [ ! -d {$output} ]; then",
            "  echo \"The build finished but {$project->build_output} does not exist. Check 'Build output folder' in the project settings.\"",
            '  exit 1',
            'fi',
            "echo \"Build output: \$(find {$output} -type f | wc -l) files in {$project->build_output}\"",
        ]);
    }

    /** Unpacks an uploaded build archive into the build folder of the release. */
    public function extractBuild(string $remoteArchive): string
    {
        $output = Shell::quote($this->project->build_output);
        $archive = Shell::quote($remoteArchive);

        return implode("\n", [
            "OUT=\"\$RELEASE_PATH\"/{$output}",
            'rm -rf "$OUT"',
            'mkdir -p "$OUT"',
            "tar -xzf {$archive} -C \"\$OUT\"",
            "rm -f {$archive}",
            "echo \"Extracted \$(find \"\$OUT\" -type f | wc -l) files into {$this->project->build_output}\"",
            'if [ -f "$OUT/manifest.json" ]; then',
            '  echo "manifest.json found"',
            'else',
            '  echo "WARNING: no manifest.json in the build folder, Laravel may not find the Vite assets."',
            'fi',
        ]);
    }

    /**
     * Gives storage/ and bootstrap/cache to the web server user (php-fpm), when we can.
     * Runs in the activate step, and again as its own step after the "after going live"
     * hooks, which run as the SSH user and may create files php-fpm cannot write.
     */
    public function permissions(): string
    {
        if (! $this->project->web_user) {
            return 'true';
        }

        $storage = in_array('storage', array_map(fn ($d) => trim($d, '/'), $this->project->sharedDirs()), true)
            ? '"$SHARED_PATH/storage"'
            : '"$RELEASE_PATH/storage"';

        return implode("\n", [
            'if id "$WEB_USER" >/dev/null 2>&1; then',
            '  if [ "$(id -u)" = "0" ]; then CHOWN="chown"; elif sudo -n true 2>/dev/null; then CHOWN="sudo -n chown"; else CHOWN=""; fi',
            '  if [ -n "$CHOWN" ]; then',
            "    for P in {$storage} \"\$RELEASE_PATH/bootstrap/cache\"; do",
            '      if [ -e "$P" ]; then $CHOWN -R "$WEB_USER":"$WEB_USER" "$P/" || echo "WARNING: could not change the owner of $P"; fi',
            '    done',
            '    echo "storage and bootstrap/cache belong to $WEB_USER"',
            '  else',
            '    echo "Not root and no sudo: storage permissions left as they are."',
            '  fi',
            'else',
            '  echo "User $WEB_USER does not exist on this server, permissions left as they are."',
            'fi',
        ]);
    }

    /** Points "current" to the release in one atomic rename (no downtime). */
    public function activate(): string
    {
        return implode("\n", [
            $this->permissions(),
            'cd "$DEPLOY_PATH"',
            'if [ ! -d "releases/$RELEASE" ]; then',
            '  echo "Release $RELEASE not found on the server."',
            '  exit 1',
            'fi',
            'PREVIOUS="$(readlink current 2>/dev/null || echo none)"',
            'ln -sfn "releases/$RELEASE" current-next',
            'mv -Tf current-next current',
            'echo "current: $PREVIOUS -> $(readlink current)"',
        ]);
    }

    /**
     * Points the Apache / Nginx site of the project URL to current/public, right after
     * the switch. Without it a moved site keeps serving its old folder, while the deploy
     * already ran the new migrations on the shared database.
     *
     * - The sites are found by the URL host in ServerName / ServerAlias (Apache) or
     *   server_name (Nginx): whole names, any case.
     * - Only a DocumentRoot / root that ends with /public is moved. A site that only
     *   redirects (e.g. DocumentRoot /var/www/html) is left as it is.
     * - Every file is backed up to .autodeploy/webserver/<release>/ first. A failing
     *   config test restores all of them, and nothing is reloaded.
     * - Exit 1 (the deploy fails, loudly) when a site that must change was found but
     *   could not be changed: no root / sudo, failing config test, failed reload. The
     *   release is live by then, but the site would run the old code against the
     *   migrated database. Exit 0 with a WARNING and the manual change when no site of
     *   the host is found at all (AutoDeploy cannot know which one serves it).
     * - A second run changes nothing: "already serves current".
     *
     * The folders it looks in are variables (APACHE_DIRS, NGINX_DIRS, FPM_DIRS,
     * WEB_BACKUPS) with the usual paths as defaults, so it can be tried on a copy of /etc.
     */
    public function webServer(): string
    {
        $host = $this->project->urlHost();

        if ($host === null) {
            return 'echo "WARNING: the project has no site URL, so AutoDeploy cannot tell which Apache / Nginx site serves it."';
        }

        return 'SITE_HOST='.Shell::quote($host)."\n".self::WEB_SERVER_SCRIPT;
    }

    private const string WEB_SERVER_SCRIPT = <<<'BASH'
        set +e
        TARGET="$CURRENT_PATH/public"
        APACHE_DIRS="${APACHE_DIRS:-/etc/apache2/sites-enabled /etc/httpd/conf.d}"
        NGINX_DIRS="${NGINX_DIRS:-/etc/nginx/sites-enabled /etc/nginx/conf.d}"
        FPM_DIRS="${FPM_DIRS:-/etc/php/*/fpm/pool.d /etc/php-fpm.d /etc/php*/php-fpm.d}"
        WEB_BACKUPS="${WEB_BACKUPS:-$DEPLOY_PATH/.autodeploy/webserver}"
        # apachectl and nginx live in /usr/sbin, which is not on the PATH of every user.
        export PATH="$PATH:/usr/sbin:/sbin" SITE_HOST
        TAB="$(printf '\t')"
        WORK="$(mktemp -d 2>/dev/null)"
        if [ -z "$WORK" ]; then WORK="$DEPLOY_PATH/.autodeploy/web-$$"; mkdir -p "$WORK"; fi
        trap 'rm -rf "$WORK"' EXIT

        # Apache: prints "M" for each <VirtualHost> that lists $SITE_HOST in ServerName or
        # ServerAlias, then "R <path>" for each of its DocumentRoot values. Names are compared
        # whole and in lower case: app.example.com never matches myapp.example.com.
        APACHE_AWK='
        BEGIN { host = ENVIRON["SITE_HOST"]; q = sprintf("%c", 39) }
        function clean(v) { gsub(/"/, "", v); gsub(q, "", v); sub(/[ \t]+$/, "", v); if (v != "/") sub(/\/+$/, "", v); return v }
        function name(v) { v = tolower(clean(v)); sub(/^[a-z]+:\/\//, "", v); sub(/:[0-9]+$/, "", v); sub(/\.$/, "", v); return v }
        { sub(/\r$/, ""); sub(/^[ \t]+/, ""); d = tolower($1) }
        d ~ /^#/ { next }
        d ~ /^<virtualhost/ { inside = 1; hit = 0; n = 0; next }
        d ~ /^<\/virtualhost/ { if (inside && hit) { print "M"; for (i = 1; i <= n; i++) print "R " roots[i] } inside = 0; next }
        !inside { next }
        d == "servername" || d == "serveralias" { for (i = 2; i <= NF; i++) if (name($i) == host) hit = 1 }
        d == "documentroot" { v = $0; sub(/^[^ \t]+[ \t]+/, "", v); roots[++n] = clean(v) }
        '

        # Nginx: the same for each server { } block and its server_name / root lines.
        # ".example.com" is Nginx for example.com and its subdomains, so it lists example.com too.
        NGINX_AWK='
        BEGIN { host = ENVIRON["SITE_HOST"]; q = sprintf("%c", 39) }
        function clean(v) { gsub(/"/, "", v); gsub(q, "", v); sub(/[ \t]+$/, "", v); if (v != "/") sub(/\/+$/, "", v); return v }
        function name(v) { v = tolower(clean(v)); sub(/\.$/, "", v); return v }
        {
            sub(/\r$/, ""); sub(/#.*/, ""); line = $0
            if (!inside && line ~ /(^|[ \t;{}])server[ \t]*\{/) { inside = 1; start = depth; hit = 0; n = 0; names = 0 }
            if (inside) {
                s = line
                if (names || s ~ /^[ \t]*server_name([ \t]|$)/) {
                    if (!names) sub(/^[ \t]*server_name/, "", s)
                    names = (s !~ /;/); sub(/;.*/, "", s)
                    k = split(s, parts, /[ \t]+/)
                    for (j = 1; j <= k; j++) { h = name(parts[j]); if (h != "" && (h == host || h == "." host)) hit = 1 }
                } else if (s ~ /^[ \t]*root[ \t]/) {
                    sub(/^[ \t]*root[ \t]+/, "", s); sub(/;.*/, "", s); roots[++n] = clean(s)
                }
            }
            t = line; opens = gsub(/\{/, "", t); t = line; shuts = gsub(/\}/, "", t); depth += opens - shuts
            if (inside && depth <= start) { if (hit) { print "M"; for (i = 1; i <= n; i++) print "R " roots[i] } inside = 0 }
        }
        '

        # Replaces the path OLD_ROOT with NEW_ROOT (MODE=swap, prints the file) or only tells
        # whether OLD_ROOT is in the file (MODE=find). Plain text search, no regex, so slashes
        # and dots in paths need no escaping. Only whole paths: /var/www/site/public matches in
        # "/var/www/site/public;" or "/var/www/site/public/uploads", never in "/var/www/site/public2".
        # A target with spaces gets quotes where the old path had none.
        PATH_AWK='
        BEGIN {
            old = ENVIRON["OLD_ROOT"]; new = ENVIRON["NEW_ROOT"]; find = (ENVIRON["MODE"] == "find"); q = sprintf("%c", 39)
            before = " \t\"=:<>,(" q; after = " \t\r\"/:<>;,)" q; quoted = (new ~ /[ \t]/) ? "\"" new "\"" : new
        }
        function swap(s,    out, i, b, a, L) {
            out = ""; L = length(old)
            while ((i = index(s, old)) > 0) {
                b = (i > 1) ? substr(s, i - 1, 1) : (out == "" ? "" : substr(out, length(out), 1))
                a = substr(s, i + L, 1)
                if ((b == "" || index(before, b) > 0) && (a == "" || index(after, a) > 0)) {
                    out = out substr(s, 1, i - 1) ((b == "\"" || b == q || a == "/") ? new : quoted); n++
                } else {
                    out = out substr(s, 1, i - 1 + L)
                }
                s = substr(s, i + L)
            }
            return out s
        }
        { line = swap($0); if (find) { if (n) exit 0; next } print line }
        END { exit (n ? 0 : 1) }
        '

        label() { if [ "$1" = apache ]; then echo Apache; else echo Nginx; fi; }

        has_dir() { for D in $1; do if [ -d "$D" ]; then return 0; fi; done; return 1; }

        # The real files (symlinks followed) a web server loads: *.conf, and every file in
        # Nginx's sites-enabled. A sites-enabled link stays a link: its target is edited.
        config_files() {
            for D in $2; do
                [ -d "$D" ] || continue
                for F in "$D"/*; do
                    [ -f "$F" ] || continue
                    case "$1:$F" in
                        *.conf) ;;
                        nginx:*) case "$D" in *sites-enabled) ;; *) continue ;; esac ;;
                        *) continue ;;
                    esac
                    readlink -f "$F"
                done
            done | sort -u
        }

        # Only a root that ends with /public is an app to move. A site that only redirects
        # (DocumentRoot /var/www/html with a Redirect, or return 301) is never touched, and
        # neither is a root built from variables.
        app_public() { case "$1" in *'$'*) return 1 ;; /?*/public) return 0 ;; *) return 1 ;; esac; }

        manual() {
            echo "Point the site of $SITE_HOST to $TARGET by hand:"
            echo "  Apache: DocumentRoot \"$TARGET\" and <Directory \"$TARGET\">, then: apachectl configtest && systemctl reload apache2"
            echo "  Nginx:  root \"$TARGET\"; then: nginx -t && systemctl reload nginx"
        }

        config_test() { if [ "$1" = apache ]; then $SUDO "$APACHECTL" configtest; else $SUDO "$NGINX" -t; fi; }

        # Never starts a stopped server ("apachectl -k graceful" would). Without pgrep: assume it runs.
        running() {
            command -v pgrep >/dev/null 2>&1 || return 0
            if [ "$1" = apache ]; then pgrep -x apache2 >/dev/null || pgrep -x httpd >/dev/null; else pgrep -x nginx >/dev/null; fi
        }

        reload_web() {
            if [ "$1" = apache ]; then
                $SUDO systemctl reload apache2 2>/dev/null || $SUDO systemctl reload httpd 2>/dev/null \
                    || $SUDO service apache2 reload 2>/dev/null || $SUDO service httpd reload 2>/dev/null \
                    || $SUDO "$APACHECTL" -k graceful
            else
                $SUDO systemctl reload nginx 2>/dev/null || $SUDO service nginx reload 2>/dev/null || $SUDO "$NGINX" -s reload
            fi
        }

        restore() {
            while IFS="$TAB" read -r NAME F <&5; do
                if $SUDO cp "$BACKUP/$NAME" "$F"; then echo "Restored $F"; else echo "✖ Could not restore $F from $BACKUP/$NAME"; fi
            done 5< "$BACKUP/original-paths.txt"
        }

        echo "Site: $SITE_HOST"
        echo "Target: $TARGET"
        APACHECTL="$(command -v apache2ctl || command -v apachectl)"
        NGINX="$(command -v nginx)"
        SERVERS=""
        if [ -n "$APACHECTL" ] && has_dir "$APACHE_DIRS"; then SERVERS="apache"; fi
        if [ -n "$NGINX" ] && has_dir "$NGINX_DIRS"; then SERVERS="$SERVERS nginx"; fi

        if [ -z "$SERVERS" ]; then
            echo "WARNING: no Apache or Nginx config found (looked in $APACHE_DIRS $NGINX_DIRS)."
            manual
            exit 0
        fi

        # 1. Find the sites of the host and what they serve now (read only).
        : > "$WORK/plan"
        MATCHED=0
        SERVING=0
        for KIND in $SERVERS; do
            if [ "$KIND" = apache ]; then DIRS="$APACHE_DIRS"; PROGRAM="$APACHE_AWK"; else DIRS="$NGINX_DIRS"; PROGRAM="$NGINX_AWK"; fi
            config_files "$KIND" "$DIRS" > "$WORK/files"
            while IFS= read -r F <&3; do
                awk "$PROGRAM" "$F" > "$WORK/roots" 2>/dev/null
                grep -q '^M$' "$WORK/roots" || continue
                MATCHED=$((MATCHED + 1))
                sed -n 's/^R //p' "$WORK/roots" | sort -u > "$WORK/olds"
                if [ ! -s "$WORK/olds" ]; then
                    echo "• $F lists $SITE_HOST without a root (a redirect or a proxy): left as it is"
                    continue
                fi
                while IFS= read -r OLD <&4; do
                    if [ "$OLD" = "$TARGET" ]; then
                        echo "✔ $F already serves current"
                        SERVING=$((SERVING + 1))
                    elif app_public "$OLD"; then
                        printf '%s\t%s\t%s\n' "$KIND" "$F" "$OLD" >> "$WORK/plan"
                    else
                        echo "• $F serves $OLD for $SITE_HOST, not an app public folder (a redirect?): left as it is"
                    fi
                done 4< "$WORK/olds"
            done 3< "$WORK/files"
        done

        if [ "$MATCHED" = 0 ]; then
            echo "WARNING: no Apache / Nginx site lists $SITE_HOST (ServerName / ServerAlias / server_name) in $APACHE_DIRS $NGINX_DIRS."
            echo "The new release is live in current, but AutoDeploy cannot tell which site serves it."
            manual
            exit 0
        fi

        if [ ! -s "$WORK/plan" ]; then
            if [ "$SERVING" -gt 0 ]; then
                echo "The web server already serves current: nothing to change."
            else
                echo "WARNING: the sites of $SITE_HOST serve no app public folder, nothing was changed."
                manual
            fi
            exit 0
        fi

        echo "To change:"
        while IFS="$TAB" read -r KIND F OLD <&3; do echo "  $F: $OLD → $TARGET"; done 3< "$WORK/plan"
        KINDS="$(cut -f1 "$WORK/plan" | sort -u)"

        # 2. Checks before touching anything.
        if [ ! -d "$TARGET" ]; then
            echo "✖ $TARGET does not exist (the release has no public folder): nothing was changed."
            exit 1
        fi

        if [ "$(id -u)" = "0" ]; then
            SUDO=""
        elif sudo -n true >/dev/null 2>&1; then
            SUDO="sudo -n"
        else
            echo "✖ $(whoami) is not root and has no passwordless sudo, so AutoDeploy cannot change these files."
            echo "The new release is live in current and its migrations ran, but the site still runs the old code."
            manual
            echo "Or connect as root (or allow sudo without a password) and deploy again."
            exit 1
        fi

        for KIND in $KINDS; do
            if ! OUT="$(config_test "$KIND" 2>&1)"; then
                echo "$OUT"
                echo "✖ The $(label "$KIND") config test fails already, before any change. Fix it first: nothing was changed."
                exit 1
            fi
        done

        # 3. Back up every file that changes, with a note of where it came from.
        BACKUP="$WEB_BACKUPS/$RELEASE"
        N=2
        while [ -e "$BACKUP" ]; do BACKUP="$WEB_BACKUPS/$RELEASE-$N"; N=$((N + 1)); done
        if ! mkdir -p "$BACKUP"; then
            echo "✖ Could not create $BACKUP: nothing was changed."
            exit 1
        fi
        cut -f2 "$WORK/plan" | sort -u > "$WORK/changed"
        I=0
        while IFS= read -r F <&3; do
            I=$((I + 1))
            NAME="$(basename "$F")"
            if [ -e "$BACKUP/$NAME" ]; then NAME="$I-$NAME"; fi
            if ! $SUDO cp -p "$F" "$BACKUP/$NAME"; then
                echo "✖ Could not back up $F: nothing was changed."
                exit 1
            fi
            printf '%s\t%s\n' "$NAME" "$F" >> "$BACKUP/original-paths.txt"
        done 3< "$WORK/changed"
        echo "Backup: $BACKUP"

        # 4. Change, test, reload. cp onto the file keeps its owner, mode and links.
        while IFS="$TAB" read -r KIND F OLD <&3; do
            if OLD_ROOT="$OLD" NEW_ROOT="$TARGET" MODE=swap awk "$PATH_AWK" "$F" > "$WORK/new" && [ -s "$WORK/new" ] && $SUDO cp "$WORK/new" "$F"; then
                echo "✔ $F: $OLD → $TARGET"
            else
                echo "✖ Could not change $F: every file is restored."
                restore
                exit 1
            fi
        done 3< "$WORK/plan"

        for KIND in $KINDS; do
            if OUT="$(config_test "$KIND" 2>&1)"; then
                echo "✔ $(label "$KIND") config test passed"
            else
                echo "$OUT"
                echo "✖ The $(label "$KIND") config test failed after the change: every file is restored and nothing was reloaded."
                restore
                exit 1
            fi
        done

        FAILED=no
        for KIND in $KINDS; do
            if ! running "$KIND"; then
                echo "• $(label "$KIND") is not running: the change applies when it starts."
            elif reload_web "$KIND"; then
                echo "✔ $(label "$KIND") reloaded: $SITE_HOST is served from $TARGET"
            else
                if [ "$KIND" = apache ]; then HINT="systemctl reload apache2"; else HINT="systemctl reload nginx"; fi
                echo "✖ Could not reload $(label "$KIND"). The config is changed and valid: reload it by hand ($HINT)."
                FAILED=yes
            fi
        done

        # 5. Only reported: the old app folder may still be named elsewhere, e.g. open_basedir
        # in a PHP-FPM pool, or logs and aliases in a site.
        cut -f3 "$WORK/plan" | sort -u | while IFS= read -r OLD; do dirname "$OLD"; done | sort -u > "$WORK/apps"
        {
            config_files apache "$APACHE_DIRS"
            config_files nginx "$NGINX_DIRS"
            for D in $FPM_DIRS; do
                [ -d "$D" ] || continue
                for F in "$D"/*.conf; do if [ -f "$F" ]; then readlink -f "$F"; fi; done
            done
        } | sort -u > "$WORK/scan"
        while IFS= read -r APP <&3; do
            [ "$APP" = "/" ] && continue
            : > "$WORK/still"
            while IFS= read -r F <&4; do
                if OLD_ROOT="$APP" MODE=find awk "$PATH_AWK" "$F" >/dev/null 2>&1; then echo "  $F" >> "$WORK/still"; fi
            done 4< "$WORK/scan"
            if [ -s "$WORK/still" ]; then
                echo "WARNING: these files still name the old folder $APP (open_basedir, logs, aliases…). AutoDeploy did not change them, check them:"
                cat "$WORK/still"
            fi
        done 3< "$WORK/apps"

        grep "^nginx$TAB" "$WORK/plan" | cut -f2 | sort -u | while IFS= read -r F; do
            grep -q realpath_root "$F" || echo "Note: $F gives PHP \$document_root. Keep “Reload PHP-FPM” after going live (it clears OPcache), or use \$realpath_root (see Quick setup)."
        done

        if [ "$FAILED" = yes ]; then exit 1; fi
        exit 0
        BASH;

    /** Keeps the newest N releases (and always the live one). */
    public function cleanup(int $keep): string
    {
        $keep = max(1, $keep);

        return implode("\n", [
            'cd "$DEPLOY_PATH/releases"',
            'ACTIVE="$(basename "$(readlink "$CURRENT_PATH" 2>/dev/null || echo none)")"',
            'COUNT=0',
            'for R in $(ls -1 | sort -r); do',
            '  COUNT=$((COUNT + 1))',
            "  if [ \"\$COUNT\" -le {$keep} ] || [ \"\$R\" = \"\$ACTIVE\" ]; then continue; fi",
            '  rm -rf "$R"',
            '  echo "Removed old release $R"',
            'done',
            'rm -f "$DEPLOY_PATH"/.autodeploy/build-*.tar.gz',
            'echo "Releases kept: $(ls -1 | sort -r | tr \'\n\' \' \')"',
            'echo "'.self::RELEASES_MARKER.' $(ls -1 | sort -r | tr \'\n\' \' \')"',
        ]);
    }

    /** Removes the release folder of a failed deploy (never the live one). */
    public function removeRelease(): string
    {
        return implode("\n", [
            'if [ "$(readlink "$CURRENT_PATH" 2>/dev/null)" != "releases/$RELEASE" ] && [ -d "$RELEASE_PATH" ]; then',
            '  rm -rf "$RELEASE_PATH"',
            '  echo "Removed the unfinished release $RELEASE"',
            'fi',
        ]);
    }

    public function checkRelease(): string
    {
        return implode("\n", [
            'if [ ! -d "$RELEASE_PATH" ]; then',
            '  echo "Release $RELEASE is no longer on the server."',
            '  exit 1',
            'fi',
            'echo "Release $RELEASE found"',
            'echo "Live now: $(readlink "$CURRENT_PATH" 2>/dev/null || echo none)"',
        ]);
    }

    /** Lists the release folders on the server and the live one. */
    public function listReleases(): string
    {
        return implode("\n", [
            'if [ -d "$DEPLOY_PATH/releases" ]; then ls -1 "$DEPLOY_PATH/releases" | sort -r | sed \'s/^/release=/\'; fi',
            'echo "current=$(basename "$(readlink "$CURRENT_PATH" 2>/dev/null || echo)")"',
        ]);
    }

    /**
     * Release folder names after the cleanup marker in a step output.
     *
     * @return list<string>|null
     */
    public static function parseReleases(string $output): ?array
    {
        if (! preg_match('/'.self::RELEASES_MARKER.' ?([^\r\n]*)/', $output, $matches)) {
            return null;
        }

        return array_values(array_filter(explode(' ', trim($matches[1]))));
    }

    /**
     * Commit hash, author and subject printed by the clone step.
     *
     * @return array{hash: string, author: string, message: string}|null
     */
    public static function parseCommit(string $output): ?array
    {
        if (! preg_match('/'.self::COMMIT_MARKER.'\t([0-9a-f]{7,64})\t([^\t]*)\t([^\r\n]*)/', $output, $matches)) {
            return null;
        }

        return ['hash' => $matches[1], 'author' => $matches[2], 'message' => $matches[3]];
    }
}
