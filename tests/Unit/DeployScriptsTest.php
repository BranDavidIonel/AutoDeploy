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
