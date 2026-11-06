<?php

namespace App\Services\Deploy;

use App\Models\Deployment;
use App\Models\DeploymentStep;

/**
 * Collects the output of a running step and saves it to the database about
 * twice a second, so the deployment page can show the log live.
 */
class StepOutput
{
    private const int MAX_BYTES = 1_500_000;

    private string $buffer;

    private float $lastFlush = 0.0;

    private float $lastCancelCheck = 0.0;

    private bool $cancelled = false;

    public function __construct(private readonly DeploymentStep $step)
    {
        $this->buffer = (string) $step->output;
    }

    public function append(string $chunk): void
    {
        $this->buffer .= $chunk;

        if (strlen($this->buffer) > self::MAX_BYTES) {
            $this->buffer = "[… earlier output trimmed …]\n".substr($this->buffer, -intdiv(self::MAX_BYTES, 2));
        }

        if (microtime(true) - $this->lastFlush > 0.5) {
            $this->flush();
        }
    }

    public function line(string $text = ''): void
    {
        $this->append($text."\n");
    }

    public function flush(): void
    {
        // Command output may end in the middle of a multi-byte character; JSON needs valid UTF-8.
        $this->step->forceFill(['output' => mb_scrub($this->buffer, 'UTF-8')])->save();
        $this->lastFlush = microtime(true);
    }

    /** Checks (at most every 2 seconds) whether the user asked to cancel. */
    public function cancelRequested(): bool
    {
        if (! $this->cancelled && microtime(true) - $this->lastCancelCheck > 2) {
            $this->lastCancelCheck = microtime(true);
            $this->cancelled = (bool) Deployment::whereKey($this->step->deployment_id)->value('cancel_requested');
        }

        return $this->cancelled;
    }

    public function text(): string
    {
        return $this->buffer;
    }
}
