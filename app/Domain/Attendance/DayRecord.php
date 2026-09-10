<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

/**
 * TS: the `DayRecord` type at copems/src/lib/dtr.ts:80-101 — one row of the
 * daily time record.
 *
 * MINUTE FIELDS ARE FLOATS, not ints. Every JS `number` is a double, and the
 * project rule is plain PHP floats end to end. In practice each of them is a
 * whole number (every assignment passes through `Minutes::round()`), but a
 * float parameter lets a pathological NaN propagate the way the reference does
 * rather than raising a TypeError inside a payroll finalize transaction.
 *
 * `$status` is a plain string, matching the source's string union rather than
 * a PHP enum: `payroll.ts` compares it with `=== "INCOMPLETE"` and friends, and
 * the constants below give the same literals a name without forcing every
 * consumer to import an enum.
 */
final readonly class DayRecord
{
    public const PRESENT = 'PRESENT';

    public const LATE = 'LATE';

    public const EXCUSED = 'EXCUSED';

    public const ABSENT = 'ABSENT';

    public const INCOMPLETE = 'INCOMPLETE';

    public const OFFICIAL_BUSINESS = 'OFFICIAL_BUSINESS';

    public function __construct(
        public string $date,
        /** The raw `logged_at` string of the first TIME_IN, unmodified. */
        public ?string $timeIn,
        /** The punch out for the break, when one was recorded. */
        public ?string $breakOut,
        /** The punch back in from the break, when one was recorded. */
        public ?string $breakIn,
        /** The raw `logged_at` string of the last TIME_OUT, unmodified. */
        public ?string $timeOut,
        public float $workedMinutes,
        public float $lateMinutes,
        public float $undertimeMinutes,
        public float $overtimeMinutes,
        public bool $excused,
        public ?string $excuseType,
        /** Whether an approved leave is paid. Null when the day is not on leave. */
        public ?bool $excusePaid,
        /** Time clocked out between the first punch in and the last punch out. */
        public float $breakMinutes,
        /** How much of the day the leave covers — 1, or 0.5 for a half day. */
        public float $excuseDays,
        public string $status,
        /**
         * Computed DTR flags for missing or out-of-window punches.
         *
         * @var list<string>
         */
        public array $flags = [],
        /** Human-readable version of flags, shown when no stored remark exists. */
        public ?string $remarks = null,
    ) {}

    /**
     * Minutes worked inside the paid shift, with overtime taken back out.
     *
     * `workedMinutes` counts every minute on the clock, overtime included, and
     * payroll DEPENDS on that: the rest-day cap at PayslipCalculator.php:257
     * exists precisely because "hours beyond it are already overtime". So the
     * engine keeps its meaning and the DTR derives this instead -- the eDTR
     * prints worked and overtime side by side, and without this the overtime
     * hour is counted in both columns.
     *
     * Deliberately NOT in toArray(): that key order is pinned to dtr.ts for
     * golden-vector diffing.
     */
    public function regularMinutes(): float
    {
        return max(0.0, $this->workedMinutes - $this->overtimeMinutes);
    }

    /**
     * Key order matches the object literal at dtr.ts:426-445, so a golden
     * vector can be diffed field-for-field against the TypeScript output.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'timeIn' => $this->timeIn,
            'breakOut' => $this->breakOut,
            'breakIn' => $this->breakIn,
            'timeOut' => $this->timeOut,
            'workedMinutes' => $this->workedMinutes,
            'lateMinutes' => $this->lateMinutes,
            'undertimeMinutes' => $this->undertimeMinutes,
            'overtimeMinutes' => $this->overtimeMinutes,
            'excused' => $this->excused,
            'excuseType' => $this->excuseType,
            'excusePaid' => $this->excusePaid,
            'breakMinutes' => $this->breakMinutes,
            'excuseDays' => $this->excuseDays,
            'status' => $this->status,
        ];
    }
}
