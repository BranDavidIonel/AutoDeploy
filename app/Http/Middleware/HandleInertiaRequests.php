<?php

namespace App\Http\Middleware;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => app()->getLocale(),
            'locales' => config('autodeploy.locales'),
            'auth' => [
                'user' => fn () => $request->user()?->only(['id', 'name', 'email']),
            ],
            // Deployments waiting in the queue for a while = the queue worker is not running.
            'queueStuck' => fn () => $request->user()
                ? Deployment::where('status', DeploymentStatus::Pending)->where('created_at', '<', now()->subSeconds(15))->exists()
                : false,
        ];
    }
}
