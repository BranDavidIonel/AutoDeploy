<?php

namespace App\Http\Requests;

use App\Enums\HookStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HookRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'stage' => ['required', Rule::enum(HookStage::class)],
            'script' => ['required', 'string', 'max:20000'],
            'enabled' => ['boolean'],
        ];
    }
}
