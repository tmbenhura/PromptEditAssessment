<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function signWebhookPayload(string $rawPayload, string $secret, ?int $timestamp = null): string
{
    $t = $timestamp ?? time();
    $hash = hash_hmac('sha256', "{$t}.{$rawPayload}", $secret);

    return "t={$t},v1={$hash}";
}

test('webhook rejects request without signature', function () {
    $response = $this->postJson('/webhooks/pixelforge', ['ping' => true]);

    $response->assertStatus(400);
});

test('webhook rejects invalid signature', function () {
    $secret = 'test_secret';
    config()->set('services.pixelforge.webhook_secret', $secret);

    $raw = json_encode(['id' => 'evt_1', 'type' => 'ping']);
    $response = $this->call(
        'POST',
        '/webhooks/pixelforge',
        server: [
            'HTTP_PIXELFORGE_SIGNATURE' => 't='.time().',v1=invalidsignature',
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $raw,
    );

    $response->assertStatus(400);
});

test('webhook rejects expired timestamp (> 300s)', function () {
    $secret = 'test_secret';
    config()->set('services.pixelforge.webhook_secret', $secret);

    $raw = json_encode(['id' => 'evt_1', 'type' => 'ping']);
    $expiredSig = signWebhookPayload($raw, $secret, time() - 305);

    $response = $this->call(
        'POST',
        '/webhooks/pixelforge',
        server: [
            'HTTP_PIXELFORGE_SIGNATURE' => $expiredSig,
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $raw,
    );

    $response->assertStatus(400);
});

test('webhook accepts ping event with valid signature', function () {
    $secret = 'test_secret';
    config()->set('services.pixelforge.webhook_secret', $secret);

    $raw = json_encode([
        'id' => 'evt_ping_123',
        'object' => 'event',
        'type' => 'ping',
    ]);
    $sig = signWebhookPayload($raw, $secret);

    $response = $this->call(
        'POST',
        '/webhooks/pixelforge',
        server: [
            'HTTP_PIXELFORGE_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $raw,
    );

    $response->assertNoContent();
});

test('webhook handles generation.processing event', function () {
    $secret = 'test_secret';
    config()->set('services.pixelforge.webhook_secret', $secret);

    $user = User::factory()->create();
    $generation = Generation::factory()->create([
        'user_id' => $user->id,
        'pixelforge_id' => 'gen_test_proc',
        'status' => GenerationStatus::Queued,
    ]);

    $raw = json_encode([
        'id' => 'evt_proc_1',
        'object' => 'event',
        'type' => 'generation.processing',
        'data' => [
            'object' => [
                'id' => 'gen_test_proc',
                'status' => 'processing',
                'started_at' => '2026-11-04T14:03:13Z',
            ],
        ],
    ]);
    $sig = signWebhookPayload($raw, $secret);

    $response = $this->call(
        'POST',
        '/webhooks/pixelforge',
        server: [
            'HTTP_PIXELFORGE_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $raw,
    );

    $response->assertNoContent();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Processing);
});

test('webhook handles generation.succeeded event, records exact cost and charges user', function () {
    $secret = 'test_secret';
    config()->set('services.pixelforge.webhook_secret', $secret);

    $user = User::factory()->create(['balance_usd' => '20.000000000']);
    $generation = Generation::factory()->create([
        'user_id' => $user->id,
        'pixelforge_id' => 'gen_test_succeed',
        'status' => GenerationStatus::Processing,
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
    ]);

    // Usage from docs: 12 text, 765 image, 6450 output, 3184 reasoning
    // Provider cost = 0.305510000, User cost (+25%) = 0.381887500
    $raw = json_encode([
        'id' => 'evt_succeed_1',
        'object' => 'event',
        'type' => 'generation.succeeded',
        'data' => [
            'object' => [
                'id' => 'gen_test_succeed',
                'status' => 'succeeded',
                'image_url' => 'https://pixelforge.promptedit.dev/images/gen_test_succeed.png',
                'image_url_expires_at' => '2026-11-05T14:03:29Z',
                'usage' => [
                    'input_text_tokens' => 12,
                    'input_image_tokens' => 765,
                    'output_tokens' => 6450,
                    'reasoning_tokens' => 3184,
                    'total_tokens' => 10411,
                ],
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
                'started_at' => '2026-11-04T14:03:13Z',
                'completed_at' => '2026-11-04T14:03:29Z',
            ],
        ],
    ]);
    $sig = signWebhookPayload($raw, $secret);

    $response = $this->call(
        'POST',
        '/webhooks/pixelforge',
        server: [
            'HTTP_PIXELFORGE_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $raw,
    );

    $response->assertNoContent();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->actual_provider_cost)->toBe('0.305510000')
        ->and($generation->actual_user_cost)->toBe('0.381887500')
        ->and($generation->image_url)->toBe('https://pixelforge.promptedit.dev/images/gen_test_succeed.png');

    $user->refresh();
    // 20.000000000 - 0.381887500 = 19.618112500
    expect($user->balance_usd)->toBe('19.618112500');

    $transaction = Transaction::where('generation_id', $generation->id)->first();
    expect($transaction)->not->toBeNull()
        ->and($transaction->amount_usd)->toBe('-0.381887500')
        ->and($transaction->type)->toBe('generation_charge');
});

test('webhook deduplicates events and never double charges', function () {
    $secret = 'test_secret';
    config()->set('services.pixelforge.webhook_secret', $secret);

    $user = User::factory()->create(['balance_usd' => '20.000000000']);
    $generation = Generation::factory()->create([
        'user_id' => $user->id,
        'pixelforge_id' => 'gen_test_dup',
        'status' => GenerationStatus::Processing,
    ]);

    $raw = json_encode([
        'id' => 'evt_dup_999',
        'object' => 'event',
        'type' => 'generation.succeeded',
        'data' => [
            'object' => [
                'id' => 'gen_test_dup',
                'status' => 'succeeded',
                'image_url' => 'https://pixelforge.promptedit.dev/images/dup.png',
                'usage' => [
                    'input_text_tokens' => 12,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 200,
                    'total_tokens' => 1312,
                ],
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
            ],
        ],
    ]);
    $sig = signWebhookPayload($raw, $secret);

    // First delivery
    $r1 = $this->call(
        'POST',
        '/webhooks/pixelforge',
        server: ['HTTP_PIXELFORGE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        content: $raw,
    );
    $r1->assertNoContent();

    $user->refresh();
    $balanceAfterFirst = $user->balance_usd;

    // Second duplicate delivery (same event id)
    $r2 = $this->call(
        'POST',
        '/webhooks/pixelforge',
        server: ['HTTP_PIXELFORGE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        content: $raw,
    );
    $r2->assertNoContent();

    $user->refresh();
    // Balance must NOT decrease further
    expect($user->balance_usd)->toBe($balanceAfterFirst);

    // Exactly one transaction exists
    expect(Transaction::where('generation_id', $generation->id)->count())->toBe(1);
    expect(WebhookEvent::where('event_id', 'evt_dup_999')->count())->toBe(1);
});

test('webhook handles out of order delivery and never moves back from succeeded', function () {
    $secret = 'test_secret';
    config()->set('services.pixelforge.webhook_secret', $secret);

    $user = User::factory()->create();
    $generation = Generation::factory()->succeeded()->create([
        'user_id' => $user->id,
        'pixelforge_id' => 'gen_test_out_of_order',
    ]);

    // Retried processing webhook arrives after generation already succeeded
    $raw = json_encode([
        'id' => 'evt_late_proc',
        'object' => 'event',
        'type' => 'generation.processing',
        'data' => [
            'object' => [
                'id' => 'gen_test_out_of_order',
                'status' => 'processing',
            ],
        ],
    ]);
    $sig = signWebhookPayload($raw, $secret);

    $response = $this->call(
        'POST',
        '/webhooks/pixelforge',
        server: ['HTTP_PIXELFORGE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        content: $raw,
    );
    $response->assertNoContent();

    $generation->refresh();
    // Must remain succeeded!
    expect($generation->status)->toBe(GenerationStatus::Succeeded);
});

test('webhook handles generation.failed event and charges $0', function () {
    $secret = 'test_secret';
    config()->set('services.pixelforge.webhook_secret', $secret);

    $user = User::factory()->create(['balance_usd' => '20.000000000']);
    $generation = Generation::factory()->create([
        'user_id' => $user->id,
        'pixelforge_id' => 'gen_test_failed',
        'status' => GenerationStatus::Processing,
    ]);

    $raw = json_encode([
        'id' => 'evt_failed_1',
        'object' => 'event',
        'type' => 'generation.failed',
        'data' => [
            'object' => [
                'id' => 'gen_test_failed',
                'status' => 'failed',
                'error' => [
                    'code' => 'generation_failed',
                    'message' => 'The model could not produce an image for the prompt.',
                ],
                'completed_at' => '2026-11-04T14:03:29Z',
            ],
        ],
    ]);
    $sig = signWebhookPayload($raw, $secret);

    $response = $this->call(
        'POST',
        '/webhooks/pixelforge',
        server: ['HTTP_PIXELFORGE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        content: $raw,
    );
    $response->assertNoContent();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->actual_provider_cost)->toBe('0.000000000')
        ->and($generation->actual_user_cost)->toBe('0.000000000')
        ->and($generation->error_code)->toBe('generation_failed');

    $user->refresh();
    // Failed generation must be completely free
    expect($user->balance_usd)->toBe('20.000000000');
    expect(Transaction::where('generation_id', $generation->id)->count())->toBe(0);
});
