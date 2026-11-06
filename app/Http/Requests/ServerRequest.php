<?php

namespace App\Http\Requests;

use App\Enums\ConnectionType;
use App\Enums\ServerAuthMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServerRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $ssh = fn () => $this->input('connection_type') === ConnectionType::Ssh->value;
        $creating = $this->route('server') === null;

        return [
            'name' => ['required', 'string', 'max:100'],
            'connection_type' => ['required', Rule::enum(ConnectionType::class)],
            'host' => [Rule::requiredIf($ssh), 'nullable', 'string', 'max:255'],
            'port' => [Rule::requiredIf($ssh), 'nullable', 'integer', 'between:1,65535'],
            'username' => [Rule::requiredIf($ssh), 'nullable', 'string', 'max:100'],
            'auth_method' => ['required', Rule::enum(ServerAuthMethod::class)],
            'key_path' => [Rule::requiredIf(fn () => $ssh() && $this->input('auth_method') === ServerAuthMethod::KeyFile->value), 'nullable', 'string', 'max:500'],
            // Secrets: empty on edit = keep the saved one.
            'private_key' => [Rule::requiredIf(fn () => $creating && $ssh() && $this->input('auth_method') === ServerAuthMethod::Key->value), 'nullable', 'string', 'max:20000'],
            'passphrase' => ['nullable', 'string', 'max:500'],
            'password' => [Rule::requiredIf(fn () => $creating && $ssh() && $this->input('auth_method') === ServerAuthMethod::Password->value), 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Validated data without empty secrets (so editing does not erase them).
     *
     * @return array<string, mixed>
     */
    public function serverData(): array
    {
        $data = $this->validated();

        foreach (['private_key', 'passphrase', 'password'] as $secret) {
            if (blank($data[$secret] ?? null)) {
                unset($data[$secret]);
            }
        }

        if (($data['connection_type'] ?? null) === ConnectionType::Local->value) {
            $data['host'] = null;
            $data['port'] = 22;
            $data['username'] = 'local';
        }

        $data['port'] = (int) ($data['port'] ?? 22);

        return $data;
    }
}
