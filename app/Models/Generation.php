<?php

namespace App\Models;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use Carbon\CarbonInterface;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $pixelforge_id
 * @property string $idempotency_key
 * @property int $user_id
 * @property string $prompt
 * @property GenerationSize $size
 * @property GenerationQuality $quality
 * @property array<int, string>|null $reference_images
 * @property GenerationStatus $status
 * @property string|null $image_url
 * @property CarbonInterface|null $image_url_expires_at
 * @property array{input_text_tokens?: int, input_image_tokens?: int, output_tokens?: int, reasoning_tokens?: int, total_tokens?: int}|null $usage
 * @property array<string, string>|null $rates
 * @property numeric-string $estimated_provider_cost
 * @property numeric-string $estimated_user_cost
 * @property numeric-string $actual_provider_cost
 * @property numeric-string $actual_user_cost
 * @property string|null $error_code
 * @property string|null $error_message
 * @property CarbonInterface|null $pixelforge_created_at
 * @property CarbonInterface|null $pixelforge_started_at
 * @property CarbonInterface|null $pixelforge_completed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'id',
    'pixelforge_id',
    'idempotency_key',
    'user_id',
    'prompt',
    'size',
    'quality',
    'reference_images',
    'status',
    'image_url',
    'image_url_expires_at',
    'usage',
    'rates',
    'estimated_provider_cost',
    'estimated_user_cost',
    'actual_provider_cost',
    'actual_user_cost',
    'error_code',
    'error_message',
    'pixelforge_created_at',
    'pixelforge_started_at',
    'pixelforge_completed_at',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => GenerationSize::class,
            'quality' => GenerationQuality::class,
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'usage' => 'array',
            'rates' => 'array',
            'image_url_expires_at' => 'datetime',
            'pixelforge_created_at' => 'datetime',
            'pixelforge_started_at' => 'datetime',
            'pixelforge_completed_at' => 'datetime',
            'estimated_provider_cost' => 'decimal:9',
            'estimated_user_cost' => 'decimal:9',
            'actual_provider_cost' => 'decimal:9',
            'actual_user_cost' => 'decimal:9',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function canTransitionTo(GenerationStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }
}
