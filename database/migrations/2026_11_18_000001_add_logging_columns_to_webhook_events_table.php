<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->string('outcome', 16)->default('processed')->after('payload')->index();
            $table->string('generation_id')->nullable()->after('outcome')->index();
            $table->unsignedInteger('delivery_count')->default(1)->after('generation_id');
            $table->text('error')->nullable()->after('delivery_count');
            // Indexed because the prune command filters on it.
            $table->timestamp('last_received_at')->nullable()->after('error')->index();
        });

        DB::table('webhook_events')->update(['last_received_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropColumn(['outcome', 'generation_id', 'delivery_count', 'error', 'last_received_at']);
        });
    }
};
