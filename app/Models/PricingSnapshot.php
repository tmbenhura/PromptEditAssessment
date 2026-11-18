<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property array<string, string> $rates Price per 1M tokens, by token type
 * @property array<string, mixed> $tokens Token-count table (characters per token, output and reasoning tokens, ...)
 * @property Carbon $fetched_at
 */
#[Fillable(['rates', 'tokens', 'fetched_at'])]
class PricingSnapshot extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rates' => 'array',
            'tokens' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * The most recently confirmed pricing, if any has ever been stored.
     */
    public static function latestKnown(): ?self
    {
        return static::query()->orderByDesc('fetched_at')->orderByDesc('id')->first();
    }
}
