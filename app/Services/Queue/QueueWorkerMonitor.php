<?php

namespace App\Services\Queue;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerStopReason;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Knows whether a queue worker runs, and starts one when a job is queued and none does.
 *
 * Every worker that runs this app (started by hand, by start-autodeploy.bat, by
 * Supervisor or by WorkerLauncher) writes a heartbeat to the cache while it loops,
 * and keeps it for the whole job while it runs one (a deploy can take long).
 */
class QueueWorkerMonitor
{
    public const HEARTBEAT_KEY = 'autodeploy:queue-worker:heartbeat';

    /** Set when a worker is started: stops a second request from starting one more. */
    public const LAUNCH_KEY = 'autodeploy:queue-worker:launch';

    /** Seconds a heartbeat counts while the worker waits for jobs (it loops about every second). */
    public const HEARTBEAT_TTL = 15;

    /** A looping worker writes its heartbeat at most this often, in seconds. */
    public const BEAT_EVERY = 5;

    /** A started worker sends its first heartbeat well within this many seconds. */
    public const STARTING_SECONDS = 20;

    /** A worker that did not come up is started again only after this many seconds. */
    public const RETRY_AFTER = 60;

    /**
     * The queue events it listens to, with the method that handles each
     * (registered in AppServiceProvider).
     */
    public const LISTENERS = [
        JobQueued::class => 'jobQueued',
        WorkerStarting::class => 'workerStarting',
        Looping::class => 'looping',
        JobProcessing::class => 'jobProcessing',
        JobProcessed::class => 'jobDone',
        JobFailed::class => 'jobDone',
        WorkerStopping::class => 'workerStopping',
    ];

    /** When this worker process last wrote the heartbeat. */
    private ?int $lastBeat = null;

    public function __construct(
        private readonly WorkerLauncher $launcher,
        private readonly QueueFactory $queue,
    ) {}

    /**
     * Starts a worker in the background when the connection needs one and none runs.
     * True when a worker was started.
     */
    public function ensureRunning(?string $connection = null): bool
    {
        $connection ??= (string) config('queue.default');

        if (! config('autodeploy.auto_worker') || ! $this->needsWorker($connection) || $this->isAlive()) {
            return false;
        }

        // Atomic: only one request starts a worker. The key goes away when the worker starts.
        if (! Cache::add(self::LAUNCH_KEY, ['at' => now()->getTimestamp(), 'ok' => true], self::RETRY_AFTER)) {
            return false;
        }

        $started = $this->launcher->launch($connection);

        if (! $started) {
            Cache::put(self::LAUNCH_KEY, ['at' => now()->getTimestamp(), 'ok' => false], self::RETRY_AFTER);
        }

        return $started;
    }

    public function isAlive(): bool
    {
        return Cache::has(self::HEARTBEAT_KEY);
    }

    /**
     * "running": a worker sends heartbeats; "starting": one was just started;
     * "failed": one was started but did not come up (or could not be started);
     * "off": none runs and the automatic start is turned off; "stopped": none runs.
     */
    public function status(): string
    {
        if ($this->isAlive()) {
            return 'running';
        }

        if (! config('autodeploy.auto_worker')) {
            return 'off';
        }

        $launch = Cache::get(self::LAUNCH_KEY);

        if (! is_array($launch)) {
            return 'stopped';
        }

        return ($launch['ok'] ?? false) && now()->getTimestamp() - (int) ($launch['at'] ?? 0) < self::STARTING_SECONDS
            ? 'starting'
            : 'failed';
    }

    /** Marks the worker of this process alive for the next $seconds. */
    public function beat(int $seconds = self::HEARTBEAT_TTL): void
    {
        $this->lastBeat = now()->getTimestamp();
        Cache::put(self::HEARTBEAT_KEY, $this->lastBeat, $seconds);
    }

    public function jobQueued(JobQueued $event): void
    {
        $this->safely(fn () => $this->ensureRunning($event->connectionName));
    }

    public function workerStarting(WorkerStarting $event): void
    {
        $this->safely(function () {
            $this->beat();
            Cache::forget(self::LAUNCH_KEY);
        });
    }

    /** Runs before every loop of the worker (about every second when idle). Never returns false: that would pause the worker. */
    public function looping(Looping $event): void
    {
        if ($this->lastBeat === null || now()->getTimestamp() - $this->lastBeat >= self::BEAT_EVERY) {
            $this->safely(fn () => $this->beat());
        }
    }

    /** A deploy can run up to the job timeout: the worker counts as alive for all of it. */
    public function jobProcessing(JobProcessing $event): void
    {
        $timeout = $event->job->timeout() ?: (int) config('autodeploy.command_timeout', 3600);

        $this->safely(fn () => $this->beat($timeout + 60));
    }

    public function jobDone(JobProcessed|JobFailed $event): void
    {
        $this->safely(fn () => $this->beat());
    }

    public function workerStopping(WorkerStopping $event): void
    {
        $this->safely(function () use ($event) {
            Cache::forget(self::HEARTBEAT_KEY);
            $this->lastBeat = null;

            // A job queued in the last second saw this worker alive and started none: start one now.
            if (in_array($event->reason, [WorkerStopReason::QueueEmpty, WorkerStopReason::QueueEmptyFor], true)
                && $event->connectionName
                && $this->hasPendingJobs($event->connectionName, $event->queue)) {
                $this->ensureRunning($event->connectionName);
            }
        });
    }

    /** Connections that run the job in the same PHP process (or right after the response) need no worker. */
    private function needsWorker(string $connection): bool
    {
        $driver = config("queue.connections.{$connection}.driver");

        return is_string($driver) && ! in_array($driver, ['sync', 'null', 'deferred', 'background'], true);
    }

    private function hasPendingJobs(string $connection, ?string $queues): bool
    {
        $queue = $this->queue->connection($connection);

        foreach (explode(',', (string) $queues) as $name) {
            if ($queue->pendingSize(trim($name) ?: null) > 0) {
                return true;
            }
        }

        return false;
    }

    /** The heartbeat must never break a deploy request or the worker: errors are only logged. */
    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
