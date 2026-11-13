<?php

namespace App\Services\Remote;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs the deploy commands on the computer that runs AutoDeploy, with bash.
 * Meant for installing AutoDeploy on the server itself (Linux). On Windows it
 * uses Git Bash, which is handy for trying things out.
 * The commands run as the user of AutoDeploy, without the variables of its .env
 * (see LocalProcess).
 */
class LocalConnection implements Connection
{
    public function run(string $script, ?callable $onOutput = null, ?int $timeout = null): CommandResult
    {
        $process = new Process(LocalProcess::command([self::shell(), '-c', $script]), null, $this->environment());
        $process->setTimeout($timeout ?? (int) config('autodeploy.command_timeout'));
        $process->start();
        $pid = $process->getPid();

        $output = '';
        $stopped = false;
        $timedOut = false;

        try {
            foreach ($process as $chunk) {
                $output .= $chunk;

                if ($onOutput && $onOutput($chunk) === true) {
                    $stopped = true;
                    break;
                }
            }
        } catch (ProcessTimedOutException) {
            // Like SshConnection: a timeout is a result (exit 124), not an exception.
            $timedOut = true;
        }

        if ($stopped || $timedOut) {
            // Symfony stops only the shell; npm, composer or artisan started by it must stop too.
            LocalProcess::stop($process, $pid);
        }

        $process->wait();

        return new CommandResult(
            exitCode: $stopped ? 130 : ($timedOut ? 124 : (int) $process->getExitCode()),
            output: $output,
            timedOut: $timedOut,
            stopped: $stopped,
        );
    }

    public function upload(string $localFile, string $remotePath): void
    {
        $result = $this->run('cp '.Shell::quote(Shell::toBashPath($localFile)).' '.Shell::quote($remotePath));

        if (! $result->successful()) {
            throw new ConnectionException(__('Copy to :path failed: :error', ['path' => $remotePath, 'error' => $result->trimmed()]));
        }
    }

    public function download(string $remotePath, string $localFile): void
    {
        $result = $this->run('cp '.Shell::quote($remotePath).' '.Shell::quote(Shell::toBashPath($localFile)));

        if (! $result->successful()) {
            throw new ConnectionException(__('Copy of :path failed: :error', ['path' => $remotePath, 'error' => $result->trimmed()]));
        }
    }

    public function put(string $remotePath, string $contents): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'autodeploy');

        try {
            file_put_contents($temp, $contents);

            // cat, not cp: a new file gets the usual mode (0644 with umask 022, like an SFTP
            // upload) instead of the 0600 of the temporary file, which the web server user
            // could not read (.env). An existing file keeps its mode and owner.
            $result = $this->run('cat '.Shell::quote(Shell::toBashPath($temp)).' > '.Shell::quote($remotePath));

            if (! $result->successful()) {
                throw new ConnectionException(__('Copy to :path failed: :error', ['path' => $remotePath, 'error' => $result->trimmed()]));
            }
        } finally {
            @unlink($temp);
        }
    }

    public function close(): void {}

    /** /bin/bash on Linux / macOS, Git Bash on Windows (or AUTODEPLOY_LOCAL_SHELL). */
    public static function shell(): string
    {
        $configured = config('autodeploy.local_shell');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            return '/bin/bash';
        }

        foreach (['C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files (x86)\\Git\\bin\\bash.exe'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return 'bash';
    }

    /** @return array<string, string|false> */
    private function environment(): array
    {
        // Git Bash: create real (MSYS) symlinks instead of copying folders.
        return LocalProcess::environment(PHP_OS_FAMILY === 'Windows' ? ['MSYS' => 'winsymlinks:sys'] : []);
    }
}
