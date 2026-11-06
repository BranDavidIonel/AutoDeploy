<?php

use App\Models\Deployment;
use App\Models\Project;

beforeEach(function () {
    fakeServer();
    $this->project = Project::factory()->create(['auto_deploy' => true, 'branch' => 'main']);
    $this->url = route('webhook', $this->project->webhook_token);
});

it('answers the GitHub ping', function () {
    $this->postJson($this->url, [], ['X-GitHub-Event' => 'ping'])->assertOk()->assertJson(['message' => 'pong']);
});

it('deploys on a push to the project branch', function () {
    $this->postJson($this->url, ['ref' => 'refs/heads/main', 'pusher' => ['name' => 'brand']])->assertStatus(202);

    expect(Deployment::first()->triggered_by)->toBe('webhook');
});

it('ignores pushes to other branches', function () {
    $this->postJson($this->url, ['ref' => 'refs/heads/feature'])->assertOk();

    expect(Deployment::count())->toBe(0);
});

it('does nothing when deploy on push is off', function () {
    $this->project->update(['auto_deploy' => false]);

    $this->postJson($this->url, ['ref' => 'refs/heads/main'])->assertForbidden();
});

it('rejects unknown tokens', function () {
    $this->postJson(route('webhook', 'nope'))->assertNotFound();
});
