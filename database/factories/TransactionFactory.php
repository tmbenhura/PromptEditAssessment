<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'generation_id' => null,
            'type' => 'purchase',
            'amount_usd' => '25.000000000',
            'description' => 'Credit purchase',
            'occurred_at' => now(),
        ];
    }

    public function charge(Generation $generation, string $amount): static
    {
        return $this->state(fn () => [
            'user_id' => $generation->user_id,
            'generation_id' => $generation->id,
            'type' => 'generation_charge',
            'amount_usd' => '-'.ltrim($amount, '-'),
            'description' => 'Image generation charge',
            'occurred_at' => $generation->pixelforge_completed_at ?? now(),
        ]);
    }
}
