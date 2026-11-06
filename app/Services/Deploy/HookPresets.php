<?php

namespace App\Services\Deploy;

use App\Enums\HookStage;
use App\Models\Project;

/**
 * Ready-made deploy commands for Laravel / npm projects, offered in the
 * "Deploy script" editor. New projects start with the defaults.
 */
class HookPresets
{
    /**
     * @return list<array{key: string, name: string, stage: string, script: string, description: string, default: bool}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'migrate',
                'name' => __('Run migrations'),
                'stage' => HookStage::BeforeActivate->value,
                'script' => '$PHP artisan migrate --force',
                'description' => __('Updates the database before the new code goes live.'),
                'default' => true,
            ],
            [
                'key' => 'storage_link',
                'name' => __('Storage link'),
                'stage' => HookStage::BeforeActivate->value,
                'script' => '$PHP artisan storage:link --force 2>/dev/null || $PHP artisan storage:link',
                'description' => __('public/storage → storage/app/public, for uploaded files.'),
                'default' => true,
            ],
            [
                'key' => 'optimize',
                'name' => __('Cache config, routes and views'),
                'stage' => HookStage::BeforeActivate->value,
                'script' => '$PHP artisan optimize',
                'description' => __('php artisan optimize: faster responses in production.'),
                'default' => true,
            ],
            [
                'key' => 'queue_restart',
                'name' => __('Restart queue workers'),
                'stage' => HookStage::AfterActivate->value,
                'script' => '$PHP artisan queue:restart',
                'description' => __('Workers finish their job and start again with the new code.'),
                'default' => true,
            ],
            [
                'key' => 'reload_fpm',
                'name' => __('Reload PHP-FPM'),
                'stage' => HookStage::AfterActivate->value,
                'script' => self::reloadFpmScript(),
                'description' => __('Clears OPcache so the new release is served right away.'),
                'default' => true,
            ],
            [
                'key' => 'npm_build',
                'name' => __('npm ci + build (custom)'),
                'stage' => HookStage::BeforeBuild->value,
                'script' => "npm ci\nnpm run build",
                'description' => __('Only if you do not use the build option of the project.'),
                'default' => false,
            ],
            [
                'key' => 'seed',
                'name' => __('Seed the database'),
                'stage' => HookStage::BeforeActivate->value,
                'script' => '$PHP artisan db:seed --force',
                'description' => __('Runs the seeders (make sure they can run more than once).'),
                'default' => false,
            ],
            [
                'key' => 'optimize_clear',
                'name' => __('Clear all caches'),
                'stage' => HookStage::BeforeActivate->value,
                'script' => '$PHP artisan optimize:clear',
                'description' => __('Use instead of "Cache config" while debugging.'),
                'default' => false,
            ],
            [
                'key' => 'horizon',
                'name' => __('Terminate Horizon'),
                'stage' => HookStage::AfterActivate->value,
                'script' => '$PHP artisan horizon:terminate',
                'description' => __('For projects that use Laravel Horizon instead of queue:work.'),
                'default' => false,
            ],
            [
                'key' => 'filament',
                'name' => __('Filament upgrade'),
                'stage' => HookStage::BeforeActivate->value,
                'script' => '$PHP artisan filament:upgrade',
                'description' => __('Publishes the Filament assets after an update.'),
                'default' => false,
            ],
            [
                'key' => 'custom',
                'name' => __('Custom command'),
                'stage' => HookStage::BeforeActivate->value,
                'script' => "# Runs in the new release folder. Variables: \$PHP \$COMPOSER_BIN \$RELEASE_PATH \$SHARED_PATH \$DEPLOY_PATH \$BRANCH \$COMMIT\n",
                'description' => __('An empty command to write yourself.'),
                'default' => false,
            ],
        ];
    }

    /**
     * Reloads the PHP-FPM service of the project's PHP version, whatever its name:
     * php8.4-fpm (Ubuntu packages), php84-fpm (own builds), php-fpm (RHEL / generic).
     */
    public static function reloadFpmScript(): string
    {
        return implode("\n", [
            '# Clears OPcache so PHP serves the new release right away.',
            'V="$($PHP -r \'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;\')"',
            'if [ "$(id -u)" = "0" ]; then SUDO=""; else SUDO="sudo -n"; fi',
            'FPM=""',
            'for S in "php$V-fpm" "php${V/./}-fpm" php-fpm; do',
            '  if systemctl cat "$S" >/dev/null 2>&1; then FPM="$S"; break; fi',
            'done',
            'if [ -z "$FPM" ]; then echo "No PHP-FPM service found for PHP $V (skipped)"; exit 0; fi',
            '$SUDO systemctl reload "$FPM" && echo "Reloaded $FPM" || echo "Could not reload $FPM (skipped)"',
        ]);
    }

    /** Adds the default hooks to a new project. */
    public static function installDefaults(Project $project): void
    {
        $sort = 0;

        foreach (self::all() as $preset) {
            if (! $preset['default']) {
                continue;
            }

            $project->hooks()->create([
                'name' => $preset['name'],
                'stage' => $preset['stage'],
                'script' => $preset['script'],
                'enabled' => true,
                'sort' => ++$sort,
            ]);
        }
    }
}
