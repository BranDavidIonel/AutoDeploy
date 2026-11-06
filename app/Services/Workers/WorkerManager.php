<?php

namespace App\Services\Workers;

use App\Models\Project;
use App\Models\Worker;
use App\Services\Remote\CommandResult;
use App\Services\Remote\Connection;
use App\Services\Remote\ConnectionFactory;
use App\Services\Remote\Shell;
use Throwable;

/**
 * Queue workers through Supervisor: install (write the program config and start
 * it), start / stop / restart, status and logs. "Quick setup" does it all in one
 * click: installs Supervisor when missing, adds a default worker, starts it.
 */
class WorkerManager
{
    public function __construct(private readonly ConnectionFactory $connections) {}

    /** @return array{ok: bool, output: string} */
    public function quickSetup(Project $project): array
    {
        $worker = $project->workers()->first() ?? $project->workers()->create([
            'name' => __('Default queue'),
            'connection' => 'database',
            'queue' => 'default',
            'user' => $project->web_user,
        ]);

        return $this->withConnection($project, function (Connection $connection) use ($project, $worker) {
            $log = [];

            $ensure = $connection->run($this->wrap(implode("\n", [
                'if command -v supervisorctl >/dev/null 2>&1; then echo "✔ Supervisor is installed ($(supervisorctl version 2>/dev/null))"; exit 0; fi',
                'if [ -n "$SUDO" ] && ! sudo -n true 2>/dev/null; then echo "✖ Supervisor is missing and this user cannot install it (no root / sudo)."; exit 1; fi',
                'echo "Installing Supervisor (apt-get install supervisor)…"',
                'export DEBIAN_FRONTEND=noninteractive',
                '$SUDO apt-get update -qq',
                '$SUDO apt-get install -y -qq supervisor',
                '$SUDO systemctl enable --now supervisor >/dev/null 2>&1 || $SUDO service supervisor start',
                'echo "✔ Supervisor installed"',
            ])), null, 600);

            $log[] = trim($ensure->output);

            if (! $ensure->successful()) {
                return ['ok' => false, 'output' => implode("\n", $log)];
            }

            foreach ($project->workers()->get() as $each) {
                $result = $this->installOn($connection, $each);
                $log[] = trim($result->output);
            }

            $worker->refresh();

            return ['ok' => $worker->status === 'running', 'output' => implode("\n", array_filter($log))];
        });
    }

    /**
     * Writes the Supervisor config and (re)starts the worker.
     *
     * @return array{ok: bool, output: string}
     */
    public function install(Worker $worker): array
    {
        return $this->withConnection($worker->project, function (Connection $connection) use ($worker) {
            $result = $this->installOn($connection, $worker);

            return ['ok' => $result->successful(), 'output' => trim($result->output)];
        });
    }

    /** @return array{ok: bool, output: string} */
    public function remove(Worker $worker): array
    {
        $name = Shell::quote($worker->programName());
        $config = Shell::quote($worker->configPath());

        return $this->withConnection($worker->project, function (Connection $connection) use ($name, $config) {
            $result = $connection->run($this->wrap(implode("\n", [
                'if ! command -v supervisorctl >/dev/null 2>&1; then echo "Supervisor is not installed, nothing to remove."; exit 0; fi',
                "\$SUDO supervisorctl stop {$name}:* >/dev/null 2>&1 || true",
                "\$SUDO rm -f {$config}",
                '$SUDO supervisorctl reread >/dev/null',
                '$SUDO supervisorctl update',
                'echo "✔ Worker removed from Supervisor"',
            ])), null, 120);

            return ['ok' => $result->successful(), 'output' => trim($result->output)];
        });
    }

    /**
     * $action: start, stop or restart.
     *
     * @return array{ok: bool, output: string}
     */
    public function control(Worker $worker, string $action): array
    {
        $name = Shell::quote($worker->programName().':*');

        return $this->withConnection($worker->project, function (Connection $connection) use ($worker, $action, $name) {
            $result = $connection->run($this->wrap("\$SUDO supervisorctl {$action} {$name}"), null, 120);
            $this->refreshStatus($connection, $worker);

            return ['ok' => $result->successful(), 'output' => trim($result->output)];
        });
    }

