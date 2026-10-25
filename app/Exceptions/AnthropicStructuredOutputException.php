<?php

namespace App\Exceptions;

class AnthropicStructuredOutputException extends \RuntimeException
{
    public function __construct(public readonly string $outputStatus, string $message, ?\Throwable $previous = null,
        public readonly array $diagnostics = [])
    {
        parent::__construct($message, 0, $previous);
    }
}
