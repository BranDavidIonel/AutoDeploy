<?php

namespace Database\Factories;

use App\Enums\BuildStrategy;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->word().' '.fake()->word();

        return [
            'server_id' => Server::factory(),
            'name' => ucfirst($name),
            'repository' => 'git@github.com:example/'.str($name)->slug().'.git',
            'branch' => 'main',
            'deploy_path' => '/var/www/'.str($name)->slug(),
            'build_strategy' => BuildStrategy::Server,
            'shared_dirs' => ['storage'],
            'shared_files' => ['.env'],
            'web_user' => 'www-data',
            'keep_releases' => 3,
            'keep_builds' => 3,
        ];
    }
}
