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
     * Process an incoming webhook payload idempotently.
     *
     * @param  array<string, mixed>  $payload
     * @return bool True if processed, false if deduplicated or skipped
     */
    public function process(array $payload): bool
    {
        $eventId = (string) ($payload['id'] ?? '');
        $eventType = (string) ($payload['type'] ?? '');

        if ($eventId === '') {
            return false;
        }

        if ($eventType === 'ping') {
            return true;
        }

        // Deduplicate using WebhookEvent table inside transaction
        return DB::transaction(function () use ($eventId, $eventType, $payload) {
            $existing = WebhookEvent::query()->where('event_id', $eventId)->lockForUpdate()->first();
            if ($existing !== null) {
                // Event already received and processed
                return false;
            }

            WebhookEvent::create([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'payload' => $payload,
                'processed_at' => now(),
            ]);

            $generationData = $payload['data']['object'] ?? null;
            if (! is_array($generationData)) {
                return true;
            }

            $pixelforgeId = (string) ($generationData['id'] ?? '');
            $appGenerationId = $generationData['metadata']['app_generation_id'] ?? null;

            // Find generation by Pixelforge ID or metadata app_generation_id
            $query = Generation::query();
            if ($pixelforgeId !== '') {
                $query->where('pixelforge_id', $pixelforgeId);
            }
            if ($appGenerationId !== null) {
                $query->orWhere('id', (string) $appGenerationId);
            }

            $generation = $query->lockForUpdate()->first();

            if (! $generation) {
                // Generation not found in DB yet (e.g. webhook arrived before POST returned)
                // We will create the placeholder or store it
                Log::warning("Pixelforge webhook received for unknown generation: {$pixelforgeId}");

                return true;
            }

            $this->applyGenerationUpdate($generation, $generationData);

            return true;
        });
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
        );
    }
}
