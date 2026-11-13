<?php

namespace App\Http\Requests;

use App\Enums\BuildStrategy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    /** Relative path inside the project, without ".." (e.g. public/build, storage, .env). */
    private const string RELATIVE_PATH = '/^(?!\/)(?!.*(^|\/)\.\.(\/|$))[A-Za-z0-9._\-\/]+$/';

    protected function prepareForValidation(): void
    {
        $this->merge([
            'shared_dirs' => $this->lines('shared_dirs'),
            'shared_files' => $this->lines('shared_files'),
            'deploy_path' => rtrim((string) $this->input('deploy_path'), '/') ?: $this->input('deploy_path'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'server_id' => ['required', 'integer', Rule::exists('servers', 'id')],
            'name' => ['required', 'string', 'max:100'],
            // The host of the URL tells which Apache / Nginx site to point to current/public.
            'url' => ['nullable', 'url', 'max:255', 'required_if_accepted:manage_web_server'],
            'repository' => ['required', 'string', 'max:255'],
            'branch' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\-\/]+$/'],
            'deploy_path' => ['required', 'string', 'max:255', 'starts_with:/', Rule::notIn(['/', '/var', '/var/www', '/root', '/home', '/etc', '/usr'])],
            'php_binary' => ['required', 'string', 'max:100'],
            'composer_binary' => ['required', 'string', 'max:150'],
            'run_composer' => ['boolean'],
            'composer_flags' => ['nullable', 'string', 'max:255'],
            'keep_releases' => ['required', 'integer', 'between:1,20'],
            // A small server: one database backup by default, 0 = no automatic backups.
            'backup_before_deploy' => ['boolean'],
            'keep_db_backups' => ['sometimes', 'required', 'integer', 'between:0,20'],
            'build_strategy' => ['required', Rule::enum(BuildStrategy::class)],
            'server_build_command' => ['required', 'string', 'max:255'],
            'local_path' => [Rule::requiredIf(fn () => $this->input('build_strategy') === BuildStrategy::Local->value), 'nullable', 'string', 'max:500'],
            'local_build_command' => ['required', 'string', 'max:255'],
            'build_output' => ['required', 'string', 'max:255', 'regex:'.self::RELATIVE_PATH],
            'keep_builds' => ['required', 'integer', 'between:1,20'],
            'shared_dirs' => ['array', 'max:30'],
            'shared_dirs.*' => ['string', 'max:255', 'regex:'.self::RELATIVE_PATH],
            'shared_files' => ['array', 'max:30'],
            'shared_files.*' => ['string', 'max:255', 'regex:'.self::RELATIVE_PATH],
            'web_user' => ['nullable', 'string', 'max:50', 'regex:/^[a-z_][a-z0-9_-]*$/'],
            'manage_web_server' => ['boolean'],
            'health_check_url' => ['nullable', 'url', 'max:255'],
            'auto_deploy' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'url.required_if_accepted' => __('Set the site URL: AutoDeploy finds the Apache / Nginx site by its host name.'),
            'deploy_path.starts_with' => __('Use a full path on the server, e.g. /var/www/my-app.'),
            'deploy_path.not_in' => __('Use a folder of its own, e.g. /var/www/my-app.'),
            'build_output.regex' => __('Use a path inside the project, e.g. public/build.'),
            'shared_dirs.*.regex' => __('Shared folders must be paths inside the project, e.g. storage.'),
            'shared_files.*.regex' => __('Shared files must be paths inside the project, e.g. .env.'),
        ];
    }

    /** @return list<string> */
    private function lines(string $key): array
    {
        $value = $this->input($key);

        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($line) => trim((string) $line, " \t/"),
            is_array($value) ? $value : [],
        ))));
    }
}
