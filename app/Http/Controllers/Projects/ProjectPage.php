<?php

namespace App\Http\Controllers\Projects;

use App\Enums\BuildStrategy;
use App\Enums\DeploymentStatus;
use App\Http\Resources\BuildResource;
use App\Http\Resources\DeploymentResource;
use App\Http\Resources\ProjectResource;
use App\Models\Project;

/**
 * Props every project tab needs: the project header (with the Deploy button and
 * its options) and the deployment that is queued / running right now.
 */
class ProjectPage
{
    /** @return array<string, mixed> */
    public static function props(Project $project): array
    {
        $project->loadMissing('server');

        return [
            'project' => ProjectResource::make($project),
            'activeDeployment' => fn () => ($active = $project->deployments()
                ->whereIn('status', DeploymentStatus::active())
                ->first())
                ? DeploymentResource::make($active)
                : null,
            'deployOptions' => fn () => [
                'strategies' => BuildStrategy::cards(),
                'builds' => BuildResource::collection($project->builds()->limit(20)->get()),
            ],
        ];
    }
}
