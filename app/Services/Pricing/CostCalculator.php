<?php

namespace App\Services\Pricing;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;

class CostCalculator
{
    /** @var numeric-string */
    public const MARKUP_MULTIPLIER = '1.25';

    /** @var array<string, numeric-string> */
    public const DEFAULT_RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /**
     * @var array<string, array<string, int>>
     */
    public const OUTPUT_TOKENS = [
        'low' => [
            '1024x1024' => 280,
            '1536x1024' => 420,
            '1024x1536' => 420,
        ],
        'medium' => [
            '1024x1024' => 1100,
            '1536x1024' => 1650,
            '1024x1536' => 1650,
        ],
        'high' => [
            '1024x1024' => 4300,
            '1536x1024' => 6450,
            '1024x1536' => 6450,
        ],
    ];

    /**
     * @var array<string, array{min: int, max: int}>
     */
    public const REASONING_TOKENS = [
        'low' => ['min' => 0, 'max' => 500],
        'medium' => ['min' => 200, 'max' => 2000],
        'high' => ['min' => 1000, 'max' => 8000],
    ];

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
        $resolvedRates = array_merge(self::DEFAULT_RATES, $rates);

        /** @var numeric-string $textRate */
        $textRate = (string) $resolvedRates['input_text_tokens'];
        /** @var numeric-string $imageRate */
        $imageRate = (string) $resolvedRates['input_image_tokens'];
        /** @var numeric-string $outputRate */
        $outputRate = (string) $resolvedRates['output_tokens'];
        /** @var numeric-string $reasoningRate */
        $reasoningRate = (string) $resolvedRates['reasoning_tokens'];

        $textCost = $this->calculateTokenCost((int) ($usage['input_text_tokens'] ?? 0), $textRate);
        $imageCost = $this->calculateTokenCost((int) ($usage['input_image_tokens'] ?? 0), $imageRate);
        $outputCost = $this->calculateTokenCost((int) ($usage['output_tokens'] ?? 0), $outputRate);
        $reasoningCost = $this->calculateTokenCost((int) ($usage['reasoning_tokens'] ?? 0), $reasoningRate);

        $total = bcadd($textCost, $imageCost, 9);
        $total = bcadd($total, $outputCost, 9);

        return bcadd($total, $reasoningCost, 9);
    }

    /**
     * Calculate user charged cost by applying 25% markup to provider cost.
     *
     * @param  numeric-string  $providerCost  Exact decimal string
     * @return numeric-string Exact decimal string with 9 decimal places
     */
    public function calculateActualUserCost(string $providerCost): string
    {
        return bcmul($providerCost, self::MARKUP_MULTIPLIER, 9);
    }

    /**
     * Estimate token counts and price ranges for a prompt before generation.
     *
     * @param  array<int, string>  $referenceImages
     * @param  array<string, string>  $rates
     * @return array{
     *     tokens: array{
     *         estimated_text_tokens: int,
     *         min_text_tokens: int,
     *         max_text_tokens: int,
     *         image_tokens: int,
     *         output_tokens: int,
     *         min_reasoning_tokens: int,
     *         max_reasoning_tokens: int,
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
        array $referenceImages = [],
        array $rates = [],
    ): array {
        $sizeKey = $size instanceof GenerationSize ? $size->value : $size;
        $qualityKey = $quality instanceof GenerationQuality ? $quality->value : $quality;

        $resolvedRates = array_merge(self::DEFAULT_RATES, $rates);

        $charCount = mb_strlen($prompt);
        $baseTextTokens = (int) max(1, ceil($charCount / 4));
        $minTextTokens = (int) max(1, round($baseTextTokens * 0.85));
        $maxTextTokens = (int) max(1, round($baseTextTokens * 1.15));

        $imageTokens = count($referenceImages) * 765;

        $outputTokens = self::OUTPUT_TOKENS[$qualityKey][$sizeKey] ?? 1100;

        $reasoningRange = self::REASONING_TOKENS[$qualityKey] ?? ['min' => 200, 'max' => 2000];
        $minReasoningTokens = $reasoningRange['min'];
        $maxReasoningTokens = $reasoningRange['max'];
        $estimatedReasoningTokens = (int) round(($minReasoningTokens + $maxReasoningTokens) / 2);

        $minProviderCost = $this->calculateActualProviderCost([
            'input_text_tokens' => $minTextTokens,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $minReasoningTokens,
        ], $resolvedRates);

        $maxProviderCost = $this->calculateActualProviderCost([
            'input_text_tokens' => $maxTextTokens,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $maxReasoningTokens,
        ], $resolvedRates);

        $estimatedProviderCost = $this->calculateActualProviderCost([
            'input_text_tokens' => $baseTextTokens,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $estimatedReasoningTokens,
        ], $resolvedRates);

        return [
            'tokens' => [
                'estimated_text_tokens' => $baseTextTokens,
                'min_text_tokens' => $minTextTokens,
                'max_text_tokens' => $maxTextTokens,
                'image_tokens' => $imageTokens,
                'output_tokens' => $outputTokens,
                'min_reasoning_tokens' => $minReasoningTokens,
                'max_reasoning_tokens' => $maxReasoningTokens,
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
