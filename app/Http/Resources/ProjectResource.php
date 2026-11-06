<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Project */
class ProjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->server_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'url' => $this->url,
            'repository' => $this->repository,
            'github_slug' => $this->githubSlug(),
            'branch' => $this->branch,
            'deploy_path' => $this->deploy_path,
            'php_binary' => $this->php_binary,
            'composer_binary' => $this->composer_binary,
            'run_composer' => $this->run_composer,
            'composer_flags' => $this->composer_flags,
            'keep_releases' => $this->keep_releases,
            'build_strategy' => $this->build_strategy->value,
            'build_strategy_label' => $this->build_strategy->label(),
            'server_build_command' => $this->server_build_command,
            'local_path' => $this->local_path,
            'local_path_exists' => $this->local_path ? is_dir($this->local_path) : false,
            'local_build_command' => $this->local_build_command,
            'build_output' => $this->build_output,
            'keep_builds' => $this->keep_builds,
            'shared_dirs' => $this->sharedDirs(),
            'shared_files' => $this->sharedFiles(),
            'web_user' => $this->web_user,
            'health_check_url' => $this->health_check_url,
            'auto_deploy' => $this->auto_deploy,
            'scheduler_enabled' => $this->scheduler_enabled,
            'active_release' => $this->active_release,
            'last_deployed_at' => $this->last_deployed_at?->toIso8601String(),
            'notes' => $this->notes,
            'paths' => [
                'current' => $this->currentPath(),
                'releases' => $this->releasesPath(),
                'shared' => $this->sharedPath(),
                'public' => $this->currentPath().'/public',
            ],
            'server' => ServerResource::make($this->whenLoaded('server')),
            'latest_deployment' => DeploymentResource::make($this->whenLoaded('latestDeployment')),
        ];
    }
}
