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

/**
 * Pixelforge's published pricing, as in the API docs.
 *
 * @return array{rates: array<string, string>, tokens: array<string, mixed>}
 */
function publishedPricing(): array
{
    return [
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];
}

test('it estimates token counts and prices correctly', function () {
    $calculator = new CostCalculator;

    $estimate = $calculator->estimate(
        prompt: str_repeat('a', 400), // 400 chars -> 100 base text tokens
        size: GenerationSize::Landscape, // 1536x1024
        quality: GenerationQuality::High,
        referenceImages: ['https://example.com/1.png', 'https://example.com/2.png'], // 2 images -> 1530 tokens
        pricing: publishedPricing(),
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


test('the markup multiplier comes from configuration', function (string $multiplier, string $expectedUserCost) {
    config(['services.pixelforge.markup_multiplier' => $multiplier]);

    expect((new CostCalculator)->calculateActualUserCost('0.373875000'))->toBe($expectedUserCost);
})->with([
    'no markup' => ['1', '0.373875000'],
    '10%' => ['1.10', '0.411262500'],
    '25% (default)' => ['1.25', '0.467343750'],
    '100%' => ['2', '0.747750000'],
]);

test('the markup defaults to 25% when not configured', function () {
    config(['services.pixelforge.markup_multiplier' => null]);

    expect((new CostCalculator)->calculateActualUserCost('1.000000000'))->toBe('1.250000000');
});

test('an invalid markup is rejected rather than silently charging the wrong amount', function (string $multiplier) {
    config(['services.pixelforge.markup_multiplier' => $multiplier]);

    (new CostCalculator)->calculateActualUserCost('1.000000000');
})->with(['zero' => '0', 'negative' => '-1.5', 'text' => 'abc', 'percent' => '25%'])
    ->throws(InvalidArgumentException::class);

test('usage cannot be priced without every rate', function () {
    (new CostCalculator)->calculateActualProviderCost(['output_tokens' => 100], ['output_tokens' => '40.00']);
})->throws(InvalidArgumentException::class);

test('the estimate follows the token table it is given, not built-in numbers', function () {
    $pricing = publishedPricing();
    $pricing['tokens']['output']['medium']['1024x1024'] = 2000;
    $pricing['tokens']['per_reference_image'] = 1000;
    $pricing['tokens']['characters_per_text_token'] = 2;

    $estimate = (new CostCalculator)->estimate(str_repeat('a', 400), '1024x1024', 'medium', ['https://example.com/a.png'], $pricing);

    expect($estimate['tokens']['output_tokens'])->toBe(2000)
        ->and($estimate['tokens']['image_tokens'])->toBe(1000)
        ->and($estimate['tokens']['estimated_text_tokens'])->toBe(200);
});

test('the user-facing estimate reveals neither provider costs, rates nor the markup', function () {
    config(['services.pixelforge.markup_multiplier' => '1.37']);

    $calculator = new CostCalculator;
    $estimate = $calculator->estimateForUser(str_repeat('a', 400), '1536x1024', 'high', ['https://example.com/1.png', 'https://example.com/2.png'], publishedPricing());

    expect(array_keys($estimate))->toBe(['tokens', 'costs'])
        ->and(array_keys($estimate['costs']))->toBe(['text', 'image', 'output', 'reasoning_min', 'reasoning_max', 'min', 'typical', 'max'])
        // Charged = provider cost x markup (0.373875 x 1.37), the only cost the user sees.
        ->and($estimate['costs']['max'])->toBe('0.512208750');

    $json = json_encode($estimate);
    expect($json)->not->toContain('provider')->not->toContain('rate')->not->toContain('markup')->not->toContain('1.37');
});
