<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ConnectionType: string
{
    use HasOptions;

    /** A remote server reached over SSH. */
    case Ssh = 'ssh';

    /** The computer AutoDeploy runs on (when AutoDeploy is installed on the server itself). */
    case Local = 'local';

    public function label(): string
    {
        return match ($this) {
            self::Ssh => __('Remote server (SSH)'),
            self::Local => __('This machine'),
        };
    }
}
