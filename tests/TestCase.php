<?php

namespace Tests;

use App\Services\Queue\WorkerLauncher;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeWorkerLauncher;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pages render without built assets (public/build) in tests.
        $this->withoutVite();

        // Never start a real queue worker from a test (see fakeWorkerLauncher()).
        $this->app->instance(WorkerLauncher::class, new FakeWorkerLauncher);
    }
}
