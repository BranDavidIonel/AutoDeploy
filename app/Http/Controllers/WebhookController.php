<?php

namespace App\Http\Controllers;

use App\Exceptions\DeployException;
use App\Models\Project;
use App\Services\Deploy\DeploymentPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Deploy on "git push": add the project's webhook URL in GitHub
 * (Settings → Webhooks, content type application/json, event "push").
 * Only pushes to the project's branch start a deploy.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $token, DeploymentPlanner $planner): JsonResponse
    {
        $project = Project::where('webhook_token', $token)->first();

        if (! $project) {
            return response()->json(['message' => 'Unknown webhook.'], 404);
        }

        if ($request->header('X-GitHub-Event') === 'ping') {
            return response()->json(['message' => 'pong']);
        }

        if (! $project->auto_deploy) {
            return response()->json(['message' => 'Deploy on push is turned off for this project.'], 403);
        }

        $ref = (string) $request->input('ref', '');

        if ($ref !== '' && $ref !== 'refs/heads/'.$project->branch) {
            return response()->json(['message' => 'Ignored: push to '.$ref.', the project deploys '.$project->branch.'.']);
        }

        try {
            $deployment = $planner->deploy($project, ['note' => 'Push by '.($request->input('pusher.name') ?: 'webhook')], null, 'webhook');
        } catch (DeployException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Deployment queued.', 'deployment' => $deployment->id], 202);
    }
}
