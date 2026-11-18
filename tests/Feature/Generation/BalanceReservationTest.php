<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pricing\CostCalculator;
use App\Services\Webhooks\WebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const RESERVATION_PROMPT = 'A calm lake at sunrise';

/**
 * The most a low quality 1024x1024 generation of the test prompt can cost the user,
 * at the rates Pixelforge currently publishes (real API call, as for the rest of the suite).
 *
 * @return numeric-string
 */
function maxCostOfTestGeneration(): string
{
    $rates = (array) (app(PixelforgeClient::class)->getPricing()['rates'] ?? CostCalculator::DEFAULT_RATES);

    return app(CostCalculator::class)
        ->estimate(RESERVATION_PROMPT, '1024x1024', 'low', [], $rates)['user']['max_cost'];
}

function submitTestGeneration(User $user)
{
    return test()->actingAs($user)->post(route('generations.store'), [
        'prompt' => RESERVATION_PROMPT,
        'size' => '1024x1024',
        'quality' => 'low',
    ]);
}

test('a new generation holds its maximum cost against the balance', function () {
    Queue::fake();

    $max = maxCostOfTestGeneration();
    $user = User::factory()->create(['balance_usd' => bcmul($max, '3', 9)]);

    submitTestGeneration($user)->assertSessionHasNoErrors();

    $generation = Generation::query()->sole();

    expect($generation->reserved_user_cost)->toBe($max)
        ->and($user->reservedBalance())->toBe($max)
        ->and($user->availableBalance())->toBe(bcmul($max, '2', 9))
        // Nothing has been charged yet, only held.
        ->and($user->fresh()->balance_usd)->toBe(bcmul($max, '3', 9));
});

test('pending generations count against the balance so a user cannot overspend', function () {
    Queue::fake();

    $max = maxCostOfTestGeneration();
    // Enough credit for one generation and a half.
    $user = User::factory()->create(['balance_usd' => bcmul($max, '1.5', 9)]);

    submitTestGeneration($user)->assertSessionHasNoErrors();

    // The first generation is still queued, so the second must be refused even though
    // the raw balance would cover it.
    submitTestGeneration($user)->assertSessionHasErrors('balance');

    expect(Generation::count())->toBe(1);
    Queue::assertPushed(SubmitGeneration::class, 1);
});

test('the user can submit as many generations as their credits cover', function () {
    Queue::fake();

    $max = maxCostOfTestGeneration();
    $user = User::factory()->create(['balance_usd' => bcmul($max, '3', 9)]);

    foreach (range(1, 3) as $ignored) {
        submitTestGeneration($user)->assertSessionHasNoErrors();
    }

    submitTestGeneration($user)->assertSessionHasErrors('balance');

    expect(Generation::count())->toBe(3);
    Queue::assertPushed(SubmitGeneration::class, 3);
});

test('a failed generation releases its hold', function () {
    Queue::fake();

    $max = maxCostOfTestGeneration();
    $user = User::factory()->create(['balance_usd' => bcmul($max, '1.5', 9)]);

    submitTestGeneration($user)->assertSessionHasNoErrors();

    // Pixelforge never accepts it, so it fails at no cost.
    $generation = Generation::query()->sole();
    $generation->update(['status' => GenerationStatus::Failed]);

    expect($user->reservedBalance())->toBe('0.000000000')
        ->and($user->availableBalance())->toBe(bcmul($max, '1.5', 9));

    submitTestGeneration($user)->assertSessionHasNoErrors();
    expect(Generation::count())->toBe(2);
});

test('settling a succeeded generation releases the hold and deducts the real cost together', function () {
    $user = User::factory()->create(['balance_usd' => '10.000000000']);
    $generation = Generation::factory()->create([
        'user_id' => $user->id,
        'status' => GenerationStatus::Processing,
        'reserved_user_cost' => '1.000000000',
    ]);

    expect($user->availableBalance())->toBe('9.000000000');

    app(WebhookProcessor::class)->applyGenerationUpdate($generation, [
        'id' => $generation->pixelforge_id,
        'status' => 'succeeded',
        'image_url' => 'https://pixelforge.promptedit.dev/images/x.png',
        'usage' => [
            'input_text_tokens' => 10,
            'input_image_tokens' => 0,
            'output_tokens' => 280,
            'reasoning_tokens' => 100,
        ],
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
    ]);

    $user->refresh();
    $charged = $generation->fresh()->actual_user_cost;

    expect($user->reservedBalance())->toBe('0.000000000')
        ->and($user->balance_usd)->toBe(bcsub('10.000000000', $charged, 9))
        ->and($user->availableBalance())->toBe($user->balance_usd)
        // The charge is the actual cost, never the reserved maximum.
        ->and(bccomp($charged, '1.000000000', 9))->toBe(-1);
});

test('settling the same generation twice only charges once', function () {
    $user = User::factory()->create(['balance_usd' => '10.000000000']);
    $generation = Generation::factory()->create([
        'user_id' => $user->id,
        'status' => GenerationStatus::Processing,
        'reserved_user_cost' => '1.000000000',
    ]);

    $payload = [
        'id' => $generation->pixelforge_id,
        'status' => 'succeeded',
        'usage' => ['input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 100],
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
    ];

    // Two independent in-memory copies, as a webhook and a poll would hold.
    $processor = app(WebhookProcessor::class);
    $processor->applyGenerationUpdate(Generation::find($generation->id), $payload);
    $processor->applyGenerationUpdate(Generation::find($generation->id), $payload);

    expect($user->transactions()->where('type', 'generation_charge')->count())->toBe(1);
});

test('dashboard shows the available balance and what is held', function () {
    $user = User::factory()->create(['balance_usd' => '10.000000000']);
    Generation::factory()->create([
        'user_id' => $user->id,
        'status' => GenerationStatus::Queued,
        'pixelforge_id' => null,
        'reserved_user_cost' => '0.750000000',
    ]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('balance', '10.000000000')
            ->where('heldBalance', '0.750000000')
            ->where('availableBalance', '9.250000000')
        );
});

test('a charge is dated by when Pixelforge completed the generation, not when we recorded it', function () {
    // The webhook reaches us on the next UTC day, after Pixelforge billed the generation.
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-03-02 00:05:00', 'UTC'));

    $user = User::factory()->create(['balance_usd' => '10.000000000']);
    $generation = Generation::factory()->create([
        'user_id' => $user->id,
        'status' => GenerationStatus::Processing,
        'reserved_user_cost' => '1.000000000',
    ]);

    app(WebhookProcessor::class)->applyGenerationUpdate($generation, [
        'id' => $generation->pixelforge_id,
        'status' => 'succeeded',
        'completed_at' => '2026-03-01T23:59:58Z',
        'usage' => ['input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 100],
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
    ]);

    $charge = $user->transactions()->where('type', 'generation_charge')->sole();

    expect($charge->occurred_at->utc()->toDateTimeString())->toBe('2026-03-01 23:59:58')
        ->and($charge->created_at->utc()->toDateString())->toBe('2026-03-02')
        // Same UTC day as the generation, which is what the usage report groups by.
        ->and($charge->occurred_at->utc()->toDateString())
        ->toBe($generation->fresh()->pixelforge_completed_at->utc()->toDateString());
});

test('a purchase happens when it is made', function () {
    $user = User::factory()->create();

    $transaction = $user->adjustBalance('5.000000000', 'purchase');

    expect($transaction->occurred_at->diffInSeconds(now()))->toBeLessThan(5);
});
