<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\Exceptions\PixelforgeRateLimitException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Webhooks\WebhookProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// These tests call the real Pixelforge API with the local development key
// (PIXELFORGE_API_KEY) and drive its behaviour with the documented prompt keywords.

function queuedGeneration(string $prompt): Generation
{
    $user = User::factory()->create(['balance_usd' => '50.000000000']);

    return Generation::factory()->create([
        'user_id' => $user->id,
        'prompt' => $prompt,
        'status' => GenerationStatus::Queued,
        'pixelforge_id' => null,
        'image_url' => null,
        'usage' => null,
        'actual_provider_cost' => '0.000000000',
        'actual_user_cost' => '0.000000000',
    ]);
}

test('job submits the generation and records the pixelforge id', function () {
    $generation = queuedGeneration('A red kite over a green hill [succeed]');

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class));

    $generation->refresh();

    expect($generation->pixelforge_id)->toStartWith('gen_')
        ->and($generation->pixelforge_created_at)->not->toBeNull()
        ->and($generation->status)->toBeIn([GenerationStatus::Queued, GenerationStatus::Processing]);
});

test('job is a no-op when the generation was already submitted', function () {
    $generation = queuedGeneration('Already submitted [succeed]');
    $generation->update(['pixelforge_id' => 'gen_existing']);

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class));

    expect($generation->fresh()->pixelforge_id)->toBe('gen_existing');
});

test('job fails a blocked prompt for free', function () {
    $generation = queuedGeneration('A photo with [blocked] keyword');

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class));

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('content_policy_violation')
        ->and($generation->pixelforge_id)->toBeNull()
        ->and($generation->actual_provider_cost)->toBe('0.000000000')
        ->and($generation->actual_user_cost)->toBe('0.000000000')
        ->and($generation->user->fresh()->balance_usd)->toBe('50.000000000');
});

test('job releases back onto the queue when pixelforge is overloaded', function () {
    $generation = queuedGeneration('Please wait [overloaded]');

    $job = new SubmitGeneration($generation->id);
    $queueJob = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->once()->with(Mockery::on(fn ($delay) => $delay >= 2));
    $job->setJob($queueJob);

    $job->handle(app(PixelforgeClient::class));

    // Still queued: nothing was created at Pixelforge, so it is safe to retry.
    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued);
});

test('job marks the generation failed once attempts are exhausted', function () {
    $generation = queuedGeneration('Never works [overloaded]');

    $job = new SubmitGeneration($generation->id);
    $queueJob = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $queueJob->shouldReceive('attempts')->andReturn($job->tries);
    $queueJob->shouldReceive('release')->never();
    $job->setJob($queueJob);

    $job->handle(app(PixelforgeClient::class));

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->actual_user_cost->toBe('0.000000000');
});

test('failed hook never overrides a generation that pixelforge accepted', function () {
    $generation = queuedGeneration('Accepted [succeed]');
    $generation->update(['pixelforge_id' => 'gen_accepted']);

    (new SubmitGeneration($generation->id))->failed(new RuntimeException('boom'));

    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued);
});

test('failed hook marks an unsubmitted generation as failed at no cost', function () {
    $generation = queuedGeneration('Unsubmitted [succeed]');

    (new SubmitGeneration($generation->id))->failed(new RuntimeException('boom'));

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('submission_failed')
        ->actual_user_cost->toBe('0.000000000');
});

function dailyLimitClient(): PixelforgeClient
{
    return new class extends PixelforgeClient
    {
        public function createGeneration(array $payload, ?string $idempotencyKey = null): array
        {
            // Pixelforge sends no Retry-After for this one.
            throw new PixelforgeRateLimitException(
                'The daily generation quota is used up.', 429, 5, 'daily_limit_exceeded', 'rate_limit_error',
            );
        }
    };
}

