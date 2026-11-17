<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Statuses of generations that may still be charged to the user.
     *
     * @return array<int, self>
     */
    public static function pending(): array
    {
        return [self::Queued, self::Processing];
    }

    public function isFinal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed => true,
            default => false,
        };
    }

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        if ($this->isFinal()) {
            return false;
        }

        return match ($this) {
            self::Queued => true,
            self::Processing => $target === self::Succeeded || $target === self::Failed,
            default => false,
        };
    }
}
