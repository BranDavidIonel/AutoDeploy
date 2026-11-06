<?php

namespace App\Services\Projects;

use App\Exceptions\DeployException;
use App\Models\Project;
use App\Services\Remote\ConnectionFactory;
use App\Services\Remote\Shell;

/** Reads and writes shared/.env of a project on its server. */
class EnvironmentFile
{
    public function __construct(private readonly ConnectionFactory $connections) {}

    /** @return array{exists: bool, contents: string} */
    public function read(Project $project): array
    {
        $connection = $this->connections->make($project->server);
        $path = Shell::quote($project->sharedPath().'/.env');

        try {
            $result = $connection->run("if [ -f {$path} ]; then echo __EXISTS__; cat {$path}; fi", null, 30);
        } finally {
            $connection->close();
        }

        if (! $result->successful()) {
            throw new DeployException($result->trimmed() ?: __('Could not read the .env file.'));
        }

        $output = str_replace("\r\n", "\n", $result->output);

        if (! str_starts_with($output, "__EXISTS__\n")) {
            return ['exists' => false, 'contents' => ''];
        }

        return ['exists' => true, 'contents' => mb_scrub(substr($output, strlen("__EXISTS__\n")), 'UTF-8')];
    }

    public function write(Project $project, string $contents): void
    {
        $connection = $this->connections->make($project->server);
        $contents = rtrim(str_replace("\r\n", "\n", $contents))."\n";

        try {
            $shared = Shell::quote($project->sharedPath());
            $result = $connection->run("mkdir -p {$shared}", null, 30);

            if (! $result->successful()) {
                throw new DeployException($result->trimmed());
            }

            // Keep a copy of the previous version next to it.
            $env = Shell::quote($project->sharedPath().'/.env');
            $connection->run("if [ -f {$env} ]; then cp {$env} {$env}.backup; fi", null, 30);
            $connection->put($project->sharedPath().'/.env', $contents);
        } finally {
            $connection->close();
        }
    }
}
