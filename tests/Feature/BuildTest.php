<?php

use App\Enums\BuildStrategy;
use App\Enums\DeploymentStatus;
use App\Models\Build;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['build_strategy' => BuildStrategy::Upload, 'keep_builds' => 2]);
});

/** A zip like the one made by right-clicking public/build on Windows. */
function buildZip(array $files): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);

    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
    }

    $zip->close();

    return new UploadedFile($path, 'build.zip', 'application/zip', null, true);
}

it('saves an uploaded zip and finds the build folder inside it', function () {
    $this->actingAs($this->user)
        ->post(route('projects.builds.store', $this->project), [
            'archive' => buildZip([
                'public/build/manifest.json' => '{}',
                'public/build/assets/app-123.js' => 'console.log(1)',
            ]),
            'label' => 'Invoice fix',
        ])
        ->assertRedirect()
        ->assertInertiaFlash('success');

    $build = Build::firstOrFail();

    expect($build->label)->toBe('Invoice fix')
        ->and($build->file_count)->toBe(2)
        ->and($build->has_manifest)->toBeTrue()
        ->and(Storage::disk('local')->exists($build->path))->toBeTrue();
});

it('keeps only the newest builds of a project', function () {
    foreach (range(1, 3) as $i) {
        $this->actingAs($this->user)->post(route('projects.builds.store', $this->project), [
            'archive' => buildZip(['manifest.json' => '{}', "assets/app-{$i}.js" => 'x']),
            'label' => "Build {$i}",
        ]);
    }

    expect(Build::pluck('label')->all())->toBe(['Build 2', 'Build 3']);
});

it('rejects archives with paths that leave the folder', function () {
    $this->actingAs($this->user)
        ->post(route('projects.builds.store', $this->project), [
            'archive' => buildZip(['../evil.php' => '<?php', 'manifest.json' => '{}']),
        ])
        ->assertInertiaFlash('error');

    expect(Build::count())->toBe(0);
});

it('uploads the saved build to the release and unpacks it there', function () {
    $server = fakeServer();

    $this->actingAs($this->user)->post(route('projects.builds.store', $this->project), [
        'archive' => buildZip(['manifest.json' => '{}', 'assets/app.css' => 'body{}']),
        'deploy' => true,
    ])->assertRedirect();

    $deployment = Deployment::firstOrFail();

    expect($deployment->status)->toBe(DeploymentStatus::Finished)
        ->and($deployment->build_id)->toBe(Build::first()->id)
        ->and($server->uploads)->toHaveCount(1)
        ->and($server->uploads[0]['remote'])->toEndWith('/.autodeploy/build-'.$deployment->release.'.tar.gz')
        ->and($server->ran('tar -xzf'))->toBeTrue();
});
