<?php

namespace App\Exceptions;

use RuntimeException;

/** The user pressed "Cancel" while the deployment was running. */
class DeploymentCancelled extends RuntimeException {}
