<?php

namespace Database\Factories;

use App\Enums\ConnectionType;
use App\Enums\ServerAuthMethod;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Server> */
class ServerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'DigitalOcean '.fake()->word(),
            'connection_type' => ConnectionType::Ssh,
            'host' => fake()->ipv4(),
            'port' => 22,
            'username' => 'root',
            'auth_method' => ServerAuthMethod::KeyFile,
            'key_path' => '~/.ssh/id_ed25519',
            'status' => 'unknown',
        ];
    }

    public function local(): static
    {
        return $this->state(fn () => [
            'name' => 'This machine',
            'connection_type' => ConnectionType::Local,
            'host' => null,
            'username' => 'local',
        ]);
    }
}
