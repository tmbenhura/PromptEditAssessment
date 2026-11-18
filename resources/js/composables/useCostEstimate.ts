import type { MaybeRefOrGetter } from 'vue';
import { computed, toValue } from 'vue';
import type {
    CostEstimate,
    ImageQuality,
    ImageSize,
    TokenRates,
} from '@/types';

/** Mirrors the server-side CostCalculator; the server remains the source of truth. */
const USER_MARKUP = 1.25;
const CHARACTERS_PER_PROMPT_TOKEN = 4;
const TOKENS_PER_REFERENCE_IMAGE = 765;
const PROMPT_TOKEN_SPREAD = 0.15;
const TOKENS_PER_MILLION = 1_000_000;

const DEFAULT_RATES = {
    input_text_tokens: 5,
    input_image_tokens: 10,
    output_tokens: 40,
    reasoning_tokens: 12.5,
};

const OUTPUT_TOKENS: Record<ImageQuality, Record<ImageSize, number>> = {
    low: { '1024x1024': 280, '1536x1024': 420, '1024x1536': 420 },
    medium: { '1024x1024': 1100, '1536x1024': 1650, '1024x1536': 1650 },
    high: { '1024x1024': 4300, '1536x1024': 6450, '1024x1536': 6450 },
};

const REASONING_TOKENS: Record<ImageQuality, { min: number; max: number }> = {
    low: { min: 0, max: 500 },
    medium: { min: 200, max: 2000 },
    high: { min: 1000, max: 8000 },
};

export interface CostEstimateInput {
    prompt: MaybeRefOrGetter<string>;
    size: MaybeRefOrGetter<string>;
    quality: MaybeRefOrGetter<string>;
    referenceImageCount: MaybeRefOrGetter<number>;
    rates: MaybeRefOrGetter<TokenRates>;
}

/**
 * Live, client-side estimate of what a generation will cost the user.
 */
export function useCostEstimate(input: CostEstimateInput) {
    const estimate = computed<CostEstimate>(() => {
        const rates = toValue(input.rates);
        const quality = toValue(input.quality) as ImageQuality;
        const size = toValue(input.size) as ImageSize;

        // User price of one token of each kind.
        const priceOf = (rateKey: keyof typeof DEFAULT_RATES): number =>
            (Number(rates[rateKey] || DEFAULT_RATES[rateKey]) /
                TOKENS_PER_MILLION) *
            USER_MARKUP;

        const promptTokens = Math.max(
            1,
            Math.ceil(
                toValue(input.prompt).length / CHARACTERS_PER_PROMPT_TOKEN,
            ),
        );
        const promptTokensMin = Math.max(
            1,
            Math.round(promptTokens * (1 - PROMPT_TOKEN_SPREAD)),
        );
        const promptTokensMax = Math.max(
            1,
            Math.round(promptTokens * (1 + PROMPT_TOKEN_SPREAD)),
        );

        const referenceImageTokens =
            toValue(input.referenceImageCount) * TOKENS_PER_REFERENCE_IMAGE;
        const outputTokens = OUTPUT_TOKENS[quality]?.[size] ?? 1100;
        const reasoning = REASONING_TOKENS[quality] ?? { min: 200, max: 2000 };

        const referenceImageCost =
            referenceImageTokens * priceOf('input_image_tokens');
        const outputCost = outputTokens * priceOf('output_tokens');
        const reasoningPrice = priceOf('reasoning_tokens');
        const promptPrice = priceOf('input_text_tokens');

        const costWith = (promptTokenCount: number, reasoningTokenCount: number) =>
            promptTokenCount * promptPrice +
            referenceImageCost +
            outputCost +
            reasoningTokenCount * reasoningPrice;

        return {
            promptTokens,
            referenceImageTokens,
            outputTokens,
            reasoningTokensMin: reasoning.min,
            reasoningTokensMax: reasoning.max,
            promptCost: promptTokens * promptPrice,
            referenceImageCost,
            outputCost,
            reasoningCostMin: reasoning.min * reasoningPrice,
            reasoningCostMax: reasoning.max * reasoningPrice,
            minCost: costWith(promptTokensMin, reasoning.min),
            typicalCost: costWith(
                promptTokens,
                (reasoning.min + reasoning.max) / 2,
            ),
            maxCost: costWith(promptTokensMax, reasoning.max),
        };
    });

    return { estimate };
}
