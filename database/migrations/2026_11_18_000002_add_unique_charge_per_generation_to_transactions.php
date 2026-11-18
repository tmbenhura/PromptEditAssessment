<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A generation can be charged (or refunded) at most once. The application checks
     * first, but this makes the database refuse a duplicate even if two workers race.
     * Purchases have no generation, and NULLs never collide in a unique index.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unique(['generation_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['generation_id', 'type']);
        });
    }
};
