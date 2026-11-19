<?php

namespace App\Services\Pricing;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;

class CostCalculator
{
    private const RATE_KEYS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * What users are charged per dollar Pixelforge charges us (services.pixelforge.markup_multiplier).
     * Kept as an exact decimal string. Never sent to the browser.
     *
     * @return numeric-string
     */
    public function markupMultiplier(): string
    {
        /** @var numeric-string $multiplier */
        $multiplier = trim((string) (config('services.pixelforge.markup_multiplier') ?? '1.25'));

        if (! preg_match('/^\d+(\.\d+)?$/', $multiplier) || bccomp($multiplier, '0', 9) <= 0) {
            throw new \InvalidArgumentException(
                "PIXELFORGE_MARKUP_MULTIPLIER must be a positive decimal number, got \"{$multiplier}\".",
            );
        }

        return $multiplier;
    }

    /**
     * Calculate exact provider cost from usage and rates.
     *
     * @param  array{
     *     input_text_tokens?: int,
     *     input_image_tokens?: int,
     *     output_tokens?: int,
     *     reasoning_tokens?: int,
     * }  $usage
     * @param  array<string, string|float>  $rates
     * @return numeric-string Exact decimal string with 9 decimal places
     */
    public function calculateActualProviderCost(array $usage, array $rates): string
    {
        // No silent defaults: a missing rate would mean charging the wrong amount.
        foreach (self::RATE_KEYS as $key) {
            if (! isset($rates[$key]) || ! is_numeric($rates[$key])) {
                throw new \InvalidArgumentException("Cannot price usage without a valid {$key} rate.");
            }
        }

        /** @var numeric-string $textRate */
        $textRate = (string) $rates['input_text_tokens'];
        /** @var numeric-string $imageRate */
        $imageRate = (string) $rates['input_image_tokens'];
        /** @var numeric-string $outputRate */
        $outputRate = (string) $rates['output_tokens'];
        /** @var numeric-string $reasoningRate */
        $reasoningRate = (string) $rates['reasoning_tokens'];

        $textCost = $this->calculateTokenCost((int) ($usage['input_text_tokens'] ?? 0), $textRate);
        $imageCost = $this->calculateTokenCost((int) ($usage['input_image_tokens'] ?? 0), $imageRate);
        $outputCost = $this->calculateTokenCost((int) ($usage['output_tokens'] ?? 0), $outputRate);
        $reasoningCost = $this->calculateTokenCost((int) ($usage['reasoning_tokens'] ?? 0), $reasoningRate);

        $total = bcadd($textCost, $imageCost, 9);
        $total = bcadd($total, $outputCost, 9);

        return bcadd($total, $reasoningCost, 9);
    }

    /**
     * Calculate the user's charge: the provider cost times the configured markup.
     *
     * @param  numeric-string  $providerCost  Exact decimal string
     * @return numeric-string Exact decimal string with 9 decimal places
     */
    public function calculateActualUserCost(string $providerCost): string
    {
        return bcmul($providerCost, $this->markupMultiplier(), 9);
    }

