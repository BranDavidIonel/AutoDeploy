<?php

namespace App\Http\Resources;

use App\Models\ProjectHook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProjectHook */
class HookResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'stage' => $this->stage->value,
            'script' => $this->script,
            'enabled' => $this->enabled,
            'sort' => $this->sort,
        ];
    }
}
