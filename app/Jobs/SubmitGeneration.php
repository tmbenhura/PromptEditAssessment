<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Jobs\Middleware\LimitConcurrency;
use App\Models\Generation;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\Exceptions\PixelforgeOverloadedException;
use App\Services\Pixelforge\Exceptions\PixelforgeRateLimitException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Submits a queued generation to Pixelforge.
 *
 * Every attempt reuses the generation's idempotency key, so a retry after a
 * timeout or a lost response can never create (or bill) a second generation.
 */
class SubmitGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Name of the concurrency limit shared by every SubmitGeneration job.
     */
    public const CONCURRENCY_KEY = 'pixelforge-submit';

    /**
     * Total attempts (services.pixelforge.submission.max_attempts). Pixelforge asks
     * callers to cap retries, so a generation it never accepts is eventually failed.
     */
    public int $tries;

    /**
     * Keep the uniqueness lock for as long as the job could still be retrying.
     */
    public int $uniqueFor;

    /**
     * @param  int  $wave  Which deferral this job is. A generation held back by the daily
     *                     quota is submitted by a brand new job (wave + 1) at the next UTC
     *                     midnight, so it starts with a full attempt budget and its own
     *                     uniqueness lock.
     */
    public function __construct(
        public readonly string $generationId,
        public readonly int $wave = 0,
    ) {
        $this->tries = max(1, (int) config('services.pixelforge.submission.max_attempts', 6));
        $this->uniqueFor = (int) config('services.pixelforge.submission.unique_for', 900);

        $this->onConnection(config('services.pixelforge.submission.connection'));
        $this->onQueue(config('services.pixelforge.submission.queue'));
    }

    /**
     * At most services.pixelforge.submission.max_concurrent of these jobs run at once.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new LimitConcurrency(
                name: self::CONCURRENCY_KEY,
                limit: (int) config('services.pixelforge.submission.max_concurrent', 3),
                waitSeconds: (int) config('services.pixelforge.submission.concurrency_wait', 10),
                lockSeconds: (int) config('services.pixelforge.submission.concurrency_lock_ttl', 60),
            ),
        ];
    }

    public function uniqueId(): string
    {
        return $this->generationId.':'.$this->wave;
    }

    /**
     * Minimum seconds to wait before each retry (services.pixelforge.submission.backoff).
     * A longer Retry-After from Pixelforge always takes precedence.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $backoff = (array) config('services.pixelforge.submission.backoff', [5, 15, 30, 60, 120]);

        return $backoff === [] ? [5] : array_values(array_map('intval', $backoff));
    }

    public function handle(PixelforgeClient $pixelforge): void
    {
        $generation = Generation::query()->find($this->generationId);

        // Already submitted (or finished via webhook), or deleted: nothing to do.
        if ($generation === null || $generation->isFinal() || $generation->pixelforge_id !== null) {
            return;
        }

        try {
            $response = $pixelforge->createGeneration(
                payload: $this->payload($generation),
                idempotencyKey: $generation->idempotency_key,
            );
        } catch (PixelforgeOverloadedException $e) {
            $this->retryLater($generation, $e, $e->retryAfter);

            return;
        } catch (PixelforgeRateLimitException $e) {
            // The daily quota is used up and Pixelforge gives no Retry-After. Retrying
            // sooner is pointless, so wait for the quota to reset instead of failing.
            if ($e->errorCode === 'daily_limit_exceeded') {
                $this->deferUntilQuotaResets($generation);

                return;
            }

            $this->retryLater($generation, $e, $e->retryAfter);

            return;
        } catch (PixelforgeException $e) {
            // 5xx: transient on Pixelforge's side, let the queue retry with backoff.
            if ($e->getCode() >= 500) {
                throw $e;
            }

            // Rejected (content policy, validation, auth): nothing was created or billed.
            $this->markFailed($generation, $e->errorCode ?? 'submission_rejected', $e->getMessage());

            return;
        }

        // A webhook may already have advanced this generation, so only touch the
        // submission fields and never the status.
        $generation = $generation->fresh() ?? $generation;
        $generation->submit_after = null;
        $generation->pixelforge_id ??= (string) $response['id'];
        $generation->pixelforge_created_at ??= ! empty($response['created_at'])
            ? Carbon::parse((string) $response['created_at'])
            : now();

        if (! $generation->isFinal() && ! empty($response['rates'])) {
            $generation->rates = $response['rates'];
        }

        $generation->save();
    }

    /**
     * The start of the next UTC day (when the daily quota resets), plus the
     * configured safety margin.
     */
    public static function nextQuotaReset(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now('UTC'))
            ->setTimezone('UTC')
            ->addDay()
            ->startOfDay()
            ->addSeconds(max(0, (int) config('services.pixelforge.submission.daily_limit_buffer', 30)));
    }

    /**
     * Hold the generation until the daily quota resets. It stays queued, its credits
     * stay reserved, and a fresh job picks it up at the next UTC midnight.
     */
    protected function deferUntilQuotaResets(Generation $generation): void
    {
        $generation = $generation->fresh() ?? $generation;

        // Accepted or finished in the meantime: nothing left to submit.
        if ($generation->isFinal() || $generation->pixelforge_id !== null) {
            return;
        }

        $resumeAt = static::nextQuotaReset();

        $generation->update(['submit_after' => $resumeAt]);

        static::dispatch($this->generationId, $this->wave + 1)->delay($resumeAt);
    }

    /**
     * Called once the job has exhausted its attempts or thrown an unrecoverable error.
     */
    public function failed(?Throwable $exception): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null) {
            return;
        }

        $this->markFailed(
            $generation,
            'submission_failed',
            'We could not submit this generation to the image provider. You have not been charged.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Generation $generation): array
    {
        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'metadata' => [
                'app_generation_id' => $generation->id,
                'user_id' => (string) $generation->user_id,
            ],
        ];

        if (! empty($generation->reference_images)) {
            $payload['reference_images'] = $generation->reference_images;
        }

        return $payload;
    }

    /**
     * Put the job back on the queue, honouring Retry-After, or give up on the last attempt.
     */
    protected function retryLater(Generation $generation, PixelforgeException $e, int $retryAfter): void
    {
        if ($this->attempts() >= $this->tries) {
            $this->markFailed($generation, $e->errorCode ?? 'submission_failed', $e->getMessage());

            return;
        }

        $backoff = $this->backoff();
        $fallback = $backoff[min($this->attempts(), count($backoff)) - 1];

        $this->release(max($retryAfter, $fallback));
    }

    /**
     * Fail a generation that Pixelforge never accepted. It costs nothing.
     */
    protected function markFailed(Generation $generation, string $code, string $message): void
    {
        $generation = $generation->fresh() ?? $generation;

        // Pixelforge accepted it, so its webhooks (or polling) own the outcome now.
        if ($generation->isFinal() || $generation->pixelforge_id !== null) {
            return;
        }

        $generation->update([
            'status' => GenerationStatus::Failed,
            'error_code' => $code,
            'error_message' => $message,
            'actual_provider_cost' => '0.000000000',
            'actual_user_cost' => '0.000000000',
            'pixelforge_completed_at' => now(),
        ]);
    }
}
