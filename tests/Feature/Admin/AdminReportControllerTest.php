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

test('the report breaks each day down per user', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $other = User::factory()->create();

    foreach ([[$admin, '0.100000000', '0.125000000'], [$admin, '0.200000000', '0.250000000'], [$other, '0.040000000', '0.050000000']] as [$user, $provider, $charged]) {
        Generation::factory()->succeeded()->create([
            'user_id' => $user->id,
            'actual_provider_cost' => $provider,
            'actual_user_cost' => $charged,
            'pixelforge_completed_at' => now(),
        ]);
    }

    $this->actingAs($admin)->get(route('admin.reports'))
        ->assertInertia(fn ($page) => $page
            ->where('dailyReport.0.generations_count', 3)
            ->where('dailyReport.0.user_spend_usd', '0.425000000')
            ->where('dailyReport.0.users.0.id', $admin->id)
            ->where('dailyReport.0.users.0.generations_count', 2)
            ->where('dailyReport.0.users.0.user_spend_usd', '0.375000000')
            ->where('dailyReport.0.users.0.profit_usd', '0.075000000')
            ->where('dailyReport.0.users.1.id', $other->id)
            ->where('userStats.0.id', $admin->id)
        );
});

test('failed generations are free and generations Pixelforge never accepted are not reported', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Generation::factory()->failed()->create([
        'user_id' => $admin->id,
        'pixelforge_completed_at' => now(),
    ]);
    Generation::factory()->failed()->create([
        'user_id' => $admin->id,
        'pixelforge_id' => null,
        'pixelforge_completed_at' => now(),
    ]);

    $this->actingAs($admin)->get(route('admin.reports'))
        ->assertInertia(fn ($page) => $page
            ->where('summary.total_generations', 1)
            ->where('summary.total_user_spend_usd', '0.000000000')
            ->where('dailyReport.0.failed_count', 1)
        );
});

test('generations are grouped by the UTC day they completed', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Generation::factory()->succeeded()->create([
        'user_id' => $admin->id,
        'actual_provider_cost' => '1.000000000',
        'actual_user_cost' => '1.250000000',
        'created_at' => '2026-03-01 23:59:00',
        'pixelforge_completed_at' => '2026-03-02 00:00:30',
    ]);

    $this->actingAs($admin)->get(route('admin.reports', ['start_date' => '2026-03-01', 'end_date' => '2026-03-02']))
        ->assertInertia(fn ($page) => $page
            ->where('dailyReport.0.date', '2026-03-02')
            ->where('dailyReport.0.provider_cost_usd', '1.000000000')
            ->where('dailyReport.1.date', '2026-03-01')
            ->where('dailyReport.1.provider_cost_usd', '0.000000000')
        );
});

test('the date range is validated', function (array $query) {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get(route('admin.reports', $query))->assertSessionHasErrors();
})->with([
    'not a date' => [['start_date' => 'yesterday']],
    'end before start' => [['start_date' => '2026-03-05', 'end_date' => '2026-03-01']],
    'longer than Pixelforge allows' => [['start_date' => '2026-01-01', 'end_date' => '2026-06-01']],
]);

test('non-admins cannot see the report', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.reports'))->assertForbidden();
});

test('the report shows the configured markup to admins', function () {
    config(['services.pixelforge.markup_multiplier' => '1.5']);

    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get(route('admin.reports'))
        ->assertInertia(fn ($page) => $page->where('summary.markup_multiplier', '1.5'));
});
