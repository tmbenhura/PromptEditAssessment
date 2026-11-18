<?php

use App\Exceptions\PricingUnavailableException;
use App\Models\PricingSnapshot;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pricing\PricingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

function pricingPayload(string $outputRate = '40.00'): array
{
    return [
        'object' => 'pricing',
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => $outputRate,
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

/**
 * A client whose pricing endpoint returns the given payloads in turn; a Throwable is thrown.
 */
function pricingClient(array|Throwable ...$responses): PixelforgeClient
{
    return new class($responses) extends PixelforgeClient
    {
        public int $calls = 0;

        public function __construct(private array $responses) {}

        public function getPricing(): array
        {
            $response = $this->responses[min($this->calls++, count($this->responses) - 1)];

            if ($response instanceof Throwable) {
                throw $response;
            }

            return $response;
        }
    };
}

function providerWith(PixelforgeClient $client): PricingProvider
{
    Cache::forget(PricingProvider::CACHE_KEY);

    return new PricingProvider($client);
}

test('fresh pricing is returned and stored', function () {
    $pricing = providerWith(pricingClient(pricingPayload()))->current();

    expect($pricing['rates']['output_tokens'])->toBe('40.00')
        ->and($pricing['tokens']['per_reference_image'])->toBe(765);

    $snapshot = PricingSnapshot::query()->sole();
    expect($snapshot->rates)->toBe($pricing['rates'])
        ->and($snapshot->tokens)->toBe($pricing['tokens']);
});

test('pricing is cached between calls', function () {
    $client = pricingClient(pricingPayload());
    $provider = providerWith($client);

    $provider->current();
    $provider->current();

    expect($client->calls)->toBe(1);
});

test('an unchanged price does not add another snapshot, a changed one does', function () {
    providerWith(pricingClient(pricingPayload()))->current();
    providerWith(pricingClient(pricingPayload()))->current();

    expect(PricingSnapshot::count())->toBe(1);

    providerWith(pricingClient(pricingPayload('45.00')))->current();

    expect(PricingSnapshot::count())->toBe(2)
        ->and(PricingSnapshot::latestKnown()->rates['output_tokens'])->toBe('45.00');
});

test('the last known pricing is used when Pixelforge cannot be reached', function () {
    providerWith(pricingClient(pricingPayload('45.00')))->current();

    $pricing = providerWith(pricingClient(new PixelforgeException('down', 503)))->current();

    expect($pricing['rates']['output_tokens'])->toBe('45.00');
});

test('the last known pricing is used when the response is unusable', function () {
    providerWith(pricingClient(pricingPayload('45.00')))->current();

    $pricing = providerWith(pricingClient(['object' => 'pricing', 'rates' => ['output_tokens' => '1.00']]))->current();

    expect($pricing['rates']['output_tokens'])->toBe('45.00');
});

test('pricing missing an estimate for a size we offer is not trusted', function () {
    providerWith(pricingClient(pricingPayload('45.00')))->current();

    $incomplete = pricingPayload('1.00');
    unset($incomplete['tokens']['output']['medium']['1536x1024']);

    $pricing = providerWith(pricingClient($incomplete))->current();

    // Falls back to the last complete pricing, and the incomplete one is not stored.
    expect($pricing['rates']['output_tokens'])->toBe('45.00')
        ->and(PricingSnapshot::count())->toBe(1);
});

test('the newest stored pricing wins', function () {
    providerWith(pricingClient(pricingPayload('40.00')))->current();
    providerWith(pricingClient(pricingPayload('50.00')))->current();

    $pricing = providerWith(pricingClient(new PixelforgeException('down', 503)))->current();

    expect($pricing['rates']['output_tokens'])->toBe('50.00');
});

test('there are no invented defaults: with no stored pricing it says so', function () {
    providerWith(pricingClient(new PixelforgeException('down', 503)))->current();
})->throws(PricingUnavailableException::class);

test('an outage does not make every request retry Pixelforge', function () {
    providerWith(pricingClient(pricingPayload()))->current();

    $client = pricingClient(new PixelforgeException('down', 503));
    $provider = providerWith($client);

    $provider->current();
    $provider->current();
    $provider->current();

    expect($client->calls)->toBe(1);
});

test('a generation cannot be created when no pricing is known, and nothing is held', function () {
    \Illuminate\Support\Facades\Queue::fake();

    app()->instance(PixelforgeClient::class, pricingClient(new PixelforgeException('down', 503)));
    Cache::forget(PricingProvider::CACHE_KEY);

    $user = \App\Models\User::factory()->create(['balance_usd' => '100.000000000']);

    $this->actingAs($user)->post(route('generations.store'), ['prompt' => 'A calm lake'])
        ->assertSessionHasErrors('general');

    expect(\App\Models\Generation::count())->toBe(0);
    \Illuminate\Support\Facades\Queue::assertNothingPushed();
});

test('the dashboard and estimate endpoint still work from stored pricing during an outage', function () {
    providerWith(pricingClient(pricingPayload()))->current();

    app()->instance(PixelforgeClient::class, pricingClient(new PixelforgeException('down', 503)));
    Cache::forget(PricingProvider::CACHE_KEY);
    app()->forgetInstance(PricingProvider::class);

    $user = \App\Models\User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('initialEstimate.costs'));

    $this->actingAs($user)->postJson(route('generations.estimate'), ['prompt' => 'A lake', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertOk()->assertJsonPath('tokens.output', 280);
});
