<?php

namespace App\Http\Controllers\Projects;

use App\Enums\BuildStrategy;
use App\Exceptions\DeployException;
use App\Http\Controllers\Controller;
use App\Http\Resources\BuildResource;
use App\Models\Build;
use App\Models\Project;
use App\Services\Deploy\BuildStorage;
use App\Services\Deploy\DeploymentPlanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Saved front-end builds: upload a zip of public/build, deploy it, download, delete. */
class BuildController extends Controller
{
    public function index(Project $project): Response
    {
        return Inertia::render('projects/Builds', [
            ...ProjectPage::props($project),
            'builds' => BuildResource::collection($project->builds()->with('user')->get()),
            'maxUploadMb' => min(
                (int) config('autodeploy.max_upload_mb'),
                $this->iniMegabytes('upload_max_filesize'),
                $this->iniMegabytes('post_max_size'),
            ),
        ]);
    }

    public function store(Request $request, Project $project, BuildStorage $storage, DeploymentPlanner $planner): RedirectResponse
    {
        $validated = $request->validate([
            'archive' => ['required', 'file', 'max:'.((int) config('autodeploy.max_upload_mb') * 1024)],
            'label' => ['nullable', 'string', 'max:100'],
            'commit_hash' => ['nullable', 'string', 'regex:/^[0-9a-f]{7,40}$/i'],
            'deploy' => ['boolean'],
        ]);

        try {
            $build = $storage->storeUpload($project, $request->file('archive'), [
                'label' => $validated['label'] ?? null,
                'commit_hash' => $validated['commit_hash'] ?? null,
                'user_id' => $request->user()?->id,
            ]);
        } catch (DeployException $e) {
            Inertia::flash('error', $e->getMessage());

            return back();
        }

        if ($request->boolean('deploy')) {
            try {
                $deployment = $planner->deploy($project, [
                    'build_source' => BuildStrategy::Upload->value,
                    'build_id' => $build->id,
                ], $request->user());

                return to_route('deployments.show', $deployment);
            } catch (DeployException $e) {
                Inertia::flash('error', $e->getMessage());

                return back();
            }
        }

        Inertia::flash('success', $build->has_manifest
            ? __('Build saved (:files files).', ['files' => $build->file_count])
            : __('Build saved, but it has no manifest.json. Check that you zipped the public/build folder.'));

        return back();
    }

    public function download(Project $project, Build $build): StreamedResponse
    {
        return Storage::disk('local')->download($build->path, $project->slug.'-build-'.$build->id.'.tar.gz');
    }

    public function destroy(Project $project, Build $build, BuildStorage $storage): RedirectResponse
    {
        $storage->delete($build);

        Inertia::flash('success', __('Build deleted.'));

        return back();
    }

    private function iniMegabytes(string $key): int
    {
        $value = trim((string) ini_get($key));

        if ($value === '' || $value === '0' || $value === '-1') {
            return PHP_INT_MAX;
        }

        $number = (float) $value;

        return (int) match (strtolower(substr($value, -1))) {
            'g' => $number * 1024,
            'm' => $number,
            'k' => $number / 1024,
            default => $number / 1024 / 1024,
        };
    }
}
