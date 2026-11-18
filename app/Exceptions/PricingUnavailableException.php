<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Pixelforge's pricing can't be fetched and none has ever been stored, so no honest
 * estimate (or credit reservation) is possible.
 */
class PricingUnavailableException extends RuntimeException
{
    public function __construct(
        string $message = 'Pricing is temporarily unavailable. Please try again in a moment.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 503, $previous);
    }
}
