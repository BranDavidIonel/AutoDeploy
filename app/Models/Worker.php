<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Laravel queue worker ("php artisan queue:work") kept running by Supervisor on the server.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $connection
 * @property string $queue
 * @property int $processes
 * @property int $timeout
 * @property int $sleep
 * @property int $tries
 * @property int $max_time
 * @property int $memory
 * @property string|null $user
 * @property string $status
 * @property string|null $status_output
 * @property Carbon|null $installed_at
 * @property Carbon|null $checked_at
 * @property-read Project $project
 */
class Worker extends Model
{
    protected $fillable = [
        'name',
        'connection',
        'queue',
        'processes',
        'timeout',
        'sleep',
        'tries',
        'max_time',
        'memory',
        'user',
        'status',
        'status_output',
        'installed_at',
        'checked_at',
    ];

    /** Same defaults as the database, so a new worker is complete before it is saved. */
    protected $attributes = [
        'connection' => 'database',
        'queue' => 'default',
        'processes' => 1,
        'timeout' => 60,
        'sleep' => 3,
        'tries' => 3,
        'max_time' => 3600,
        'memory' => 128,
        'status' => 'not_installed',
    ];

    protected function casts(): array
    {
        return [
            'processes' => 'integer',
            'timeout' => 'integer',
            'sleep' => 'integer',
            'tries' => 'integer',
            'max_time' => 'integer',
            'memory' => 'integer',
            'installed_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Supervisor program name, unique per worker: "autodeploy-my-app-3". */
    public function programName(): string
    {
        return 'autodeploy-'.$this->project->slug.'-'.$this->id;
    }

    public function configPath(): string
    {
        return '/etc/supervisor/conf.d/'.$this->programName().'.conf';
    }

    public function logPath(): string
    {
        return '/var/log/supervisor/'.$this->programName().'.log';
    }

    /** The artisan command Supervisor runs. */
    public function command(): string
    {
        $project = $this->project;

        return sprintf(
            '%s %s/artisan queue:work %s --queue=%s --sleep=%d --tries=%d --timeout=%d --max-time=%d --memory=%d',
            $project->php_binary,
            $project->currentPath(),
            // Not $this->connection: inside the model that is Eloquent's database connection name.
            $this->getAttribute('connection'),
            $this->queue,
            $this->sleep,
            $this->tries,
            $this->timeout,
            $this->max_time,
            $this->memory,
        );
    }
}
