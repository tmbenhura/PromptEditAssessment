<?php

namespace App\Exceptions;

use Exception;

class InsufficientBalanceException extends Exception
{
    public function __construct(
        string $message = 'Insufficient credit balance to generate this image.',
        public readonly ?string $requiredAmount = null,
        public readonly ?string $currentBalance = null,
    ) {
        parent::__construct($message, 402);
    }
}
