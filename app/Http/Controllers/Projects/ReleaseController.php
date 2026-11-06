<?php

namespace App\Http\Controllers\Projects;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentType;
use App\Exceptions\DeployException;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeploymentResource;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\Deploy\DeploymentPlanner;
use App\Services\Deploy\DeploymentRunner;
use App\Services\Deploy\DeployScripts;
use App\Services\Remote\ConnectionFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** The release folders kept on the server, and rollback to one of them. */
class ReleaseController extends Controller
{
    public function index(Project $project): Response
    {
        $releases = $project->deployments()
            ->with('user')
            ->where('type', DeploymentType::Deploy)
            ->where('status', DeploymentStatus::Finished)
            ->where('release_exists', true)
            ->get()
            ->each->setRelation('project', $project);

        return Inertia::render('projects/Releases', [
            ...ProjectPage::props($project),
            'releases' => DeploymentResource::collection($releases),
        ]);
    }

    /** Reads the releases/ folder on the server and updates what can be rolled back to. */
    public function sync(Project $project, ConnectionFactory $connections): RedirectResponse
    {
        $connection = null;

        try {
            $connection = $connections->make($project->server);
            $scripts = new DeployScripts($project, 'sync');
            $result = $connection->run($scripts->wrap($scripts->listReleases()), null, 60);

            if (! $result->successful()) {
                throw new DeployException($result->trimmed());
            }

            $releases = [];
            $current = null;

            foreach (preg_split('/\r?\n/', $result->output) ?: [] as $line) {
                if (str_starts_with($line, 'release=')) {
                    $releases[] = trim(substr($line, 8));
                } elseif (str_starts_with($line, 'current=')) {
                    $current = trim(substr($line, 8)) ?: null;
                }
            }

            DeploymentRunner::syncReleases($project, $releases);

            $live = $current ? $project->deployments()->where('type', DeploymentType::Deploy)->where('release', $current)->first() : null;
            $project->forceFill(['active_release' => $current, 'active_deployment_id' => $live?->id])->save();

            Inertia::flash('success', __('Found :count releases on the server. Live: :current.', [
                'count' => count($releases),
                'current' => $current ?? __('none'),
            ]));
        } catch (Throwable $e) {
            Inertia::flash('error', $e->getMessage());
        } finally {
            $connection?->close();
        }

        return back();
    }

    public function rollback(Request $request, Project $project, Deployment $deployment, DeploymentPlanner $planner): RedirectResponse
    {
        try {
            $rollback = $planner->rollback($project, $deployment, $request->user());
        } catch (DeployException $e) {
            Inertia::flash('error', $e->getMessage());

            return back();
        }

        return to_route('deployments.show', $rollback);
    }
}
