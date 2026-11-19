<?php

namespace App\Services\Pricing;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Exceptions\PricingUnavailableException;
use App\Models\PricingSnapshot;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The pricing used for estimates and credit reservations.
 *
 * Fresh from Pixelforge when possible (cached briefly) and saved whenever it
 * changes. If Pixelforge can't be reached, the last pricing it confirmed is used
 * instead of made-up defaults; if there never was one, it says so.
 *
 * Generations are still billed at the rates Pixelforge fixed for each of them
 * (returned when it accepted them), so this only affects estimates and the hold.
 */
class PricingProvider
{
    public const CACHE_KEY = 'pixelforge_pricing';

    private const RATE_KEYS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function __construct(
        protected PixelforgeClient $pixelforgeClient,
    ) {}

    /**
     * @return array{rates: array<string, numeric-string>, tokens: array<string, mixed>}
     *
     * @throws PricingUnavailableException
     */
    public function current(): array
    {
        /** @var array{rates: array<string, numeric-string>, tokens: array<string, mixed>}|null $cached */
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $pricing = $this->validated($this->pixelforgeClient->getPricing());
        } catch (PixelforgeException|ConnectionException|\UnexpectedValueException $e) {
            return $this->lastKnown($e);
        }

        $this->remember($pricing);
        Cache::put(self::CACHE_KEY, $pricing, (int) config('services.pixelforge.pricing_cache_seconds', 600));

        return $pricing;
    }

    /**
     * @return array{rates: array<string, numeric-string>, tokens: array<string, mixed>}
     */
    private function lastKnown(\Throwable $reason): array
    {
        $snapshot = PricingSnapshot::latestKnown();

        if ($snapshot === null) {
            throw new PricingUnavailableException(previous: $reason);
        }

        Log::warning('Pixelforge pricing unavailable, using the last known pricing.', [
            'last_confirmed_at' => $snapshot->fetched_at->toIso8601String(),
            'reason' => $reason->getMessage(),
        ]);

        /** @var array{rates: array<string, numeric-string>, tokens: array<string, mixed>} $pricing */
        $pricing = ['rates' => $snapshot->rates, 'tokens' => $snapshot->tokens];

        // Don't retry the API on every request while it is down.
        Cache::put(self::CACHE_KEY, $pricing, 60);

        return $pricing;
    }

    /**
     * Save the pricing if it differs from the last stored one, otherwise just note
     * that it was confirmed again.
     *
     * @param  array{rates: array<string, numeric-string>, tokens: array<string, mixed>}  $pricing
     */
    private function remember(array $pricing): void
    {
        $latest = PricingSnapshot::latestKnown();

        if ($latest !== null && $latest->rates === $pricing['rates'] && $latest->tokens === $pricing['tokens']) {
            $latest->forceFill(['fetched_at' => now()])->save();

            return;
        }

        PricingSnapshot::create([
            'rates' => $pricing['rates'],
            'tokens' => $pricing['tokens'],
            'fetched_at' => now(),
        ]);
    }

    /**
     * Only trust a response that has everything the calculator needs.
     *
     * @param  array<string, mixed>  $payload
     * @return array{rates: array<string, numeric-string>, tokens: array<string, mixed>}
     *
     * @throws \UnexpectedValueException
     */
    private function validated(array $payload): array
    {
        $rates = $payload['rates'] ?? null;
        $tokens = $payload['tokens'] ?? null;

        if (! is_array($rates) || ! is_array($tokens)) {
            throw new \UnexpectedValueException('The pricing response has no rates or token table.');
        }

        foreach (self::RATE_KEYS as $key) {
            if (! isset($rates[$key]) || ! is_numeric($rates[$key])) {
                throw new \UnexpectedValueException("The pricing response has no valid {$key} rate.");
            }
        }

        foreach (['characters_per_text_token', 'text_token_variance', 'per_reference_image', 'output', 'reasoning'] as $key) {
            if (! isset($tokens[$key])) {
                throw new \UnexpectedValueException("The pricing response has no {$key} in its token table.");
            }
        }

        // Every quality and size we offer needs an estimate, or a user could pick one
        // that can't be priced.
        foreach (GenerationQuality::cases() as $quality) {
            if (! isset($tokens['reasoning'][$quality->value]['min'], $tokens['reasoning'][$quality->value]['max'])) {
                throw new \UnexpectedValueException("The pricing response has no reasoning range for {$quality->value} quality.");
            }

            foreach (GenerationSize::cases() as $size) {
                if (! isset($tokens['output'][$quality->value][$size->value])) {
                    throw new \UnexpectedValueException("The pricing response has no output tokens for {$quality->value} {$size->value}.");
                }
            }
        }

        /** @var array<string, numeric-string> $rates */

        return [
            'rates' => array_map('strval', array_intersect_key($rates, array_flip(self::RATE_KEYS))),
            'tokens' => $tokens,
        ];
    }
}
