<?php

namespace App\Services\AI;

/**
 * One deadline could not be used as it stands. Carries a stable, loggable
 * reason beside the human message so the drop can be counted in telemetry
 * without ever recording the deadline's own text.
 *
 * Internal to ResponseValidator: it is caught per item and never surfaces.
 */
class DeadlineRejection extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
