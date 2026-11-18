<?php

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('dashboard page renders for authenticated user with balance and rates', function () {
    $user = User::factory()->create([
        'balance_usd' => '25.000000000',
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('generations')
        ->where('balance', '25.000000000')
        ->where('availableBalance', '25.000000000')
        ->where('heldBalance', '0.000000000')
        ->has('rates')
        ->has('sizes')
        ->has('qualities')
    );
});

test('estimate endpoint calculates accurate token breakdown and costs', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('generations.estimate'), [
        'prompt' => 'A serene Japanese garden with cherry blossoms, digital art',
        'size' => GenerationSize::Square->value,
        'quality' => GenerationQuality::Medium->value,
    ]);

    $response->assertOk();
    $response->assertJsonStructure([
        'tokens' => [
            'estimated_text_tokens',
            'min_text_tokens',
            'max_text_tokens',
            'image_tokens',
            'output_tokens',
            'min_reasoning_tokens',
            'max_reasoning_tokens',
        ],
        'provider' => ['min_cost', 'max_cost', 'estimated_cost'],
        'user' => ['min_cost', 'max_cost', 'estimated_cost'],
    ]);
});

test('store generation requires sufficient credit balance', function () {
    $user = User::factory()->create([
        'balance_usd' => '0.000000000', // zero balance
    ]);

    $response = $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'Test prompt with zero balance',
        'size' => '1024x1024',
        'quality' => 'medium',
    ]);

    $response->assertSessionHasErrors('balance');
    expect(Generation::count())->toBe(0);
});

test('store generation queues a submission job instead of calling Pixelforge inline', function () {
    Queue::fake();

    $user = User::factory()->create([
        'balance_usd' => '50.000000000',
    ]);

    $response = $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'A lighthouse on a cliff at dawn [succeed]',
        'size' => '1024x1024',
        'quality' => 'low',
    ]);

    $response->assertSessionHasNoErrors();

    $generation = Generation::query()->sole();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->pixelforge_id)->toBeNull()
        ->and($generation->user_id)->toBe($user->id)
        ->and($generation->actual_user_cost)->toBe('0.000000000');

    Queue::assertPushed(
        SubmitGeneration::class,
        fn (SubmitGeneration $job) => $job->generationId === $generation->id,
    );
});

test('store generation returns 202 for json requests', function () {
    Queue::fake();

    $user = User::factory()->create(['balance_usd' => '50.000000000']);

    $this->actingAs($user)->postJson(route('generations.store'), [
        'prompt' => 'A quiet harbour at night',
    ])->assertAccepted()->assertJsonPath('generation.status', 'queued');

    Queue::assertPushed(SubmitGeneration::class, 1);
});

test('insufficient balance never dispatches a job', function () {
    Queue::fake();

    $user = User::factory()->create(['balance_usd' => '0.000000000']);

    $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'No credit here',
    ])->assertSessionHasErrors('balance');

    Queue::assertNothingPushed();
});

test('status endpoint returns generation status and syncs active generation', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->create([
        'user_id' => $user->id,
        'status' => GenerationStatus::Succeeded,
        'actual_provider_cost' => '0.050000000',
        'actual_user_cost' => '0.062500000',
    ]);

    $response = $this->actingAs($user)->getJson(route('generations.status', $generation));

    $response->assertOk();
    $response->assertJson([
        'id' => $generation->id,
        'status' => GenerationStatus::Succeeded->value,
        'actual_user_cost' => '0.062500000',
    ]);
});

test('status endpoint prohibits unauthorized user', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $generation = Generation::factory()->create(['user_id' => $owner->id]);

    $response = $this->actingAs($stranger)->getJson(route('generations.status', $generation));

    $response->assertForbidden();
});

test('reference images must be http or https urls', function () {
    Queue::fake();
    $user = User::factory()->create(['balance_usd' => '100.000000000']);

    $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'A calm lake',
        'reference_images' => ['ftp://example.com/a.png'],
    ])->assertSessionHasErrors('reference_images.0');

    Queue::assertNothingPushed();
});
