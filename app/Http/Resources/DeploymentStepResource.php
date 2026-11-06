<?php

namespace App\Http\Resources;

use App\Models\DeploymentStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DeploymentStep */
class DeploymentStepResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            // Built-in step names are stored in English and shown in the UI language; hook names as typed.
            'name' => __($this->name),
            'is_hook' => $this->isHook(),
            'status' => $this->status->value,
            'script' => $this->script,
            'output' => (string) $this->output,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration' => $this->duration(),
        ];
    }
}
