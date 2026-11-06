<?php

namespace App\Exceptions;

use RuntimeException;

/** A deploy cannot start or a step cannot continue; the message is shown to the user as is. */
class DeployException extends RuntimeException {}
