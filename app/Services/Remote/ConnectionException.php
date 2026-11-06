<?php

namespace App\Services\Remote;

use RuntimeException;

/** The server could not be reached, or refused the login, or a file transfer failed. */
class ConnectionException extends RuntimeException {}
