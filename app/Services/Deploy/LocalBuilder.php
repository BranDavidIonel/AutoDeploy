<?php

namespace App\Services\Deploy;

use App\Enums\BuildSource;
use App\Exceptions\DeployException;
use App\Exceptions\DeploymentCancelled;
use App\Models\Build;
use App\Models\Project;
use Illuminate\Support\Number;
use Symfony\Component\Process\Process;

/**
 * "Build locally": runs the build command (npm run build) in the project folder
 * on this computer and saves the build folder as a Build, ready to upload.
 */
class LocalBuilder
{
    public function __construct(private readonly BuildStorage $storage) {}

    /**
     * @return array{build: Build, commit: string|null, branch: string|null}
     */
    public function build(Project $project, StepOutput $out, ?int $userId = null): array
    {
        $path = (string) $project->local_path;

        if ($path === '' || ! is_dir($path)) {
            throw new DeployException(__('Local project folder not found: :path. Check "Local project folder" in the project settings.', ['path' => $path]));
        }

        $git = $this->gitInfo($path, $project, $out);

        $out->line('$ '.$project->local_build_command.'   (in '.$path.')');

        $process = Process::fromShellCommandline($project->local_build_command, $path, null, null, (int) config('autodeploy.local_build_timeout'));
        $process->start();

        foreach ($process as $chunk) {
            $out->append($chunk);

            if ($out->cancelRequested()) {
                $process->stop(3);

                throw new DeploymentCancelled;
            }
        }

        $process->wait();
        $out->flush();

        if (! $process->isSuccessful()) {
            throw new DeployException(__('The local build failed (exit code :code).', ['code' => (int) $process->getExitCode()]));
        }

        $directory = rtrim($path, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, trim($project->build_output, '/'));

        $build = $this->storage->storeDirectory($project, $directory, BuildSource::Local, [
            'label' => __('Local build').($git['branch'] ? ' · '.$git['branch'] : ''),
            'commit_hash' => $git['commit'],
            'branch' => $git['branch'],
            'user_id' => $userId,
        ]);

        $out->line();
        $out->line(__('Saved as build #:id: :files files, :size', [
            'id' => $build->id,
            'files' => $build->file_count,
            'size' => Number::fileSize($build->size, 1),
        ]));

        return ['build' => $build, ...$git];
    }

    /**
     * @return array{commit: string|null, branch: string|null}
     */
    private function gitInfo(string $path, Project $project, StepOutput $out): array
    {
        $commit = $this->git($path, ['rev-parse', 'HEAD']);

        if ($commit === null) {
            $out->line(__('Not a git repository (or git is missing): the server will deploy the tip of :branch.', ['branch' => $project->branch]));

            return ['commit' => null, 'branch' => null];
        }

        $branch = $this->git($path, ['rev-parse', '--abbrev-ref', 'HEAD']);
        $out->line(__('Local folder is on :branch at :commit', ['branch' => $branch ?? '?', 'commit' => substr($commit, 0, 7)]));

        if ($branch !== null && $branch !== $project->branch) {
            $out->line(__('WARNING: the local folder is on :local, the project deploys :branch. The server will deploy the local commit.', [
                'local' => $branch,
                'branch' => $project->branch,
            ]));
        }

        if (($this->git($path, ['status', '--porcelain', '--untracked-files=no']) ?? '') !== '') {
            $out->line(__('WARNING: the local folder has uncommitted changes. They end up in the built assets but not in the code on the server.'));
        }

        $out->line();

        return ['commit' => $commit, 'branch' => $branch];
    }

    /** @param  list<string>  $arguments */
    private function git(string $path, array $arguments): ?string
    {
        $process = new Process(['git', ...$arguments], $path, null, null, 30);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }
}
