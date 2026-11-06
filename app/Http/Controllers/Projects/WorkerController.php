<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\WorkerRequest;
use App\Http\Resources\WorkerResource;
use App\Models\Project;
use App\Models\Worker;
use App\Services\Workers\SchedulerManager;
use App\Services\Workers\WorkerManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Queue workers (Supervisor) and the Laravel scheduler (cron) of a project. */
class WorkerController extends Controller
{
    public function __construct(private readonly WorkerManager $manager) {}

    public function index(Project $project): Response
    {
        return Inertia::render('projects/Workers', [
            ...ProjectPage::props($project),
            'workers' => WorkerResource::collection($project->workers()->with('project')->get()),
            'cronLine' => SchedulerManager::cronLine($project),
            'defaults' => [
                'name' => __('Default queue'),
                'connection' => 'database',
                'queue' => 'default',
                'processes' => 1,
                'timeout' => 60,
                'sleep' => 3,
                'tries' => 3,
                'max_time' => 3600,
                'memory' => 128,
                'user' => $project->web_user,
            ],
        ]);
    }

    /** One click: install Supervisor if missing, add a default worker, start everything. */
    public function quickSetup(Project $project): RedirectResponse
    {
        $result = $this->manager->quickSetup($project);

        return $this->respond($result, __('Quick setup: queue workers'));
    }

    public function store(WorkerRequest $request, Project $project): RedirectResponse
    {
        $worker = $project->workers()->create($request->safe()->except('install'));

        if ($request->boolean('install')) {
            return $this->respond($this->manager->install($worker->load('project')), __('Install worker :name', ['name' => $worker->name]));
        }

        Inertia::flash('success', __('Worker added. Press Install to start it on the server.'));

        return back();
    }

    public function update(WorkerRequest $request, Project $project, Worker $worker): RedirectResponse
    {
        $worker->update($request->safe()->except('install'));

        if ($worker->installed_at || $request->boolean('install')) {
            return $this->respond($this->manager->install($worker->load('project')), __('Update worker :name', ['name' => $worker->name]));
        }

        Inertia::flash('success', __('Worker saved.'));

        return back();
    }

    public function destroy(Project $project, Worker $worker): RedirectResponse
    {
        $result = $worker->installed_at ? $this->manager->remove($worker->load('project')) : ['ok' => true, 'output' => ''];

        if (! $result['ok']) {
            return $this->respond($result, __('Remove worker :name', ['name' => $worker->name]));
        }

        $worker->delete();
        Inertia::flash('success', __('Worker removed.'));

        return back();
    }

    public function action(Project $project, Worker $worker, string $action): RedirectResponse
    {
        $worker->load('project');

        $result = match ($action) {
            'install' => $this->manager->install($worker),
            'status' => $this->manager->status($worker),
            default => $this->manager->control($worker, $action),
        };

        $title = match ($action) {
            'install' => __('Apply worker :name', ['name' => $worker->name]),
            'start' => __('Start worker :name', ['name' => $worker->name]),
            'stop' => __('Stop worker :name', ['name' => $worker->name]),
            'restart' => __('Restart worker :name', ['name' => $worker->name]),
            default => __('Status of worker :name', ['name' => $worker->name]),
        };

        return $this->respond($result, $title);
    }

    public function logs(Project $project, Worker $worker): JsonResponse
    {
        return response()->json($this->manager->logs($worker->load('project')));
    }

    public function scheduler(Request $request, Project $project, SchedulerManager $scheduler): RedirectResponse
    {
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $result = $enabled ? $scheduler->enable($project) : $scheduler->disable($project);

        return $this->respond($result, $enabled ? __('Enable scheduler') : __('Disable scheduler'));
    }

    /** @param  array{ok: bool, output: string}  $result */
    private function respond(array $result, string $title): RedirectResponse
    {
        Inertia::flash('output', ['title' => $title, 'ok' => $result['ok'], 'text' => $result['output']]);
        Inertia::flash($result['ok'] ? 'success' : 'error', $result['ok'] ? __('Done.') : __('Something went wrong, see the output.'));

        return back();
    }
}
