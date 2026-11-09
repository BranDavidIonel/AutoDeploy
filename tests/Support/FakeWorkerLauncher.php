<?php

namespace Tests\Support;

use App\Services\Queue\WorkerLauncher;

/** Records the queue workers AutoDeploy would start, without starting any process. */
class FakeWorkerLauncher extends WorkerLauncher
{
    /** @var list<string> Connection of every worker started. */
    public array $launched = [];

    /** What launch() answers: false = the worker could not be started. */
    public bool $works = true;

    public function launch(string $connection): bool
    {
        $this->launched[] = $connection;

        return $this->works;
    }
}
