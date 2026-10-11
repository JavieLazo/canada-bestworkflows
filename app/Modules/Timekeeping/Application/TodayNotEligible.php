<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use RuntimeException;

/**
 * A change that can no longer take effect TODAY (D4): today's shift has
 * started, the proposed one would have, or a punch already belongs to it.
 * `DatedChange` catches it to move an undated change to the next eligible
 * date; a change that asked for today by name is refused with `$reason`.
 */
final class TodayNotEligible extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
