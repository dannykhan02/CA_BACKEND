<?php

namespace App\Exceptions;

use RuntimeException;

class PaystackInitializationException extends RuntimeException
{
    public function __construct(public readonly bool $definitivelyRejected = false)
    {
        parent::__construct('Unable to initialize payment. Please try again later.');
    }
}
