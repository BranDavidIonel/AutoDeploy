<?php

namespace App\Services\Deploy;

use App\Enums\BuildSource;
use App\Exceptions\DeployException;
use App\Exceptions\DeploymentCancelled;
use App\Models\Build;
use App\Models\Project;
use App\Services\Remote\LocalProcess;
use Illuminate\Support\Number;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * "Build locally": runs the build command (npm run build) in the project folder
 * on this computer and saves the build folder as a Build, ready to upload.
 */
class LocalBuilder
{
    /** Changed files listed when the working tree is not clean. */
    private const int LISTED_FILES = 10;

    /** Seconds to wait for "git fetch" before giving up the pushed check. */
    private const int FETCH_TIMEOUT = 60;

    public function __construct(private readonly BuildStorage $storage) {}

    /**
     * @param  string|null  $branch  the branch of the deployment (default: the project branch)
     * @return array{build: Build, commit: string|null, branch: string|null}
     */
    public function build(Project $project, StepOutput $out, ?int $userId = null, ?string $branch = null): array
    {
        $path = (string) $project->local_path;

        if ($path === '' || ! is_dir($path)) {
            throw new DeployException(__('Local project folder not found: :path. Check "Local project folder" in the project settings.', ['path' => $path]));
        }

        $git = $this->gitInfo($path, $branch ?: $project->branch, $out);

        $out->line('$ '.$project->local_build_command.'   (in '.$path.')');

        $timeout = (int) config('autodeploy.local_build_timeout');
        $process = $this->buildProcess($project->local_build_command, $path, $timeout);
        $process->start();
        $pid = $process->getPid();

        try {
            foreach ($process as $chunk) {
                $out->append($chunk);

                if ($out->cancelRequested()) {
                    LocalProcess::stop($process, $pid);

                    throw new DeploymentCancelled;
                }
            }
        } catch (ProcessTimedOutException) {
            LocalProcess::stop($process, $pid);
            $out->flush();

            throw new DeployException(__('The local build took longer than :seconds seconds and was stopped (AUTODEPLOY_LOCAL_BUILD_TIMEOUT).', ['seconds' => $timeout]));
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
     * Reads the commit and branch of the local folder and checks that the server can
     * deploy exactly that code: no uncommitted changes, and the commit is pushed.
     *
     * @return array{commit: string|null, branch: string|null}
     */
    private function gitInfo(string $path, string $deployBranch, StepOutput $out): array
    {
        $head = $this->run($path, ['rev-parse', 'HEAD']);
        $commit = $head->isSuccessful() ? rtrim($head->getOutput()) : null;

        if ($commit === null) {
            $this->assertTrustedFolder($path, $head, $out);
            $out->line(__('Not a git repository (or git is missing): the server will deploy the tip of :branch.', ['branch' => $deployBranch]));

            return ['commit' => null, 'branch' => null];
        }

        $branch = $this->git($path, ['rev-parse', '--abbrev-ref', 'HEAD']);
        $out->line(__('Local folder is on :branch at :commit', ['branch' => $branch ?? '?', 'commit' => substr($commit, 0, 7)]));

        if ($branch !== null && $branch !== $deployBranch) {
            $out->line(__('WARNING: the local folder is on :local, the project deploys :branch. The server will deploy the local commit.', [
                'local' => $branch,
                'branch' => $deployBranch,
            ]));
        }

        $this->assertCommitted($path, $out);

        // The commit may be on the deployed branch or on the local one ("HEAD" = no branch).
        $branches = array_values(array_unique(array_filter([$deployBranch, $branch === 'HEAD' ? null : $branch])));
        $this->assertPushed($path, $commit, $branches, $out);

        $out->line();

        return ['commit' => $commit, 'branch' => $branch];
    }

    /**
     * Git refuses a repository that belongs to another user ("dubious ownership"), e.g. a
     * checkout made by root on the server while AutoDeploy runs as its own user. That is
     * not "no repository": the uncommitted / unpushed checks cannot run, so stop.
     */
    private function assertTrustedFolder(string $path, Process $head, StepOutput $out): void
    {
        $error = $head->isStarted() ? $head->getErrorOutput() : '';

        // "safe.directory" is in the hint git prints, in every language.
        if (! str_contains($error, 'safe.directory')) {
            return;
        }

        $lines = array_filter(array_map('trim', preg_split('/\R/', $error) ?: []));
        $out->line('  '.(reset($lines) ?: ''));

        throw new DeployException(__('Git refuses to read :path: the folder belongs to another user than the one AutoDeploy runs as. Make that user the owner of the folder (chown -R), or add the folder to its git safe.directory list, then deploy again.', ['path' => $path]));
    }

    /**
     * Stops the deploy when tracked files changed since the last commit: the assets would
     * be built from the changed files, while the server checks out the committed code.
     */
    private function assertCommitted(string $path, StepOutput $out): void
    {
        $status = $this->git($path, ['status', '--porcelain', '--untracked-files=no']) ?? '';

        if ($status === '') {
            return;
        }

        $files = preg_split('/\R/', $status) ?: [];
        $out->line(__('The local folder has uncommitted changes:'));

        foreach (array_slice($files, 0, self::LISTED_FILES) as $file) {
            $out->line('  '.$file);
        }

        if (count($files) > self::LISTED_FILES) {
            $out->line('  '.__('… and :count more', ['count' => count($files) - self::LISTED_FILES]));
        }

        throw new DeployException(__('Deploy stopped: the local folder has uncommitted changes. The assets would be built from them, but the server deploys the last commit, so code and assets would not match. Commit (and push) the changes first, then deploy again.'));
    }

    /**
     * Stops the deploy when the commit is not on the remote repository yet: the server
     * fetches it from there. When the remote cannot be reached (offline, no access) it
     * only warns: then the clone step on the server fails anyway, before the site is touched.
     *
     * @param  list<string>  $branches
     */
    private function assertPushed(string $path, string $commit, array $branches, StepOutput $out): void
    {
        $short = substr($commit, 0, 7);
        $env = $this->fetchEnvironment($path);
        $fetched = [];

        foreach ($branches as $branch) {
            $out->line('$ git fetch origin '.$branch);
            $out->flush();

            // An explicit refspec also works in clones made with --single-branch.
            $fetch = $this->run($path, ['fetch', '--quiet', 'origin', "+refs/heads/{$branch}:refs/remotes/origin/{$branch}"], $env, self::FETCH_TIMEOUT);

            if (! $fetch->isSuccessful()) {
                $error = $fetch->isStarted() ? trim($fetch->getErrorOutput()) : '';
                $lines = array_filter(array_map('trim', preg_split('/\R/', $error) ?: []));

                foreach (array_slice($lines, -3) as $line) {
                    $out->line('  '.$line);
                }

                continue;
            }

            $fetched[] = 'origin/'.$branch;

            if ($this->run($path, ['merge-base', '--is-ancestor', $commit, "refs/remotes/origin/{$branch}"])->isSuccessful()) {
                $out->line(__('Commit :commit is on origin/:branch.', ['commit' => $short, 'branch' => $branch]));

                return;
            }
        }

        if ($fetched === []) {
            $out->line(__('WARNING: could not reach the remote repository (offline or no access), so it is not checked that :commit is pushed. If it is not, the server cannot fetch it and the deploy stops before the site is touched.', ['commit' => $short]));

            return;
        }

        throw new DeployException(__('Deploy stopped: commit :commit is not pushed yet (not on :refs). The server takes the code from the repository, so push it first (git push), then deploy again.', [
            'commit' => $short,
            'refs' => implode(', ', $fetched),
        ]));
    }

    /**
     * Git must never wait for a password or a question here: nobody can answer it.
     * An SSH command set by the user (key, config) is kept, only made non-interactive.
     *
     * @return array<string, string>
     */
    private function fetchEnvironment(string $path): array
    {
        $ssh = getenv('GIT_SSH_COMMAND') ?: ($this->git($path, ['config', 'core.sshCommand']) ?: 'ssh');

        return [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_SSH_COMMAND' => $ssh.' -o BatchMode=yes',
            // Git Credential Manager (Windows): fail instead of opening a login window.
            'GCM_INTERACTIVE' => 'never',
        ];
    }

    /**
     * Output of a git command (without the trailing newline), or null when it fails.
     *
     * @param  list<string>  $arguments
     */
    private function git(string $path, array $arguments): ?string
    {
        $process = $this->run($path, $arguments);

        // rtrim only: the first line of "git status --porcelain" may start with a space.
        return $process->isSuccessful() ? rtrim($process->getOutput()) : null;
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $env  added to the environment of this process
     */
    private function run(string $path, array $arguments, array $env = [], int $timeout = 30): Process
    {
        $process = new Process(LocalProcess::command(['git', ...$arguments]), $path, LocalProcess::environment($env), null, $timeout);
        $pid = null;

        try {
            $process->start();
            $pid = $process->getPid();
            $process->wait();
        } catch (ProcessTimedOutException) {
            // Too slow (e.g. a fetch that hangs): ssh started by git must stop too.
            LocalProcess::stop($process, $pid, 1);
        } catch (Throwable) {
            // Git missing: the process is simply not successful.
        }

        return $process;
    }

    /**
     * The build command through the shell: cmd.exe on Windows, /bin/sh elsewhere, in a
     * process group of its own (see LocalProcess) and without AutoDeploy's .env variables.
     */
    private function buildProcess(string $command, string $path, int $timeout): Process
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return Process::fromShellCommandline($command, $path, LocalProcess::environment(), null, $timeout);
        }

        return new Process(LocalProcess::command(['/bin/sh', '-c', $command]), $path, LocalProcess::environment(), null, $timeout);
    }
}
