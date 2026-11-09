<?php

use App\Enums\BuildStrategy;
use App\Enums\DeploymentStatus;
use App\Enums\StepStatus;
use App\Models\Build;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Services\Deploy\DeploymentPlanner;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
| "Build locally" with a real git repository in a temporary folder: a bare
| repository plays the remote (origin), "work" is the local project folder.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/autodeploy-test-'.Str::lower(Str::random(10));
    $this->work = $this->root.'/work';

    mkdir($this->root.'/remote.git', 0777, true);
    mkdir($this->work.'/public/build', 0777, true);

    runGit($this->root.'/remote.git', 'init', '--bare', '-q');
    runGit($this->work, 'init', '-q');
    runGit($this->work, 'remote', 'add', 'origin', $this->root.'/remote.git');

    file_put_contents($this->work.'/public/build/manifest.json', '{}');

    foreach (range(1, 12) as $i) {
        file_put_contents($this->work.sprintf('/file%02d.txt', $i), "v1\n");
    }

    runGit($this->work, 'add', '-A');
    runGit($this->work, 'commit', '-q', '-m', 'First');
    runGit($this->work, 'push', '-q', 'origin', 'main');

    $this->project = Project::factory()->create([
        'build_strategy' => BuildStrategy::Local,
        'local_path' => $this->work,
        // Leaves public/build (committed above) as it is.
        'local_build_command' => 'git --version',
    ]);
});

afterEach(function () {
    removeTree($this->root);
});

/** Runs git in a folder, with a test identity and no signing (whatever the global git config says). */
function runGit(string $dir, string ...$arguments): string
{
    $process = new Process([
        'git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com',
        '-c', 'commit.gpgsign=false', '-c', 'init.defaultBranch=main', ...$arguments,
    ], $dir);
    $process->mustRun();

    return trim($process->getOutput());
}

/** Deletes a folder; git makes its object files read-only, which Windows refuses to delete. */
function removeTree(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        @chmod($item->getPathname(), 0777);
        $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($dir);
}

function deployLocally(Project $project, User $user): Deployment
{
    app(DeploymentPlanner::class)->deploy($project, [], $user);

    return Deployment::with('steps')->latest('id')->firstOrFail();
}

it('builds a clean, pushed commit and deploys it', function () {
    $server = fakeServer();

    $deployment = deployLocally($this->project, $this->user);
    $output = $deployment->steps->firstWhere('key', 'local_build')->output;

    expect($deployment->status)->toBe(DeploymentStatus::Finished)
        ->and($output)->toContain('$ git fetch origin main')
        ->and($output)->toContain('is on origin/main')
        ->and(Build::count())->toBe(1)
        ->and($server->ran('git fetch --depth 1'))->toBeTrue();
});

it('stops the deploy before any server step when the working tree has uncommitted changes', function () {
    $server = fakeServer();

    foreach (range(1, 12) as $i) {
        file_put_contents($this->work.sprintf('/file%02d.txt', $i), "changed\n");
    }

    $deployment = deployLocally($this->project, $this->user);
    $step = $deployment->steps->firstWhere('key', 'local_build');

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toContain('uncommitted changes')
        ->and($deployment->error)->toContain('Commit (and push) the changes first')
        ->and($step->status)->toBe(StepStatus::Failed)
        // Up to 10 changed files are listed, then how many more.
        ->and($step->output)->toContain('M file01.txt')
        ->and($step->output)->toContain('M file10.txt')
        ->and($step->output)->not->toContain('file11.txt')
        ->and($step->output)->toContain('… and 2 more')
        // Nothing was built, nothing was sent to the server.
        ->and($step->output)->not->toContain('git --version')
        ->and(Build::count())->toBe(0)
        ->and($server->scripts)->toBe([])
        ->and($deployment->steps->firstWhere('key', 'prepare')->status)->toBe(StepStatus::Skipped);
});

it('stops the deploy when the commit is not pushed', function () {
    $server = fakeServer();

    file_put_contents($this->work.'/file01.txt', "v2\n");
    runGit($this->work, 'commit', '-q', '-am', 'Not pushed yet');

    $deployment = deployLocally($this->project, $this->user);

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toContain('is not pushed yet (not on origin/main)')
        ->and($deployment->error)->toContain('push it first')
        ->and(Build::count())->toBe(0)
        ->and($server->scripts)->toBe([]);
});

it('only warns when the remote cannot be reached', function () {
    fakeServer();
    runGit($this->work, 'remote', 'set-url', 'origin', $this->root.'/missing.git');

    $deployment = deployLocally($this->project, $this->user);
    $output = $deployment->steps->firstWhere('key', 'local_build')->output;

    expect($deployment->status)->toBe(DeploymentStatus::Finished)
        ->and($output)->toContain('WARNING: could not reach the remote repository')
        ->and(Build::count())->toBe(1);
});

it('accepts a commit pushed to the local branch when it differs from the project branch', function () {
    fakeServer();

    runGit($this->work, 'checkout', '-q', '-b', 'feature');
    file_put_contents($this->work.'/file01.txt', "v2\n");
    runGit($this->work, 'commit', '-q', '-am', 'Feature work');
    runGit($this->work, 'push', '-q', 'origin', 'feature');

    $deployment = deployLocally($this->project, $this->user);
    $output = $deployment->steps->firstWhere('key', 'local_build')->output;

    expect($deployment->status)->toBe(DeploymentStatus::Finished)
        ->and($output)->toContain('the project deploys main')
        ->and($output)->toContain('is on origin/feature');
});

it('still builds a folder that is not a git repository', function () {
    fakeServer();
    removeTree($this->work.'/.git');

    $deployment = deployLocally($this->project, $this->user);

    expect($deployment->status)->toBe(DeploymentStatus::Finished)
        ->and($deployment->steps->firstWhere('key', 'local_build')->output)->toContain('Not a git repository');
});