    /** @return array{ok: bool, output: string} */
    public function status(Worker $worker): array
    {
        return $this->withConnection($worker->project, function (Connection $connection) use ($worker) {
            $result = $this->refreshStatus($connection, $worker);

            return ['ok' => $result->successful(), 'output' => trim($result->output)];
        });
    }

    /** @return array{ok: bool, output: string} */
    public function logs(Worker $worker, int $lines = 200): array
    {
        $log = Shell::quote($worker->logPath());

        return $this->withConnection($worker->project, function (Connection $connection) use ($log, $lines) {
            $result = $connection->run($this->wrap("if [ -f {$log} ]; then \$SUDO tail -n {$lines} {$log}; else echo \"No log yet.\"; fi"), null, 60);

            return ['ok' => $result->successful(), 'output' => mb_scrub($result->output, 'UTF-8')];
        });
    }

    private function installOn(Connection $connection, Worker $worker): CommandResult
    {
        $worker->loadMissing('project');
        $name = Shell::quote($worker->programName());

        $result = $connection->run($this->wrap(implode("\n", [
            'if ! command -v supervisorctl >/dev/null 2>&1; then echo "✖ Supervisor is not installed. Use Quick setup to install it."; exit 1; fi',
            'if [ ! -e '.Shell::quote($worker->project->currentPath()).' ]; then echo "! The project has not been deployed yet: the worker starts after the first deploy."; fi',
            Shell::heredoc($worker->configPath(), SupervisorConfig::render($worker), '$SUDO tee {file} >/dev/null'),
            '$SUDO supervisorctl reread >/dev/null',
            '$SUDO supervisorctl update >/dev/null',
            "\$SUDO supervisorctl restart {$name}:* >/dev/null 2>&1 || \$SUDO supervisorctl start {$name}:* >/dev/null 2>&1 || true",
            'sleep 2',
            "echo \"✔ {$worker->name}: config written to {$worker->configPath()}\"",
            "\$SUDO supervisorctl status {$name}:* || true",
        ])), null, 120);

        if ($result->successful()) {
            $worker->forceFill(['installed_at' => $worker->installed_at ?? now()])->save();
        }

        $this->applyStatus($worker, $result->output, $result->successful());

        return $result;
    }

    private function refreshStatus(Connection $connection, Worker $worker): CommandResult
    {
        $name = Shell::quote($worker->programName().':*');
        $result = $connection->run($this->wrap(implode("\n", [
            'if ! command -v supervisorctl >/dev/null 2>&1; then echo "SUPERVISOR_MISSING"; exit 0; fi',
            "\$SUDO supervisorctl status {$name} || true",
        ])), null, 60);

        $this->applyStatus($worker, $result->output, true);

        return $result;
    }

    /** Reads "name_00   RUNNING   pid 1234, uptime 0:01:02" lines. */
    private function applyStatus(Worker $worker, string $output, bool $reachable): void
    {
        $status = match (true) {
            str_contains($output, 'SUPERVISOR_MISSING') => 'not_installed',
            str_contains($output, 'no such process') || str_contains($output, 'no such group') => 'not_installed',
            (bool) preg_match('/\b(FATAL|BACKOFF)\b/', $output) => 'fatal',
            (bool) preg_match('/\bRUNNING\b/', $output) && ! preg_match('/\b(STOPPED|EXITED|STARTING)\b/', $output) => 'running',
            (bool) preg_match('/\bSTARTING\b/', $output) => 'starting',
            (bool) preg_match('/\b(STOPPED|EXITED)\b/', $output) => 'stopped',
            default => $reachable ? $worker->status : 'unknown',
        };

        $worker->forceFill([
            'status' => $status,
            'status_output' => mb_substr(trim($output), 0, 4000),
            'checked_at' => now(),
        ])->save();
    }

    private function wrap(string $body): string
    {
        return Shell::sudoPrelude()."\n".$body;
    }

    /**
     * @param  callable(Connection): array{ok: bool, output: string}  $callback
     * @return array{ok: bool, output: string}
     */
    private function withConnection(Project $project, callable $callback): array
    {
        $connection = null;

        try {
            $connection = $this->connections->make($project->server);

            return $callback($connection);
        } catch (Throwable $e) {
            return ['ok' => false, 'output' => $e->getMessage()];
        } finally {
            $connection?->close();
        }
    }
}
