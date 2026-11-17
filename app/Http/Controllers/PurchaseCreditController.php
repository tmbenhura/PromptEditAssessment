<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseCreditRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class PurchaseCreditController extends Controller
{
    /**
     * Add test credits to user balance.
     */
    public function __invoke(PurchaseCreditRequest $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        $amount = $request->validated('amount');
        $amountUsd = number_format((float) $amount, 9, '.', '');

        $user->adjustBalance(
            amountUsd: $amountUsd,
            type: 'purchase',
            description: "Purchased \${$amount} credits (Test transaction)",
        );

        if ($request->wantsJson()) {
            return response()->json([
                'message' => "Successfully added \${$amount} credits.",
                'balance' => (string) $user->balance_usd,
            ]);
        }

        return back()->with('success', "Successfully added \${$amount} credits!");
    }
}
