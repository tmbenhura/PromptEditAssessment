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
        Schema::table('generations', function (Blueprint $table) {
            // The most this generation can cost the user. It is held against the
            // user's balance while the generation is queued or processing.
            $table->decimal('reserved_user_cost', 18, 9)->default('0.000000000')->after('estimated_user_cost');
        });

        // Generations already in flight: the best available figure is their estimate.
        DB::table('generations')
            ->whereIn('status', ['queued', 'processing'])
            ->update(['reserved_user_cost' => DB::raw('estimated_user_cost')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('reserved_user_cost');
        });
    }
};
