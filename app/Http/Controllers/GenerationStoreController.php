<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\PricingUnavailableException;
use App\Http\Requests\StoreGenerationRequest;
use App\Services\Generation\GenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class GenerationStoreController extends Controller
{
    public function __construct(
        protected GenerationService $generationService,
    ) {}

    /**
     * Record a new generation and queue it for submission to Pixelforge.
     */
    public function __invoke(StoreGenerationRequest $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();

        /** @var array{prompt: string, size?: string, quality?: string, reference_images?: array<int, string>} $data */
        $data = $request->validated();

        try {
            $generation = $this->generationService->create($user, $data);

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Generation queued successfully.',
                    'generation' => $generation,
                ], 202);
            }

            return back()->with('success', 'Generation created and queued!');
        } catch (InsufficientBalanceException $e) {
            if ($request->wantsJson()) {
                return response()->json(['error' => $e->getMessage()], 402);
            }

            return back()->withErrors(['balance' => $e->getMessage()]);
        } catch (PricingUnavailableException $e) {
            if ($request->wantsJson()) {
                return response()->json(['error' => $e->getMessage()], 503);
            }

            return back()->withErrors(['general' => $e->getMessage()]);
        }
    }
}
