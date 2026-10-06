<?php

namespace App\Exceptions;

/**
 * No global Anthropic permit was available. This is admission control, not a provider
 * failure: nothing was sent, nothing is billed, and no retry attempt should be consumed.
 */
class ProviderBusyException extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $retryAfterSeconds)
    {
        parent::__construct('AI provider capacity is busy ('.$reason.').');
    }
}
