<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    config(['autodeploy.admin.email' => 'admin@example.test', 'autodeploy.admin.password' => 'correct-horse-battery']);
});

it('sends guests to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
    $this->get(route('login'))->assertOk()->assertInertia(fn ($page) => $page->component('auth/Login'));
});

it('logs in the admin made by the seeder from .env', function () {
    $this->seed(DatabaseSeeder::class);

    $this->post(route('login.store'), ['email' => 'admin@example.test', 'password' => 'correct-horse-battery'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs(User::where('email', 'admin@example.test')->first());
});

it('makes a random password when .env has none, and keeps it on the next seed', function () {
    config(['autodeploy.admin.password' => null]);

    $this->seed(DatabaseSeeder::class);
    $hash = User::where('email', 'admin@example.test')->value('password');
    $this->seed(DatabaseSeeder::class);

    expect($hash)->not->toBeEmpty()
        ->and(User::where('email', 'admin@example.test')->value('password'))->toBe($hash);
});

it('refuses a wrong password', function () {
    $this->seed(DatabaseSeeder::class);

    $this->post(route('login.store'), ['email' => 'admin@example.test', 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('logs out', function () {
    $this->actingAs(User::factory()->create())->post(route('logout'))->assertRedirect(route('login'));
    $this->assertGuest();
});
