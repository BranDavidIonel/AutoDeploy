<?php

use App\Services\Remote\LocalConnection;
use App\Services\Remote\LocalProcess;
use App\Services\Remote\Shell;
use Illuminate\Support\Str;

/*
| Commands that run on the machine of AutoDeploy itself (servers of type "This machine",
| local builds). These run real bash: Git Bash on Windows, /bin/bash on Linux.
*/

beforeEach(function () {
    // A .env of its own, so the tests do not depend on the real one.
    $this->envDir = str_replace('\\', '/', sys_get_temp_dir()).'/autodeploy-env-'.Str::lower(Str::random(10));
    mkdir($this->envDir);
    file_put_contents($this->envDir.'/.env', implode("\n", [
        'APP_KEY=base64:secret',
        'DB_CONNECTION=sqlite',
        'VITE_APP_NAME=AutoDeploy',
        'AUTODEPLOY_TEST_SECRET=from-dotenv',
        'PATH=/opt/tools/bin',
        'GIT_SSH_COMMAND="ssh -i key"',
        'NODE_OPTIONS=--max-old-space-size=512',
        '',
    ]));
    app()->useEnvironmentPath($this->envDir);
    app()->loadEnvironmentFrom('.env');
});

afterEach(function () {
    unset($_ENV['AUTODEPLOY_TEST_SECRET'], $_ENV['AUTODEPLOY_TEST_KEEP']);
    @unlink($this->envDir.'/.env');
    @rmdir($this->envDir);
});

it('removes the variables of the AutoDeploy .env, but keeps the ones of the tools', function () {
    $env = LocalProcess::environment(['MSYS' => 'winsymlinks:sys']);

    expect($env)->toMatchArray([
        'APP_KEY' => false,
        'DB_CONNECTION' => false,
        'VITE_APP_NAME' => false,
        'AUTODEPLOY_TEST_SECRET' => false,
        'SHELL_VERBOSITY' => false,
        'MSYS' => 'winsymlinks:sys',
    ])->not->toHaveKeys(['PATH', 'GIT_SSH_COMMAND', 'NODE_OPTIONS']);
});

it('runs a local script without the variables of the AutoDeploy .env', function () {
    // As Laravel leaves them after loading the .env, plus one that is not in it.
    $_ENV['AUTODEPLOY_TEST_SECRET'] = 'leaked';
    $_ENV['AUTODEPLOY_TEST_KEEP'] = 'kept';

    $result = (new LocalConnection)->run('echo "secret=${AUTODEPLOY_TEST_SECRET-unset} keep=${AUTODEPLOY_TEST_KEEP-unset}"', null, 30);

    expect($result->successful())->toBeTrue()
        ->and($result->trimmed())->toBe('secret=unset keep=kept');
});

it('reports a local script that runs too long as timed out', function () {
    $started = microtime(true);
    $result = (new LocalConnection)->run('echo begin; sleep 20; echo end', null, 1);

    expect($result->timedOut)->toBeTrue()
        ->and($result->successful())->toBeFalse()
        ->and($result->exitCode)->toBe(124)
        ->and($result->output)->toContain('begin')
        ->and($result->output)->not->toContain('end')
        ->and(microtime(true) - $started)->toBeLessThan(15);
});

it('stops what a cancelled local script started, not only the shell', function () {
    $pidFile = $this->envDir.'/child.pid';

    $result = (new LocalConnection)->run(
        'sleep 30 & echo $! > '.Shell::quote($pidFile).'; echo started; wait',
        fn (string $chunk) => str_contains($chunk, 'started'),
        30,
    );

    $pid = (int) file_get_contents($pidFile);
    $deadline = microtime(true) + 5;

    while (posix_kill($pid, 0) && microtime(true) < $deadline) {
        usleep(50_000);
    }

    expect($result->stopped)->toBeTrue()
        ->and($pid)->toBeGreaterThan(1)
        ->and(posix_kill($pid, 0))->toBeFalse();

    @unlink($pidFile);
})->skip(fn () => LocalProcess::setsid() === null || ! function_exists('posix_kill'), 'Needs setsid and posix (Linux).');

it('writes new files with the usual mode, not the 0600 of the temporary file', function () {
    $file = $this->envDir.'/written.env';

    (new LocalConnection)->put($file, "APP_NAME=Site\n");

    expect(file_get_contents($file))->toBe("APP_NAME=Site\n")
        ->and(fileperms($file) & 0777)->toBe(0666 & ~umask());

    @unlink($file);
})->skipOnWindows();

it('puts setsid in front of local commands on Linux only', function () {
    $command = LocalProcess::command(['/bin/bash', '-c', 'true']);

    if (PHP_OS_FAMILY === 'Windows') {
        expect(LocalProcess::setsid())->toBeNull()
            ->and($command)->toBe(['/bin/bash', '-c', 'true']);
    } else {
        expect($command)->toBe(LocalProcess::setsid() === null
            ? ['/bin/bash', '-c', 'true']
            : [LocalProcess::setsid(), '/bin/bash', '-c', 'true']);
    }
});

it('finds the home folder without HOME (PHP-FPM clears the environment)', function () {
    $home = getenv('HOME');
    putenv('HOME');

    try {
        $user = posix_getpwuid(posix_geteuid());

        expect(Shell::homeDirectory())->toBe($user['dir'])
            ->and(Shell::expandHome('~/.ssh/id_ed25519'))->toBe(rtrim($user['dir'], '/').'/.ssh/id_ed25519');
    } finally {
        if ($home !== false) {
            putenv('HOME='.$home);
        }
    }
})->skip(fn () => PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_getpwuid'), 'Needs posix (Linux).');
