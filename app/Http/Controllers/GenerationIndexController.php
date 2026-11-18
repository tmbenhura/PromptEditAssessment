<?php

namespace App\Http\Controllers;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationIndexController extends Controller
{
    public function __construct(
        protected PixelforgeClient $pixelforgeClient,
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

        $rates = $this->pixelforgeClient->getRates();

        $hasActive = $user->generations()
            ->whereIn('status', GenerationStatus::pending())
            ->exists();

        return Inertia::render('Dashboard', [
            'generations' => $generations,
            'balance' => (string) $user->balance_usd,
            'availableBalance' => $user->availableBalance(),
            'heldBalance' => $user->reservedBalance(),
            'rates' => $rates,
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
}
