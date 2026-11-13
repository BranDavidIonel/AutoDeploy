<?php

namespace App\Http\Resources;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Deployment */
class DeploymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'restore_backup_id' => $this->restore_backup_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_active' => $this->status->isActive(),
            'triggered_by' => $this->triggered_by,
            'branch' => $this->branch,
            'commit_hash' => $this->commit_hash,
            'short_commit' => $this->shortCommit(),
            'commit_message' => $this->commit_message,
            'commit_author' => $this->commit_author,
            'release' => $this->release,
            'release_exists' => $this->release_exists,
            'is_live' => $this->relationLoaded('project')
                && $this->status === DeploymentStatus::Finished
                && $this->release !== null
                && $this->project->active_release === $this->release,
            'build_source' => $this->build_source?->value,
            'build_source_label' => $this->build_source?->label(),
            'build_id' => $this->build_id,
            'note' => $this->note,
            'error' => $this->error,
            'cancel_requested' => $this->cancel_requested,
            'user' => $this->whenLoaded('user', fn () => $this->user?->name),
            'created_at' => $this->created_at->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration' => $this->duration(),
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'name' => $this->project->name,
                'slug' => $this->project->slug,
                'url' => $this->project->url,
                'github_slug' => $this->project->githubSlug(),
            ]),
            'steps' => DeploymentStepResource::collection($this->whenLoaded('steps')),
        ];
    }
}
