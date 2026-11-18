<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When set (and in the future), the generation is waiting for Pixelforge's daily
     * quota to reset and will be submitted then. Nothing should resubmit it earlier.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->timestamp('submit_after')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('submit_after');
        });
    }
};
