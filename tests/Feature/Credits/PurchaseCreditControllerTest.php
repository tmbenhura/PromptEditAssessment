<?php

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user can purchase credits and balance is incremented', function () {
    $user = User::factory()->create([
        'balance_usd' => '10.000000000',
    ]);

    $response = $this->actingAs($user)->post(route('credits.buy'), [
        'amount' => 25,
    ]);

    $response->assertRedirect();
    $user->refresh();

    // 10.000000000 + 25.000000000 = 35.000000000
    expect($user->balance_usd)->toBe('35.000000000');

    $transaction = Transaction::where('user_id', $user->id)->first();
    expect($transaction)->not->toBeNull()
        ->and($transaction->type)->toBe('purchase')
        ->and($transaction->amount_usd)->toBe('25.000000000');
});

test('user cannot purchase invalid credit amounts', function () {
    $user = User::factory()->create(['balance_usd' => '10.000000000']);

    $response = $this->actingAs($user)->post(route('credits.buy'), [
        'amount' => -5, // invalid
    ]);

    $response->assertSessionHasErrors('amount');
    $user->refresh();
    expect($user->balance_usd)->toBe('10.000000000');
});
