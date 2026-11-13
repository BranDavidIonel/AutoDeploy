<?php

namespace App\Services\Workers;

use App\Models\Project;
use App\Services\Remote\ConnectionFactory;
use App\Services\Remote\Shell;
use Throwable;

/**
 * The Laravel scheduler: one crontab line that runs "artisan schedule:run" every
 * minute in the live release. It runs as the web user when we are root.
 */
class SchedulerManager
{
    public function __construct(private readonly ConnectionFactory $connections) {}

    public static function cronLine(Project $project): string
    {
        return sprintf(
            '* * * * * cd %s && %s artisan schedule:run >> /dev/null 2>&1 %s',
            $project->currentPath(),
            $project->php_binary,
            self::marker($project),
        );
    }

    /** @return array{ok: bool, output: string} */
    public function enable(Project $project): array
    {
        $line = Shell::quote(self::cronLine($project));
        $marker = Shell::quote(self::marker($project));

        // "|| true": with set -e, a grep that keeps no line (empty crontab, or only our line)
        // would end the group before the echo and install an empty crontab.
        $result = $this->run($project, implode("\n", [
            '{ $CRONTAB -l 2>/dev/null | grep -vF '.$marker.' || true; echo '.$line.'; } | $CRONTAB -',
            'echo "✔ Scheduler enabled (crontab of $CRON_USER)"',
            '$CRONTAB -l | grep -F '.$marker,
        ]));

        if ($result['ok']) {
            $project->forceFill(['scheduler_enabled' => true])->save();
        }

        return $result;
    }

    /** @return array{ok: bool, output: string} */
    public function disable(Project $project): array
    {
        $marker = Shell::quote(self::marker($project));

        $result = $this->run($project, implode("\n", [
            '{ $CRONTAB -l 2>/dev/null | grep -vF '.$marker.' || true; } | $CRONTAB -',
            'echo "✔ Scheduler disabled (crontab of $CRON_USER)"',
        ]));

        if ($result['ok']) {
            $project->forceFill(['scheduler_enabled' => false])->save();
        }

        return $result;
    }

    private static function marker(Project $project): string
    {
        return '# autodeploy-project-'.$project->id;
    }

    /** @return array{ok: bool, output: string} */
    private function run(Project $project, string $body): array
    {
        $user = Shell::quote((string) $project->web_user);
        $connection = null;

        try {
            $connection = $this->connections->make($project->server);
            $result = $connection->run(implode("\n", [
                'exec 2>&1',
                'set -e',
                'if ! command -v crontab >/dev/null 2>&1; then echo "✖ crontab is not installed on this server."; exit 1; fi',
                "WEB={$user}",
                'if [ -n "$WEB" ] && [ "$(id -u)" = "0" ] && id "$WEB" >/dev/null 2>&1; then CRONTAB="crontab -u $WEB"; CRON_USER="$WEB"; else CRONTAB="crontab"; CRON_USER="$(whoami)"; fi',
                $body,
            ]), null, 60);

            return ['ok' => $result->successful(), 'output' => trim($result->output)];
        } catch (Throwable $e) {
            return ['ok' => false, 'output' => $e->getMessage()];
        } finally {
            $connection?->close();
        }
    }
}
