<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ServerAuthMethod: string
{
    use HasOptions;

    /** A private key file on the computer that runs AutoDeploy (e.g. ~/.ssh/id_ed25519). */
    case KeyFile = 'key_file';

    /** A private key pasted into AutoDeploy (stored encrypted). */
    case Key = 'key';

    /** SSH password (stored encrypted). */
    case Password = 'password';

    public function label(): string
    {
        return match ($this) {
            self::KeyFile => __('Key file on this computer'),
            self::Key => __('Paste a private key'),
            self::Password => __('Password'),
        };
    }
}
