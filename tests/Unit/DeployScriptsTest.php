<?php

use App\Models\Project;
use App\Services\Deploy\DeployScripts;
use App\Services\Remote\Shell;

function scriptsFor(array $attributes = []): DeployScripts
{
    $project = new Project([
        'name' => 'Shop',
        'repository' => 'git@github.com:acme/shop.git',
        'branch' => 'main',
        'deploy_path' => '/var/www/shop/',
        'php_binary' => 'php8.4',
        'composer_binary' => 'composer',
        'composer_flags' => '--no-dev',
        'build_output' => 'public/build',
        'shared_dirs' => ['storage'],
        'shared_files' => ['.env'],
        'web_user' => 'www-data',
        ...$attributes,
    ]);
    $project->slug = 'shop';

    return new DeployScripts($project, '20261106183000');
}

it('quotes values for bash, also single quotes', function () {
    expect(Shell::quote("it's"))->toBe("'it'\\''s'")
        ->and(Shell::exports(['A' => 'x y']))->toBe("export A='x y'");
});

it('exports the deploy variables (never COMPOSER, which composer reads itself)', function () {
    $wrapped = scriptsFor()->wrap('echo hi');

    expect($wrapped)
        ->toContain("RELEASE_PATH='/var/www/shop/releases/20261106183000'")
        ->toContain("SHARED_PATH='/var/www/shop/shared'")
        ->toContain("PHP='php8.4'")
        ->toContain("COMPOSER_BIN='composer'")
        ->not->toContain(" COMPOSER='")
        ->toContain('set -e');
});

it('switches the current link atomically', function () {
    expect(scriptsFor()->activate())
        ->toContain('ln -sfn "releases/$RELEASE" current-next')
        ->toContain('mv -Tf current-next current');
});

it('links shared folders and files into the release', function () {
    $script = scriptsFor()->shared();

    expect($script)
        ->toContain("S=\"\$SHARED_PATH\"/'storage'")
        ->toContain('ln -s "$S" "$R"')
        ->toContain('storage/framework/views')
        ->toContain("S=\"\$SHARED_PATH\"/'.env'");
});

it('stops when the shared .env is missing', function () {
    expect(scriptsFor()->prepare())->toContain('Missing $SHARED_PATH/.env')
        ->and(scriptsFor(['shared_files' => []])->prepare())->not->toContain('Missing $SHARED_PATH/.env');
});

it('keeps the newest releases and the live one', function () {
    expect(scriptsFor()->cleanup(3))
        ->toContain('if [ "$COUNT" -le 3 ] || [ "$R" = "$ACTIVE" ]; then continue; fi');
});

it('gives storage and bootstrap/cache to the web user', function () {
    expect(scriptsFor()->permissions())->toBe(implode("\n", [
        'if id "$WEB_USER" >/dev/null 2>&1; then',
        '  if [ "$(id -u)" = "0" ]; then CHOWN="chown"; elif sudo -n true 2>/dev/null; then CHOWN="sudo -n chown"; else CHOWN=""; fi',
        '  if [ -n "$CHOWN" ]; then',
        '    for P in "$SHARED_PATH/storage" "$RELEASE_PATH/bootstrap/cache"; do',
        '      if [ -e "$P" ]; then $CHOWN -R "$WEB_USER":"$WEB_USER" "$P/" || echo "WARNING: could not change the owner of $P"; fi',
        '    done',
        '    echo "storage and bootstrap/cache belong to $WEB_USER"',
        '  else',
        '    echo "Not root and no sudo: storage permissions left as they are."',
        '  fi',
        'else',
        '  echo "User $WEB_USER does not exist on this server, permissions left as they are."',
        'fi',
    ]))
        ->and(scriptsFor(['shared_dirs' => []])->permissions())->toContain('for P in "$RELEASE_PATH/storage" "$RELEASE_PATH/bootstrap/cache"; do')
        ->and(scriptsFor(['web_user' => null])->permissions())->toBe('true')
        // Before the switch; after the "after going live" hooks it runs as its own step.
        ->and(scriptsFor()->activate())->toStartWith(scriptsFor()->permissions())
        ->and(scriptsFor()->cleanup(3))->not->toContain('chown');
});

