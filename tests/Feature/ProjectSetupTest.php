<?php

use App\Models\Project;
use App\Services\Projects\ProjectSetup;

it('imports storage with hard links first and copies it when they are not possible', function () {
    $server = fakeServer();
    $project = Project::factory()->create();

    $result = app(ProjectSetup::class)->run($project, '/var/www/old-site/');
    $script = $server->scripts[0];

    $link = strpos($script, 'if cp -al "$FROM/storage" "$SHARED_PATH/storage" 2>/dev/null; then');
    $remove = strpos($script, 'rm -rf "$SHARED_PATH/storage"');
    $copy = strpos($script, 'cp -a "$FROM/storage" "$SHARED_PATH/storage"');

    expect($result['ok'])->toBeTrue()
        ->and($script)->toContain("FROM='/var/www/old-site'")
        // Only when shared/storage did not exist before the setup.
        ->and($script)->toContain('if [ "$HAD_STORAGE" = "no" ] && [ -d "$FROM/storage" ]; then')
        ->and($script)->toContain('• shared/storage already exists, left as it is')
        ->and($link)->toBeInt()
        ->and($remove)->toBeGreaterThan($link)
        ->and($copy)->toBeGreaterThan($remove)
        ->and($script)->toContain('✔ Linked $FROM/storage to shared/storage ($(du -sh "$SHARED_PATH/storage" | cut -f1), hard links, no extra disk space)')
        ->and($script)->toContain('✔ Copied $FROM/storage to shared/storage ($(du -sh "$SHARED_PATH/storage" | cut -f1))');
});

it('imports no storage without an import folder or when storage is not shared', function () {
    $server = fakeServer();

    app(ProjectSetup::class)->run(Project::factory()->create());
    app(ProjectSetup::class)->run(Project::factory()->create(['shared_dirs' => []]), '/var/www/old-site');

    expect($server->scripts)->toHaveCount(2)
        ->and($server->scripts[0])->not->toContain('$FROM/storage')
        ->and($server->scripts[1])->toContain('$FROM/.env')
        ->and($server->scripts[1])->not->toContain('$FROM/storage');
});
