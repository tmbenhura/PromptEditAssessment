<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'pixelforge' => [
        'api_key' => env('PIXELFORGE_API_KEY'),
        'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
        // Set false only on a local machine that lacks a CA bundle. Never in production.
        'verify_ssl' => (bool) env('PIXELFORGE_VERIFY_SSL', true),
        'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET', 'whsec_local_secret'),

        /*
        | Queued submission of generations (see App\Jobs\SubmitGeneration).
        */
        'submission' => [
            // Total attempts before a generation that Pixelforge never accepted is
            // failed (at no cost). Pixelforge asks callers to cap retries.
            'max_attempts' => (int) env('PIXELFORGE_SUBMISSION_MAX_ATTEMPTS', 6),

            // Minimum seconds to wait before attempt 2, 3, ... (comma separated; the
            // last value repeats). A longer Retry-After from Pixelforge always wins.
            'backoff' => array_values(array_map('intval', array_filter(
                explode(',', (string) env('PIXELFORGE_SUBMISSION_BACKOFF', '5,15,30,60,120')),
                fn (string $seconds) => trim($seconds) !== '',
            ))),

            // How many submission jobs may run at the same time across all workers.
            // Set to 0 to remove the limit.
            'max_concurrent' => (int) env('PIXELFORGE_SUBMISSION_MAX_CONCURRENT', 3),

            // How long a job waits for a free slot before going back on the queue.
            'concurrency_wait' => (int) env('PIXELFORGE_SUBMISSION_CONCURRENCY_WAIT', 10),

            // Safety expiry (seconds) of a slot, in case a worker dies while holding it.
            // Keep it above the HTTP timeout.
            'concurrency_lock_ttl' => (int) env('PIXELFORGE_SUBMISSION_LOCK_TTL', 60),

            // How long a generation's uniqueness lock lasts, covering all retries.
            'unique_for' => (int) env('PIXELFORGE_SUBMISSION_UNIQUE_FOR', 900),

            // Queue (and connection) submission jobs are dispatched to; null = defaults.
            'queue' => env('PIXELFORGE_SUBMISSION_QUEUE'),
            'connection' => env('PIXELFORGE_SUBMISSION_CONNECTION'),
        ],

        /*
        | Safety net for missed webhooks (see App\Console\Commands\ReconcileGenerations).
        */
        'reconcile' => [
            // Poll an unfinished generation once it has had no update for this long.
            'stale_after' => (int) env('PIXELFORGE_RECONCILE_STALE_AFTER', 120),

            // Resubmit a never-accepted generation after this long. Keep it above
            // submission.unique_for so a job that is still retrying is left alone.
            'resubmit_after' => (int) env('PIXELFORGE_RECONCILE_RESUBMIT_AFTER', 900),

            // Most generations handled per run, to stay well inside the 60/min API limit.
            'max_per_run' => (int) env('PIXELFORGE_RECONCILE_MAX_PER_RUN', 30),
        ],

        /*
        | Webhook event log (see App\Console\Commands\PruneWebhookEvents).
        */
        'webhook_events' => [
            // Events last received more than this many days ago are deleted by
            // `php artisan webhook-events:prune`. Zero or less disables pruning.
            'retention_days' => (int) env('PIXELFORGE_WEBHOOK_EVENTS_RETENTION_DAYS', 30),

            // Rows deleted per query, so a large backlog never holds one long lock.
            'prune_chunk' => (int) env('PIXELFORGE_WEBHOOK_EVENTS_PRUNE_CHUNK', 1000),

            // Time of day (server time) the prune command runs each day.
            'prune_at' => env('PIXELFORGE_WEBHOOK_EVENTS_PRUNE_AT', '03:00'),
        ],
    ],

];
