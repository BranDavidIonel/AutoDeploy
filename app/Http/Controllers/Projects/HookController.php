<?php

namespace App\Http\Controllers\Projects;

use App\Enums\HookStage;
use App\Http\Controllers\Controller;
use App\Http\Requests\HookRequest;
use App\Http\Resources\HookResource;
use App\Models\Project;
use App\Models\ProjectHook;
use App\Services\Deploy\HookPresets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** "Deploy script": the custom commands (Laravel, npm, bash) of each deploy stage. */
class HookController extends Controller
{
    public function index(Project $project): Response
    {
        return Inertia::render('projects/Hooks', [
            ...ProjectPage::props($project),
            'hooks' => HookResource::collection($project->hooks()->get()),
            'stages' => HookStage::cards(),
            'presets' => HookPresets::all(),
            'variables' => [
                ['name' => '$PHP', 'description' => __('PHP binary (:value)', ['value' => $project->php_binary])],
                ['name' => '$COMPOSER_BIN', 'description' => __('Composer binary (:value)', ['value' => $project->composer_binary])],
                ['name' => '$RELEASE_PATH', 'description' => __('Folder of the new release (the commands run here)')],
                ['name' => '$SHARED_PATH', 'description' => $project->sharedPath()],
                ['name' => '$CURRENT_PATH', 'description' => __(':path (link to the live release)', ['path' => $project->currentPath()])],
                ['name' => '$DEPLOY_PATH', 'description' => $project->deployPath()],
                ['name' => '$RELEASE', 'description' => __('Release name, e.g. 20261106183000')],
                ['name' => '$BRANCH', 'description' => __('Deployed branch')],
                ['name' => '$COMMIT', 'description' => __('Deployed commit hash')],
                ['name' => '$WEB_USER', 'description' => (string) $project->web_user],
            ],
        ]);
    }

    public function store(HookRequest $request, Project $project): RedirectResponse
    {
        $project->hooks()->create([
            ...$request->validated(),
            'enabled' => $request->boolean('enabled', true),
            'sort' => (int) $project->hooks()->max('sort') + 1,
        ]);

        Inertia::flash('success', __('Command added.'));

        return back();
    }

    public function update(HookRequest $request, Project $project, ProjectHook $hook): RedirectResponse
    {
        $hook->update([...$request->validated(), 'enabled' => $request->boolean('enabled')]);

        Inertia::flash('success', __('Command saved.'));

        return back();
    }

    public function destroy(Project $project, ProjectHook $hook): RedirectResponse
    {
        $hook->delete();

        Inertia::flash('success', __('Command deleted.'));

        return back();
    }

    /** Saves a new order: ids in the order they should run. */
    public function reorder(Request $request, Project $project): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];

        foreach (array_values($ids) as $index => $id) {
            $project->hooks()->whereKey($id)->update(['sort' => $index + 1]);
        }

        return back();
    }
}
