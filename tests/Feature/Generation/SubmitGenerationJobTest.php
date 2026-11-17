<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
    $queueJob = Mockery::mock(Illuminate\Contracts\Queue\Job::class)->shouldIgnoreMissing();
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
    $queueJob = Mockery::mock(Illuminate\Contracts\Queue\Job::class)->shouldIgnoreMissing();
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
