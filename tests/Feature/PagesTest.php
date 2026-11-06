<?php

use App\Enums\DeploymentStatus;
use App\Models\Project;
use App\Models\User;

beforeEach(function () {
    fakeServer();
    $this->actingAs(User::factory()->create());
    $this->project = Project::factory()->create();
    $this->deployment = $this->project->deployments()->create(['status' => DeploymentStatus::Finished, 'type' => 'deploy', 'release' => '20261106120000', 'release_exists' => true]);
    $this->worker = $this->project->workers()->create(['name' => 'Default queue']);
});

it('renders every page', function (string $route, string $component, Closure $parameters) {
    $this->get(route($route, $parameters->call($this)))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    ['dashboard', 'Dashboard', fn () => []],
    ['account.edit', 'account/Edit', fn () => []],
    ['servers.index', 'servers/Index', fn () => []],
    ['servers.create', 'servers/Create', fn () => []],
    ['servers.show', 'servers/Show', fn () => [$this->project->server]],
    ['servers.edit', 'servers/Edit', fn () => [$this->project->server]],
    ['projects.index', 'projects/Index', fn () => []],
    ['projects.create', 'projects/Create', fn () => []],
    ['projects.show', 'projects/Show', fn () => [$this->project]],
    ['projects.edit', 'projects/Edit', fn () => [$this->project]],
    ['projects.releases.index', 'projects/Releases', fn () => [$this->project]],
    ['projects.builds.index', 'projects/Builds', fn () => [$this->project]],
    ['projects.hooks.index', 'projects/Hooks', fn () => [$this->project]],
    ['projects.workers.index', 'projects/Workers', fn () => [$this->project]],
    ['projects.environment.edit', 'projects/Environment', fn () => [$this->project]],
    ['projects.setup.show', 'projects/Setup', fn () => [$this->project]],
    ['deployments.show', 'deployments/Show', fn () => [$this->deployment]],
]);

it('creates a project with the default Laravel deploy commands', function () {
    $this->post(route('projects.store'), [
        'server_id' => $this->project->server_id,
        'name' => 'Company site',
        'repository' => 'git@github.com:acme/company-site.git',
        'branch' => 'main',
        'deploy_path' => '/var/www/company-site',
        'php_binary' => 'php',
        'composer_binary' => 'composer',
        'run_composer' => true,
        'keep_releases' => 3,
        'build_strategy' => 'upload',
        'server_build_command' => 'npm ci && npm run build',
        'local_build_command' => 'npm run build',
        'build_output' => 'public/build',
        'keep_builds' => 3,
        'shared_dirs' => "storage\n",
        'shared_files' => ['.env'],
        'web_user' => 'www-data',
    ])->assertRedirect();

    $project = Project::where('name', 'Company site')->firstOrFail();

    expect($project->shared_dirs)->toBe(['storage'])
        ->and($project->githubSlug())->toBe('acme/company-site')
        ->and($project->hooks()->pluck('name')->all())->toContain('Run migrations', 'Restart queue workers');
});

it('does not accept a deploy path outside its own folder', function () {
    $this->post(route('projects.store'), ['deploy_path' => '/var/www', 'build_output' => '../x'])
        ->assertSessionHasErrors(['deploy_path', 'build_output', 'name', 'repository']);
});

it('saves the .env on the server', function () {
    $server = fakeServer();

    $this->put(route('projects.environment.update', $this->project), ['contents' => "APP_ENV=production\r\nAPP_DEBUG=false"])
        ->assertRedirect();

    expect($server->files[$this->project->sharedPath().'/.env'])->toBe("APP_ENV=production\nAPP_DEBUG=false\n");
});
