<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\Generation\GenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GenerationStatusController extends Controller
{
    public function __construct(
        protected GenerationService $generationService,
    ) {}

    /**
     * Get live status of a single generation.
     */
    public function __invoke(Request $request, Generation $generation): JsonResponse
    {
        if ($generation->user_id !== $request->user()->id) {
            abort(403);
        }

        if (! $generation->isFinal()) {
            $this->generationService->sync($generation);
            $generation->refresh();
        }

        return response()->json($generation);
    }
}
