<?php

namespace App\Http\Controllers;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GenerationEstimateController extends Controller
{
    public function __construct(
        protected CostCalculator $costCalculator,
        protected PixelforgeClient $pixelforgeClient,
    ) {}

    /**
     * Calculate upfront cost estimate without creating a generation.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => ['nullable', 'string', 'max:4000'],
            'size' => ['nullable', 'string'],
            'quality' => ['nullable', 'string'],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['nullable', 'url:http,https'],
        ]);

        $prompt = (string) ($validated['prompt'] ?? '');
        $size = GenerationSize::tryFrom($validated['size'] ?? '1024x1024') ?? GenerationSize::Square;
        $quality = GenerationQuality::tryFrom($validated['quality'] ?? 'medium') ?? GenerationQuality::Medium;
        $referenceImages = array_values(array_filter($validated['reference_images'] ?? [], fn ($url) => ! empty($url)));

        $rates = $this->pixelforgeClient->getRates();

        $estimate = $this->costCalculator->estimate(
            prompt: $prompt,
            size: $size,
            quality: $quality,
            referenceImages: $referenceImages,
            rates: $rates,
        );

        return response()->json($estimate);
    }
}
