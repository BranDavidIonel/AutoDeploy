<?php

namespace App\Models;

use App\Enums\StepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $deployment_id
 * @property string $key
 * @property string $name
 * @property StepStatus $status
 * @property string|null $script
 * @property string|null $output
 * @property int $sort
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class DeploymentStep extends Model
{
    protected $fillable = ['key', 'name', 'status', 'script', 'output', 'sort', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return [
            'status' => StepStatus::class,
            'sort' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Deployment, $this> */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function duration(): ?int
    {
        if (! $this->started_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now(), true);
    }

    /** Hook steps are stored as "hook:{id}". */
    public function isHook(): bool
    {
        return str_starts_with($this->key, 'hook:');
    }
}
