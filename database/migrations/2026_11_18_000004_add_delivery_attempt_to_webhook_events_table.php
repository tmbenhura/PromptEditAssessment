<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pixelforge-Delivery-Attempt: its own count of attempts to deliver the event,
     * including automatic retries and resends from its dashboard. Handy when debugging.
     */
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->unsignedSmallInteger('delivery_attempt')->nullable()->after('delivery_count');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropColumn('delivery_attempt');
        });
    }
};
