<?php

namespace App\Services\Queue;

use App\Services\Remote\LocalProcess;
use App\Services\Remote\Shell;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Starts "php artisan queue:work" in the background, detached from the web request,
 * so a queued deployment runs even when nobody started a worker by hand.
 * Tests replace it with a fake: it must never start real processes there.
 */
class WorkerLauncher
{
    /** The options of start-autodeploy.bat, plus: stop once the queue is empty. */
    public const OPTIONS = ['--stop-when-empty', '--timeout=3600', '--tries=1', '--sleep=1'];

    /** Starts a worker for the queue connection. False when it could not be started. */
    public function launch(string $connection): bool
    {
        $php = $this->phpBinary();

        if ($php === null) {
            Log::warning('AutoDeploy could not start a queue worker: the PHP command line binary was not found. Set AUTODEPLOY_PHP_BINARY in .env.');

            return false;
        }

        $windows = PHP_OS_FAMILY === 'Windows';
        $log = storage_path('logs/queue-worker.log');
        $command = $this->commandLine($php, base_path('artisan'), $connection, $log, $windows, $windows ? null : LocalProcess::setsid());

        // Linux: the command appends to the log itself; Windows: see commandLine().
        if (! $this->spawn($command, base_path(), $windows ? $log : null, $windows)) {
            Log::warning('AutoDeploy could not start a queue worker.', ['command' => $command]);

            return false;
        }

        Log::info('AutoDeploy started a queue worker in the background.', ['command' => $command]);

        return true;
    }

    /**
     * The shell command that starts the worker and returns at once:
     * Windows (cmd.exe): start "" /B ... (no window, not tied to the request);
     * Linux / macOS (sh): [setsid] nohup ... >> log 2>&1 & (keeps running after the
     * request ends; with setsid in a session of its own, so no signal meant for the
     * PHP-FPM / Apache process group reaches it).
     * On Windows the log is not in the command: cmd.exe keeps a file it redirects to
     * locked while the worker runs, so the next worker could not start. spawn() gives
     * the log to the worker instead.
     */
    public function commandLine(string $php, string $artisan, string $connection, string $log, bool $windows, ?string $setsid = null): string
    {
        $parts = array_map(
            fn (string $part) => $this->quote($part, $windows),
            [$php, $artisan, 'queue:work', $connection, ...self::OPTIONS],
        );

        $command = implode(' ', $parts);

        if ($windows) {
            return "start \"\" /B {$command}";
        }

        $detach = $setsid ? $this->quote($setsid, false).' nohup' : 'nohup';

        return "{$detach} {$command} >> {$this->quote($log, false)} 2>&1 &";
    }

    /**
     * Runs the command through the shell (cmd.exe /s /c or /bin/sh -c) and waits only
     * for the shell, which ends as soon as the worker is started. No pipes are kept
     * open, so nothing waits for the worker. With a log, the output (and errors) of
     * the command are appended to it.
     */
    public function spawn(string $command, string $cwd, ?string $log, bool $windows): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }

        $null = $windows ? 'NUL' : '/dev/null';

        // One handle for the output and the errors, so they do not write over each other.
        // A log that cannot be opened does not stop the worker from starting.
        $output = ($log ? @fopen($log, 'a') : false) ?: @fopen($null, 'w');

        if ($output === false) {
            return false;
        }

        // On Windows the worker gets the file handle without the append mode: start at the end.
        fseek($output, 0, SEEK_END);

        $process = @proc_open($command, [0 => ['file', $null, 'r'], 1 => $output, 2 => $output], $pipes, $cwd);
        fclose($output);

        if (! is_resource($process)) {
            return false;
        }

        return proc_close($process) === 0;
    }

    /**
     * The PHP command line binary. Under a web server (mod_php, PHP-FPM) PHP_BINARY is
     * not the command line PHP, so: the configured one, the php.exe next to the loaded
     * php.ini (XAMPP on Windows), the php8.4 of the same version (Debian / Ubuntu, where
     * "php" may be another version), then Symfony's finder (the running PHP for the
     * command line and the built-in server, PHP_BINDIR, the PATH).
     */
    public function phpBinary(): ?string
    {
        $configured = trim((string) config('autodeploy.php_binary'));

        if ($configured !== '') {
            return $this->existing($configured);
        }

        if (! in_array(PHP_SAPI, ['cli', 'cli-server', 'phpdbg'], true)) {
            if (PHP_OS_FAMILY === 'Windows') {
                $ini = php_ini_loaded_file();
                $candidate = $ini ? dirname($ini).DIRECTORY_SEPARATOR.'php.exe' : null;

                if ($candidate && is_file($candidate)) {
                    return $candidate;
                }
            } elseif ($candidate = $this->sameVersionBinary(PHP_BINDIR)) {
                return $candidate;
            }
        }

        $found = (new PhpExecutableFinder)->find(false);

        return $found ? $this->existing($found) : null;
    }

    /** "<dir>/php8.4" for PHP 8.4, as Debian / Ubuntu name it, when it is there. */
    public function sameVersionBinary(string $directory): ?string
    {
        $candidate = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

        return is_file($candidate) ? $candidate : null;
    }

    /** The full path of a binary (a path, or a name looked up in the PATH), null when missing. */
    private function existing(string $binary): ?string
    {
        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            return is_file($binary) ? $binary : null;
        }

        return (new ExecutableFinder)->find($binary);
    }

    /** Quotes a value for cmd.exe (double quotes) or sh (single quotes) when it needs it. */
    private function quote(string $value, bool $windows): string
    {
        if (preg_match('~^[\w.:=/\\\\-]+$~', $value)) {
            return $value;
        }

        // Windows paths cannot contain double quotes.
        return $windows ? '"'.str_replace('"', '', $value).'"' : Shell::quote($value);
    }
}
