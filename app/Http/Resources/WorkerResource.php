<?php

namespace App\Http\Resources;

use App\Models\Worker;
use App\Services\Workers\SupervisorConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Worker */
class WorkerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'processes' => $this->processes,
            'timeout' => $this->timeout,
            'sleep' => $this->sleep,
            'tries' => $this->tries,
            'max_time' => $this->max_time,
            'memory' => $this->memory,
            'user' => $this->user,
            'status' => $this->status,
            'status_output' => $this->status_output,
            'installed_at' => $this->installed_at?->toIso8601String(),
            'checked_at' => $this->checked_at?->toIso8601String(),
            'program_name' => $this->programName(),
            'command' => $this->command(),
            'config_path' => $this->configPath(),
            'config' => SupervisorConfig::render($this->resource),
        ];
    }
}
