<?php

namespace App\Models;

use App\Enums\BackupKind;
use App\Enums\DeploymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A database backup (.sql.gz) kept on the server in <deploy_path>/backups/db/.
 * Only the row lives in AutoDeploy; the file never leaves the server (except for a download).
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $deployment_id
 * @property BackupKind $kind
 * @property string $file
 * @property int $size
 * @property string $db_connection
 * @property string|null $database
 * @property string|null $release
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read Project $project
 * @property-read Deployment|null $deployment
 */
class Backup extends Model
{
    protected $fillable = [
        'deployment_id',
        'kind',
        'file',
        'size',
        'db_connection',
        'database',
        'release',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'kind' => BackupKind::class,
            'size' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Deployment, $this> */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function fileName(): string
    {
        return basename($this->file);
    }

    /** True while a queued / running restore (or rollback with the database) puts this backup back. */
    public function isBeingRestored(): bool
    {
        return Deployment::query()
            ->where('restore_backup_id', $this->id)
            ->whereIn('status', DeploymentStatus::active())
            ->exists();
    }

    /** The file is inside the backups folder of its project (never a path somewhere else). */
    public function isInsideBackupsFolder(Project $project): bool
    {
        $folder = $project->backupsPath().'/';

        return str_starts_with($this->file, $folder)
            && ! str_contains($this->file, '/../')
            && ! str_contains(substr($this->file, strlen($folder)), '/');
    }
}
