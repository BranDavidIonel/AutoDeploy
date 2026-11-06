<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** When a custom deploy command (hook) runs inside the deploy pipeline. */
enum HookStage: string
{
    use HasOptions;

    /** After Composer, before the front-end build / upload. */
    case BeforeBuild = 'before_build';

    /** In the new release, before it goes live (migrations, caches). */
    case BeforeActivate = 'before_activate';

    /** Right after the new release is live (queue restart, PHP-FPM reload). Also runs on rollback. */
    case AfterActivate = 'after_activate';

    public function label(): string
    {
        return match ($this) {
            self::BeforeBuild => __('Before build'),
            self::BeforeActivate => __('Before going live'),
            self::AfterActivate => __('After going live'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::BeforeBuild => __('Runs in the new release after composer install, before the assets are built or uploaded.'),
            self::BeforeActivate => __('Runs in the new release before it becomes "current": migrations, config and route caches.'),
            self::AfterActivate => __('Runs right after the switch to the new release (and after a rollback): restart queues, reload PHP-FPM.'),
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function cards(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label(), 'description' => $case->description()],
            self::cases(),
        );
    }
}
