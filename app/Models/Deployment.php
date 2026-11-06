<?php

namespace App\Models;

use App\Enums\BuildStrategy;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $user_id
 * @property int|null $build_id
 * @property DeploymentType $type
 * @property DeploymentStatus $status
 * @property string $triggered_by
 * @property string|null $branch
 * @property string|null $commit_hash
 * @property string|null $commit_message
 * @property string|null $commit_author
 * @property string|null $release
 * @property BuildStrategy|null $build_source
 * @property string|null $note
 * @property string|null $error
 * @property bool $cancel_requested
 * @property bool $release_exists
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property-read Project $project
 */
class Deployment extends Model
{
    protected $fillable = [
        'user_id',
        'build_id',
        'type',
        'status',
        'triggered_by',
        'branch',
        'commit_hash',
        'commit_message',
        'commit_author',
        'release',
        'build_source',
        'note',
        'error',
        'cancel_requested',
        'release_exists',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => DeploymentType::class,
            'status' => DeploymentStatus::class,
            'build_source' => BuildStrategy::class,
            'cancel_requested' => 'boolean',
            'release_exists' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Build, $this> */
    public function build(): BelongsTo
    {
        return $this->belongsTo(Build::class);
    }

    /** @return HasMany<DeploymentStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(DeploymentStep::class)->orderBy('sort');
    }

    /** Seconds from start to end (or to now while running). */
    public function duration(): ?int
    {
        if (! $this->started_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now(), true);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /** True when this deployment's release is the one "current" points to. */
    public function isLive(): bool
    {
        return $this->release !== null
            && $this->status === DeploymentStatus::Finished
            && $this->project->active_release === $this->release;
    }

    public function shortCommit(): ?string
    {
        return $this->commit_hash ? substr($this->commit_hash, 0, 7) : null;
    }
}
