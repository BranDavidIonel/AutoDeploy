<?php

namespace App\Models;

use App\Enums\HookStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A custom deploy command (Laravel, npm, anything bash) that runs at one stage of the deploy.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property HookStage $stage
 * @property string $script
 * @property bool $enabled
 * @property int $sort
 */
class ProjectHook extends Model
{
    protected $fillable = ['name', 'stage', 'script', 'enabled', 'sort'];

    protected function casts(): array
    {
        return [
            'stage' => HookStage::class,
            'enabled' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
