<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user can be made an admin and revoked again', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->artisan('users:make-admin', ['email' => $user->email])
        ->expectsOutputToContain('is now an admin')
        ->assertSuccessful();
    expect($user->fresh()->is_admin)->toBeTrue();

    $this->artisan('users:make-admin', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
    expect($user->fresh()->is_admin)->toBeFalse();
});

test('an unknown email fails', function () {
    $this->artisan('users:make-admin', ['email' => 'nobody@example.com'])->assertFailed();
});
