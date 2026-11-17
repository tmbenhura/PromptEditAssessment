<?php

namespace App\Services\Generation;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Exceptions\InsufficientBalanceException;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pricing\CostCalculator;
use App\Services\Webhooks\WebhookProcessor;
use Illuminate\Support\Str;

class GenerationService
{
    public function __construct(
        protected PixelforgeClient $pixelforgeClient,
        protected CostCalculator $costCalculator,
        protected WebhookProcessor $webhookProcessor,
    ) {}

    /**
     * Record a new image generation and queue its submission to Pixelforge.
     *
     * @param  array{
     *     prompt: string,
     *     size?: string,
     *     quality?: string,
     *     reference_images?: array<int, string>,
     * }  $data
     */
    public function create(User $user, array $data): Generation
    {
        $prompt = trim($data['prompt']);
        $size = GenerationSize::tryFrom($data['size'] ?? '1024x1024') ?? GenerationSize::Square;
        $quality = GenerationQuality::tryFrom($data['quality'] ?? 'medium') ?? GenerationQuality::Medium;
        $referenceImages = array_values(array_filter($data['reference_images'] ?? [], fn ($url) => ! empty($url)));

        // Fetch current rates from Pixelforge (cached)
        $pricing = $this->pixelforgeClient->getPricing();
        $rates = (array) ($pricing['rates'] ?? CostCalculator::DEFAULT_RATES);

        // Estimate token counts and prices
        $estimates = $this->costCalculator->estimate(
            prompt: $prompt,
            size: $size,
            quality: $quality,
            referenceImages: $referenceImages,
            rates: $rates,
        );

        $maxEstimatedUserCost = $estimates['user']['max_cost'];

        // Enforce credit balance check
        if (! $user->hasSufficientBalance($maxEstimatedUserCost)) {
            throw new InsufficientBalanceException(
                message: "You need at least \${$maxEstimatedUserCost} credits for this generation, but your current balance is \${$user->balance_usd}.",
                requiredAmount: $maxEstimatedUserCost,
                currentBalance: $user->balance_usd,
            );
        }

        $generationId = (string) Str::uuid();
        $idempotencyKey = (string) Str::uuid();

        $generation = Generation::create([
            'id' => $generationId,
            'idempotency_key' => $idempotencyKey,
            'user_id' => $user->id,
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $referenceImages,
            'status' => GenerationStatus::Queued,
            'rates' => $rates,
            'estimated_provider_cost' => $estimates['provider']['estimated_cost'],
            'estimated_user_cost' => $estimates['user']['estimated_cost'],
            'actual_provider_cost' => '0.000000000',
            'actual_user_cost' => '0.000000000',
        ]);

        // Submitting to Pixelforge happens in a queued job so the request returns
        // immediately and rate limits or overloads are retried off the request cycle.
        SubmitGeneration::dispatch($generation->id)->afterCommit();

        return $generation;
    }

    /**
     * Poll and synchronize generation status from Pixelforge.
     */
    public function sync(Generation $generation): Generation
    {
        if ($generation->isFinal() || ! $generation->pixelforge_id) {
            return $generation;
        }

        try {
            $data = $this->pixelforgeClient->getGeneration($generation->pixelforge_id);
            $this->webhookProcessor->applyGenerationUpdate($generation, $data);

            return $generation->fresh();
        } catch (PixelforgeException) {
            return $generation;
        }
    }
}
