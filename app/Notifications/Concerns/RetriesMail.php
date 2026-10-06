<?php

namespace App\Notifications\Concerns;

/**
 * The default Horizon supervisor runs jobs with tries=1, so one provider timeout used to land a verification
 * code or reset link in failed_jobs with no second attempt. Mail is idempotent enough to retry.
 */
trait RetriesMail
{
    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120];
}
