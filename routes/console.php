<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep the webhook event log from growing forever.
Schedule::command('webhook-events:prune')
    ->dailyAt((string) config('services.pixelforge.webhook_events.prune_at', '03:00'))
    ->onOneServer()
    ->withoutOverlapping();
