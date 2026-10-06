<?php

namespace App\Exceptions;

class AiProcessingException extends \RuntimeException
{
    /** $partial: strictly validated records salvaged from a truncated response, if any. */
    public function __construct(public string $classification, public int $retryAfter = 0, public ?array $partial = null)
    {
        parent::__construct('AI operation stopped: '.$classification);
    }
}
