<?php

namespace App\Exceptions;

class AiProcessingException extends \RuntimeException
{
    /** $partial: strictly validated records salvaged from a truncated response, if any. */
    /** $diagnostics contains validation counts only; never rejected records or source text. */
    public function __construct(public string $classification, public int $retryAfter = 0, public ?array $partial = null, public ?array $diagnostics = null)
    {
        parent::__construct('AI operation stopped: '.$classification);
    }
}