    /**
     * Estimate token counts and price ranges for a prompt before generation, using
     * the token table and rates Pixelforge publishes. For internal use: it contains
     * the provider's costs, which users must never see (see estimateForUser()).
     *
     * @param  array<int, string>  $referenceImages
     * @param  array{rates: array<string, string>, tokens: array<string, mixed>}  $pricing
     * @return array{
     *     tokens: array{
     *         estimated_text_tokens: int,
     *         min_text_tokens: int,
     *         max_text_tokens: int,
     *         image_tokens: int,
     *         output_tokens: int,
     *         min_reasoning_tokens: int,
     *         max_reasoning_tokens: int,
     *         estimated_reasoning_tokens: int,
     *     },
     *     provider: array{
     *         min_cost: numeric-string,
     *         max_cost: numeric-string,
     *         estimated_cost: numeric-string,
     *     },
     *     user: array{
     *         min_cost: numeric-string,
     *         max_cost: numeric-string,
     *         estimated_cost: numeric-string,
     *     },
     * }
     */
    public function estimate(
        string $prompt,
        GenerationSize|string $size,
        GenerationQuality|string $quality,
        array $referenceImages,
        array $pricing,
    ): array {
        $sizeKey = $size instanceof GenerationSize ? $size->value : $size;
        $qualityKey = $quality instanceof GenerationQuality ? $quality->value : $quality;

        $rates = $pricing['rates'];
        $table = $pricing['tokens'];

        $outputTokens = $table['output'][$qualityKey][$sizeKey] ?? null;
        $reasoningRange = $table['reasoning'][$qualityKey] ?? null;

        if ($outputTokens === null || $reasoningRange === null) {
            throw new \InvalidArgumentException("The pricing has no token estimate for {$qualityKey} {$sizeKey}.");
        }

        $charactersPerToken = max(1, (int) $table['characters_per_text_token']);
        $variance = (float) $table['text_token_variance'];

        $baseTextTokens = (int) max(1, ceil(mb_strlen($prompt) / $charactersPerToken));
        $minTextTokens = (int) max(1, round($baseTextTokens * (1 - $variance)));
        $maxTextTokens = (int) max(1, round($baseTextTokens * (1 + $variance)));

        $imageTokens = count($referenceImages) * (int) $table['per_reference_image'];
        $outputTokens = (int) $outputTokens;

        $minReasoningTokens = (int) $reasoningRange['min'];
        $maxReasoningTokens = (int) $reasoningRange['max'];
        $estimatedReasoningTokens = (int) round(($minReasoningTokens + $maxReasoningTokens) / 2);

        $costOf = fn (int $textTokens, int $reasoningTokens): string => $this->calculateActualProviderCost([
            'input_text_tokens' => $textTokens,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoningTokens,
        ], $rates);

        $minProviderCost = $costOf($minTextTokens, $minReasoningTokens);
        $maxProviderCost = $costOf($maxTextTokens, $maxReasoningTokens);
        $estimatedProviderCost = $costOf($baseTextTokens, $estimatedReasoningTokens);

        return [
            'tokens' => [
                'estimated_text_tokens' => $baseTextTokens,
                'min_text_tokens' => $minTextTokens,
                'max_text_tokens' => $maxTextTokens,
                'image_tokens' => $imageTokens,
                'output_tokens' => $outputTokens,
                'min_reasoning_tokens' => $minReasoningTokens,
                'max_reasoning_tokens' => $maxReasoningTokens,
                'estimated_reasoning_tokens' => $estimatedReasoningTokens,
            ],
            'provider' => [
                'min_cost' => $minProviderCost,
                'max_cost' => $maxProviderCost,
                'estimated_cost' => $estimatedProviderCost,
            ],
            'user' => [
                'min_cost' => $this->calculateActualUserCost($minProviderCost),
                'max_cost' => $this->calculateActualUserCost($maxProviderCost),
                'estimated_cost' => $this->calculateActualUserCost($estimatedProviderCost),
            ],
        ];
    }

    /**
     * The estimate as shown to the user: token counts and what they will be charged,
     * line by line. It carries no provider cost, rate or markup, so the markup can't
     * be worked out from it.
     *
     * @param  array<int, string>  $referenceImages
     * @param  array{rates: array<string, numeric-string>, tokens: array<string, mixed>}  $pricing
     * @return array{
     *     tokens: array{text: int, image: int, output: int, reasoning_min: int, reasoning_max: int},
     *     costs: array{
     *         text: numeric-string,
     *         image: numeric-string,
     *         output: numeric-string,
     *         reasoning_min: numeric-string,
     *         reasoning_max: numeric-string,
     *         min: numeric-string,
     *         typical: numeric-string,
     *         max: numeric-string,
     *     },
     * }
     */
    public function estimateForUser(
        string $prompt,
        GenerationSize|string $size,
        GenerationQuality|string $quality,
        array $referenceImages,
        array $pricing,
    ): array {
        $estimate = $this->estimate($prompt, $size, $quality, $referenceImages, $pricing);
        $tokens = $estimate['tokens'];
        $rates = $pricing['rates'];

        $userCostOf = fn (int $tokenCount, string $rateKey): string => $this->calculateActualUserCost(
            $this->calculateTokenCost($tokenCount, (string) $rates[$rateKey]),
        );

        return [
            'tokens' => [
                'text' => $tokens['estimated_text_tokens'],
                'image' => $tokens['image_tokens'],
                'output' => $tokens['output_tokens'],
                'reasoning_min' => $tokens['min_reasoning_tokens'],
                'reasoning_max' => $tokens['max_reasoning_tokens'],
            ],
            'costs' => [
                'text' => $userCostOf($tokens['estimated_text_tokens'], 'input_text_tokens'),
                'image' => $userCostOf($tokens['image_tokens'], 'input_image_tokens'),
                'output' => $userCostOf($tokens['output_tokens'], 'output_tokens'),
                'reasoning_min' => $userCostOf($tokens['min_reasoning_tokens'], 'reasoning_tokens'),
                'reasoning_max' => $userCostOf($tokens['max_reasoning_tokens'], 'reasoning_tokens'),
                'min' => $estimate['user']['min_cost'],
                'typical' => $estimate['user']['estimated_cost'],
                'max' => $estimate['user']['max_cost'],
            ],
        ];
    }

    /**
     * Calculate cost for a single token type.
     * Formula: (tokenCount * ratePer1M) / 1,000,000
     *
     * @param  numeric-string  $ratePer1M
     * @return numeric-string
     */
    private function calculateTokenCost(int $tokenCount, string $ratePer1M): string
    {
        if ($tokenCount <= 0) {
            return '0.000000000';
        }

        // Multiply token count by rate with precision
        $product = bcmul((string) $tokenCount, $ratePer1M, 9);

        // Divide by 1,000,000
        return bcdiv($product, '1000000', 9);
    }
}
