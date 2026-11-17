<?php

namespace App\Services\Pixelforge\Exceptions;

class PixelforgePolicyViolationException extends PixelforgeException
{
    public function __construct(
        string $message = 'The prompt violated content safety policy.',
        int $statusCode = 400,
        ?string $errorCode = 'content_policy_violation',
        ?string $errorType = 'invalid_request_error',
    ) {
        parent::__construct($message, $statusCode, $errorCode, $errorType);
    }
}
