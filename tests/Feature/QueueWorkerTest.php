<?php

use App\Enums\DeploymentStatus;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Services\Queue\QueueWorkerMonitor;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Queue\WorkerStopReason;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    // The queue AutoDeploy really uses: jobs wait in the database for a worker.
    config(['queue.default' => 'database']);

    $this->user = User::factory()->create();
    $this->project = Project::factory()->create();
    $this->monitor = app(QueueWorkerMonitor::class);
});

/** A deployment waiting in the queue, as DeploymentPlanner leaves it. */
function queueDeployment(Project $project): Deployment
{
    $deployment = $project->deployments()->create(['status' => DeploymentStatus::Pending, 'type' => 'deploy']);
    RunDeployment::dispatch($deployment);

    return $deployment;
}

/** A queue job that may run for an hour, like a deploy. */
function longJob(): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('timeout')->andReturn(3600);
    $job->shouldReceive('payload')->andReturn([]);

    return $job;
}

it('starts a worker when a deployment is queued and none runs', function () {
    $this->actingAs($this->user)
        ->post(route('projects.deploy', $this->project))
        ->assertRedirect();

    expect(Deployment::sole()->status)->toBe(DeploymentStatus::Pending)
        ->and(fakeWorkerLauncher()->launched)->toBe(['database'])
        ->and($this->monitor->status())->toBe('starting');
});

it('does not start a worker next to one that sends heartbeats', function () {
    $this->monitor->beat();

    queueDeployment($this->project);

    expect(fakeWorkerLauncher()->launched)->toBe([])
        ->and($this->monitor->status())->toBe('running');
});

it('does not start a worker when the automatic start is off', function () {
    config(['autodeploy.auto_worker' => false]);

    queueDeployment($this->project);

    expect(fakeWorkerLauncher()->launched)->toBe([])
        ->and($this->monitor->status())->toBe('off');
});

it('does not start a worker for the sync queue', function () {
    config(['queue.default' => 'sync']);

    event(new JobQueued('sync', 'default', 1, 'job', '{}', null));

    expect(fakeWorkerLauncher()->launched)->toBe([]);
});

it('starts only one worker when two jobs are queued at once', function () {
    expect($this->monitor->ensureRunning())->toBeTrue()
        ->and($this->monitor->ensureRunning())->toBeFalse();

    queueDeployment($this->project);

    expect(fakeWorkerLauncher()->launched)->toBe(['database']);
});

it('says when the worker did not come up and tries again a minute later', function () {
    $this->monitor->ensureRunning();
    $this->travel(QueueWorkerMonitor::STARTING_SECONDS)->seconds();

    expect($this->monitor->status())->toBe('failed')
        ->and($this->monitor->ensureRunning())->toBeFalse();

    $this->travel(QueueWorkerMonitor::RETRY_AFTER)->seconds();

    expect($this->monitor->ensureRunning())->toBeTrue()
        ->and(fakeWorkerLauncher()->launched)->toBe(['database', 'database']);
});

it('says at once when the worker could not be started', function () {
    fakeWorkerLauncher()->works = false;

    expect($this->monitor->ensureRunning())->toBeFalse()
        ->and($this->monitor->status())->toBe('failed');
});

it('writes the heartbeat when the worker starts, loops and runs a long job', function () {
    $this->monitor->ensureRunning();
    event(new WorkerStarting('database', 'default', new WorkerOptions));

    expect($this->monitor->status())->toBe('running')
        ->and(Cache::has(QueueWorkerMonitor::LAUNCH_KEY))->toBeFalse();

    // Idle: the heartbeat is short, the next loop writes it again.
    $this->travel(QueueWorkerMonitor::HEARTBEAT_TTL + 1)->seconds();
    expect($this->monitor->isAlive())->toBeFalse();

    event(new Looping('database', 'default'));
    expect($this->monitor->isAlive())->toBeTrue();

    // A deploy: alive for as long as the job may run.
    event(new JobProcessing('database', longJob()));
    $this->travel(30)->minutes();
    expect($this->monitor->isAlive())->toBeTrue();

    // Done: back to the short heartbeat.
    event(new JobProcessed('database', longJob()));
    $this->travel(QueueWorkerMonitor::HEARTBEAT_TTL + 1)->seconds();
    expect($this->monitor->isAlive())->toBeFalse();
});

it('writes the heartbeat of a looping worker only every few seconds', function () {
    event(new Looping('database', 'default'));
    Cache::forget(QueueWorkerMonitor::HEARTBEAT_KEY);

    event(new Looping('database', 'default'));
    expect($this->monitor->isAlive())->toBeFalse();

    $this->travel(QueueWorkerMonitor::BEAT_EVERY)->seconds();
    event(new Looping('database', 'default'));
    expect($this->monitor->isAlive())->toBeTrue();
});

it('forgets the heartbeat when the worker stops and starts one for a job queued meanwhile', function () {
    $this->monitor->beat();
    queueDeployment($this->project);
    expect(fakeWorkerLauncher()->launched)->toBe([]);

    event(new WorkerStopping(0, new WorkerOptions, WorkerStopReason::QueueEmpty, connectionName: 'database', queue: 'default'));

    expect($this->monitor->isAlive())->toBeFalse()
        ->and(fakeWorkerLauncher()->launched)->toBe(['database']);
});

it('does not start a worker after one was stopped on purpose', function () {
    $this->monitor->beat();
    queueDeployment($this->project);

    event(new WorkerStopping(0, new WorkerOptions, WorkerStopReason::Interrupted, connectionName: 'database', queue: 'default'));

    expect($this->monitor->isAlive())->toBeFalse()
        ->and(fakeWorkerLauncher()->launched)->toBe([]);
});

it('starts a worker from the deployment page and tells why the deploy waits', function (Closure $setup, string $status, array $launched) {
    $deployment = $this->project->deployments()->create(['status' => DeploymentStatus::Pending, 'type' => 'deploy']);
    $this->travel(30)->seconds();
    $setup->call($this);

    $this->actingAs($this->user)
        ->get(route('deployments.show', $deployment))
        ->assertInertia(fn ($page) => $page
            ->where('waitingForWorker', true)
            ->where('queueWorker', $status));

    expect(fakeWorkerLauncher()->launched)->toBe($launched);
})->with([
    'no worker' => [fn () => null, 'starting', ['database']],
    'a busy worker' => [fn () => $this->monitor->beat(), 'running', []],
    'automatic start off' => [fn () => config(['autodeploy.auto_worker' => false]), 'off', []],
    'a worker that did not come up' => [fn () => fakeWorkerLauncher()->works = false, 'failed', ['database']],
]);

it('does not show the waiting notice for a deployment queued a moment ago', function () {
    $deployment = queueDeployment($this->project);

    $this->actingAs($this->user)
        ->get(route('deployments.show', $deployment))
        ->assertInertia(fn ($page) => $page
            ->where('waitingForWorker', false)
            ->where('queueWorker', null));

    expect(fakeWorkerLauncher()->launched)->toBe(['database']);
});

it('shows the stuck queue banner only when no worker runs', function () {
    config(['autodeploy.auto_worker' => false]);
    $this->project->deployments()->create(['status' => DeploymentStatus::Pending, 'type' => 'deploy']);
    $this->travel(30)->seconds();

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('queueStuck', true));

    $this->monitor->beat();

    $this->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('queueStuck', false));
});
