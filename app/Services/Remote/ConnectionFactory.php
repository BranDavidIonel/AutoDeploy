<?php

namespace App\Services\Remote;

use App\Models\Server;
use Closure;

/** Creates the right connection for a server. Tests swap it with fake(). */
class ConnectionFactory
{
    /** @var (Closure(Server): Connection)|null */
    private ?Closure $fake = null;

    public function make(Server $server): Connection
    {
        if ($this->fake) {
            return ($this->fake)($server);
        }

        return $server->isLocal() ? new LocalConnection : new SshConnection($server);
    }

    /**
     * Every server gets this connection from now on (tests).
     *
     * @param  Connection|(Closure(Server): Connection)  $connection
     */
    public function fake(Connection|Closure $connection): void
    {
        $this->fake = $connection instanceof Connection ? fn () => $connection : $connection;
    }
}
