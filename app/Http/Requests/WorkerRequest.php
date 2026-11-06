<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WorkerRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'connection' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'queue' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-,.:]+$/'],
            'processes' => ['required', 'integer', 'between:1,20'],
            'timeout' => ['required', 'integer', 'between:0,86400'],
            'sleep' => ['required', 'integer', 'between:0,300'],
            'tries' => ['required', 'integer', 'between:0,100'],
            'max_time' => ['required', 'integer', 'between:0,86400'],
            'memory' => ['required', 'integer', 'between:32,8192'],
            'user' => ['nullable', 'string', 'max:50', 'regex:/^[a-z_][a-z0-9_-]*$/'],
            'install' => ['boolean'],
        ];
    }
}
