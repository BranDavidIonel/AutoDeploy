<?php

namespace App\Http\Controllers;

use App\Enums\ConnectionType;
use App\Enums\ServerAuthMethod;
use App\Http\Requests\ServerRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ServerResource;
use App\Models\Server;
use App\Services\Servers\ServerInspector;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServerController extends Controller
{
    public function __construct(private readonly ServerInspector $inspector) {}

    public function index(): Response
    {
        return Inertia::render('servers/Index', [
            'servers' => ServerResource::collection(Server::query()->withCount('projects')->orderBy('name')->get()),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('servers/Create', $this->formOptions());
    }

    public function store(ServerRequest $request): RedirectResponse
    {
        $server = Server::create($request->serverData());
        $result = $this->inspector->inspect($server);

        Inertia::flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? __('Server added. :message', ['message' => $result['message']])
            : __('Server added, but the connection failed: :message', ['message' => $result['message']]));

        return to_route('servers.show', $server);
    }

    public function show(Server $server): Response
    {
        return Inertia::render('servers/Show', [
            'server' => ServerResource::make($server),
            'projects' => ProjectResource::collection($server->projects()->with('latestDeployment')->orderBy('name')->get()),
        ]);
    }

    public function edit(Server $server): Response
    {
        return Inertia::render('servers/Edit', [
            ...$this->formOptions(),
            'server' => ServerResource::make($server),
        ]);
    }

    public function update(ServerRequest $request, Server $server): RedirectResponse
    {
        $data = $request->serverData();

        // A different machine: the saved host key no longer applies.
        if (($data['host'] ?? null) !== $server->host || (int) ($data['port'] ?? 22) !== $server->port) {
            $data['host_fingerprint'] = null;
        }

        $server->update($data);

        Inertia::flash('success', __('Server saved.'));

        return to_route('servers.show', $server);
    }

    public function destroy(Server $server): RedirectResponse
    {
        if ($server->projects()->exists()) {
            Inertia::flash('error', __('Delete or move the projects of this server first.'));

            return back();
        }

        $server->delete();

        Inertia::flash('success', __('Server removed from AutoDeploy.'));

        return to_route('servers.index');
    }

    public function test(Server $server): RedirectResponse
    {
        $result = $this->inspector->inspect($server);

        Inertia::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return back();
    }

    public function generateKey(Server $server): RedirectResponse
    {
        $result = $this->inspector->generateKey($server);

        Inertia::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return back();
    }

    public function forgetFingerprint(Server $server): RedirectResponse
    {
        $server->forceFill(['host_fingerprint' => null])->save();

        Inertia::flash('success', __('Host key forgotten. The next connection saves the new one.'));

        return back();
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'connectionTypes' => ConnectionType::options(),
            'authMethods' => ServerAuthMethod::options(),
            'defaultKeyPath' => '~/.ssh/id_ed25519',
            'isWindows' => PHP_OS_FAMILY === 'Windows',
        ];
    }
}
