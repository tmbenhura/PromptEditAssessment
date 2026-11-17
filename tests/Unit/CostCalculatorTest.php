<?php

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Services\Pricing\CostCalculator;

test('it calculates exact provider cost from worked example in docs', function () {
    $calculator = new CostCalculator;

    $usage = [
        'input_text_tokens' => 115,
        'input_image_tokens' => 1530,
        'output_tokens' => 6450,
        'reasoning_tokens' => 8000,
    ];

    $rates = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    $providerCost = $calculator->calculateActualProviderCost($usage, $rates);
    expect($providerCost)->toBe('0.373875000');

    $userCost = $calculator->calculateActualUserCost($providerCost);
    expect($userCost)->toBe('0.467343750');
});

test('it calculates exact cost for succeeded generation from api docs', function () {
    $calculator = new CostCalculator;

    // From section "A succeeded generation" in docs:
    // input_text_tokens: 12
    // input_image_tokens: 765
    // output_tokens: 6450
    // reasoning_tokens: 3184
    // rates: 5.00, 10.00, 40.00, 12.50
    // 12 * 5 / 1M = 0.000060000
    // 765 * 10 / 1M = 0.007650000
    // 6450 * 40 / 1M = 0.258000000
    // 3184 * 12.5 / 1M = 0.039800000
    // Total = 0.305510000
    $usage = [
        'input_text_tokens' => 12,
        'input_image_tokens' => 765,
        'output_tokens' => 6450,
        'reasoning_tokens' => 3184,
    ];

    $rates = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    $providerCost = $calculator->calculateActualProviderCost($usage, $rates);
    expect($providerCost)->toBe('0.305510000');

    $userCost = $calculator->calculateActualUserCost($providerCost);
    // 0.305510000 * 1.25 = 0.381887500
    expect($userCost)->toBe('0.381887500');
});

test('it estimates token counts and prices correctly', function () {
    $calculator = new CostCalculator;

    $estimate = $calculator->estimate(
        prompt: str_repeat('a', 400), // 400 chars -> 100 base text tokens
        size: GenerationSize::Landscape, // 1536x1024
        quality: GenerationQuality::High,
        referenceImages: ['https://example.com/1.png', 'https://example.com/2.png'], // 2 images -> 1530 tokens
    );

    expect($estimate['tokens']['estimated_text_tokens'])->toBe(100)
        ->and($estimate['tokens']['min_text_tokens'])->toBe(85)
        ->and($estimate['tokens']['max_text_tokens'])->toBe(115)
        ->and($estimate['tokens']['image_tokens'])->toBe(1530)
        ->and($estimate['tokens']['output_tokens'])->toBe(6450)
        ->and($estimate['tokens']['min_reasoning_tokens'])->toBe(1000)
        ->and($estimate['tokens']['max_reasoning_tokens'])->toBe(8000);

    // Max cost should match doc worked example
    expect($estimate['provider']['max_cost'])->toBe('0.373875000')
        ->and($estimate['user']['max_cost'])->toBe('0.467343750');
});
