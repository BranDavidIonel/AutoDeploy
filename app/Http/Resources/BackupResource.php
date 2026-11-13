<?php

namespace App\Http\Resources;

use App\Models\Backup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Backup */
class BackupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'file' => $this->file,
            'file_name' => $this->fileName(),
            'size' => $this->size,
            'db_connection' => $this->db_connection,
            'database' => $this->database,
            'release' => $this->release,
            'note' => $this->note,
            // The deployment that made it (the deploy for "before deploy" backups).
            'deployment_id' => $this->deployment_id,
            'deployment_type' => $this->whenLoaded('deployment', fn () => $this->deployment?->type->value),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
