<?php

namespace App\Models;

use App\Enums\BuildStrategy;
use App\Enums\DeploymentStatus;
use App\Enums\HookStage;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $server_id
 * @property string $name
 * @property string $slug
 * @property string|null $url
 * @property string $repository
 * @property string $branch
 * @property string $deploy_path
 * @property string $php_binary
 * @property string $composer_binary
 * @property bool $run_composer
 * @property string $composer_flags
 * @property int $keep_releases
 * @property BuildStrategy $build_strategy
 * @property string $server_build_command
 * @property string|null $local_path
 * @property string $local_build_command
 * @property string $build_output
 * @property int $keep_builds
 * @property list<string>|null $shared_dirs
 * @property list<string>|null $shared_files
 * @property string|null $web_user
 * @property bool $manage_web_server
 * @property string|null $health_check_url
 * @property bool $auto_deploy
 * @property string $webhook_token
 * @property bool $scheduler_enabled
 * @property string|null $active_release
 * @property int|null $active_deployment_id
 * @property Carbon|null $last_deployed_at
 * @property string|null $notes
 * @property-read Server $server
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'server_id',
        'name',
        'url',
        'repository',
        'branch',
        'deploy_path',
        'php_binary',
        'composer_binary',
        'run_composer',
        'composer_flags',
        'keep_releases',
        'build_strategy',
        'server_build_command',
        'local_path',
        'local_build_command',
        'build_output',
        'keep_builds',
        'shared_dirs',
        'shared_files',
        'web_user',
        'manage_web_server',
        'health_check_url',
        'auto_deploy',
        'scheduler_enabled',
        'active_release',
        'active_deployment_id',
        'last_deployed_at',
        'notes',
    ];

    protected $hidden = ['webhook_token'];

    /** Same defaults as the database, so a new project is complete before it is saved. */
    protected $attributes = [
        'branch' => 'main',
        'php_binary' => 'php',
        'composer_binary' => 'composer',
        'run_composer' => true,
        'composer_flags' => '--no-dev --optimize-autoloader --no-interaction --prefer-dist',
        'keep_releases' => 3,
        'build_strategy' => 'local',
        'server_build_command' => 'npm ci && npm run build',
        'local_build_command' => 'npm run build',
        'build_output' => 'public/build',
        'keep_builds' => 3,
        'web_user' => 'www-data',
        'manage_web_server' => false,
        'auto_deploy' => false,
        'scheduler_enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'build_strategy' => BuildStrategy::class,
            'run_composer' => 'boolean',
            'manage_web_server' => 'boolean',
            'auto_deploy' => 'boolean',
            'scheduler_enabled' => 'boolean',
            'keep_releases' => 'integer',
            'keep_builds' => 'integer',
            'shared_dirs' => 'array',
            'shared_files' => 'array',
            'last_deployed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            $project->slug ??= static::uniqueSlug($project->name);
            $project->webhook_token ??= Str::random(48);
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;

        for ($i = 2; static::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return HasMany<ProjectHook, $this> */
    public function hooks(): HasMany
    {
        return $this->hasMany(ProjectHook::class)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<Deployment, $this> */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class)->latest('id');
    }

    /** @return HasOne<Deployment, $this> */
    public function latestDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->latestOfMany();
    }

    /** @return HasMany<Build, $this> */
    public function builds(): HasMany
    {
        return $this->hasMany(Build::class)->latest('id');
    }

    /** @return HasMany<Worker, $this> */
    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class)->orderBy('id');
    }

    /**
     * Enabled hooks of one stage, in order.
     *
     * @return Collection<int, ProjectHook>
     */
    public function hooksFor(HookStage $stage): Collection
    {
        return $this->hooks()->where('stage', $stage)->where('enabled', true)->get();
    }

    public function hasActiveDeployment(): bool
    {
        return $this->deployments()->whereIn('status', DeploymentStatus::active())->exists();
    }

    public function deployPath(): string
    {
        return rtrim($this->deploy_path, '/');
    }

    public function releasesPath(): string
    {
        return $this->deployPath().'/releases';
    }

    public function releasePath(string $release): string
    {
        return $this->releasesPath().'/'.$release;
    }

    public function sharedPath(): string
    {
        return $this->deployPath().'/shared';
    }

    public function currentPath(): string
    {
        return $this->deployPath().'/current';
    }

    /** Host of the site URL in lower case (app.example.com), null without a URL. */
    public function urlHost(): ?string
    {
        $host = $this->url ? parse_url($this->url, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /** True when deploys point the Apache / Nginx site of the URL host to current/public. */
    public function managesWebServer(): bool
    {
        return $this->manage_web_server && $this->urlHost() !== null;
    }

    /** @return list<string> */
    public function sharedDirs(): array
    {
        return array_values(array_filter($this->shared_dirs ?? []));
    }

    /** @return list<string> */
    public function sharedFiles(): array
    {
        return array_values(array_filter($this->shared_files ?? []));
    }

    /** "owner/repo" for GitHub repositories (https or git@ URLs), otherwise null. */
    public function githubSlug(): ?string
    {
        if (preg_match('~github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?/?$~i', $this->repository, $matches)) {
            return $matches[1].'/'.$matches[2];
        }

        return null;
    }

    /** Host name of the repository (github.com, gitlab.com, …) for SSH URLs, null for local paths. */
    public function repositoryHost(): ?string
    {
        if (preg_match('~^(?:ssh://)?[^@/\s]+@([^:/\s]+)[:/]~', $this->repository, $matches)) {
            return $matches[1];
        }

        if (preg_match('~^https?://([^/\s]+)/~i', $this->repository, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
