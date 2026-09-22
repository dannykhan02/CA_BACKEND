<?php

namespace App\Exceptions;

use RuntimeException;

class PaystackInitializationException extends RuntimeException
{
    public function __construct(
        public readonly bool $definitivelyRejected = false,
        public readonly string $failureReason = 'unknown',
        public readonly ?int $upstreamStatus = null,
        public readonly ?string $upstreamMessage = null,
    ) {
        parent::__construct('Unable to initialize payment. Please try again later.');
    }
}
