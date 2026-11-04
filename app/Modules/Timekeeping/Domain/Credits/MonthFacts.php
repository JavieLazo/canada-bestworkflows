<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Credits;

/**
 * One employee's month as the earning rule reads it (LeaveEarningRule).
 * Days are scheduled working days of the month: `scheduledDays` all of
 * them, `employedScheduledDays` those on which the employee was employed,
 * `presentDays` those worked or excused with pay, `unpaidDays` those the
 * policy counts as without pay. `balanceBefore` is the type's balance
 * before this posting, for the cap. Detached and pure.
 */
final readonly class MonthFacts
{
    public function __construct(
        public int $scheduledDays,
        public int $employedScheduledDays,
        public float $presentDays,
        public float $unpaidDays,
        public float $balanceBefore,
    ) {}

    /** @return array<string, float|int> */
    public function toArray(): array
    {
        return [
            'scheduled_days' => $this->scheduledDays,
            'employed_scheduled_days' => $this->employedScheduledDays,
            'present_days' => $this->presentDays,
            'unpaid_days' => $this->unpaidDays,
            'balance_before' => $this->balanceBefore,
        ];
    }
}
