<?php

use App\Enums\BuildStrategy;
use App\Enums\DeploymentStatus;
use App\Enums\HookStage;
use App\Enums\StepStatus;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Services\Deploy\DeploymentPlanner;
use App\Services\Deploy\HookPresets;
use App\Services\Remote\CommandResult;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['build_strategy' => BuildStrategy::Server]);
    HookPresets::installDefaults($this->project);
});

it('runs every step of a deploy and makes the release live', function () {
    $server = fakeServer();

    $this->actingAs($this->user)
        ->post(route('projects.deploy', $this->project))
        ->assertRedirect();

    $deployment = Deployment::firstOrFail();

    expect($deployment->status)->toBe(DeploymentStatus::Finished)
        ->and($deployment->commit_hash)->toBe('0123456789abcdef0123456789abcdef01234567')
        ->and($deployment->commit_message)->toBe('Fix the invoice totals')
        ->and($deployment->release_exists)->toBeTrue()
        ->and($deployment->steps->pluck('status')->unique()->all())->toBe([StepStatus::Finished])
        ->and($deployment->steps->pluck('key')->all())->toContain('prepare', 'clone', 'shared', 'composer', 'server_build', 'activate', 'cleanup')
        ->and($this->project->fresh()->active_release)->toBe($deployment->release);

    // The default Laravel hooks ran in the release, in order.
    expect($server->ran('$PHP artisan migrate --force'))->toBeTrue()
        ->and($server->ran('$PHP artisan queue:restart'))->toBeTrue()
        ->and($server->ran('mv -Tf current-next current'))->toBeTrue();
});

it('stops at the failing step, skips the rest and removes the unfinished release', function () {
    $server = fakeServer(fn (string $script) => str_contains($script, 'artisan migrate')
        ? new CommandResult(1, "SQLSTATE[HY000] Connection refused\n")
        : null);

    app(DeploymentPlanner::class)->deploy($this->project, [], $this->user);

    $deployment = Deployment::with('steps')->firstOrFail();
    $failed = $deployment->steps->firstWhere('status', StepStatus::Failed);

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($failed->name)->toBe('Run migrations')
        ->and($failed->output)->toContain('Connection refused')
        ->and($deployment->steps->firstWhere('key', 'activate')->status)->toBe(StepStatus::Skipped)
        ->and($this->project->fresh()->active_release)->toBeNull()
        ->and($server->ran('Removed the unfinished release'))->toBeTrue();
});

it('refuses a second deploy while one is running', function () {
    fakeServer();
    $this->project->deployments()->create(['status' => DeploymentStatus::Running, 'type' => 'deploy']);

    $this->actingAs($this->user)
        ->post(route('projects.deploy', $this->project))
        ->assertRedirect()
        ->assertInertiaFlash('error', 'A deployment of this project is already queued or running.');

    expect(Deployment::count())->toBe(1);
});

it('needs a saved build to deploy with the upload option', function () {
    fakeServer();

    $this->actingAs($this->user)
        ->post(route('projects.deploy', $this->project), ['build_source' => 'upload'])
        ->assertInertiaFlash('error');

    expect(Deployment::count())->toBe(0);
});

it('rolls back to an earlier release and runs the after-live hooks', function () {
    $server = fakeServer();
    $planner = app(DeploymentPlanner::class);

    $first = $planner->deploy($this->project, [], $this->user)->fresh();
    $this->travel(2)->seconds();
    $planner->deploy($this->project->fresh(), [], $this->user);

    // The fake server only reports the newest release; pretend the first one is still there.
    Deployment::whereKey($first->id)->update(['release_exists' => true]);

    $this->actingAs($this->user)
        ->post(route('projects.releases.rollback', [$this->project, $first]))
        ->assertRedirect()
        ->assertInertiaFlashMissing('error');

    $rollback = Deployment::latest('id')->with('steps')->first();

    expect($rollback->type->value)->toBe('rollback')
        ->and($rollback->status)->toBe(DeploymentStatus::Finished)
        ->and($rollback->release)->toBe($first->release)
        ->and($rollback->steps->pluck('key')->all())->toContain('check_release', 'activate')
        ->and($rollback->steps->pluck('name')->all())->toContain('Restart queue workers')
        ->and($this->project->fresh()->active_release)->toBe($first->release)
        ->and($server->ran('composer'))->toBeTrue();
});

it('cancels a deployment that is still queued', function () {
    $deployment = $this->project->deployments()->create(['status' => DeploymentStatus::Pending, 'type' => 'deploy']);
    $deployment->steps()->create(['key' => 'prepare', 'name' => 'Prepare server', 'sort' => 1]);

    $this->actingAs($this->user)->post(route('deployments.cancel', $deployment))->assertRedirect();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->steps()->first()->status)->toBe(StepStatus::Skipped);
});

it('runs custom commands of each stage with the deploy variables', function () {
    $server = fakeServer();
    $this->project->hooks()->delete();
    $this->project->hooks()->create(['name' => 'Warm cache', 'stage' => HookStage::AfterActivate, 'script' => 'curl -s "$APP_URL" >/dev/null', 'sort' => 1]);

    app(DeploymentPlanner::class)->deploy($this->project, [], $this->user);

    $hookScript = collect($server->scripts)->first(fn ($s) => str_contains($s, 'Warm cache') || str_contains($s, 'curl -s'));

    expect(Deployment::first()->status)->toBe(DeploymentStatus::Finished)
        ->and($hookScript)->toContain("RELEASE_PATH='/var/www/")
        ->and($hookScript)->toContain('cd "$RELEASE_PATH"')
        ->and($hookScript)->toContain('set -e');
});
