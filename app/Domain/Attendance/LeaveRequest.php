<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

/**
 * TS: the `LeaveRequest` type at copems/src/lib/dtr.ts:41-52 — an approved
 * leave, leave without pay, or official-business travel.
 *
 * `$startDate` / `$endDate` are 'Y-m-d' strings and are compared as STRINGS
 * (`date >= l.start_date && date <= l.end_date` at dtr.ts:302), never parsed.
 */
final readonly class LeaveRequest
{
    public const STATUS_APPROVED = 'APPROVED';

    public const DAY_TYPE_HALF = 'HALF_DAY';

    public function __construct(
        public string $requestType,
        public string $startDate,
        public string $endDate,
        public string $status,
        public ?string $id = null,
        public ?string $employeeId = null,
        /**
         * Set when approved: paid leave, or leave without pay. Null for OB
         * travel — and null also READS AS PAID at dtr.ts:438
         * (`excuse.is_paid ?? true`), which only matters for a non-OB row.
         */
        public ?bool $isPaid = null,
        /** WHOLE_DAY or HALF_DAY. Missing rows are read as a whole day. */
        public ?string $dayType = null,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $start = $row['start_date'] ?? $row['startDate'] ?? '';
        $end = $row['end_date'] ?? $row['endDate'] ?? '';

        return new self(
            requestType: (string) ($row['request_type'] ?? $row['requestType'] ?? ''),
            // Trimmed to 'Y-m-d'. This is not a liberty: the reference runs
            // every row through `serialise()` (copems/src/lib/api/respond.ts),
            // which rewrites every `@db.Date` column — `start_date` and
            // `end_date` among them — to `d.toISOString().slice(0, 10)` before
            // the DTR sees it. Without that, `date <= end_date` compares
            // '2026-01-05' against '2026-01-05T00:00:00.000Z' and the last day
            // of every leave silently falls outside the range.
            startDate: self::dayOnly($start),
            endDate: self::dayOnly($end),
            status: (string) ($row['status'] ?? ''),
            id: isset($row['id']) ? (string) $row['id'] : null,
            employeeId: isset($row['employee_id']) ? (string) $row['employee_id'] : null,
            isPaid: isset($row['is_paid']) ? (bool) $row['is_paid'] : null,
            dayType: isset($row['day_type']) ? (string) $row['day_type'] : null,
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

    /** `serialise()`'s `toDay`: `d.toISOString().slice(0, 10)` — UTC, always. */
    private static function dayOnly(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }
}
