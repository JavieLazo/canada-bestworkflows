<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use RuntimeException;

/**
 * A schedule change whose impact has not been confirmed — or has changed
 * since it was shown. Nothing was written; the screen shows `$impact` and
 * saves again with its token.
 */
final class ScheduleImpactPending extends RuntimeException
{
    public function __construct(public readonly ScheduleImpact $impact)
    {
        parent::__construct('Review what this schedule change affects, then confirm it.');
    }
}
