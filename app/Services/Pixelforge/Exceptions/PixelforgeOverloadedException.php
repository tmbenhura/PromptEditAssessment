<?php

namespace App\Services\Pixelforge\Exceptions;

class PixelforgeOverloadedException extends PixelforgeException
{
    public function __construct(
        string $message = 'Pixelforge is temporarily overloaded.',
        int $statusCode = 503,
        public readonly int $retryAfter = 2,
        ?string $errorCode = 'overloaded',
        ?string $errorType = 'api_error',
    ) {
        parent::__construct($message, $statusCode, $errorCode, $errorType);
    }
}
