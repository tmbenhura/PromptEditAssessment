<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property numeric-string $balance_usd
 * @property bool $is_admin
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// Balance and admin flag are deliberately not mass-assignable: they change only
// through adjustBalance() and the users:make-admin command.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'balance_usd' => 'decimal:9',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Generation, $this>
     */
    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * The most this user could still be charged for generations that are queued
     * or processing. Their final cost is unknown until they finish, so the
     * maximum possible cost is held against the balance.
     *
     * @return numeric-string
     */
    public function reservedBalance(): string
    {
        return $this->generations()
            ->whereIn('status', GenerationStatus::pending())
            ->get(['id', 'reserved_user_cost'])
            ->reduce(
                fn (string $total, Generation $generation) => bcadd($total, (string) $generation->reserved_user_cost, 9),
                '0.000000000',
            );
    }

    /**
     * Balance the user can still spend: credits minus what pending generations may cost.
     *
     * @return numeric-string
     */
    public function availableBalance(): string
    {
        return bcsub($this->balance_usd ?? '0.000000000', $this->reservedBalance(), 9);
    }

    /**
     * @param  numeric-string  $amountUsd
     */
    public function hasSufficientBalance(string $amountUsd): bool
    {
        return bccomp($this->availableBalance(), $amountUsd, 9) >= 0;
    }

    /**
     * @param  numeric-string  $amountUsd
     * @param  \DateTimeInterface|null  $occurredAt  When the money moved, if that isn't now. A
     *                                               generation is billed when Pixelforge completed it.
     */
    public function adjustBalance(
        string $amountUsd,
        string $type,
        ?Generation $generation = null,
        ?string $description = null,
        ?\DateTimeInterface $occurredAt = null,
    ): Transaction {
        return DB::transaction(function () use ($amountUsd, $type, $generation, $description, $occurredAt) {
            $user = User::query()->where('id', $this->id)->lockForUpdate()->firstOrFail();
            $newBalance = bcadd($user->balance_usd ?? '0.000000000', $amountUsd, 9);

            $user->balance_usd = $newBalance;
            $user->save();

            $this->balance_usd = $newBalance;

            return Transaction::create([
                'user_id' => $user->id,
                'generation_id' => $generation?->id,
                'type' => $type,
                'amount_usd' => $amountUsd,
                'description' => $description,
                'occurred_at' => $occurredAt ?? now(),
            ]);
        });
    }
}
