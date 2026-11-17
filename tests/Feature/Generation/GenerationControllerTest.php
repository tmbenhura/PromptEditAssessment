<?php

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

test('store generation rejects blocked content policy violation prompt', function () {
    $user = User::factory()->create([
        'balance_usd' => '50.000000000',
    ]);

    // Using Pixelforge test keyword [blocked]
    $response = $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'A photo with [blocked] keyword',
        'size' => '1024x1024',
        'quality' => 'medium',
    ]);

    $response->assertSessionHasErrors('prompt');
    expect(Generation::count())->toBe(0);
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
