<?php

namespace App\Exceptions;

class AiProcessingException extends \RuntimeException
{
    public function __construct(public string $classification, public int $retryAfter = 0)
    {
        parent::__construct('AI operation stopped: '.$classification);
    }
}
