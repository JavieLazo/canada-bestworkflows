<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Schedules;

use InvalidArgumentException;

/**
 * A pay schedule: how a month is cut into salary periods and when each is
 * paid. The figures a Pay Schedules row holds (`params`) and PayCalendar
 * reads:
 *
 *  - `periods`: the month's salary periods in order, each `start` day and
 *    `end` day (null = the month's last day), covering the month without a
 *    gap or an overlap — the semi-monthly 1–15 / 16–end, or one 1–end;
 *  - each period's `pay_day`: PERIOD_END (the period's last day), or DAY
 *    `day` of the period's month (a day the month lacks becomes its last
 *    day), optionally `next_month` (the same day of the following month);
 *  - `holiday_rule`: what a pay date on a Saturday, Sunday or holiday does
 *    — BEFORE moves it to the working day before, AFTER to the one after,
 *    KEEP leaves it;
 *  - `deadlines`: the dates counted back from the pay date — a `label` and
 *    `days_before` — that remind payroll before the pay date;
 *  - `contribution_month`: which month's contributions a payroll carries —
 *    PERIOD (the salary period's month) or PAY_DATE (the pay date's);
 *  - `attendance_lag`: how many days before the salary period the
 *    attendance window ends (0 = the period itself; a later phase prices
 *    a lagged window, so a payroll from a lagged schedule is refused).
 *
 * ONE immutable value, read strictly from a row's figures. Pure: no clock,
 * database or framework.
 */
final class PaySchedule
{
    public const string PAY_PERIOD_END = 'PERIOD_END';

    public const string PAY_DAY = 'DAY';

    public const string MOVE_BEFORE = 'BEFORE';

    public const string MOVE_AFTER = 'AFTER';

    public const string MOVE_KEEP = 'KEEP';

    public const string MONTH_PERIOD = 'PERIOD';

    public const string MONTH_PAY_DATE = 'PAY_DATE';

    /** @var list<string> */
    public const array MOVES = [self::MOVE_BEFORE, self::MOVE_AFTER, self::MOVE_KEEP];

    /** @var list<string> */
    public const array MONTHS = [self::MONTH_PERIOD, self::MONTH_PAY_DATE];

    public const int MAX_DEADLINES = 4;

    public const int MAX_DEADLINE_DAYS = 60;

    public const int MAX_LAG_DAYS = 31;

    /**
     * @param  list<array{start: int, end: ?int, pay_day: array{kind: string, day: ?int, next_month: bool}}>  $periods
     * @param  list<array{label: string, days_before: int}>  $deadlines
     */
    private function __construct(
        public readonly array $periods,
        public readonly string $holidayRule,
        public readonly array $deadlines,
        public readonly string $contributionMonth,
        public readonly int $attendanceLag,
    ) {}

    /** Today's behaviour: the 1st–15th and the 16th–end, paid on the period's last day, no move, no deadlines. */
    public static function standard(): self
    {
        return new self(
            [
                ['start' => 1, 'end' => 15, 'pay_day' => ['kind' => self::PAY_PERIOD_END, 'day' => null, 'next_month' => false]],
                ['start' => 16, 'end' => null, 'pay_day' => ['kind' => self::PAY_PERIOD_END, 'day' => null, 'next_month' => false]],
            ],
            self::MOVE_KEEP,
            [],
            self::MONTH_PERIOD,
            0,
        );
    }

