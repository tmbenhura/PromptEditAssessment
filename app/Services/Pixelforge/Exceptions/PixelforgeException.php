<?php

namespace App\Services\Pixelforge\Exceptions;

use Exception;

class PixelforgeException extends Exception
{
    /**
     * @param  array<string, mixed>  $validationErrors
     */
    public function __construct(
        string $message,
        int $statusCode = 0,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorType = null,
        public readonly ?string $errorParam = null,
        public readonly array $validationErrors = [],
    ) {
        parent::__construct($message, $statusCode);
    }
}
