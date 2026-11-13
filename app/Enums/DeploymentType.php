<?php

namespace App\Enums;

enum DeploymentType: string
{
    case Deploy = 'deploy';
    case Rollback = 'rollback';

    /** "Back up now" on the Backups tab: runs in the queue like a deploy, with a live log. */
    case Backup = 'backup';

    /** Puts a database backup back (a safety backup of the current database is made first). */
    case Restore = 'restore';

    public function label(): string
    {
        return match ($this) {
            self::Deploy => __('Deploy'),
            self::Rollback => __('Rollback'),
            self::Backup => __('Database backup'),
            self::Restore => __('Database restore'),
        };
    }

    /**
     * The types that change the code on the server (what "last deploy" and the
     * deploy statistics count).
     *
     * @return list<self>
     */
    public static function releases(): array
    {
        return [self::Deploy, self::Rollback];
    }
}
