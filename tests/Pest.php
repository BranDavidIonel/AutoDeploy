<?php

use App\Services\Remote\ConnectionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeConnection;
use Tests\TestCase;

/*
| Feature tests run on a fresh in-memory SQLite database, with the queue
| on "sync" (a deploy runs right away) and never touch a real server:
| use fakeServer() to replace every SSH connection with a FakeConnection.
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
