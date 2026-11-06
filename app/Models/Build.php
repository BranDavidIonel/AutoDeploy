<?php

namespace App\Models;

use App\Enums\BuildSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A saved front-end build: a .tar.gz with the contents of the build folder (public/build),
 * stored in storage/app/private/builds/{project}/.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $user_id
 * @property BuildSource $source
 * @property string|null $label
 * @property string|null $original_name
 * @property string $path
 * @property int $size
 * @property int $file_count
 * @property bool $has_manifest
 * @property string|null $commit_hash
 * @property string|null $branch
 * @property Carbon $created_at
 */
class Build extends Model
{
    protected $fillable = [
        'user_id',
        'source',
        'label',
        'original_name',
        'path',
        'size',
        'file_count',
        'has_manifest',
        'commit_hash',
        'branch',
    ];

    protected function casts(): array
    {
        return [
            'source' => BuildSource::class,
            'size' => 'integer',
            'file_count' => 'integer',
            'has_manifest' => 'boolean',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function absolutePath(): string
    {
        return Storage::disk('local')->path($this->path);
    }

    public function fileExists(): bool
    {
        return Storage::disk('local')->exists($this->path);
    }

    public function displayName(): string
    {
        return $this->label ?: ($this->original_name ?: __('Build #:id', ['id' => $this->id]));
    }
}
