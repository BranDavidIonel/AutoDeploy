<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Services\Remote\CommandResult;
use App\Services\Workers\SchedulerManager;
use App\Services\Workers\SupervisorConfig;

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
