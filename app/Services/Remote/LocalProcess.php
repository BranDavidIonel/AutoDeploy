<?php

namespace App\Services\Remote;

use Dotenv\Dotenv;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Helpers for the commands AutoDeploy runs on its own machine (local builds, git checks,
 * servers of type "This machine"):
 *
 * - A clean environment: the variables of AutoDeploy's own .env (APP_KEY, DB_*, VITE_*…)
 *   are removed. Laravel never overrides a variable that is already set, so the deployed
 *   app's artisan would use AutoDeploy's database and key instead of its own .env, and
 *   Vite would build with AutoDeploy's VITE_APP_NAME.
 * - Linux: the command gets a process group of its own (setsid), so a cancel or a timeout
 *   stops everything it started (npm, composer, php artisan), not only the shell.
 *   Windows: Symfony already stops the whole tree (taskkill /T).
 */
final class LocalProcess
{
    /** Variables of the .env that commands still get: they configure the tools, not Laravel. */
    private const string KEEP = '/^(PATH|HOME|USER|LOGNAME|SHELL|LANG|LANGUAGE|TZ|TMPDIR|TMP|TEMP|TERM|(HTTPS?|NO|ALL)_PROXY|(LC|NODE|NPM|COMPOSER|GIT|SSH|XDG|NVM)_\w*)$/i';

    /** Path of setsid; false = not available (Windows, macOS). */
    private static string|false|null $setsid = null;

    /**
     * The command with setsid in front where it exists.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    public static function command(array $command): array
    {
        $setsid = self::setsid();

        return $setsid === null ? $command : [$setsid, ...$command];
    }

    /**
     * Environment changes for a local command (false = remove the variable): AutoDeploy's
     * own variables removed, then $extra added.
     *
     * @param  array<string, string>  $extra
     * @return array<string, string|false>
     */
    public static function environment(array $extra = []): array
    {
        // SHELL_VERBOSITY is set by the console of the running artisan command (the worker).
        $remove = ['SHELL_VERBOSITY'];

        foreach (self::dotenvNames() as $name) {
            if (! preg_match(self::KEEP, $name)) {
                $remove[] = $name;
            }
        }

        return [...array_fill_keys($remove, false), ...$extra];
    }

    /** Full path of setsid (Linux), null where a command cannot get a process group of its own. */
    public static function setsid(): ?string
    {
        if (self::$setsid === null) {
            self::$setsid = false;

            if (PHP_OS_FAMILY !== 'Windows') {
                // The usual places first: PHP-FPM may run without a PATH (clear_env).
                $known = array_values(array_filter(['/usr/bin/setsid', '/bin/setsid'], fn (string $file) => @is_executable($file)));
                self::$setsid = $known[0] ?? (new ExecutableFinder)->find('setsid') ?? false;
            }
        }

        return self::$setsid === false ? null : self::$setsid;
    }

    /**
     * Stops a command and everything it started. $pid is read right after start():
     * Symfony forgets it once the process ended (e.g. after its own timeout stop).
     */
    public static function stop(Process $process, ?int $pid, float $timeout = 3): void
    {
        // Only a group made by setsid; never 0 or 1 (kill -0 / -1 would hit this group or every process).
        $group = $pid !== null && $pid > 1 && self::setsid() !== null;

        if ($group) {
            self::signal($pid, 15);
        }

        $process->stop($timeout);

        if (! $group) {
            return;
        }

        // What is left of the group (a build that ends slowly) gets the same time, then KILL.
        $deadline = microtime(true) + $timeout;

        while (self::signal($pid, 0) && microtime(true) < $deadline) {
            usleep(50_000);
        }

        if (self::signal($pid, 0)) {
            self::signal($pid, 9);
        }
    }

    /** Sends a signal to the process group $pid (0 = only checks that it still exists). */
    private static function signal(int $pid, int $signal): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill(-$pid, $signal);
        }

        try {
            return (new Process(['kill', '-'.$signal, '--', '-'.$pid]))->run() === 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<string> the variable names in the .env file AutoDeploy loaded */
    private static function dotenvNames(): array
    {
        try {
            $file = app()->environmentFilePath();

            return is_file($file) ? array_map(strval(...), array_keys(Dotenv::parse((string) file_get_contents($file)))) : [];
        } catch (Throwable) {
            return [];
        }
    }
}
