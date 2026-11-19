<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Cache;

/**
 * Lets at most $limit jobs run at the same time, across every queue worker.
 *
 * It is a counting semaphore built from $limit cache locks ("slots"), so it works
 * with any cache store that supports atomic locks (database, redis, file, array).
 *
 * A job that cannot get a slot waits for one for up to $waitSeconds, polling
 * briefly, instead of being released straight away: releasing would use up one of
 * the job's attempts without it having done anything. If the wait runs out, the
 * job goes back on the queue.
 */
class LimitConcurrency
{
    public function __construct(
        protected string $name,
        protected int $limit,
        protected int $waitSeconds = 10,
        protected int $lockSeconds = 60,
        protected int $releaseAfter = 5,
    ) {}

    /**
     * @param  Job  $job  The queued job (it uses InteractsWithQueue)
     * @param  Closure(object): mixed  $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        // A limit of zero (or less) means "no limit".
        if ($this->limit <= 0) {
            return $next($job);
        }

        $slot = $this->acquireSlot();

        if ($slot === null) {
            $job->release($this->releaseAfter);

            return null;
        }

        try {
            return $next($job);
        } finally {
            $slot->release();
        }
    }

    protected function acquireSlot(): ?Lock
    {
        $deadline = microtime(true) + $this->waitSeconds;

        do {
            for ($slot = 1; $slot <= $this->limit; $slot++) {
                $lock = Cache::lock("concurrency:{$this->name}:{$slot}", $this->lockSeconds);

                if ($lock->get()) {
                    return $lock;
                }
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        return null;
    }
}
