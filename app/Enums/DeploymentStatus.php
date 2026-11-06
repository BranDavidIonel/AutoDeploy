<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum DeploymentStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Running = 'running';
    case Finished = 'finished';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Queued'),
            self::Running => __('Running'),
            self::Finished => __('Finished'),
            self::Failed => __('Failed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /** Still queued or running. */
    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Running;
    }

    /** @return list<self> */
    public static function active(): array
    {
        return [self::Pending, self::Running];
    }
}
