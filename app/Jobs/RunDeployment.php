<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Enums\StepStatus;
use App\Models\Deployment;
use App\Services\Deploy\DeploymentRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunDeployment implements ShouldQueue
{
    use Queueable;

    /** Deploys are never retried automatically. */
    public int $tries = 1;

    /** One hour; DB_QUEUE_RETRY_AFTER must be higher (see .env.example). */
    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public function __construct(public Deployment $deployment) {}

    public function handle(DeploymentRunner $runner): void
    {
        $runner->run($this->deployment);
    }

    /** The worker died or timed out: do not leave the deployment "running" forever. */
    public function failed(?Throwable $exception): void
    {
        $deployment = $this->deployment->fresh();

        if (! $deployment || ! $deployment->status->isActive()) {
            return;
        }

        $deployment->steps()->whereIn('status', [StepStatus::Pending, StepStatus::Running])->update(['status' => StepStatus::Skipped]);
        $deployment->forceFill([
            'status' => DeploymentStatus::Failed,
            'error' => $exception?->getMessage() ?: __('The queue worker stopped while deploying.'),
            'finished_at' => now(),
        ])->save();
    }
}
