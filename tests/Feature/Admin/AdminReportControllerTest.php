<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('reports page displays reconciled daily breakdown and user stats', function () {
    $user = User::factory()->create([
        'name' => 'Jane Operator',
        'is_admin' => true,
    ]);

    $generation = Generation::factory()->succeeded()->create([
        'user_id' => $user->id,
        'actual_provider_cost' => '0.100000000',
        'actual_user_cost' => '0.125000000',
        'pixelforge_completed_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('admin.reports'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Reports')
        ->has('dailyReport')
        ->has('userStats')
        ->has('summary')
        ->where('summary.total_generations', 1)
        ->where('summary.total_provider_cost_usd', '0.100000000')
        ->where('summary.total_user_spend_usd', '0.125000000')
        ->where('summary.total_profit_usd', '0.025000000')
    );
});
