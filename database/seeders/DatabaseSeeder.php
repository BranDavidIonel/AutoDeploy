<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * The admin account used to log in to AutoDeploy. Email and password come from .env
     * (AUTODEPLOY_ADMIN_EMAIL / AUTODEPLOY_ADMIN_PASSWORD), never from the code.
     * Without a password in .env a random one is made once and printed. Safe to run again.
     */
    public function run(): void
    {
        $email = (string) config('autodeploy.admin.email');
        $password = config('autodeploy.admin.password');
        $user = User::where('email', $email)->first();

        if ($user && blank($password)) {
            return;
        }

        if (blank($password)) {
            $password = Str::password(16, symbols: false);
            $this->command?->warn("Admin account: {$email} / {$password}  (save the password, it is not shown again)");
        }

        User::updateOrCreate(['email' => $email], ['name' => 'Admin', 'password' => $password]);
    }
}
