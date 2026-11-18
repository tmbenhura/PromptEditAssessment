<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $event_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property string $outcome
 * @property string|null $generation_id
 * @property int $delivery_count
 * @property int|null $delivery_attempt Pixelforge's own count of delivery attempts (latest seen)
 * @property string|null $error
 * @property Carbon|null $last_received_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'event_id',
    'event_type',
    'payload',
    'outcome',
    'generation_id',
    'error',
    'processed_at',
])]
class WebhookEvent extends Model
{
    /** Logged and acknowledged, not applied yet. */
    public const OUTCOME_RECEIVED = 'received';

    public const OUTCOME_PROCESSED = 'processed';

    /** Received but nothing to do: ping, no event id, or unknown generation. */
    public const OUTCOME_IGNORED = 'ignored';

    public const OUTCOME_FAILED = 'failed';

    /**
     * Not applied yet: still waiting (received) or it threw last time (failed).
     */
    public function isUnfinished(): bool
    {
        return in_array($this->outcome, [self::OUTCOME_RECEIVED, self::OUTCOME_FAILED], true);
    }

    /**
     * Events logged but never applied, e.g. the deferred work failed or the process died.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeUnfinishedBefore($query, \DateTimeInterface $cutoff)
    {
        return $query
            ->whereIn('outcome', [self::OUTCOME_RECEIVED, self::OUTCOME_FAILED])
            ->where('last_received_at', '<', $cutoff);
    }

    /**
     * Events last received before the cutoff (the retention period, in days).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeOlderThanDays($query, int $days)
    {
        return $query->where('last_received_at', '<', now()->subDays($days));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivery_count' => 'integer',
            'last_received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
