<?php

namespace App\Services\Deploy;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentType;
use App\Enums\StepStatus;
use App\Exceptions\DeployException;
use App\Exceptions\DeploymentCancelled;
use App\Models\Build;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\Project;
use App\Services\Remote\CommandResult;
use App\Services\Remote\Connection;
use App\Services\Remote\ConnectionException;
use App\Services\Remote\ConnectionFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Throwable;

/**
 * Runs the steps of a deployment one by one (called from the RunDeployment job).
 * A failing step stops the deploy: the rest is skipped, the unfinished release
 * folder is removed and the live site stays on the previous release.
 */
class DeploymentRunner
{
    private Deployment $deployment;

    private Project $project;

    private DeployScripts $scripts;

    private ?Connection $connection = null;

    private ?StepOutput $out = null;

    /** Local path of the build archive to upload (local build or saved build). */
    private ?string $archive = null;

    private bool $releaseCreated = false;

    private bool $activated = false;

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly LocalBuilder $localBuilder,
    ) {}

    public function run(Deployment $deployment): void
    {
        $deployment->refresh();

        // Cancelled while it was waiting in the queue.
        if ($deployment->status !== DeploymentStatus::Pending) {
            return;
        }

        $this->deployment = $deployment;
        $this->project = $deployment->project()->with('server')->firstOrFail();

        if ($deployment->type === DeploymentType::Deploy) {
            $deployment->release = $this->newReleaseName();
        }

        $deployment->forceFill(['status' => DeploymentStatus::Running, 'started_at' => now()])->save();
        $this->scripts = new DeployScripts($this->project, (string) $deployment->release);

        $current = null;

        try {
            foreach ($deployment->steps()->get() as $step) {
                if ($step->status !== StepStatus::Pending) {
                    continue;
                }

                if ((bool) Deployment::whereKey($deployment->id)->value('cancel_requested')) {
                    throw new DeploymentCancelled;
                }

                $current = $step;
                $this->runStep($step);
            }

            $deployment->forceFill(['status' => DeploymentStatus::Finished, 'finished_at' => now()])->save();
        } catch (DeploymentCancelled) {
            $this->abort($current, DeploymentStatus::Cancelled, __('Cancelled by user.'));
        } catch (Throwable $e) {
            if (! $e instanceof DeployException && ! $e instanceof ConnectionException) {
                Log::error('Deployment #'.$deployment->id.' failed', ['exception' => $e]);
            }

            $this->abort($current, DeploymentStatus::Failed, $e->getMessage());
        } finally {
            $this->connection?->close();
        }
    }

    private function runStep(DeploymentStep $step): void
    {
        $step->forceFill(['status' => StepStatus::Running, 'started_at' => now()])->save();
        $this->out = new StepOutput($step);

        match (true) {
            $step->key === 'local_build' => $this->localBuild(),
            $step->key === 'prepare' => $this->exec($this->scripts->prepare()),
            $step->key === 'clone' => $this->cloneRepository(),
            $step->key === 'shared' => $this->exec($this->scripts->shared()),
            $step->key === 'composer' => $this->exec($this->scripts->composer()),
            $step->key === 'server_build' => $this->exec($this->scripts->serverBuild()),
            $step->key === 'upload_build' => $this->uploadBuild(),
            $step->key === 'activate' => $this->activate(),
            $step->key === 'check_release' => $this->exec($this->scripts->checkRelease()),
            $step->key === 'cleanup' => $this->cleanup(),
            $step->key === 'health' => $this->healthCheck(),
            $step->isHook() => $this->exec($this->scripts->hook((string) $step->script)),
            default => throw new DeployException(__('Unknown step :key', ['key' => $step->key])),
        };

        $this->out->flush();
        $step->forceFill(['status' => StepStatus::Finished, 'finished_at' => now()])->save();
    }

    private function localBuild(): void
    {
        $result = $this->localBuilder->build($this->project, $this->output(), $this->deployment->user_id);

        $this->archive = $result['build']->absolutePath();

        $this->deployment->forceFill([
            'build_id' => $result['build']->id,
            // The server gets exactly the commit the assets were built from.
            'commit_hash' => $result['commit'] ?? $this->deployment->commit_hash,
        ])->save();
    }

    private function cloneRepository(): void
    {
        $this->releaseCreated = true;
        $ref = $this->deployment->commit_hash ?: (string) $this->deployment->branch;
        $result = $this->exec($this->scripts->clone($ref));

        if ($commit = DeployScripts::parseCommit($result->output)) {
            $this->deployment->forceFill([
                'commit_hash' => $commit['hash'],
                'commit_author' => mb_substr($commit['author'], 0, 250),
                'commit_message' => mb_substr($commit['message'], 0, 250),
            ])->save();
        }
    }

    private function uploadBuild(): void
    {
        $out = $this->output();

        if ($this->archive === null) {
            $build = $this->deployment->build_id ? Build::find($this->deployment->build_id) : null;

            if (! $build || ! $build->fileExists()) {
                throw new DeployException(__('The build archive of this deployment is no longer available. Upload it again.'));
            }

            $out->line(__('Using saved build #:id: :name', ['id' => $build->id, 'name' => $build->displayName()]));
            $this->archive = $build->absolutePath();
        }

        $remote = $this->project->deployPath().'/.autodeploy/build-'.$this->deployment->release.'.tar.gz';
        $size = Number::fileSize((int) @filesize($this->archive), 1);
        $out->line(__('Uploading :size to the server…', ['size' => $size]));
        $out->flush();

        $started = microtime(true);
        $this->connection()->upload($this->archive, $remote);
        $out->line(__('Uploaded in :seconds s', ['seconds' => number_format(microtime(true) - $started, 1)]));

        $this->exec($this->scripts->extractBuild($remote));
    }

    private function activate(): void
    {
        $this->exec($this->scripts->activate());
        $this->activated = true;

        $this->deployment->forceFill(['release_exists' => true])->save();
        $this->project->forceFill([
            'active_release' => $this->deployment->release,
            'active_deployment_id' => $this->deployment->id,
            'last_deployed_at' => now(),
        ])->save();
    }

    private function cleanup(): void
    {
        $result = $this->exec($this->scripts->cleanup($this->project->keep_releases));
        $releases = DeployScripts::parseReleases($result->output);

        if ($releases !== null) {
            self::syncReleases($this->project, $releases, $this->deployment->id);
        }
    }

    private function healthCheck(): void
    {
        $url = (string) $this->project->health_check_url;
        $out = $this->output();
        $out->line('GET '.$url);
        $started = microtime(true);

        try {
            $response = Http::timeout(20)->get($url);
        } catch (Throwable $e) {
            throw new DeployException(__('Health check failed: :error. The new release is live, use Rollback if the site is broken.', ['error' => $e->getMessage()]));
        }

        $out->line(__('HTTP :status in :ms ms', ['status' => $response->status(), 'ms' => (int) ((microtime(true) - $started) * 1000)]));

        if ($response->failed()) {
            throw new DeployException(__('Health check failed: HTTP :status. The new release is live, use Rollback if the site is broken.', ['status' => $response->status()]));
        }
    }

    /**
     * Marks which deployments still have their release folder on the server.
     *
     * The running deployment ($currentId) already went live, so it counts as finished.
     *
     * @param  list<string>  $releases
     */
    public static function syncReleases(Project $project, array $releases, ?int $currentId = null): void
    {
        $project->deployments()
            ->where('type', DeploymentType::Deploy)
            ->whereNotNull('release')
            ->get()
            ->each(function (Deployment $deployment) use ($releases, $currentId) {
                $usable = $deployment->status === DeploymentStatus::Finished || $deployment->id === $currentId;
                $exists = $usable && in_array($deployment->release, $releases, true);

                if ($deployment->release_exists !== $exists) {
                    $deployment->forceFill(['release_exists' => $exists])->save();
                }
            });
    }

    /** Runs a script on the server, streaming its output into the step log. */
    private function exec(string $body, ?int $timeout = null): CommandResult
    {
        $out = $this->output();
        $result = $this->connection()->run(
            $this->scripts->wrap($body, $this->deployment),
            function (string $chunk) use ($out) {
                $out->append($chunk);

                return $out->cancelRequested();
            },
            $timeout,
        );
        $out->flush();

        if ($result->stopped) {
            throw new DeploymentCancelled;
        }

        if ($result->timedOut) {
            throw new DeployException(__('The command took too long and was stopped (timeout).'));
        }

        if (! $result->successful()) {
            throw new DeployException(__('The command failed with exit code :code.', ['code' => $result->exitCode]));
        }

        return $result;
    }

    private function abort(?DeploymentStep $step, DeploymentStatus $status, string $message): void
    {
        $out = $this->out;

        if ($step && $out) {
            $out->line();
            $out->line(($status === DeploymentStatus::Cancelled ? '■ ' : '✖ ').$message);
            $out->flush();
            $step->forceFill(['status' => StepStatus::Failed, 'finished_at' => now()])->save();
        }

        $this->deployment->steps()->where('status', StepStatus::Pending)->update(['status' => StepStatus::Skipped]);

        // A new release that never went live is removed, so "keep 3 releases" stays meaningful.
        if ($this->releaseCreated && ! $this->activated && $this->deployment->type === DeploymentType::Deploy) {
            try {
                $result = $this->connection()->run($this->scripts->wrap($this->scripts->removeRelease(), $this->deployment), null, 120);

                if ($out && $result->trimmed() !== '') {
                    $out->line($result->trimmed());
                    $out->flush();
                }
            } catch (Throwable) {
                // Best effort: the next cleanup removes it anyway.
            }
        }

        $this->deployment->forceFill([
            'status' => $status,
            'error' => mb_substr($message, 0, 2000),
            'finished_at' => now(),
        ])->save();
    }

    private function connection(): Connection
    {
        return $this->connection ??= $this->connections->make($this->project->server);
    }

    private function output(): StepOutput
    {
        return $this->out ?? throw new DeployException('No step is running.');
    }

    /** "20261106183005": sortable, unique per project. */
    private function newReleaseName(): string
    {
        $release = now()->format('YmdHis');

        while ($this->project->deployments()->where('release', $release)->whereKeyNot($this->deployment->id)->exists()) {
            $release = now()->addSecond()->format('YmdHis');
            sleep(1);
        }

        return $release;
    }
}
