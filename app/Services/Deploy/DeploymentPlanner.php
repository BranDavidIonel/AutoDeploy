<?php

namespace App\Services\Deploy;

use App\Enums\BuildStrategy;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentType;
use App\Enums\HookStage;
use App\Exceptions\DeployException;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a deployment with its list of steps (shown right away on the page)
 * and queues it. The queue worker runs it with DeploymentRunner.
 */
class DeploymentPlanner
{
    /**
     * @param  array{build_source?: string|null, build_id?: int|string|null, note?: string|null, branch?: string|null}  $options
     */
    public function deploy(Project $project, array $options = [], ?User $user = null, string $trigger = 'manual'): Deployment
    {
        $this->assertIdle($project);

        $source = BuildStrategy::tryFrom((string) ($options['build_source'] ?? '')) ?? $project->build_strategy;
        $build = null;

        if ($source === BuildStrategy::Upload) {
            $build = ! empty($options['build_id'])
                ? $project->builds()->find($options['build_id'])
                : $project->builds()->first();

            if (! $build || ! $build->fileExists()) {
                throw new DeployException(__('There is no saved build to deploy. Upload one first (Builds tab) or pick another build option.'));
            }
        }

        if ($source === BuildStrategy::Local && (! $project->local_path || ! is_dir($project->local_path))) {
            throw new DeployException(__('The local project folder is not set or does not exist on this computer. Set it in the project settings, or pick another build option.'));
        }

        $deployment = DB::transaction(function () use ($project, $options, $user, $trigger, $source, $build) {
            $deployment = $project->deployments()->create([
                'user_id' => $user?->id,
                'build_id' => $build?->id,
                'type' => DeploymentType::Deploy,
                'status' => DeploymentStatus::Pending,
                'triggered_by' => $trigger,
                'branch' => ($options['branch'] ?? null) ?: $project->branch,
                // A saved build made from a known commit deploys that same commit.
                'commit_hash' => $build?->commit_hash,
                'build_source' => $source,
                'note' => $options['note'] ?? null,
            ]);

            $this->createSteps($deployment, $this->deploySteps($project, $source));

            return $deployment;
        });

        RunDeployment::dispatch($deployment);

        return $deployment;
    }

    /** Points "current" back to the release of an earlier deployment. */
    public function rollback(Project $project, Deployment $target, ?User $user = null): Deployment
    {
        $this->assertIdle($project);

        if (! $target->release || ! $target->release_exists || $target->status !== DeploymentStatus::Finished) {
            throw new DeployException(__('This release is no longer on the server.'));
        }

        if ($project->active_release === $target->release) {
            throw new DeployException(__('This release is already live.'));
        }

        $deployment = DB::transaction(function () use ($project, $target, $user) {
            $deployment = $project->deployments()->create([
                'user_id' => $user?->id,
                'build_id' => $target->build_id,
                'type' => DeploymentType::Rollback,
                'status' => DeploymentStatus::Pending,
                'triggered_by' => 'manual',
                'branch' => $target->branch,
                'commit_hash' => $target->commit_hash,
                'commit_message' => $target->commit_message,
                'commit_author' => $target->commit_author,
                'release' => $target->release,
                'build_source' => $target->build_source,
                'note' => __('Rollback to release :release (deployment #:id)', ['release' => $target->release, 'id' => $target->id]),
            ]);

            $steps = [
                ['check_release', 'Check release on the server'],
                ['activate', 'Activate release'],
                ...$this->webServerSteps($project),
                ...$this->afterActivateSteps($project),
            ];

            if ($project->health_check_url) {
                $steps[] = ['health', 'Health check'];
            }

            $this->createSteps($deployment, $steps);

            return $deployment;
        });

        RunDeployment::dispatch($deployment);

        return $deployment;
    }

    /** Runs a deployment again with the same options (same build source, build and branch). */
    public function redeploy(Deployment $deployment, ?User $user = null): Deployment
    {
        return $this->deploy($deployment->project, [
            'build_source' => $deployment->build_source?->value,
            'build_id' => $deployment->build_source === BuildStrategy::Upload ? $deployment->build_id : null,
            'branch' => $deployment->branch,
            'note' => __('Redeploy of #:id', ['id' => $deployment->id]),
        ], $user);
    }

    /**
     * @return list<array{0: string, 1: string, 2?: string}>
     */
    public function deploySteps(Project $project, BuildStrategy $source): array
    {
        $steps = [];

        if ($source === BuildStrategy::Local) {
            $steps[] = ['local_build', 'Build assets locally'];
        }

        $steps[] = ['prepare', 'Prepare server'];
        $steps[] = ['clone', 'Clone repository'];

        if ($project->sharedDirs() !== [] || $project->sharedFiles() !== []) {
            $steps[] = ['shared', 'Link shared files'];
        }

        if ($project->run_composer) {
            $steps[] = ['composer', 'Install Composer dependencies'];
        }

        foreach ($project->hooksFor(HookStage::BeforeBuild) as $hook) {
            $steps[] = ['hook:'.$hook->id, $hook->name, $hook->script];
        }

        if ($source === BuildStrategy::Server) {
            $steps[] = ['server_build', 'Build assets on the server'];
        } elseif ($source->uploadsArchive()) {
            $steps[] = ['upload_build', 'Upload build'];
        }

        foreach ($project->hooksFor(HookStage::BeforeActivate) as $hook) {
            $steps[] = ['hook:'.$hook->id, $hook->name, $hook->script];
        }

        $steps[] = ['activate', 'Activate release'];
        array_push($steps, ...$this->webServerSteps($project));
        array_push($steps, ...$this->afterActivateSteps($project));
        $steps[] = ['cleanup', 'Clean up old releases'];

        if ($project->health_check_url) {
            $steps[] = ['health', 'Health check'];
        }

        return $steps;
    }

    /**
     * Right after the switch, before the "after going live" hooks (PHP-FPM reload, queue
     * restart) and the health check, so they all see the site that serves current.
     *
     * Rollbacks run it too: there current only moves and the site already serves it, so it
     * just prints "already serves current". It still switches the site when the setting was
     * turned on after the last deploy, so after any activation the site serves current.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function webServerSteps(Project $project): array
    {
        return $project->managesWebServer() ? [['web_server', 'Point the web server to current']] : [];
    }

    /**
     * The "after going live" hooks, then the storage owner fixed again: the hooks run as
     * the SSH user (often root), so a command like queue:restart can leave root-owned
     * cache files in storage/ that php-fpm cannot write.
     *
     * @return list<array{0: string, 1: string, 2?: string}>
     */
    private function afterActivateSteps(Project $project): array
    {
        $steps = [];

        foreach ($project->hooksFor(HookStage::AfterActivate) as $hook) {
            $steps[] = ['hook:'.$hook->id, $hook->name, $hook->script];
        }

        if ($steps !== [] && $project->web_user) {
            $steps[] = ['permissions', 'Fix storage permissions'];
        }

        return $steps;
    }

    private function assertIdle(Project $project): void
    {
        if ($project->hasActiveDeployment()) {
            throw new DeployException(__('A deployment of this project is already queued or running.'));
        }
    }

    /** @param  list<array{0: string, 1: string, 2?: string}>  $steps */
    private function createSteps(Deployment $deployment, array $steps): void
    {
        foreach ($steps as $index => $step) {
            $deployment->steps()->create([
                'key' => $step[0],
                'name' => $step[1],
                'script' => $step[2] ?? null,
                'sort' => $index + 1,
            ]);
        }
    }
}
