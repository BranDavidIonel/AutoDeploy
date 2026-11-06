<?php

namespace App\Services\Remote;

final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public bool $timedOut = false,
        public bool $stopped = false,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut && ! $this->stopped;
    }

    /** Output without the trailing newline. */
    public function trimmed(): string
    {
        return trim($this->output);
    }

    /**
     * Parses "key=value" lines (as printed by the info scripts).
     *
     * @return array<string, string>
     */
    public function keyValues(): array
    {
        $values = [];

        foreach (preg_split('/\r?\n/', $this->output) ?: [] as $line) {
            if (preg_match('/^([a-z0-9_]+)=(.*)$/', $line, $matches)) {
                $values[$matches[1]] = trim($matches[2]);
            }
        }

        return $values;
    }
}
