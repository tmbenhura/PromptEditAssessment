<?php

use App\Console\Commands\PruneWebhookEvents;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Webhooks\WebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sendSignedWebhook(array $event)
{
    $secret = 'test_secret';
    config()->set('services.pixelforge.webhook_secret', $secret);

    $raw = json_encode($event);
    $t = time();

    return test()->call('POST', '/webhooks/pixelforge', server: [
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$raw}", $secret),
        'CONTENT_TYPE' => 'application/json',
    ], content: $raw);
}

function eventPayload(string $id, Generation $generation, string $status = 'processing'): array
{
    return [
        'id' => $id,
        'type' => "generation.{$status}",
        'data' => ['object' => ['id' => $generation->pixelforge_id, 'status' => $status]],
    ];
}

test('every authenticated event is logged, including pings', function () {
    sendSignedWebhook(['id' => 'evt_ping', 'type' => 'ping'])->assertNoContent();

    $event = WebhookEvent::query()->where('event_id', 'evt_ping')->sole();

    expect($event->event_type)->toBe('ping')
        ->and($event->outcome)->toBe(WebhookEvent::OUTCOME_IGNORED)
        ->and($event->delivery_count)->toBe(1)
        ->and($event->last_received_at)->not->toBeNull()
        ->and($event->payload)->toBe(['id' => 'evt_ping', 'type' => 'ping']);
});

test('a processed event records the generation it applied to', function () {
    $generation = Generation::factory()->create();

    sendSignedWebhook(eventPayload('evt_1', $generation))->assertNoContent();

    $event = WebhookEvent::query()->sole();

    expect($event->outcome)->toBe(WebhookEvent::OUTCOME_PROCESSED)
        ->and($event->generation_id)->toBe($generation->id);
});

test('redelivered events are counted but not applied twice', function () {
    $generation = Generation::factory()->create();
    $payload = eventPayload('evt_dup', $generation);

    sendSignedWebhook($payload)->assertNoContent();
    sendSignedWebhook($payload)->assertNoContent();
    sendSignedWebhook($payload)->assertNoContent();

    $event = WebhookEvent::query()->sole();

    expect($event->delivery_count)->toBe(3)
        ->and($event->outcome)->toBe(WebhookEvent::OUTCOME_PROCESSED);
});

test('an event for an unknown generation is logged as ignored', function () {
    sendSignedWebhook([
        'id' => 'evt_unknown',
        'type' => 'generation.processing',
        'data' => ['object' => ['id' => 'gen_nope', 'status' => 'processing']],
    ])->assertNoContent();

    expect(WebhookEvent::query()->sole()->outcome)->toBe(WebhookEvent::OUTCOME_IGNORED);
});

test('an event without an id is still logged', function () {
    app(WebhookProcessor::class)->process(['type' => 'ping']);

    expect(WebhookEvent::query()->sole())
        ->outcome->toBe(WebhookEvent::OUTCOME_IGNORED)
        ->event_id->toStartWith('missing-');
});

test('events with a bad signature are not logged', function () {
    config()->set('services.pixelforge.webhook_secret', 'test_secret');

    $this->postJson('/webhooks/pixelforge', ['id' => 'evt_x', 'type' => 'ping'])->assertStatus(400);

    expect(WebhookEvent::count())->toBe(0);
});

test('a failure is logged and the retry then processes the event', function () {
    $generation = Generation::factory()->create();
    $payload = eventPayload('evt_fail', $generation);

    // Make applying the update blow up once.
    $processor = new class(app(\App\Services\Pricing\CostCalculator::class)) extends WebhookProcessor
    {
        public function applyGenerationUpdate(Generation $generation, array $data): void
        {
            throw new RuntimeException('boom');
        }
    };

    expect(fn () => $processor->process($payload))->toThrow(RuntimeException::class);

    $event = WebhookEvent::query()->sole();
    expect($event->outcome)->toBe(WebhookEvent::OUTCOME_FAILED)
        ->and($event->error)->toBe('boom');

    // Pixelforge redelivers; the normal processor handles it this time.
    expect(app(WebhookProcessor::class)->process($payload))->toBeTrue();

    $event->refresh();
    expect(WebhookEvent::count())->toBe(1)
        ->and($event->outcome)->toBe(WebhookEvent::OUTCOME_PROCESSED)
        ->and($event->error)->toBeNull()
        ->and($event->delivery_count)->toBe(2);
});

