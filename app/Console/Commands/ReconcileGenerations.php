<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Generation\GenerationService;
use App\Services\Webhooks\WebhookProcessor;
use Illuminate\Console\Command;

/**
 * Safety net for the webhook path. Pixelforge's delivery is at-least-once but not
 * guaranteed, so a generation could otherwise sit in "processing" forever, with
 * its maximum cost held against the user's balance.
 *
 * - Accepted generations that have been quiet for a while are polled directly.
 * - Webhooks that were logged but never applied are replayed from their stored payload.
 * - Generations whose submission job was lost are submitted again (the stored
 *   idempotency key makes that safe).
 */
class ReconcileGenerations extends Command
{
    protected $signature = 'generations:reconcile
                            {--dry-run : Only report what would be polled or resubmitted}';

    protected $description = 'Poll quiet in-flight generations and resubmit orphaned ones';

    public function handle(GenerationService $generationService, WebhookProcessor $webhookProcessor): int
    {
        $staleAfter = max(1, (int) config('services.pixelforge.reconcile.stale_after', 120));
        $resubmitAfter = max(1, (int) config('services.pixelforge.reconcile.resubmit_after', 900));
        $maxPerRun = max(1, (int) config('services.pixelforge.reconcile.max_per_run', 30));

        // Accepted by Pixelforge, still unfinished, and no webhook for a while.
        $quiet = Generation::query()
            ->whereIn('status', GenerationStatus::pending())
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<', now()->subSeconds($staleAfter))
            ->oldest('updated_at')
            ->limit($maxPerRun)
            ->get();

        // Never reached Pixelforge and nothing is retrying them any more.
        $orphaned = Generation::query()
            ->where('status', GenerationStatus::Queued)
            ->whereNull('pixelforge_id')
            ->where('created_at', '<', now()->subSeconds($resubmitAfter))
            // Waiting for the daily quota to reset: a job is already scheduled for then.
            ->where(fn ($query) => $query->whereNull('submit_after')->orWhere('submit_after', '<=', now()))
            ->oldest()
            ->limit($maxPerRun)
            ->get();

        // Webhooks acknowledged but not applied (the deferred work failed or was cut off).
        // Their payloads are stored, so apply them again.
        $unfinishedEvents = WebhookEvent::query()
            ->unfinishedBefore(now()->subSeconds(max(1, (int) config('services.pixelforge.reconcile.replay_after', 60))))
            ->oldest('last_received_at')
            ->limit($maxPerRun)
            ->get();

        if ($this->option('dry-run')) {
            $this->info("{$quiet->count()} generation(s) would be polled, {$orphaned->count()} resubmitted, {$unfinishedEvents->count()} webhook event(s) replayed.");

            return self::SUCCESS;
        }

        $replayed = 0;
        foreach ($unfinishedEvents as $event) {
            try {
                $replayed += $webhookProcessor->processEvent($event) ? 1 : 0;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        foreach ($quiet as $generation) {
            $generationService->sync($generation);

            // Back off from this one until it is quiet for another full period,
            // whatever the outcome, so polling never hammers the API.
            $generation->refresh();
            if (! $generation->isFinal()) {
                $generation->touch();
            }
        }

        foreach ($orphaned as $generation) {
            // A no-op while an earlier job for it still holds the uniqueness lock.
            SubmitGeneration::dispatch($generation->id);
        }

        $this->info("Polled {$quiet->count()} generation(s), resubmitted {$orphaned->count()}, replayed {$replayed} webhook event(s).");

        return self::SUCCESS;
    }
}
