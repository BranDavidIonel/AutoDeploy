<?php

namespace App\Services\Deploy;

use App\Enums\BackupKind;
use App\Enums\DeploymentStatus;
use App\Models\Backup;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which database backups to delete after a new one, so the small disk of the server
 * stays under control:
 *
 * - "before deploy" + "manual": the newest keep_db_backups stay (at least 1, so a manual
 *   backup made with 0 is kept until the next one);
 * - "before restore": only the newest one stays, kept apart, so the last restore can
 *   always be undone;
 * - a backup that a queued / running restore puts back is never deleted.
 */
class BackupRetention
{
    /** @return Collection<int, Backup> */
    public static function toDelete(Project $project): Collection
    {
        $restoring = $project->deployments()
            ->whereIn('status', DeploymentStatus::active())
            ->whereNotNull('restore_backup_id')
            ->pluck('restore_backup_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        [$safety, $regular] = $project->backups()->get()
            ->partition(fn (Backup $backup) => $backup->kind === BackupKind::BeforeRestore);

        return $regular->slice(max(1, $project->keep_db_backups))
            ->merge($safety->slice(1))
            ->reject(fn (Backup $backup) => in_array($backup->id, $restoring, true))
            ->values();
    }
}
