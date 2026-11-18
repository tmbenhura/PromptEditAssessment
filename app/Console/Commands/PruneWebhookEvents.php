<?php

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use Illuminate\Console\Command;

class PruneWebhookEvents extends Command
{
    protected $signature = 'webhook-events:prune
                            {--days= : Retention in days, overriding services.pixelforge.webhook_events.retention_days}
                            {--dry-run : Only report how many events would be deleted}';

    protected $description = 'Delete webhook events older than the configured retention period';

    public function handle(): int
    {
        $option = $this->option('days');

        if ($option !== null && (! ctype_digit((string) $option))) {
            $this->error('The --days option must be a non-negative whole number.');

            return self::INVALID;
        }

        $days = $option !== null
            ? (int) $option
            : (int) config('services.pixelforge.webhook_events.retention_days', 30);

        if ($days <= 0) {
            $this->info('Webhook event pruning is disabled (retention is not a positive number of days).');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $count = WebhookEvent::query()->olderThanDays($days)->count();
            $this->info("{$count} webhook event(s) older than {$days} day(s) would be deleted.");

            return self::SUCCESS;
        }

        $chunk = max(1, (int) config('services.pixelforge.webhook_events.prune_chunk', 1000));
        $deleted = 0;

        do {
            $ids = WebhookEvent::query()->olderThanDays($days)->orderBy('id')->limit($chunk)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += WebhookEvent::query()->whereKey($ids)->delete();
        } while ($ids->count() === $chunk);

        $this->info("Deleted {$deleted} webhook event(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
