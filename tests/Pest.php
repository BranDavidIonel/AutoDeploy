<?php

use App\Services\Queue\WorkerLauncher;
use App\Services\Remote\ConnectionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeConnection;
use Tests\Support\FakeWorkerLauncher;
use Tests\TestCase;

/*
| Feature tests run on a fresh in-memory SQLite database, with the queue
| on "sync" (a deploy runs right away) and never touch a real server:
| use fakeServer() to replace every SSH connection with a FakeConnection.
| Queue workers are never started: fakeWorkerLauncher() lists the ones
| AutoDeploy would have started.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/** Replaces every server connection with a fake one and returns it. */
function fakeServer(?Closure $responder = null): FakeConnection
{
    $connection = new FakeConnection($responder);
    app(ConnectionFactory::class)->fake($connection);

    return $connection;
}

/** The fake that stands in for WorkerLauncher in every test. */
function fakeWorkerLauncher(): FakeWorkerLauncher
{
    $launcher = app(WorkerLauncher::class);
    assert($launcher instanceof FakeWorkerLauncher);

    return $launcher;
}
