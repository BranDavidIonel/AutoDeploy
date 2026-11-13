<?php

namespace App\Services\Remote;

/** Small helpers for building bash scripts safely. */
final class Shell
{
    /** Quotes a value for bash, always with single quotes (escapeshellarg differs on Windows). */
    public static function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    /**
     * "export A='1' B='2'" for the given variables.
     *
     * @param  array<string, string|int|null>  $variables
     */
    public static function exports(array $variables): string
    {
        $parts = [];

        foreach ($variables as $name => $value) {
            $parts[] = $name.'='.self::quote((string) $value);
        }

        return $parts === [] ? '' : 'export '.implode(' ', $parts);
    }

    /**
     * A file written with a heredoc (the delimiter never appears in the content).
     * "{file}" in the command is replaced by the quoted target path.
     */
    public static function heredoc(string $target, string $contents, string $command = 'cat > {file}'): string
    {
        $delimiter = 'AUTODEPLOY_EOF_'.bin2hex(random_bytes(4));

        return str_replace('{file}', self::quote($target), $command)." <<'{$delimiter}'\n".rtrim($contents, "\n")."\n{$delimiter}";
    }

    /** Lines that set $SUDO to "" for root and "sudo -n" for other users. */
    public static function sudoPrelude(): string
    {
        return 'if [ "$(id -u)" = "0" ]; then SUDO=""; else SUDO="sudo -n"; fi';
    }

    /** Converts a Windows path (C:\foo\bar) to the form Git Bash understands (/c/foo/bar). */
    public static function toBashPath(string $path): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return $path;
        }

        $path = str_replace('\\', '/', $path);

        if (preg_match('~^([A-Za-z]):/(.*)$~', $path, $matches)) {
            return '/'.strtolower($matches[1]).'/'.$matches[2];
        }

        return $path;
    }

    /** Expands "~/" to the home folder of the user running AutoDeploy. */
    public static function expandHome(string $path): string
    {
        if (! str_starts_with($path, '~')) {
            return $path;
        }

        return rtrim(self::homeDirectory(), '/\\').substr($path, 1);
    }

    /**
     * Home folder of the user running AutoDeploy. PHP-FPM clears the environment by
     * default (clear_env), so without HOME it is read from the user database (Linux).
     */
    public static function homeDirectory(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: ((getenv('HOMEDRIVE') ?: '').(getenv('HOMEPATH') ?: ''));

        if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user = posix_getpwuid(posix_geteuid());
            $home = is_array($user) ? $user['dir'] : '';
        }

        return $home;
    }
}