test('the next quota reset is the start of the next UTC day plus the margin', function () {
    config(['services.pixelforge.submission.daily_limit_buffer' => 30]);

    $late = CarbonImmutable::parse('2026-11-18 23:59:50', 'UTC');
    expect(SubmitGeneration::nextQuotaReset($late)->toDateTimeString())->toBe('2026-11-19 00:00:30');

    // Always the *next* day, even exactly at midnight, and whatever the input timezone.
    $midnight = CarbonImmutable::parse('2026-11-19 00:00:00', 'UTC');
    expect(SubmitGeneration::nextQuotaReset($midnight)->toDateTimeString())->toBe('2026-11-20 00:00:30');

    $harare = CarbonImmutable::parse('2026-11-19 01:30:00', 'Africa/Harare'); // 23:30 UTC on the 18th
    expect(SubmitGeneration::nextQuotaReset($harare)->toDateTimeString())->toBe('2026-11-19 00:00:30');
});

test('hitting the daily limit holds the generation until the next UTC day instead of failing it', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-18 14:00:00', 'UTC'));

    $generation = Generation::factory()->create([
        'status' => GenerationStatus::Queued,
        'pixelforge_id' => null,
    ]);

    (new SubmitGeneration($generation->id))->handle(dailyLimitClient());

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->error_code)->toBeNull()
        ->and($generation->submit_after->utc()->toDateTimeString())->toBe('2026-11-19 00:00:30');

    // A fresh job (own attempt budget, own uniqueness key) is scheduled for then.
    Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $generation->id
        && $job->wave === 1
        && $job->uniqueId() === $generation->id.':1'
        && $job->delay->utc()->toDateTimeString() === '2026-11-19 00:00:30');
});

test('a generation held for the daily limit keeps its credits reserved', function () {
    Queue::fake();

    $user = User::factory()->create(['balance_usd' => '10.000000000']);
    $generation = Generation::factory()->create([
        'user_id' => $user->id,
        'status' => GenerationStatus::Queued,
        'pixelforge_id' => null,
        'reserved_user_cost' => '1.000000000',
    ]);

    (new SubmitGeneration($generation->id))->handle(dailyLimitClient());

    expect($user->availableBalance())->toBe('9.000000000');
});

test('a held generation is not resubmitted early by the reconcile command', function () {
    Queue::fake();

    $generation = Generation::factory()->create([
        'status' => GenerationStatus::Queued,
        'pixelforge_id' => null,
        'submit_after' => now()->addHours(6),
    ]);
    $generation->forceFill(['created_at' => now()->subHours(8)])->saveQuietly();

    $this->artisan('generations:reconcile')->assertSuccessful();

    Queue::assertNothingPushed();

    // Once the wait is over it is fair game again.
    $generation->forceFill(['submit_after' => now()->subMinute()])->saveQuietly();

    $this->artisan('generations:reconcile')->assertSuccessful();

    Queue::assertPushed(SubmitGeneration::class, 1);
});

test('a webhook that lands before the create call returns is matched and not undone by the response', function () {
    $user = User::factory()->create(['balance_usd' => '10.000000000']);
    $generation = Generation::factory()->create([
        'user_id' => $user->id,
        'status' => GenerationStatus::Queued,
        'pixelforge_id' => null,
        'reserved_user_cost' => '1.000000000',
    ]);

    // Pixelforge delivers the success webhook while our POST /v1/generations is still
    // in flight; the response (which still says "queued") only comes back afterwards.
    $client = new class($generation->id) extends PixelforgeClient
    {
        public function __construct(private string $generationId) {}

        public function createGeneration(array $payload, ?string $idempotencyKey = null): array
        {
            app(WebhookProcessor::class)->process([
                'id' => 'evt_early',
                'type' => 'generation.succeeded',
                'data' => ['object' => [
                    'id' => 'gen_early',
                    'status' => 'succeeded',
                    'image_url' => 'https://pixelforge.promptedit.dev/images/early.png',
                    'metadata' => ['app_generation_id' => $this->generationId],
                    'usage' => ['input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 100],
                    'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
                ]],
            ]);

            return [
                'id' => 'gen_early',
                'status' => 'queued',
                'created_at' => '2026-11-18T10:00:00Z',
                'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            ];
        }
    };

    (new SubmitGeneration($generation->id))->handle($client);

    $generation->refresh();
    $user->refresh();

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->pixelforge_id)->toBe('gen_early')
        ->and($generation->image_url)->not->toBeNull()
        ->and($user->transactions()->where('type', 'generation_charge')->count())->toBe(1)
        ->and($user->reservedBalance())->toBe('0.000000000')
        ->and($user->balance_usd)->toBe(bcsub('10.000000000', $generation->actual_user_cost, 9));
});
