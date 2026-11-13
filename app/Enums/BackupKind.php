<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Why a database backup was made. */
enum BackupKind: string
{
    use HasOptions;

    /** Made by a deploy, right before its migrations. */
    case BeforeDeploy = 'before_deploy';

    /** "Back up now" on the Backups tab. */
    case Manual = 'manual';

    /** The safety copy of the current database, made before a restore puts another backup back. */
    case BeforeRestore = 'before_restore';

    public function label(): string
    {
        return match ($this) {
            self::BeforeDeploy => __('Before deploy'),
            self::Manual => __('Manual'),
            self::BeforeRestore => __('Before restore'),
        };
    }
}
