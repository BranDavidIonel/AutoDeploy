<?php

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Enums\StepStatus;
use App\Exceptions\DeployException;
use App\Http\Resources\DeploymentResource;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\Deploy\DeploymentPlanner;
use App\Services\Queue\QueueWorkerMonitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DeploymentController extends Controller
{
    public function __construct(private readonly DeploymentPlanner $planner) {}

    public function store(Request $request, Project $project): RedirectResponse
    {
        $options = $request->validate([
            'build_source' => ['nullable', Rule::in(['local', 'upload', 'server', 'none'])],
            'build_id' => ['nullable', 'integer'],
            'branch' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\-\/]+$/'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $deployment = $this->planner->deploy($project, $options, $request->user());
        } catch (DeployException $e) {
            Inertia::flash('error', $e->getMessage());

            return back();
        }

        return to_route('deployments.show', $deployment);
    }

    public function show(Deployment $deployment, QueueWorkerMonitor $worker): Response
    {
        $deployment->load(['steps', 'user', 'project.server']);

        // The page reloads every 1.5 s while the deploy waits in the queue: if no worker runs
        // (it stopped, or the one started for this deploy did not come up), start one.
        $pending = $deployment->status === DeploymentStatus::Pending;

        if ($pending) {
            $worker->ensureRunning();
        }

        // A queued deploy that nobody picks up for a while: tell why (see QueueWorkerMonitor::status()).
        $waiting = $pending && $deployment->created_at->lt(now()->subSeconds(10));

        return Inertia::render('deployments/Show', [
            'deployment' => DeploymentResource::make($deployment),
            'server' => [
                'name' => $deployment->project->server->name,
                'address' => $deployment->project->server->address(),
            ],
            'previousLive' => fn () => $deployment->project->active_deployment_id && $deployment->project->active_deployment_id !== $deployment->id
                ? $deployment->project->active_deployment_id
                : null,
            'waitingForWorker' => $waiting,
            'queueWorker' => fn () => $waiting ? $worker->status() : null,
        ]);
    }

    public function cancel(Deployment $deployment): RedirectResponse
    {
        if ($deployment->status === DeploymentStatus::Pending || ($deployment->status === DeploymentStatus::Running && $deployment->cancel_requested)) {
            // Not started yet, or a second click while the worker does not react (stopped worker).
            $deployment->steps()->whereIn('status', [StepStatus::Pending, StepStatus::Running])->update(['status' => StepStatus::Skipped]);
            $deployment->forceFill([
                'status' => DeploymentStatus::Cancelled,
                'cancel_requested' => true,
                'error' => __('Cancelled by user.'),
                'finished_at' => now(),
            ])->save();

            Inertia::flash('success', __('The deployment was cancelled.'));
        } elseif ($deployment->status === DeploymentStatus::Running) {
            $deployment->forceFill(['cancel_requested' => true])->save();

            Inertia::flash('success', __('Stopping after the current command…'));
        }

        return back();
    }

    public function redeploy(Request $request, Deployment $deployment): RedirectResponse
    {
        try {
            $new = $this->planner->redeploy($deployment, $request->user());
        } catch (DeployException $e) {
            Inertia::flash('error', $e->getMessage());

            return back();
        }

        return to_route('deployments.show', $new);
    }
}
