<?php

namespace App\Http\Controllers\Projects;

use App\Enums\DeploymentStatus;
use App\Exceptions\DeployException;
use App\Http\Controllers\Controller;
use App\Http\Resources\BackupResource;
use App\Models\Backup;
use App\Models\Project;
use App\Services\Deploy\BackupScripts;
use App\Services\Deploy\DeploymentPlanner;
use App\Services\Deploy\DeployScripts;
use App\Services\Remote\ConnectionFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Database backups kept on the server: back up now, restore, download, delete.
 * Backups and restores run in the queue as deployments (live log on the deployment page).
 */
class BackupController extends Controller
{
    public function index(Project $project): Response
    {
        $backups = $project->backups()->with('deployment')->get();

        return Inertia::render('projects/Backups', [
            ...ProjectPage::props($project),
            'backups' => BackupResource::collection($backups),
            'totalSize' => (int) $backups->sum('size'),
            // The backup a queued / running restore puts back (it cannot be deleted meanwhile).
            'restoringId' => fn () => $project->deployments()
                ->whereIn('status', DeploymentStatus::active())
                ->whereNotNull('restore_backup_id')
                ->value('restore_backup_id'),
            'minFreeMb' => BackupScripts::MIN_FREE_MB,
        ]);
    }

    /** "Back up now": queued like a deploy, the browser goes to its live log. */
    public function store(Request $request, Project $project, DeploymentPlanner $planner): RedirectResponse
    {
        try {
            $deployment = $planner->backup($project, $request->user());
        } catch (DeployException $e) {
            Inertia::flash('error', $e->getMessage());

            return back();
        }

        return to_route('deployments.show', $deployment);
    }

    /** Puts a backup back. The owner confirms by typing the project name. */
    public function restore(Request $request, Project $project, Backup $backup, DeploymentPlanner $planner): RedirectResponse
    {
        $request->validate(['confirm_name' => ['required', 'string', 'max:100']]);

        if (trim((string) $request->input('confirm_name')) !== $project->name) {
            throw ValidationException::withMessages([
                'confirm_name' => __('Type the project name exactly as it is written: :name', ['name' => $project->name]),
            ]);
        }

        try {
            $deployment = $planner->restore($project, $backup, $request->user());
        } catch (DeployException $e) {
            Inertia::flash('error', $e->getMessage());

            return back();
        }

        return to_route('deployments.show', $deployment);
    }

    /**
     * Sends the .sql.gz from the server to the browser. It passes through a temporary file
     * that is deleted right after sending: AutoDeploy keeps no copy.
     */
    public function download(Project $project, Backup $backup, ConnectionFactory $connections): BinaryFileResponse|RedirectResponse
    {
        if (! $backup->isInsideBackupsFolder($project)) {
            abort(404);
        }

        // A big database takes a while over SFTP.
        set_time_limit(0);

        $temp = (string) tempnam(sys_get_temp_dir(), 'autodeploy-backup-');
        $connection = null;

        try {
            $connection = $connections->make($project->server);
            $connection->download($backup->file, $temp);
        } catch (Throwable $e) {
            @unlink($temp);
            Inertia::flash('error', __('The backup could not be downloaded: :error', ['error' => $e->getMessage()]));

            return back();
        } finally {
            $connection?->close();
        }

        return response()
            ->download($temp, $backup->fileName(), ['Content-Type' => 'application/gzip'])
            ->deleteFileAfterSend();
    }

    /** Deletes the file on the server, then the row. */
    public function destroy(Project $project, Backup $backup, ConnectionFactory $connections): RedirectResponse
    {
        if ($backup->isBeingRestored()) {
            Inertia::flash('error', __('This backup is being restored right now. Wait until the restore ends.'));

            return back();
        }

        $connection = null;

        try {
            $connection = $connections->make($project->server);
            $scripts = new DeployScripts($project, 'backups');
            $result = $connection->run($scripts->wrap((new BackupScripts($project))->remove([$backup->file])), null, 60);

            if (! $result->successful()) {
                throw new DeployException($result->trimmed());
            }
        } catch (Throwable $e) {
            Inertia::flash('error', __('The backup could not be deleted on the server: :error', ['error' => $e->getMessage()]));

            return back();
        } finally {
            $connection?->close();
        }

        $backup->delete();

        Inertia::flash('success', __('Backup #:id was deleted from the server.', ['id' => $backup->id]));

        return back();
    }
}
