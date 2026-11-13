<?php

use App\Services\Queue\WorkerLauncher;

// Only the command line and the PHP binary lookup: tests never start a real worker.

it('builds the Windows command and quotes the paths with spaces', function () {
    // The log is not in the Windows command: spawn() hands it to the worker.
    $command = (new WorkerLauncher)->commandLine(
        'C:\Program Files\PHP\php.exe',
        'D:\Apps\Auto Deploy\artisan',
        'database',
        'D:\Apps\Auto Deploy\storage\logs\queue-worker.log',
        true,
    );

    expect($command)->toBe('start "" /B "C:\Program Files\PHP\php.exe" "D:\Apps\Auto Deploy\artisan" queue:work database --stop-when-empty --timeout=3600 --tries=1 --sleep=1');
});

it('leaves Windows paths without spaces as they are', function () {
    $command = (new WorkerLauncher)->commandLine('C:\php\php.exe', 'D:\apps\autodeploy\artisan', 'database', 'D:\apps\autodeploy\storage\logs\queue-worker.log', true);

    expect($command)->toBe('start "" /B C:\php\php.exe D:\apps\autodeploy\artisan queue:work database --stop-when-empty --timeout=3600 --tries=1 --sleep=1');
});

it('builds the Linux command in the background with nohup', function () {
    $command = (new WorkerLauncher)->commandLine(
        '/usr/bin/php8.4',
        "/srv/auto deploy/it's/artisan",
        'database',
        "/srv/auto deploy/it's/storage/logs/queue-worker.log",
        false,
    );

    expect($command)->toBe("nohup /usr/bin/php8.4 '/srv/auto deploy/it'\\''s/artisan' queue:work database --stop-when-empty --timeout=3600 --tries=1 --sleep=1 >> '/srv/auto deploy/it'\\''s/storage/logs/queue-worker.log' 2>&1 &");
});

it('detaches the Linux worker into a session of its own with setsid', function () {
    // Started from PHP-FPM or Apache: no signal for their process group may reach it.
    $command = (new WorkerLauncher)->commandLine(
        '/usr/bin/php8.4',
        '/srv/autodeploy/artisan',
        'database',
        '/srv/autodeploy/storage/logs/queue-worker.log',
        false,
        '/usr/bin/setsid',
    );

    expect($command)->toBe('/usr/bin/setsid nohup /usr/bin/php8.4 /srv/autodeploy/artisan queue:work database --stop-when-empty --timeout=3600 --tries=1 --sleep=1 >> /srv/autodeploy/storage/logs/queue-worker.log 2>&1 &');
});

it('finds the command line PHP of the same version next to PHP-FPM (php8.4 on Ubuntu)', function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'autodeploy-php-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $launcher = new WorkerLauncher;

    try {
        expect($launcher->sameVersionBinary($dir))->toBeNull();

        $binary = $dir.DIRECTORY_SEPARATOR.'php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        touch($binary);

        expect($launcher->sameVersionBinary($dir))->toBe($binary);
    } finally {
        @unlink($dir.DIRECTORY_SEPARATOR.'php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION);
        @rmdir($dir);
    }
});

it('uses the configured PHP binary only when it exists', function () {
    config(['autodeploy.php_binary' => PHP_BINARY]);
    expect((new WorkerLauncher)->phpBinary())->toBe(PHP_BINARY);

    config(['autodeploy.php_binary' => dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'no-such-php']);
    expect((new WorkerLauncher)->phpBinary())->toBeNull();
});

it('finds the PHP binary by itself', function () {
    config(['autodeploy.php_binary' => null]);

    expect((new WorkerLauncher)->phpBinary())->toBeString()->toBeFile();
});
