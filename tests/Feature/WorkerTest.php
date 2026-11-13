<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Services\Remote\CommandResult;
use App\Services\Workers\SchedulerManager;
use App\Services\Workers\SupervisorConfig;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['deploy_path' => '/var/www/shop', 'web_user' => 'www-data']);
});

it('sets up a queue worker with one click', function () {
    $server = fakeServer(fn (string $script) => str_contains($script, 'supervisorctl status')
        ? new CommandResult(0, "autodeploy-x:autodeploy-x_00   RUNNING   pid 1234, uptime 0:00:02\n")
        : null);

    $this->actingAs($this->user)
        ->post(route('projects.workers.quick-setup', $this->project))
        ->assertRedirect()
        ->assertInertiaFlash('success');

    $worker = Worker::firstOrFail();

    expect($worker->status)->toBe('running')
        ->and($worker->user)->toBe('www-data')
        ->and($server->ran('/etc/supervisor/conf.d/'.$worker->programName().'.conf'))->toBeTrue()
        ->and($server->ran('supervisorctl reread'))->toBeTrue();
});

it('writes a supervisor program that runs queue:work in the live release', function () {
    $worker = $this->project->workers()->create(['name' => 'Emails', 'queue' => 'emails,default', 'processes' => 2, 'timeout' => 90]);

    $config = SupervisorConfig::render($worker);

    expect($config)
        ->toContain('[program:autodeploy-'.$this->project->slug.'-'.$worker->id.']')
        ->toContain('command=php /var/www/shop/current/artisan queue:work database --queue=emails,default --sleep=3 --tries=3 --timeout=90')
        ->toContain('directory=/var/www/shop/current')
        ->toContain('user=www-data')
        ->toContain('numprocs=2')
        ->toContain('stopwaitsecs=100');
});

it('turns the scheduler cron on and off', function () {
    $server = fakeServer();

    $this->actingAs($this->user)
        ->post(route('projects.workers.scheduler', $this->project), ['enabled' => true])
        ->assertRedirect();

    expect($this->project->fresh()->scheduler_enabled)->toBeTrue()
        ->and($server->ran(SchedulerManager::cronLine($this->project)))->toBeTrue();

    $this->actingAs($this->user)->post(route('projects.workers.scheduler', $this->project), ['enabled' => false]);

    expect($this->project->fresh()->scheduler_enabled)->toBeFalse();
});

it('writes the scheduler line when the user has no crontab yet, and removes it again', function () {
    // The real script runs in bash against a fake crontab that keeps its table in a file.
    $dir = sys_get_temp_dir().'/autodeploy-fake-cron-'.uniqid();
    mkdir($dir);
    $table = $dir.'/table';
    file_put_contents($dir.'/crontab', implode("\n", [
        '#!/bin/sh',
        'table="$(dirname "$0")/table"',
        'if [ "$1" = "-l" ]; then [ -s "$table" ] || { echo "no crontab for user" >&2; exit 1; }; cat "$table"; else cat > "$table"; fi',
        '',
    ]));
    chmod($dir.'/crontab', 0755);

    fakeServer(function (string $script) use ($dir) {
        $process = new Process(['bash', '-c', $script], null, ['PATH' => $dir.':'.getenv('PATH')]);
        $process->run();

        return new CommandResult((int) $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    });

    $scheduler = app(SchedulerManager::class);
    $enabled = $scheduler->enable($this->project);

    expect($enabled['ok'])->toBeTrue()
        ->and(file_get_contents($table))->toContain(SchedulerManager::cronLine($this->project));

    // Enabling twice keeps one line.
    $scheduler->enable($this->project);
    expect(substr_count((string) file_get_contents($table), '# autodeploy-project-'.$this->project->id))->toBe(1);

    expect($scheduler->disable($this->project)['ok'])->toBeTrue()
        ->and((string) file_get_contents($table))->not->toContain('# autodeploy-project-');
})->skip(PHP_OS_FAMILY === 'Windows', 'Runs the real crontab script in bash (Linux).');
