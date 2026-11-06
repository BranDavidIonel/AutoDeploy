<?php

namespace App\Http\Resources;

use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Server */
class ServerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'connection_type' => $this->connection_type->value,
            'is_local' => $this->isLocal(),
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'auth_method' => $this->auth_method->value,
            'key_path' => $this->key_path,
            'has_private_key' => filled($this->getRawOriginal('private_key')),
            'has_passphrase' => filled($this->getRawOriginal('passphrase')),
            'has_password' => filled($this->getRawOriginal('password')),
            'host_fingerprint' => $this->host_fingerprint,
            'address' => $this->address(),
            'status' => $this->status,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'info' => (object) ($this->info ?? []),
            'memory_mb' => $this->memoryMb(),
            'projects_count' => $this->whenCounted('projects'),
        ];
    }
}
