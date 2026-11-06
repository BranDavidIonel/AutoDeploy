<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Projects\EnvironmentFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Edit shared/.env on the server (used by every release). */
class EnvironmentController extends Controller
{
    public function edit(Project $project, EnvironmentFile $env): Response
    {
        return Inertia::render('projects/Environment', [
            ...ProjectPage::props($project),
            'path' => $project->sharedPath().'/.env',
            // Loaded after the page is shown (needs an SSH round trip).
            'environment' => Inertia::defer(function () use ($project, $env) {
                try {
                    return [...$env->read($project), 'error' => null];
                } catch (Throwable $e) {
                    return ['exists' => false, 'contents' => '', 'error' => $e->getMessage()];
                }
            }),
        ]);
    }

    public function update(Request $request, Project $project, EnvironmentFile $env): RedirectResponse
    {
        $contents = $request->validate(['contents' => ['present', 'nullable', 'string', 'max:100000']])['contents'] ?? '';

        try {
            $env->write($project, (string) $contents);
            Inertia::flash('success', __('.env saved on the server (the previous version is in .env.backup). Deploy, or run "php artisan config:cache", to use it.'));
        } catch (Throwable $e) {
            Inertia::flash('error', $e->getMessage());
        }

        return back();
    }
}
