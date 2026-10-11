<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

/**
 * TS: the `HolidaySetting` type and `holidayOn()` at
 * copems/src/lib/dtr.ts:57-78.
 *
 * It lives in the DTR rather than in payroll because the DTR is what decides a
 * day's status, and an unworked holiday must read as a REST DAY, not an
 * absence. `payroll.ts` re-exports it so existing imports keep working.
 */
final readonly class HolidaySetting
{
    public const SCHEDULE_ONE_TIME = 'ONE_TIME';

    public function __construct(
        public string $name,
        /** 'Y-m-d'. Annual holidays match on month and day only. */
        public string $date,
        public string $schedule,
        public string $type,
        /** Premium paid on top of the day, as a percent of the daily rate. */
        public float $percent = 0.0,
    ) {}

    /**
     * TS: `holidayOn(date, holidays)` — the FIRST holiday covering a date, or
     * null. `Array.prototype.find` stops at the first match, so a date carrying
     * both a one-time and an annual holiday returns whichever the caller
     * ordered first. Reproduced with an early `return`.
     *
     * Quirk carried over: an annual holiday is matched with `.slice(5)` on both
     * sides, i.e. a raw substring compare of 'MM-DD'. If `$date` were ever a
     * full ISO timestamp the comparison would be against 'MM-DDTHH:...' and
     * would never match. It cannot be here — `$date` always comes from
     * `CalendarDays::each()` — and `$this->date` is normalised by `fromArray()`
     * the way the reference's `serialise()` normalises a `@db.Date` column.
     *
     * @param  list<self>  $holidays
     */
    public static function on(string $date, array $holidays = []): ?self
    {
        foreach ($holidays as $holiday) {
            $matches = $holiday->schedule === self::SCHEDULE_ONE_TIME
                ? $holiday->date === $date
                : substr($holiday->date, 5) === substr($date, 5);

            if ($matches) {
                return $holiday;
            }
        }

        return null;
    }

    /**
     * Every holiday on a date, in list order — two or more is a double
     * holiday, which a Pay Basis & Rates version may price (PayRules).
     *
     * @param  list<self>  $holidays
     * @return list<self>
     */
    public static function allOn(string $date, array $holidays = []): array
    {
        $out = [];
        foreach ($holidays as $holiday) {
            $matches = $holiday->schedule === self::SCHEDULE_ONE_TIME
                ? $holiday->date === $date
                : substr($holiday->date, 5) === substr($date, 5);
            if ($matches) {
                $out[] = $holiday;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $date = $row['date'] ?? '';

        return new self(
            name: (string) ($row['name'] ?? ''),
            date: $date instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($date)
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d')
                : substr((string) $date, 0, 10),
            schedule: (string) ($row['schedule'] ?? ''),
            type: (string) ($row['type'] ?? ''),
            percent: (float) ($row['percent'] ?? 0),
        );
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return list<self>
     */
    public static function listFromArrays(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::fromArray($row);
        }

        return $out;
    }
}
