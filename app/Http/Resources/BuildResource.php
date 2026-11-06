<?php

namespace App\Http\Resources;

use App\Models\Build;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Build */
class BuildResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'label' => $this->label,
            'display_name' => $this->displayName(),
            'original_name' => $this->original_name,
            'size' => $this->size,
            'file_count' => $this->file_count,
            'has_manifest' => $this->has_manifest,
            'commit_hash' => $this->commit_hash,
            'short_commit' => $this->commit_hash ? substr($this->commit_hash, 0, 7) : null,
            'branch' => $this->branch,
            'user' => $this->whenLoaded('user', fn () => $this->user?->name),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
