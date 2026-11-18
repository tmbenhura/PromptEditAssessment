<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * created_at is when we recorded a transaction. For a generation charge that can
     * be later than when Pixelforge billed it (a late webhook, a missed one recovered by
     * polling), possibly on the next UTC day. occurred_at is the billing time.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('occurred_at')->nullable()->after('description')->index();
        });

        DB::table('transactions')->update(['occurred_at' => DB::raw('created_at')]);

        DB::table('transactions')
            ->where('type', 'generation_charge')
            ->whereNotNull('generation_id')
            ->orderBy('id')
            ->each(function ($transaction): void {
                $completedAt = DB::table('generations')->where('id', $transaction->generation_id)->value('pixelforge_completed_at');

                if ($completedAt !== null) {
                    DB::table('transactions')->where('id', $transaction->id)->update(['occurred_at' => $completedAt]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('occurred_at');
        });
    }
};
