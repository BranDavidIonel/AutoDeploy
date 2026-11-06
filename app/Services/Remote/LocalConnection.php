<?php

namespace App\Services\Remote;

use Symfony\Component\Process\Process;

/**
 * Runs the deploy commands on the computer that runs AutoDeploy, with bash.
 * Meant for installing AutoDeploy on the server itself (Linux). On Windows it
 * uses Git Bash, which is handy for trying things out.
 */
class LocalConnection implements Connection
{
    public function run(string $script, ?callable $onOutput = null, ?int $timeout = null): CommandResult
    {
        $process = new Process([self::shell(), '-c', $script], null, $this->environment());
        $process->setTimeout($timeout ?? (int) config('autodeploy.command_timeout'));
        $process->start();

        $output = '';
        $stopped = false;

        foreach ($process as $chunk) {
            $output .= $chunk;

            if ($onOutput && $onOutput($chunk) === true) {
                $stopped = true;
                $process->stop(3);
                break;
            }
        }

        $process->wait();

        return new CommandResult(
            exitCode: $stopped ? 130 : (int) $process->getExitCode(),
            output: $output,
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

    public function put(string $remotePath, string $contents): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'autodeploy');

        try {
            file_put_contents($temp, $contents);
            $this->upload($temp, $remotePath);
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

    /** @return array<string, string> */
    private function environment(): array
    {
        // Git Bash: create real (MSYS) symlinks instead of copying folders.
        return PHP_OS_FAMILY === 'Windows' ? ['MSYS' => 'winsymlinks:sys'] : [];
    }
}
