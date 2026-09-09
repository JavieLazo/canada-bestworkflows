<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use DateTimeInterface;

/**
 * TS: the `AttendanceLog` type at copems/src/lib/dtr.ts:3-16 — one raw punch.
 *
 * `$loggedAt` is deliberately a STRING, not a CarbonImmutable: the reference
 * carries `logged_at` as an ISO string and hands that exact string back out as
 * `DayRecord.timeIn` / `.timeOut` / `.breakOut` / `.breakIn`. Converting on
 * the way in would change what the DTR reports.
 *
 * ⚠ THE STRING MUST CARRY ITS ZONE. `ManilaClock::instant()` reads an
 * offset-less string as MANILA WALL CLOCK (dtr.ts:206-208 does the same). The
 * `attendance_logs.logged_at` column is DATETIME(3) in UTC, so a row rendered
 * as 'Y-m-d H:i:s.v' with no offset would be read eight hours late. Build the
 * string with `AttendanceLog::isoFromUtc()` or pass the Carbon instance to
 * `fromArray()` and let it do the formatting.
 *
 * Only `$logType` and `$loggedAt` reach the DTR arithmetic. The rest of the
 * shape is kept so the same object serves the punch station and the anomaly
 * screens without a second DTO.
 */
final readonly class AttendanceLog
{
    public const TIME_IN = 'TIME_IN';

    public const TIME_OUT = 'TIME_OUT';

    public function __construct(
        /** Free text in the source, not an enum — see the pseudo-enum note in the starter pack. */
        public string $logType,
        /** ISO 8601 WITH an offset or 'Z'. Offset-less is read as Manila wall clock. */
        public string $loggedAt,
        public ?string $id = null,
        public ?string $employeeId = null,
        public ?string $siteId = null,
        public ?string $source = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?float $distanceMeters = null,
        public bool $isGeofenced = false,
        public bool $isAnomaly = false,
        public ?string $anomalyNote = null,
    ) {}

    /**
     * The exact wire form the reference works with: what Postgres `timestamptz`
     * becomes once Prisma serialises it to JSON — `2026-08-31T00:15:00.000Z`.
     *
     * Millisecond precision, because `attendance_logs.logged_at` is
     * DATETIME(3) and pair durations are computed from raw milliseconds.
     */
    public static function isoFromUtc(DateTimeInterface $at): string
    {
        return $at->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Build from a snake_case row (an Eloquent `toArray()`, a query result, a
     * seeded fixture). `logged_at` may be a string or any DateTimeInterface;
     * a DateTimeInterface is normalised to UTC ISO so the zone can never be
     * lost on the way in.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $loggedAt = $row['logged_at'] ?? $row['loggedAt'] ?? '';

        return new self(
            logType: (string) ($row['log_type'] ?? $row['logType'] ?? ''),
            loggedAt: $loggedAt instanceof DateTimeInterface
                ? self::isoFromUtc($loggedAt)
                : (string) $loggedAt,
            id: isset($row['id']) ? (string) $row['id'] : null,
            employeeId: isset($row['employee_id']) ? (string) $row['employee_id'] : null,
            siteId: isset($row['site_id']) ? (string) $row['site_id'] : null,
            source: isset($row['source']) ? (string) $row['source'] : null,
            latitude: isset($row['latitude']) ? (float) $row['latitude'] : null,
            longitude: isset($row['longitude']) ? (float) $row['longitude'] : null,
            distanceMeters: isset($row['distance_meters']) ? (float) $row['distance_meters'] : null,
            isGeofenced: (bool) ($row['is_geofenced'] ?? false),
            isAnomaly: (bool) ($row['is_anomaly'] ?? false),
            anomalyNote: isset($row['anomaly_note']) ? (string) $row['anomaly_note'] : null,
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
