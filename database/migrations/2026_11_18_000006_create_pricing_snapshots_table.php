<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pixelforge's published pricing (rates and token tables), kept so estimates keep
     * working with the last known figures when GET /v1/pricing is unreachable.
     * A new row is added only when the pricing actually changes.
     */
    public function up(): void
    {
        Schema::create('pricing_snapshots', function (Blueprint $table) {
            $table->id();
            $table->json('rates');
            $table->json('tokens');
            // When these figures were last confirmed by Pixelforge.
            $table->timestamp('fetched_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_snapshots');
    }
};
