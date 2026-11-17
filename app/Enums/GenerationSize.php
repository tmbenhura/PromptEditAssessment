<?php

namespace App\Enums;

enum GenerationSize: string
{
    case Square = '1024x1024';
    case Landscape = '1536x1024';
    case Portrait = '1024x1536';

    public function label(): string
    {
        return match ($this) {
            self::Square => 'Square (1024 x 1024)',
            self::Landscape => 'Landscape (1536 x 1024)',
            self::Portrait => 'Portrait (1024 x 1536)',
        };
    }

    public function aspectRatio(): string
    {
        return match ($this) {
            self::Square => '1:1',
            self::Landscape => '3:2',
            self::Portrait => '2:3',
        };
    }
}
