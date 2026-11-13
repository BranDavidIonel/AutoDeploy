<?php

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentType;
use App\Http\Resources\DeploymentResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ServerResource;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        // Deploys and rollbacks only: database backups / restores are not deploys.
        $last30 = Deployment::query()->whereIn('type', DeploymentType::releases())->where('created_at', '>=', now()->subDays(30));
        $finished = (clone $last30)->where('status', DeploymentStatus::Finished)->count();
        $failed = (clone $last30)->where('status', DeploymentStatus::Failed)->count();

        $recent = Deployment::query()
            ->with(['project', 'user'])
            ->latest('id')
            ->limit(8)
            ->get();

        return Inertia::render('Dashboard', [
            'stats' => [
                'projects' => Project::count(),
                'servers' => Server::count(),
                'deploymentsWeek' => Deployment::whereIn('type', DeploymentType::releases())->where('created_at', '>=', now()->subDays(7))->count(),
                'successRate' => $finished + $failed > 0 ? (int) round($finished / ($finished + $failed) * 100) : null,
                'running' => Deployment::whereIn('status', DeploymentStatus::active())->count(),
            ],
            'projects' => ProjectResource::collection(Project::with(['server', 'latestDeployment'])->orderByDesc('last_deployed_at')->orderBy('name')->get()),
            'servers' => ServerResource::collection(Server::withCount('projects')->orderBy('name')->get()),
            'recentDeployments' => DeploymentResource::collection($recent),
        ]);
    }
}
