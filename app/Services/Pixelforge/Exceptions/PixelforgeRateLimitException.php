<?php

namespace App\Services\Pixelforge\Exceptions;

class PixelforgeRateLimitException extends PixelforgeException
{
    public function __construct(
        string $message,
        int $statusCode = 429,
        public readonly int $retryAfter = 5,
        ?string $errorCode = null,
        ?string $errorType = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $errorType);
    }
}
