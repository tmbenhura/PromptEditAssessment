<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('balance_usd', 18, 9)->default('20.000000000')->after('email');
            $table->boolean('is_admin')->default(false)->after('balance_usd');
        });

        Schema::create('generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('idempotency_key')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->string('size', 32)->default('1024x1024');
            $table->string('quality', 16)->default('medium');
            $table->json('reference_images')->nullable();
            $table->string('status', 32)->default('queued')->index();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->json('usage')->nullable();
            $table->json('rates')->nullable();
            $table->decimal('estimated_provider_cost', 18, 9)->default('0.000000000');
            $table->decimal('estimated_user_cost', 18, 9)->default('0.000000000');
            $table->decimal('actual_provider_cost', 18, 9)->default('0.000000000');
            $table->decimal('actual_user_cost', 18, 9)->default('0.000000000');
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('pixelforge_created_at')->nullable();
            $table->timestamp('pixelforge_started_at')->nullable();
            $table->timestamp('pixelforge_completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('generation_id')->nullable()->constrained('generations')->nullOnDelete();
            $table->string('type', 32); // purchase, generation_charge, generation_refund
            $table->decimal('amount_usd', 18, 9); // positive for additions, negative for deductions
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('event_type', 64)->index();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('generations');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['balance_usd', 'is_admin']);
        });
    }
};
