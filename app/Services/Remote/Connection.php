<?php

namespace App\Services\Remote;

/** A place where deploy commands run: a server over SSH, or this machine. */
interface Connection
{
    /**
     * Runs a bash script. stdout and stderr are passed to $onOutput as they arrive;
     * returning true from $onOutput stops the command.
     *
     * @param  (callable(string): (bool|null))|null  $onOutput
     */
    public function run(string $script, ?callable $onOutput = null, ?int $timeout = null): CommandResult;

    /** Copies a local file to the server (the folder must exist). */
    public function upload(string $localFile, string $remotePath): void;

    /** Writes a string to a file on the server (the folder must exist). */
    public function put(string $remotePath, string $contents): void;

    public function close(): void;
}
