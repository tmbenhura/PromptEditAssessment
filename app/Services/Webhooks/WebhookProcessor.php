<?php

namespace App\Services\Webhooks;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\Transaction;
use App\Models\WebhookEvent;
use App\Services\Pricing\CostCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class WebhookProcessor
{
    public function __construct(
        protected CostCalculator $costCalculator,
    ) {}

    /**
     * Verify Pixelforge webhook signature.
     *
     * @param  string  $signatureHeader  e.g. "t=1762265009,v1=5257a869..."
     * @param  string  $rawBody  The raw HTTP request body
     * @param  string  $secret  The webhook signing secret
     */
    public function verifySignature(string $signatureHeader, string $rawBody, string $secret): bool
    {
        if ($signatureHeader === '' || $secret === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $signature = (string) ($parts['v1'] ?? '');

        if ($timestamp <= 0 || $signature === '') {
            return false;
        }

        // Reject timestamps older or newer than 300 seconds to prevent replay attacks
        if (abs(time() - $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Record and process a webhook in one go (what the controller does in two steps,
     * with the processing deferred until after the response).
     *
     * @param  array<string, mixed>  $payload
     * @return bool True if processed, false if deduplicated or skipped
     */
    public function process(array $payload, ?int $deliveryAttempt = null): bool
    {
        $event = $this->receive($payload, $deliveryAttempt);

        return $event !== null && $this->processEvent($event);
    }

    /**
     * Step one, quick and synchronous: durably log the delivery before acknowledging it.
     *
     * Every authenticated delivery gets a row in webhook_events (one per event id;
     * redeliveries bump delivery_count and remember Pixelforge's attempt number).
     *
     * @param  array<string, mixed>  $payload
     * @return WebhookEvent|null The event still to be processed, or null if there is
     *                           nothing (more) to do, e.g. a redelivery of a handled event
     */
    public function receive(array $payload, ?int $deliveryAttempt = null): ?WebhookEvent
    {
        $eventId = (string) ($payload['id'] ?? '');
        $eventType = (string) ($payload['type'] ?? '');

        if ($eventId === '') {
            // Nothing to dedupe on, but still worth keeping a record of.
            WebhookEvent::create([
                'event_id' => 'missing-'.Str::uuid(),
                'event_type' => $eventType,
                'payload' => $payload,
                'outcome' => WebhookEvent::OUTCOME_IGNORED,
                'processed_at' => now(),
            ])->forceFill([
                'delivery_attempt' => $deliveryAttempt,
                'last_received_at' => now(),
            ])->save();

            return null;
        }

        return DB::transaction(function () use ($eventId, $eventType, $payload, $deliveryAttempt): ?WebhookEvent {
            $existing = WebhookEvent::query()->where('event_id', $eventId)->lockForUpdate()->first();

            if ($existing !== null) {
                $existing->forceFill([
                    'delivery_count' => $existing->delivery_count + 1,
                    'delivery_attempt' => $deliveryAttempt ?? $existing->delivery_attempt,
                    'last_received_at' => now(),
                ])->save();

                // A redelivery is only worth processing if the first try never finished.
                return $existing->isUnfinished() ? $existing : null;
            }

            $event = WebhookEvent::create([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'payload' => $payload,
                'outcome' => WebhookEvent::OUTCOME_RECEIVED,
            ]);

            $event->forceFill([
                'delivery_count' => 1,
                'delivery_attempt' => $deliveryAttempt,
                'last_received_at' => now(),
            ])->save();

            return $event;
        });
    }

    /**
     * Step two: apply a logged event. Safe to run twice, concurrently or later (for
     * example when replaying a failed event): the event row is locked and only an
     * unfinished event is applied.
     *
     * @return bool True if it was applied, false if it was already handled or skipped
     *
     * @throws Throwable After recording the failure on the event
     */
    public function processEvent(WebhookEvent $event): bool
    {
        try {
            return DB::transaction(function () use ($event): bool {
                $locked = WebhookEvent::query()->whereKey($event->id)->lockForUpdate()->first();

                if ($locked === null || ! $locked->isUnfinished()) {
                    return false;
                }

                $payload = (array) $locked->payload;
                $generationData = $payload['data']['object'] ?? null;

                $locked->forceFill(['error' => null, 'processed_at' => now()]);

                if ($locked->event_type === 'ping' || ! is_array($generationData)) {
                    $locked->forceFill(['outcome' => WebhookEvent::OUTCOME_IGNORED])->save();

                    return true;
                }

                $pixelforgeId = (string) ($generationData['id'] ?? '');
                $appGenerationId = $generationData['metadata']['app_generation_id'] ?? null;

                // Find the generation by Pixelforge ID, or by the id we sent as metadata
                // (the event can arrive before our create request has returned).
                $generation = ($pixelforgeId === '' && $appGenerationId === null)
                    ? null
                    : Generation::query()
                        ->where(function ($query) use ($pixelforgeId, $appGenerationId): void {
                            if ($pixelforgeId !== '') {
                                $query->where('pixelforge_id', $pixelforgeId);
                            }
                            if ($appGenerationId !== null) {
                                $query->orWhere('id', (string) $appGenerationId);
                            }
                        })
                        ->lockForUpdate()
                        ->first();

                if (! $generation) {
                    Log::warning("Pixelforge webhook received for unknown generation: {$pixelforgeId}");
                    $locked->forceFill(['outcome' => WebhookEvent::OUTCOME_IGNORED])->save();

                    return true;
                }

                $this->applyGenerationUpdate($generation, $generationData);

                $locked->forceFill([
                    'outcome' => WebhookEvent::OUTCOME_PROCESSED,
                    'generation_id' => $generation->id,
                ])->save();

                return true;
            });
        } catch (Throwable $e) {
            // The transaction rolled back and left the event unfinished. Record why, so
            // it can be replayed (generations:reconcile does, and so does a redelivery).
            WebhookEvent::query()->whereKey($event->id)->update([
                'outcome' => WebhookEvent::OUTCOME_FAILED,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Apply generation update according to state machine rules.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyGenerationUpdate(Generation $generation, array $data): void
    {
        $newStatusString = (string) ($data['status'] ?? '');
        $newStatus = GenerationStatus::tryFrom($newStatusString);

        if (! $newStatus) {
            return;
        }

        // Pixelforge ID assignment if missing
        if (! $generation->pixelforge_id && ! empty($data['id'])) {
            $generation->pixelforge_id = (string) $data['id'];
        }

        // Enforce state machine transitions
        // Never move back from succeeded or failed
        if ($generation->status->isFinal()) {
            return;
        }

        if ($newStatus === GenerationStatus::Processing) {
            if ($generation->status === GenerationStatus::Queued) {
                $generation->status = GenerationStatus::Processing;
                $generation->pixelforge_started_at = ! empty($data['started_at']) ? Carbon::parse((string) $data['started_at']) : now();
                $generation->save();
            }

            return;
        }

        if ($newStatus === GenerationStatus::Succeeded) {
            $usage = (array) ($data['usage'] ?? []);
            $rates = (array) ($data['rates'] ?? $generation->rates ?? []);

            $providerCost = $this->costCalculator->calculateActualProviderCost($usage, $rates);
            $userCost = $this->costCalculator->calculateActualUserCost($providerCost);

            // Marking the generation succeeded releases its reservation, and charging
            // the user deducts the real cost. They must commit together, otherwise a
            // concurrent request could see the credits as free between the two writes.
            DB::transaction(function () use ($generation, $data, $usage, $rates, $providerCost, $userCost): void {
                // Re-check under a lock so webhook and polling can't both settle it.
                $current = Generation::query()->whereKey($generation->id)->lockForUpdate()->first();

                if ($current === null || $current->status->isFinal()) {
                    return;
                }

                $generation->status = GenerationStatus::Succeeded;
                $generation->image_url = $data['image_url'] ?? null;
                $generation->image_url_expires_at = ! empty($data['image_url_expires_at']) ? Carbon::parse((string) $data['image_url_expires_at']) : null;
                $generation->usage = $usage;
                $generation->rates = $rates;
                $generation->actual_provider_cost = $providerCost;
                $generation->actual_user_cost = $userCost;
                $generation->pixelforge_started_at = ! empty($data['started_at']) ? Carbon::parse((string) $data['started_at']) : $generation->pixelforge_started_at;
                $generation->pixelforge_completed_at = ! empty($data['completed_at']) ? Carbon::parse((string) $data['completed_at']) : now();
                $generation->save();

                // Deduct user balance exactly once
                $this->chargeUserForGeneration($generation, $userCost);
            });

            return;
        }

        if ($newStatus === GenerationStatus::Failed) {
            $generation->status = GenerationStatus::Failed;
            $generation->error_code = $data['error']['code'] ?? 'generation_failed';
            $generation->error_message = $data['error']['message'] ?? 'Generation failed';
            $generation->actual_provider_cost = '0.000000000';
            $generation->actual_user_cost = '0.000000000';
            $generation->pixelforge_started_at = ! empty($data['started_at']) ? Carbon::parse((string) $data['started_at']) : $generation->pixelforge_started_at;
            $generation->pixelforge_completed_at = ! empty($data['completed_at']) ? Carbon::parse((string) $data['completed_at']) : now();
            $generation->save();
        }
    }

    /**
     * Charge user balance for succeeded generation (prevent duplicate charges).
     *
     * @param  numeric-string  $amountUsd
     */
    protected function chargeUserForGeneration(Generation $generation, string $amountUsd): void
    {
        // Check if transaction already exists for this generation
        $alreadyCharged = Transaction::query()
            ->where('generation_id', $generation->id)
            ->where('type', 'generation_charge')
            ->exists();

        if ($alreadyCharged) {
            return;
        }

        $user = $generation->user;
        $deductAmount = bcmul($amountUsd, '-1', 9);

        $user->adjustBalance(
            amountUsd: $deductAmount,
            type: 'generation_charge',
            generation: $generation,
            description: "Generation charge for {$generation->size->value} {$generation->quality->value} image",
            // Billed on the day Pixelforge completed it, which can differ from when the
            // webhook (or a poll) reached us, e.g. across UTC midnight.
            occurredAt: $generation->pixelforge_completed_at,
        );
    }
}
