<?php

namespace Tests\Support;

use App\Services\Deploy\BackupScripts;
use App\Services\Deploy\DeployScripts;
use App\Services\Remote\CommandResult;
use App\Services\Remote\Connection;
use Closure;

/**
 * A server that only records what it was asked to do. By default every command
 * works and the clone / cleanup steps print the markers the runner reads.
 * Pass a responder to fail or answer specific scripts.
 */
class FakeConnection implements Connection
{
    /** @var list<string> */
    public array $scripts = [];

    /** @var list<array{local: string, remote: string}> */
    public array $uploads = [];

    /** @var array<string, string> */
    public array $files = [];

    /** @var list<array{remote: string, local: string}> */
    public array $downloads = [];

    /** Database backups made so far (they get numbered file names). */
    public int $backups = 0;

    /** @var (Closure(string): (CommandResult|null))|null */
    private ?Closure $responder;

    public function __construct(?Closure $responder = null)
    {
        $this->responder = $responder;
    }

    public function run(string $script, ?callable $onOutput = null, ?int $timeout = null): CommandResult
    {
        $this->scripts[] = $script;

        $result = ($this->responder ? ($this->responder)($script) : null) ?? $this->defaultResult($script);

        if ($onOutput && $result->output !== '') {
            $onOutput($result->output);
        }

        return $result;
    }

    public function upload(string $localFile, string $remotePath): void
    {
        $this->uploads[] = ['local' => $localFile, 'remote' => $remotePath];
    }

    /** Writes the file put() wrote before, or a small fake gzip file. */
    public function download(string $remotePath, string $localFile): void
    {
        $this->downloads[] = ['remote' => $remotePath, 'local' => $localFile];
        file_put_contents($localFile, $this->files[$remotePath] ?? (string) gzencode("-- fake dump\n"));
    }

    public function put(string $remotePath, string $contents): void
    {
        $this->files[$remotePath] = $contents;
    }

    public function close(): void {}

    /** True when one of the scripts contains the text. */
    public function ran(string $needle): bool
    {
        foreach ($this->scripts as $script) {
            if (str_contains($script, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function defaultResult(string $script): CommandResult
    {
        // A database backup: a new file in <deploy path>/backups/db/ with the kind in its name.
        if (str_contains($script, BackupScripts::BACKUP_MARKER)) {
            preg_match("/DEPLOY_PATH='([^']*)'/", $script, $path);
            preg_match("/KIND='([a-z_]+)'/", $script, $kind);
            $file = ($path[1] ?? '/var/www/app').'/backups/db/app-'.(++$this->backups).'-'.($kind[1] ?? 'manual').'.sql.gz';

            return new CommandResult(0, "✔ Backup saved: {$file}\n".BackupScripts::BACKUP_MARKER."\t{$file}\t2048\tmysql\tapp\n");
        }

        if (str_contains($script, 'git fetch --depth 1')) {
            return new CommandResult(0, DeployScripts::COMMIT_MARKER."\t0123456789abcdef0123456789abcdef01234567\tAda\tFix the invoice totals\n");
        }

        if (str_contains($script, 'echo "'.DeployScripts::RELEASES_MARKER)) {
            preg_match("/export .*RELEASE='(\\d+)'/", $script, $matches);

            return new CommandResult(0, DeployScripts::RELEASES_MARKER.' '.($matches[1] ?? '')." 20200101000000\n");
        }

        return new CommandResult(0, "ok\n");
    }
}
