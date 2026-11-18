<?php

namespace App\Http\Controllers;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Exceptions\PricingUnavailableException;
use App\Services\Pricing\CostCalculator;
use App\Services\Pricing\PricingProvider;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationIndexController extends Controller
{
    public function __construct(
        protected PricingProvider $pricingProvider,
        protected CostCalculator $costCalculator,
    ) {}

    /**
     * Show dashboard with generation form and generation history.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $generations = $user->generations()
            ->latest()
            ->paginate(15)
            ->withQueryString();


        $hasActive = $user->generations()
            ->whereIn('status', GenerationStatus::pending())
            ->exists();

        return Inertia::render('Dashboard', [
            'generations' => $generations,
            'balance' => (string) $user->balance_usd,
            'availableBalance' => $user->availableBalance(),
            'heldBalance' => $user->reservedBalance(),
            // Estimate for the form's starting values, so it isn't blank on first paint.
            'initialEstimate' => $this->initialEstimate(),
            'hasActiveGenerations' => $hasActive,
            'sizes' => array_map(fn (GenerationSize $size) => [
                'value' => $size->value,
                'label' => $size->label(),
                'aspectRatio' => $size->aspectRatio(),
            ], GenerationSize::cases()),
            'qualities' => array_map(fn (GenerationQuality $q) => [
                'value' => $q->value,
                'label' => $q->label(),
            ], GenerationQuality::cases()),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function initialEstimate(): ?array
    {
        try {
            return $this->costCalculator->estimateForUser(
                prompt: '',
                size: GenerationSize::Square,
                quality: GenerationQuality::Medium,
                referenceImages: [],
                pricing: $this->pricingProvider->current(),
            );
        } catch (PricingUnavailableException) {
            return null;
        }
    }
}
