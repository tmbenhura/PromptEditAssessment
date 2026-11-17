<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Requests\StoreGenerationRequest;
use App\Models\Generation;
use App\Services\Generation\GenerationService;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\Exceptions\PixelforgePolicyViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class GenerationStoreController extends Controller
{
    public function __construct(
        protected GenerationService $generationService,
    ) {}

    /**
     * Store and dispatch a new generation request.
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
                ], 201);
            }

            return back()->with('success', 'Generation created and queued!');
        } catch (InsufficientBalanceException $e) {
            if ($request->wantsJson()) {
                return response()->json(['error' => $e->getMessage()], 402);
            }

            return back()->withErrors(['balance' => $e->getMessage()]);
        } catch (PixelforgePolicyViolationException $e) {
            if ($request->wantsJson()) {
                return response()->json(['error' => $e->getMessage()], 400);
            }

            return back()->withErrors(['prompt' => $e->getMessage()]);
        } catch (PixelforgeException $e) {
            if ($request->wantsJson()) {
                return response()->json(['error' => $e->getMessage()], (int) ($e->getCode() ?: 500));
            }

            return back()->withErrors(['general' => $e->getMessage()]);
        }
    }
}