/**
 * Lines where bash would split $name on spaces: the variable is not inside "…" on its line.
 * Knows '…', "…", \ and $( … ) inside "…" (e.g. "$(cut -f1 "$WORK/plan")").
 *
 * @return list<string>
 */
function unquotedUses(string $script, string $name): array
{
    $lines = [];

    foreach (explode("\n", $script) as $line) {
        if (str_starts_with(ltrim($line), '#')) {
            continue;
        }

        $stack = []; // "d" inside "…", "s" inside '…', "c" inside $( … )

        for ($i = 0; $i < strlen($line); $i++) {
            $top = end($stack);
            $char = $line[$i];

            if ($top === 's') {
                if ($char === "'") {
                    array_pop($stack);
                }
            } elseif ($char === '\\') {
                $i++;
            } elseif ($char === '$' && ($line[$i + 1] ?? '') === '(') {
                $stack[] = 'c';
                $i++;
            } elseif ($char === ')' && $top === 'c') {
                array_pop($stack);
            } elseif ($char === '"' && $top === 'd') {
                array_pop($stack);
            } elseif ($char === '"') {
                $stack[] = 'd';
            } elseif ($char === "'" && $top !== 'd') {
                $stack[] = 's';
            } elseif ($char === '$' && $top !== 'd' && preg_match('/\G\$\{?'.$name.'(?![A-Za-z0-9_])/', $line, $m, 0, $i)) {
                $lines[] = trim($line);
            }
        }
    }

    return $lines;
}

it('finds the Apache and Nginx sites of the site URL by their whole host name', function () {
    $script = scriptsFor(['url' => 'https://App.Example.com/shop'])->webServer();

    expect($script)
        ->toStartWith("SITE_HOST='app.example.com'\n")
        ->toContain('TARGET="$CURRENT_PATH/public"')
        // The folders are variables, so the script can be tried on a copy of /etc.
        ->toContain('APACHE_DIRS="${APACHE_DIRS:-/etc/apache2/sites-enabled /etc/httpd/conf.d}"')
        ->toContain('NGINX_DIRS="${NGINX_DIRS:-/etc/nginx/sites-enabled /etc/nginx/conf.d}"')
        ->toContain('FPM_DIRS="${FPM_DIRS:-/etc/php/*/fpm/pool.d /etc/php-fpm.d /etc/php*/php-fpm.d}"')
        ->toContain('APACHECTL="$(command -v apache2ctl || command -v apachectl)"')
        // Whole names in lower case, in ServerName / ServerAlias and server_name.
        ->toContain('d == "servername" || d == "serveralias" { for (i = 2; i <= NF; i++) if (name($i) == host) hit = 1 }')
        ->toContain('if (h != "" && (h == host || h == "." host)) hit = 1')
        // sites-enabled links are followed: the real file is edited, the link stays.
        ->toContain('readlink -f "$F"')
        // Only an app public folder moves; a site that only redirects stays as it is.
        ->toContain("app_public() { case \"\$1\" in *'\$'*) return 1 ;; /?*/public) return 0 ;; *) return 1 ;; esac; }")
        ->toContain('already serves current')
        // Plain text replacement of whole paths, no regex to escape.
        ->toContain('OLD_ROOT="$OLD" NEW_ROOT="$TARGET" MODE=swap awk "$PATH_AWK" "$F" > "$WORK/new"')
        // The old app folder is only reported in PHP-FPM pools and sites (open_basedir).
        ->toContain('OLD_ROOT="$APP" MODE=find awk "$PATH_AWK" "$F"')
        ->toContain('WARNING: these files still name the old folder $APP');
});

