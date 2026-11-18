<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function pendingGeneration(array $attributes = []): Generation
{
    return Generation::factory()->create(array_merge([
        'status' => GenerationStatus::Processing,
    ], $attributes));
}

test('only quiet in-flight generations are picked up for polling', function () {
    config(['services.pixelforge.reconcile.stale_after' => 120]);

    $quiet = pendingGeneration();
    $quiet->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();

    // Fresh, finished, and never-submitted generations must be left alone.
    pendingGeneration();
    Generation::factory()->succeeded()->create()->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
    pendingGeneration(['pixelforge_id' => null, 'status' => GenerationStatus::Queued])
        ->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

    $this->artisan('generations:reconcile --dry-run')
        ->expectsOutputToContain('1 generation(s) would be polled, 0 resubmitted, 0 webhook event(s) replayed.')
        ->assertSuccessful();
});

test('orphaned queued generations are submitted again', function () {
    Queue::fake();
    config(['services.pixelforge.reconcile.resubmit_after' => 900]);

    $orphan = pendingGeneration(['pixelforge_id' => null, 'status' => GenerationStatus::Queued]);
    $orphan->forceFill(['created_at' => now()->subHour()])->saveQuietly();

    // Recent: its submission job is presumably still working on it.
    pendingGeneration(['pixelforge_id' => null, 'status' => GenerationStatus::Queued]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    Queue::assertPushed(SubmitGeneration::class, 1);
    Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $orphan->id);
});

test('polling a generation Pixelforge does not know leaves it alone but defers the next poll', function () {
    $generation = pendingGeneration();
    $generation->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();

    $this->artisan('generations:reconcile')->assertSuccessful();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Processing)
        ->and($generation->updated_at->gt(now()->subMinute()))->toBeTrue();
});

test('the reconcile command is scheduled every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'generations:reconcile'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *');
});
