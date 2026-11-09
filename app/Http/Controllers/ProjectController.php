<?php

namespace App\Http\Controllers;

use App\Enums\BuildStrategy;
use App\Enums\DeploymentStatus;
use App\Http\Controllers\Projects\ProjectPage;
use App\Http\Requests\ProjectRequest;
use App\Http\Resources\DeploymentResource;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\Server;
use App\Services\Deploy\HookPresets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(): Response
    {
        $projects = Project::query()
            ->with(['server', 'latestDeployment'])
            ->orderBy('name')
            ->get();

        return Inertia::render('projects/Index', [
            'projects' => ProjectResource::collection($projects),
            // The empty state says "add a server first" when there is none.
            'hasServers' => Server::query()->exists(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('projects/Create', [
            ...$this->formOptions(),
            'defaults' => [
                'server_id' => Server::query()->value('id'),
                'branch' => 'main',
                'php_binary' => 'php',
                'composer_binary' => 'composer',
                'run_composer' => true,
                'composer_flags' => '--no-dev --optimize-autoloader --no-interaction --prefer-dist',
                'keep_releases' => 3,
                'build_strategy' => BuildStrategy::Local->value,
                'server_build_command' => 'npm ci && npm run build',
                'local_build_command' => 'npm run build',
                'build_output' => 'public/build',
                'keep_builds' => 3,
                'shared_dirs' => ['storage'],
                'shared_files' => ['.env'],
                'web_user' => 'www-data',
                'manage_web_server' => false,
            ],
        ]);
    }

    public function store(ProjectRequest $request): RedirectResponse
    {
        $project = Project::create($request->validated());
        HookPresets::installDefaults($project);

        Inertia::flash('success', __('Project :name was created. Run the quick setup to prepare the server.', ['name' => $project->name]));

        return to_route('projects.setup.show', $project);
    }

    public function show(Project $project): Response
    {
        $deployments = $project->deployments()
            ->with('user')
            ->paginate(15)
            ->withQueryString();

        // The live check needs the project on each deployment.
        $deployments->getCollection()->each->setRelation('project', $project);

        $recent = $project->deployments()->where('created_at', '>=', now()->subDays(30))->get(['status', 'started_at', 'finished_at']);
        $done = $recent->whereIn('status', [DeploymentStatus::Finished, DeploymentStatus::Failed]);

        return Inertia::render('projects/Show', [
            ...ProjectPage::props($project),
            'deployments' => DeploymentResource::collection($deployments),
            'stats' => [
                'total' => $project->deployments()->count(),
                'last30' => $recent->count(),
                'successRate' => $done->count() > 0
                    ? (int) round($done->where('status', DeploymentStatus::Finished)->count() / $done->count() * 100)
                    : null,
                'averageSeconds' => ($avg = $recent->where('status', DeploymentStatus::Finished)->map(fn ($d) => $d->duration())->filter(fn ($s) => $s !== null)->avg()) !== null
                    ? (int) round((float) $avg)
                    : null,
                'releasesOnServer' => $project->deployments()->where('release_exists', true)->count(),
            ],
        ]);
    }

    public function edit(Project $project): Response
    {
        return Inertia::render('projects/Edit', [
            ...ProjectPage::props($project),
            ...$this->formOptions(),
            'webhookUrl' => route('webhook', $project->webhook_token),
        ]);
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());

        Inertia::flash('success', __('Project settings were saved.'));

        return to_route('projects.edit', $project);
    }

    public function destroy(Project $project): RedirectResponse
    {
        if ($project->hasActiveDeployment()) {
            Inertia::flash('error', __('Wait for the running deployment to finish first.'));

            return back();
        }

        Storage::disk('local')->deleteDirectory('builds/'.$project->id);
        $project->delete();

        Inertia::flash('success', __('Project :name was removed from AutoDeploy. Nothing was deleted on the server.', ['name' => $project->name]));

        return to_route('projects.index');
    }

    public function regenerateWebhook(Project $project): RedirectResponse
    {
        $project->forceFill(['webhook_token' => Str::random(48)])->save();

        Inertia::flash('success', __('A new webhook URL was created. Update it in GitHub.'));

        return back();
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'servers' => Server::query()->orderBy('name')->get()->map(fn (Server $server) => [
                'value' => $server->id,
                'label' => $server->name.' · '.$server->address(),
                'memory_mb' => $server->memoryMb(),
                'is_local' => $server->isLocal(),
            ]),
            'strategies' => BuildStrategy::cards(),
            'isWindows' => PHP_OS_FAMILY === 'Windows',
        ];
    }
}