    /**
     * Strictly from a row's figures.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $error = self::error($data);
        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }
        $periods = [];
        foreach ($data['periods'] as $period) {
            $periods[] = [
                'start' => (int) $period['start'],
                'end' => $period['end'] === null ? null : (int) $period['end'],
                'pay_day' => [
                    'kind' => (string) $period['pay_day']['kind'],
                    'day' => $period['pay_day']['day'] === null ? null : (int) $period['pay_day']['day'],
                    'next_month' => (bool) $period['pay_day']['next_month'],
                ],
            ];
        }
        $deadlines = [];
        foreach ($data['deadlines'] as $deadline) {
            $deadlines[] = ['label' => trim((string) $deadline['label']), 'days_before' => (int) $deadline['days_before']];
        }

        return new self($periods, (string) $data['holiday_rule'], $deadlines, (string) $data['contribution_month'], (int) $data['attendance_lag']);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'periods' => $this->periods,
            'holiday_rule' => $this->holidayRule,
            'deadlines' => $this->deadlines,
            'contribution_month' => $this->contributionMonth,
            'attendance_lag' => $this->attendanceLag,
        ];
    }

    /** Why the figures cannot be used, or null. */
    public static function error(mixed $data): ?string
    {
        if (! is_array($data)) {
            return 'The pay schedule is missing.';
        }
        foreach (['periods', 'holiday_rule', 'deadlines', 'contribution_month', 'attendance_lag'] as $key) {
            if (! array_key_exists($key, $data)) {
                return "The pay schedule is incomplete ({$key}).";
            }
        }
        if (! is_array($data['periods']) || $data['periods'] === [] || count($data['periods']) > 4) {
            return 'Cut the month into one to four salary periods.';
        }
        $expectedStart = 1;
        $count = count($data['periods']);
        foreach (array_values($data['periods']) as $index => $period) {
            if (! is_array($period) || ! isset($period['start']) || ! array_key_exists('end', $period) || ! isset($period['pay_day'])) {
                return 'Each salary period needs a first day, a last day and a pay day.';
            }
            $start = $period['start'];
            $end = $period['end'];
            if (! self::isDay($start) || ($end !== null && ! self::isDay($end))) {
                return 'A salary period starts and ends on a day of the month (1 to 28), or on the month\'s last day.';
            }
            if ((int) $start !== $expectedStart) {
                return 'The salary periods must cover the month in order, the first starting on the 1st and each starting the day after the one before.';
            }
            $last = $index === $count - 1;
            if ($last && $end !== null) {
                return 'The last salary period ends on the month\'s last day.';
            }
            if (! $last && ($end === null || (int) $end < (int) $start)) {
                return 'A salary period ends on or after the day it starts, and only the last one reaches the month\'s end.';
            }
            $expectedStart = $end === null ? 0 : (int) $end + 1;
            $payDay = $period['pay_day'];
            if (! is_array($payDay) || ! isset($payDay['kind']) || ! array_key_exists('day', $payDay) || ! array_key_exists('next_month', $payDay)) {
                return 'Each salary period needs a pay day.';
            }
            if (! in_array($payDay['kind'], [self::PAY_PERIOD_END, self::PAY_DAY], true)) {
                return 'Choose when each salary period is paid.';
            }
            if ($payDay['kind'] === self::PAY_DAY && ! self::isDay($payDay['day'], 31)) {
                return 'A pay day is a day of the month, 1 to 31.';
            }
            if (! is_bool($payDay['next_month'])) {
                return 'Say whether the pay day falls in the following month.';
            }
        }
        if (! in_array($data['holiday_rule'], self::MOVES, true)) {
            return 'Choose what a pay date on a weekend or holiday does.';
        }
        if (! is_array($data['deadlines']) || count($data['deadlines']) > self::MAX_DEADLINES) {
            return 'Enter up to '.self::MAX_DEADLINES.' deadlines.';
        }
        foreach ($data['deadlines'] as $deadline) {
            if (! is_array($deadline) || ! isset($deadline['label'], $deadline['days_before'])) {
                return 'Each deadline needs a label and how many days before the pay date it falls.';
            }
            $label = $deadline['label'];
            if (! is_string($label) || trim($label) === '' || mb_strlen(trim($label)) > 60) {
                return 'A deadline label is 1 to 60 characters.';
            }
            if (! is_int($deadline['days_before']) || $deadline['days_before'] < 0 || $deadline['days_before'] > self::MAX_DEADLINE_DAYS) {
                return 'A deadline falls 0 to '.self::MAX_DEADLINE_DAYS.' days before the pay date.';
            }
        }
        if (! in_array($data['contribution_month'], self::MONTHS, true)) {
            return 'Choose which month\'s contributions a payroll carries.';
        }
        if (! is_int($data['attendance_lag']) || $data['attendance_lag'] < 0 || $data['attendance_lag'] > self::MAX_LAG_DAYS) {
            return 'The attendance window ends 0 to '.self::MAX_LAG_DAYS.' days before the salary period.';
        }

        return null;
    }

    /** Whether the periods are the standard 1–15 and 16–end. */
    public function isSemiMonthly(): bool
    {
        return count($this->periods) === 2 && $this->periods[0]['start'] === 1 && $this->periods[0]['end'] === 15 && $this->periods[1]['start'] === 16;
    }

    /** The schedule in a sentence, for the audit row. */
    public function describe(): string
    {
        $periods = [];
        foreach ($this->periods as $period) {
            $pay = $period['pay_day']['kind'] === self::PAY_PERIOD_END
                ? 'paid on its last day'
                : 'paid on the '.self::ordinal((int) $period['pay_day']['day']).($period['pay_day']['next_month'] ? ' of the next month' : '');
            $periods[] = self::ordinal($period['start']).'–'.($period['end'] === null ? 'end' : self::ordinal($period['end'])).' '.$pay;
        }
        $move = match ($this->holidayRule) {
            self::MOVE_BEFORE => 'moved before a weekend or holiday',
            self::MOVE_AFTER => 'moved after a weekend or holiday',
            default => 'kept on a weekend or holiday',
        };
        $deadlines = array_map(static fn (array $d): string => $d['label'].' '.$d['days_before'].' days before', $this->deadlines);

        return implode('; ', $periods).'; '.$move.($deadlines === [] ? '' : '; '.implode(', ', $deadlines))
            .'; contributions of the '.($this->contributionMonth === self::MONTH_PAY_DATE ? 'pay date\'s month' : 'period\'s month')
            .($this->attendanceLag > 0 ? "; attendance window ends {$this->attendanceLag} days earlier" : '');
    }

    /** "1st", "2nd", "15th", "31st". */
    public static function ordinal(int $day): string
    {
        $suffix = match (true) {
            $day % 100 >= 11 && $day % 100 <= 13 => 'th',
            $day % 10 === 1 => 'st',
            $day % 10 === 2 => 'nd',
            $day % 10 === 3 => 'rd',
            default => 'th',
        };

        return $day.$suffix;
    }

    private static function isDay(mixed $value, int $max = 28): bool
    {
        return is_int($value) && $value >= 1 && $value <= $max;
    }
}