function webhookEventReceived(string $id, int $daysAgo): WebhookEvent
{
    return WebhookEvent::create([
        'event_id' => $id,
        'event_type' => 'ping',
        'payload' => ['id' => $id],
        'outcome' => WebhookEvent::OUTCOME_IGNORED,
        'delivery_count' => 1,
        'last_received_at' => now()->subDays($daysAgo),
    ]);
}

test('prune deletes events older than the configured retention', function () {
    config(['services.pixelforge.webhook_events.retention_days' => 30]);

    webhookEventReceived('old', 31);
    webhookEventReceived('recent', 29);

    $this->artisan('webhook-events:prune')
        ->expectsOutputToContain('Deleted 1 webhook event(s) older than 30 day(s).')
        ->assertSuccessful();

    expect(WebhookEvent::pluck('event_id')->all())->toBe(['recent']);
});

test('prune goes by the last delivery, not the first', function () {
    config(['services.pixelforge.webhook_events.retention_days' => 7]);

    $event = webhookEventReceived('redelivered', 20);
    $event->forceFill(['last_received_at' => now()->subDay()])->save();

    $this->artisan('webhook-events:prune')->assertSuccessful();

    expect(WebhookEvent::count())->toBe(1);
});

test('prune works through a backlog larger than one chunk', function () {
    config([
        'services.pixelforge.webhook_events.retention_days' => 1,
        'services.pixelforge.webhook_events.prune_chunk' => 2,
    ]);

    foreach (range(1, 5) as $i) {
        webhookEventReceived("old-{$i}", 10);
    }
    webhookEventReceived('fresh', 0);

    $this->artisan('webhook-events:prune')
        ->expectsOutputToContain('Deleted 5 webhook event(s)')
        ->assertSuccessful();

    expect(WebhookEvent::pluck('event_id')->all())->toBe(['fresh']);
});

test('the days option overrides the configured retention', function () {
    config(['services.pixelforge.webhook_events.retention_days' => 30]);

    webhookEventReceived('ten-days', 10);

    $this->artisan('webhook-events:prune --days=5')->assertSuccessful();

    expect(WebhookEvent::count())->toBe(0);
});

test('dry run reports without deleting', function () {
    config(['services.pixelforge.webhook_events.retention_days' => 1]);

    webhookEventReceived('old', 10);

    $this->artisan('webhook-events:prune --dry-run')
        ->expectsOutputToContain('1 webhook event(s) older than 1 day(s) would be deleted.')
        ->assertSuccessful();

    expect(WebhookEvent::count())->toBe(1);
});

test('a retention of zero disables pruning', function () {
    config(['services.pixelforge.webhook_events.retention_days' => 0]);

    webhookEventReceived('ancient', 1000);

    $this->artisan('webhook-events:prune')->assertSuccessful();

    expect(WebhookEvent::count())->toBe(1);
});

test('a non-numeric days option is rejected', function () {
    $this->artisan('webhook-events:prune --days=abc')->assertExitCode(PruneWebhookEvents::INVALID);
});

test('the prune command is scheduled daily', function () {
    $scheduled = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'webhook-events:prune'));

    expect($scheduled)->not->toBeNull()
        ->and($scheduled->expression)->toBe('0 3 * * *');
});

test('the webhook route does not start a session', function () {
    $response = sendSignedWebhook(['id' => 'evt_nosession', 'type' => 'ping']);

    $response->assertNoContent();
    expect($response->headers->getCookies())->toBeEmpty();
});

test('an event with neither a generation id nor metadata matches nothing', function () {
    Generation::factory()->create();

    sendSignedWebhook([
        'id' => 'evt_blank',
        'type' => 'generation.succeeded',
        'data' => ['object' => ['status' => 'succeeded']],
    ])->assertNoContent();

    expect(WebhookEvent::query()->sole()->generation_id)->toBeNull();
});
