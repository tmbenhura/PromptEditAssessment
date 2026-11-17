<?php

use App\Jobs\Middleware\LimitConcurrency;
use App\Jobs\SubmitGeneration;
use Illuminate\Support\Facades\Cache;

/**
 * Stands in for a queued job: the middleware only ever calls release() on it.
 */
function fakeQueuedJob(): object
{
    return new class
    {
        /** @var array<int, int> */
        public array $released = [];

        public function release(int $delay = 0): void
        {
            $this->released[] = $delay;
        }
    };
}

test('runs the job when a slot is free and frees the slot afterwards', function () {
    $middleware = new LimitConcurrency('test', limit: 1, waitSeconds: 0);
    $ran = 0;

    $middleware->handle(fakeQueuedJob(), function () use (&$ran) {
        $ran++;
    });
    $middleware->handle(fakeQueuedJob(), function () use (&$ran) {
        $ran++;
    });

    expect($ran)->toBe(2);
});

test('releases the job instead of running it when every slot is taken', function () {
    $middleware = new LimitConcurrency('test', limit: 2, waitSeconds: 0, releaseAfter: 7);
    $outer = fakeQueuedJob();
    $inner = fakeQueuedJob();
    $third = fakeQueuedJob();
    $innerRan = false;
    $thirdRan = false;

    $middleware->handle($outer, function () use ($middleware, $inner, $third, &$innerRan, &$thirdRan) {
        // A second job fits (limit 2)...
        $middleware->handle($inner, function () use ($middleware, $third, &$innerRan, &$thirdRan) {
            $innerRan = true;

            // ...but a third running at the same time does not.
            $middleware->handle($third, function () use (&$thirdRan) {
                $thirdRan = true;
            });
        });
    });

    expect($innerRan)->toBeTrue()
        ->and($thirdRan)->toBeFalse()
        ->and($third->released)->toBe([7])
        ->and($outer->released)->toBe([])
        ->and($inner->released)->toBe([]);
});

test('frees the slot even when the job throws', function () {
    $middleware = new LimitConcurrency('test', limit: 1, waitSeconds: 0);

    expect(fn () => $middleware->handle(fakeQueuedJob(), fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class);

    $ran = false;
    $middleware->handle(fakeQueuedJob(), function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeTrue();
});

test('a limit of zero means no limit', function () {
    $middleware = new LimitConcurrency('test', limit: 0, waitSeconds: 0);
    $job = fakeQueuedJob();
    $depth = 0;

    $run = function () use (&$run, $middleware, $job, &$depth) {
        if (++$depth < 5) {
            $middleware->handle($job, $run);
        }
    };

    $middleware->handle($job, $run);

    expect($depth)->toBe(5)->and($job->released)->toBe([]);
});

test('different limits do not share slots', function () {
    $a = new LimitConcurrency('a', limit: 1, waitSeconds: 0);
    $b = new LimitConcurrency('b', limit: 1, waitSeconds: 0);
    $bRan = false;

    $a->handle(fakeQueuedJob(), function () use ($b, &$bRan) {
        $b->handle(fakeQueuedJob(), function () use (&$bRan) {
            $bRan = true;
        });
    });

    expect($bRan)->toBeTrue();
});

test('submit job takes its limits and retry settings from configuration', function () {
    config([
        'services.pixelforge.submission.max_attempts' => 4,
        'services.pixelforge.submission.backoff' => [1, 2, 3],
        'services.pixelforge.submission.unique_for' => 123,
        'services.pixelforge.submission.queue' => 'submissions',
        'services.pixelforge.submission.connection' => 'database',
    ]);

    $job = new SubmitGeneration('gen-1');

    expect($job->tries)->toBe(4)
        ->and($job->backoff())->toBe([1, 2, 3])
        ->and($job->uniqueFor)->toBe(123)
        ->and($job->queue)->toBe('submissions')
        ->and($job->connection)->toBe('database');
});

test('submit job never runs with fewer than one attempt', function () {
    config(['services.pixelforge.submission.max_attempts' => 0]);

    expect((new SubmitGeneration('gen-1'))->tries)->toBe(1);
});

test('submit job concurrency limit follows configuration', function () {
    config([
        'services.pixelforge.submission.max_concurrent' => 1,
        'services.pixelforge.submission.concurrency_wait' => 0,
    ]);

    // Another worker is already using the only slot.
    $held = Cache::lock('concurrency:'.SubmitGeneration::CONCURRENCY_KEY.':1', 60);
    expect($held->get())->toBeTrue();

    $queuedJob = fakeQueuedJob();
    $ran = false;

    (new SubmitGeneration('gen-1'))->middleware()[0]->handle($queuedJob, function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeFalse()->and($queuedJob->released)->toHaveCount(1);

    // Raising the limit through configuration lets it through.
    config(['services.pixelforge.submission.max_concurrent' => 2]);

    (new SubmitGeneration('gen-1'))->middleware()[0]->handle(fakeQueuedJob(), function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeTrue();

    $held->release();
});
