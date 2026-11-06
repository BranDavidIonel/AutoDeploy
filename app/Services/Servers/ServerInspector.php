<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\Remote\ConnectionFactory;
use App\Services\Remote\Shell;
use Throwable;

/** Tests the connection to a server and reads RAM, disk and installed tools. */
class ServerInspector
{
    public function __construct(private readonly ConnectionFactory $connections) {}

    /** @return array{ok: bool, message: string} */
    public function inspect(Server $server): array
    {
        $connection = null;

        try {
            $connection = $this->connections->make($server);
            $result = $connection->run($this->script(), null, 60);

            if (! $result->successful()) {
                throw new \RuntimeException(trim($result->output) ?: __('The info script failed (exit code :code).', ['code' => $result->exitCode]));
            }

            $info = $result->keyValues();

            $server->forceFill([
                'status' => 'connected',
                'last_checked_at' => now(),
                'last_error' => null,
                'info' => $info,
            ])->save();

            return ['ok' => true, 'message' => __('Connected to :name.', ['name' => $info['hostname'] ?? $server->name])];
        } catch (Throwable $e) {
            $server->forceFill([
                'status' => 'failed',
                'last_checked_at' => now(),
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            return ['ok' => false, 'message' => $e->getMessage()];
        } finally {
            $connection?->close();
        }
    }

    /**
     * Creates ~/.ssh/id_ed25519 on the server (for GitHub deploy keys) when it has none.
     *
     * @return array{ok: bool, message: string}
     */
    public function generateKey(Server $server): array
    {
        $connection = $this->connections->make($server);

        try {
            $result = $connection->run(implode("\n", [
                'mkdir -p ~/.ssh && chmod 700 ~/.ssh',
                'if [ ! -f ~/.ssh/id_ed25519 ]; then',
                '  ssh-keygen -t ed25519 -N "" -C '.Shell::quote('autodeploy@'.$server->name).' -f ~/.ssh/id_ed25519 >/dev/null',
                'fi',
                'cat ~/.ssh/id_ed25519.pub',
            ]), null, 60);
        } finally {
            $connection->close();
        }

        if (! $result->successful()) {
            return ['ok' => false, 'message' => $result->trimmed()];
        }

        $this->inspect($server);

        return ['ok' => true, 'message' => __('The server has an SSH key. Add it as a deploy key in GitHub.')];
    }

    private function script(): string
    {
        return implode("\n", [
            'export PATH="$HOME/.config/composer/vendor/bin:/usr/local/bin:$PATH" COMPOSER_ALLOW_SUPERUSER=1',
            'if [ -s "$HOME/.nvm/nvm.sh" ]; then . "$HOME/.nvm/nvm.sh" >/dev/null 2>&1 || true; fi',
            'echo "hostname=$(hostname 2>/dev/null)"',
            'echo "user=$(whoami 2>/dev/null)"',
            'if [ -f /etc/os-release ]; then . /etc/os-release; echo "os=$PRETTY_NAME"; else echo "os=$(uname -s)"; fi',
            'echo "kernel=$(uname -r)"',
            'echo "cpus=$(nproc 2>/dev/null || echo ?)"',
            'if command -v free >/dev/null 2>&1; then',
            '  free -m | awk \'/^Mem:/ {print "mem_total=" $2; print "mem_used=" $3; print "mem_available=" $7} /^Swap:/ {print "swap_total=" $2; print "swap_used=" $3}\'',
            'fi',
            'df -Pm / 2>/dev/null | awk \'NR==2 {print "disk_total=" $2; print "disk_used=" $3; print "disk_available=" $4}\'',
            'echo "uptime=$(uptime -p 2>/dev/null | sed \'s/^up //\')"',
            'echo "load=$(cut -d\' \' -f1-3 /proc/loadavg 2>/dev/null)"',
            'echo "php=$(php -r \'echo PHP_VERSION;\' 2>/dev/null)"',
            'echo "composer=$(composer --version 2>/dev/null | head -n1 | awk \'{print $3}\')"',
            'echo "node=$(node -v 2>/dev/null)"',
            'echo "npm=$(npm -v 2>/dev/null)"',
            'echo "git=$(git --version 2>/dev/null | awk \'{print $3}\')"',
            'echo "nginx=$(nginx -v 2>&1 | grep -o \'[0-9][0-9.]*\' | head -n1)"',
            'echo "mysql=$(mysql --version 2>/dev/null | grep -o \'[0-9][0-9.]*\' | head -n1)"',
            'echo "supervisor=$(supervisorctl version 2>/dev/null)"',
            'echo "ssh_public_key=$(cat ~/.ssh/id_ed25519.pub 2>/dev/null || cat ~/.ssh/id_rsa.pub 2>/dev/null)"',
        ]);
    }
}
