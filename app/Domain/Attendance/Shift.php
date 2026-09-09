<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

/**
 * TS: the `Shift` type at copems/src/lib/dtr.ts:18-39, minus `employee_id` —
 * every DTR entry point takes `Omit<Shift, "employee_id">`.
 *
 * All clock fields are 'HH:MM:SS' strings, validated upstream by the same
 * `^\d{2}:\d{2}:\d{2}$` regex the reference API uses.
 */
final readonly class Shift
{
    public const MODE_FIXED = 'FIXED';

    public const MODE_FLEXIBLE = 'FLEXIBLE';

    public function __construct(
        public string $startTime = '08:00:00',
        public string $endTime = '17:00:00',
        public int $graceMinutes = 15,
        /**
         * Optional in the source (`mode?: "FIXED" | "FLEXIBLE"`). Every test in
         * the reference is `=== "FLEXIBLE"` or `!== "FLEXIBLE"`, so null and
         * any unrecognised value behave as FIXED. Reproduced.
         */
        public ?string $mode = self::MODE_FIXED,
        public ?float $requiredHours = null,
        /**
         * Days of the week, 0 Sunday to 6 Saturday. Null or empty reads as
         * "not configured" — see ShiftResolver::workWeek().
         *
         * @var list<int>|null
         */
        public ?array $workDays = null,
        public ?string $shiftTypeId = null,
        public ?string $breakStart = null,
        public ?string $breakEnd = null,
        /** Deducted from worked time when the break was never punched. */
        public ?int $breakMinutes = null,
        public ?bool $requiresBreakPunch = null,
    ) {}

    /**
     * TS: `DEFAULT_SHIFT` — dtr.ts:106-113. What a shift falls back to when the
     * employee has no roster row at all: 08:00–17:00 Monday–Friday, 15 minutes
     * of grace, no break allowance (`break_minutes` is absent, and every read
     * of it is `?? 0`).
     */
    public static function default(): self
    {
        return new self(
            startTime: '08:00:00',
            endTime: '17:00:00',
            graceMinutes: 15,
            mode: self::MODE_FIXED,
            requiredHours: null,
            workDays: ShiftResolver::DEFAULT_WORK_DAYS,
        );
    }

    public function isFlexible(): bool
    {
        return $this->mode === self::MODE_FLEXIBLE;
    }

    /** TS: `s.break_minutes ?? 0` — dtr.ts:141, :380. */
    public function breakAllowance(): float
    {
        return (float) ($this->breakMinutes ?? 0);
    }

    /**
     * TS: `Number(s.required_hours ?? 8)` — dtr.ts:136, :269.
     *
     * NULLISH coalescing, not `||`: `required_hours = 0` stays 0 and owes no
     * hours at all, where `||` would have substituted 8. Reproduced.
     */
    public function requiredHoursOrDefault(): float
    {
        return $this->requiredHours ?? 8.0;
    }

    /**
     * Build from a snake_case `shifts` row.
     *
     * ⚠ `start_time` / `end_time` on a FLEXIBLE row are FILLER, not a
     * schedule — see the note on ShiftResolver::geometry().
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $workDays = $row['work_days'] ?? $row['workDays'] ?? null;
        if (is_string($workDays)) {
            /** @var mixed $decoded */
            $decoded = json_decode($workDays, true);
            $workDays = is_array($decoded) ? $decoded : null;
        }

        return new self(
            startTime: (string) ($row['start_time'] ?? $row['startTime'] ?? '08:00:00'),
            endTime: (string) ($row['end_time'] ?? $row['endTime'] ?? '17:00:00'),
            // 15, not 0, so a partial array degrades to DEFAULT_SHIFT rather
            // than to a stricter shift than any row on disk. `grace_minutes` is
            // NOT NULL in the schema, so this default is unreachable from a
            // real `shifts` row.
            graceMinutes: (int) ($row['grace_minutes'] ?? $row['graceMinutes'] ?? 15),
            mode: isset($row['mode']) ? (string) $row['mode'] : null,
            requiredHours: isset($row['required_hours']) ? (float) $row['required_hours'] : null,
            // `in_array($dow, $days, true)` is strict, exactly like JS
            // `Array.prototype.includes`, so the day numbers must be ints.
            workDays: is_array($workDays) ? array_values(array_map(intval(...), $workDays)) : null,
            shiftTypeId: isset($row['shift_type_id']) ? (string) $row['shift_type_id'] : null,
            breakStart: isset($row['break_start']) ? (string) $row['break_start'] : null,
            breakEnd: isset($row['break_end']) ? (string) $row['break_end'] : null,
            breakMinutes: isset($row['break_minutes']) ? (int) $row['break_minutes'] : null,
            requiresBreakPunch: isset($row['requires_break_punch'])
                ? (bool) $row['requires_break_punch']
                : null,
        );
    }
}
