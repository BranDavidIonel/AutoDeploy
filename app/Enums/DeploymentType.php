<?php

namespace App\Enums;

enum DeploymentType: string
{
    case Deploy = 'deploy';
    case Rollback = 'rollback';

    public function label(): string
    {
        return match ($this) {
            self::Deploy => __('Deploy'),
            self::Rollback => __('Rollback'),
        };
    }
}
