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
use Illuminate\Support\Facades\Http;

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

it('fixes the storage owner again after the after-live hooks of a deploy', function () {
    $server = fakeServer();

    app(DeploymentPlanner::class)->deploy($this->project, [], $this->user);

    $deployment = Deployment::with('steps')->firstOrFail();
    $keys = $deployment->steps->pluck('key')->all();
    $hooks = $this->project->hooksFor(HookStage::AfterActivate)->map(fn ($hook) => 'hook:'.$hook->id)->all();

    expect($hooks)->not->toBeEmpty()
        ->and(array_slice($keys, (int) array_search('activate', $keys, true)))->toBe(['activate', ...$hooks, 'permissions', 'cleanup'])
        ->and($deployment->status)->toBe(DeploymentStatus::Finished)
        ->and($deployment->steps->firstWhere('key', 'permissions')->name)->toBe('Fix storage permissions')
        ->and(__('Fix storage permissions', [], 'ro'))->toBe('Repară permisiunile pentru storage');

    // The owner is given back after queue:restart, which may have created root-owned cache files.
    $restart = collect($server->scripts)->search(fn ($script) => str_contains($script, 'artisan queue:restart'));
    $chown = collect($server->scripts)->keys()->last(fn ($i) => str_contains($server->scripts[$i], '$CHOWN -R "$WEB_USER"'));

    expect($restart)->toBeInt()
        ->and($chown)->toBeGreaterThan($restart);
});

it('fixes the storage owner again after the after-live hooks of a rollback', function () {
    fakeServer();
    Http::fake(['*' => Http::response('ok')]);
    $this->project->update(['health_check_url' => 'https://example.com/up']);
    $target = $this->project->deployments()->create([
        'type' => 'deploy',
        'status' => DeploymentStatus::Finished,
        'release' => '20260101000000',
        'release_exists' => true,
    ]);

    $rollback = app(DeploymentPlanner::class)->rollback($this->project->fresh(), $target, $this->user)->fresh('steps');
    $hooks = $this->project->hooksFor(HookStage::AfterActivate)->map(fn ($hook) => 'hook:'.$hook->id)->all();

    expect($rollback->status)->toBe(DeploymentStatus::Finished)
        ->and($rollback->steps->pluck('key')->all())->toBe(['check_release', 'activate', ...$hooks, 'permissions', 'health']);
});

it('adds no permissions step without a web user or without after-live hooks', function () {
    fakeServer();
    $planner = app(DeploymentPlanner::class);
    $keys = fn () => array_column($planner->deploySteps($this->project->fresh(), BuildStrategy::Server), 0);

    expect($keys())->toContain('permissions');

    $this->project->update(['web_user' => null]);
    expect($keys())->not->toContain('permissions');

    $target = $this->project->deployments()->create([
        'type' => 'deploy',
        'status' => DeploymentStatus::Finished,
        'release' => '20260101000000',
        'release_exists' => true,
    ]);
    $rollback = $planner->rollback($this->project->fresh(), $target, $this->user)->fresh('steps');
    expect($rollback->steps->pluck('key')->all())->not->toContain('permissions');

    $this->project->update(['web_user' => 'www-data']);
    $this->project->hooks()->where('stage', HookStage::AfterActivate)->update(['enabled' => false]);
    expect($keys())->not->toContain('permissions')
        ->and(array_slice($keys(), -2))->toBe(['activate', 'cleanup']);
});

it('points the web server to current right after the switch, only with the setting and a site URL', function () {
    $planner = app(DeploymentPlanner::class);
    $keys = fn () => array_column($planner->deploySteps($this->project->fresh(), BuildStrategy::Server), 0);
    $hooks = $this->project->hooksFor(HookStage::AfterActivate)->map(fn ($hook) => 'hook:'.$hook->id)->all();

    // Off by default: nothing in /etc changes until the owner turns it on.
    expect($this->project->fresh()->manage_web_server)->toBeFalse()
        ->and($keys())->not->toContain('web_server');

    // The URL host tells which site to change.
    $this->project->update(['manage_web_server' => true, 'url' => null]);
    expect($keys())->not->toContain('web_server');

    $this->project->update(['url' => 'https://app.example.com']);
    expect(array_slice($keys(), (int) array_search('activate', $keys(), true)))
        ->toBe(['activate', 'web_server', ...$hooks, 'permissions', 'cleanup']);

    $this->project->update(['manage_web_server' => false]);
    expect($keys())->not->toContain('web_server');
});

it('runs the web server step after the switch and before the after-live hooks and the health check', function () {
    $server = fakeServer();
    Http::fake(['*' => Http::response('ok')]);
    $this->project->update(['manage_web_server' => true, 'url' => 'https://App.Example.com', 'health_check_url' => 'https://app.example.com/up']);

    app(DeploymentPlanner::class)->deploy($this->project, [], $this->user);

    $deployment = Deployment::with('steps')->firstOrFail();
    $step = $deployment->steps->firstWhere('key', 'web_server');
    $index = fn (string $needle) => collect($server->scripts)->search(fn ($script) => str_contains($script, $needle));

    expect($deployment->status)->toBe(DeploymentStatus::Finished)
        ->and($step->status)->toBe(StepStatus::Finished)
        ->and($step->name)->toBe('Point the web server to current')
        ->and(__('Point the web server to current', [], 'ro'))->toBe('Îndreaptă serverul web spre current')
        ->and($deployment->steps->pluck('key')->last())->toBe('health')
        ->and($index("SITE_HOST='app.example.com'"))->toBeGreaterThan($index('mv -Tf current-next current'))
        ->and($index('artisan queue:restart'))->toBeGreaterThan($index("SITE_HOST='app.example.com'"));
});

it('fails the deploy loudly when the web server cannot be switched, and keeps the new release live', function () {
    $server = fakeServer(fn (string $script) => str_contains($script, 'SITE_HOST=')
        ? new CommandResult(1, "✖ The Apache config test failed after the change: every file is restored and nothing was reloaded.\n")
        : null);
    $this->project->update(['manage_web_server' => true, 'url' => 'https://app.example.com']);

    app(DeploymentPlanner::class)->deploy($this->project, [], $this->user);

    $deployment = Deployment::with('steps')->firstOrFail();
    $step = $deployment->steps->firstWhere('key', 'web_server');

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($step->status)->toBe(StepStatus::Failed)
        ->and($step->output)->toContain('every file is restored')
        ->and($deployment->steps->firstWhere('key', 'cleanup')->status)->toBe(StepStatus::Skipped)
        // The switch already happened: the release stays live and is not removed.
        ->and($this->project->fresh()->active_release)->toBe($deployment->release)
        ->and($server->ran('Removed the unfinished release'))->toBeFalse();
});

it('checks the web server in a rollback too', function () {
    fakeServer();
    $this->project->update(['manage_web_server' => true, 'url' => 'https://app.example.com']);
    $target = $this->project->deployments()->create([
        'type' => 'deploy',
        'status' => DeploymentStatus::Finished,
        'release' => '20260101000000',
        'release_exists' => true,
    ]);

    $rollback = app(DeploymentPlanner::class)->rollback($this->project->fresh(), $target, $this->user)->fresh('steps');
    $hooks = $this->project->hooksFor(HookStage::AfterActivate)->map(fn ($hook) => 'hook:'.$hook->id)->all();

    expect($rollback->status)->toBe(DeploymentStatus::Finished)
        ->and($rollback->steps->pluck('key')->all())->toBe(['check_release', 'activate', 'web_server', ...$hooks, 'permissions']);
});
