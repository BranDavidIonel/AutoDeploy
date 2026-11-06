<?php

namespace App\Enums;

/** How a saved build archive was made. */
enum BuildSource: string
{
    case Upload = 'upload';
    case Local = 'local';

    public function label(): string
    {
        return match ($this) {
            self::Upload => __('Uploaded'),
            self::Local => __('Built locally'),
        };
    }
}