it('backs up, tests and reloads the web server, and restores every file when the test fails', function () {
    $script = scriptsFor(['url' => 'https://app.example.com'])->webServer();
    $position = fn (string $text, int $after = 0) => strpos($script, $text, $after);

    expect($script)
        ->toContain('WEB_BACKUPS="${WEB_BACKUPS:-$DEPLOY_PATH/.autodeploy/webserver}"')
        ->toContain('BACKUP="$WEB_BACKUPS/$RELEASE"')
        ->toContain('if ! $SUDO cp -p "$F" "$BACKUP/$NAME"; then')
        ->toContain('printf \'%s\t%s\n\' "$NAME" "$F" >> "$BACKUP/original-paths.txt"')
        ->toContain('config_test() { if [ "$1" = apache ]; then $SUDO "$APACHECTL" configtest; else $SUDO "$NGINX" -t; fi; }')
        ->toContain('$SUDO systemctl reload apache2 2>/dev/null || $SUDO systemctl reload httpd 2>/dev/null')
        ->toContain('$SUDO systemctl reload nginx 2>/dev/null || $SUDO service nginx reload 2>/dev/null || $SUDO "$NGINX" -s reload')
        // Root or passwordless sudo, otherwise a loud failure with the manual change.
        ->toContain('elif sudo -n true >/dev/null 2>&1; then')
        // No site of the host at all: a warning, the deploy goes on.
        ->toContain('WARNING: no Apache / Nginx site lists $SITE_HOST');

    // Order: test before any change, back up, change, test again (restore on failure), reload.
    expect($position('fails already, before any change'))->toBeLessThan($position('echo "Backup: $BACKUP"'))
        ->and($position('echo "Backup: $BACKUP"'))->toBeLessThan($position('NEW_ROOT="$TARGET" MODE=swap'))
        ->and($position('NEW_ROOT="$TARGET" MODE=swap'))->toBeLessThan($position('config test failed after the change'))
        ->and($position('restore', $position('config test failed after the change')))->toBeLessThan($position('elif reload_web "$KIND"; then'));
});

it('quotes the target path, so deploy folders with spaces work', function () {
    $scripts = scriptsFor(['url' => 'https://app.example.com', 'deploy_path' => '/var/www/my shop']);
    $script = $scripts->webServer();

    expect($scripts->wrap($script))->toContain("CURRENT_PATH='/var/www/my shop/current'");

    foreach (['TARGET', 'CURRENT_PATH', 'DEPLOY_PATH', 'BACKUP', 'WORK', 'F', 'OLD', 'APP'] as $name) {
        expect(unquotedUses($script, $name))->toBe([], "\${$name} is not quoted");
    }

    // A config value gets quotes when the new path has spaces and the old one had none.
    expect($script)->toContain('quoted = (new ~ /[ \t]/) ? "\"" new "\"" : new');
});

it('prints a warning instead of a script without a site URL', function () {
    expect(scriptsFor(['url' => null])->webServer())->toContain('WARNING: the project has no site URL')
        ->and(scriptsFor(['url' => null])->webServer())->not->toContain('systemctl');
});

it('reads the commit and the releases from the step output', function () {
    expect(DeployScripts::parseCommit("x\n__AUTODEPLOY_COMMIT__\tabc1234\tAda\tFix it\n"))
        ->toBe(['hash' => 'abc1234', 'author' => 'Ada', 'message' => 'Fix it'])
        ->and(DeployScripts::parseReleases("__AUTODEPLOY_RELEASES__ 2 1 \n"))->toBe(['2', '1']);
});

it('recognises GitHub repositories', function (string $repository, ?string $slug) {
    expect((new Project(['repository' => $repository]))->githubSlug())->toBe($slug);
})->with([
    ['git@github.com:BranDavidIonel/AutoDeploy.git', 'BranDavidIonel/AutoDeploy'],
    ['https://github.com/BranDavidIonel/AutoDeploy.git', 'BranDavidIonel/AutoDeploy'],
    ['https://github.com/BranDavidIonel/AutoDeploy', 'BranDavidIonel/AutoDeploy'],
    ['git@gitlab.com:acme/shop.git', null],
]);
