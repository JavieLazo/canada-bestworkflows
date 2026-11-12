<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ShiftType;

/**
 * The seven shift patterns (seed.ts:1575-1598) - the CATALOGUE only, no
 * per-employee assignments. Extracted from ShiftSeeder so ProductionSeeder can
 * seed the reference data without dragging the demo roster along.
 */
final class ShiftTypeSeeder extends PortSeeder
{
    /** Every pattern works all seven days: a rest day is set on the employee, never on a pattern. */
    private const EVERY_DAY = [0, 1, 2, 3, 4, 5, 6];

    /** id, name, mode, start, end, grace, required hours, break start, break end, break minutes, punch break, week */
    private const TYPES = [
        ['sft-1', 'Morning', 'FIXED', '07:00:00', '16:00:00', 10, null, '11:30:00', '12:30:00', 60, true, self::EVERY_DAY],
        ['sft-2', 'Regular Day', 'FIXED', '08:00:00', '17:00:00', 15, null, '12:00:00', '13:00:00', 60, true, self::EVERY_DAY],
        ['sft-3', 'Mid Shift', 'FIXED', '12:00:00', '21:00:00', 15, null, '16:00:00', '17:00:00', 60, true, self::EVERY_DAY],
        ['sft-4', 'Night Shift', 'FIXED', '22:00:00', '06:00:00', 15, null, '02:00:00', '03:00:00', 60, true, self::EVERY_DAY],
        ['sft-5', 'Flexi 8', 'FLEXIBLE', null, null, 0, 8, null, null, 60, false, self::EVERY_DAY],
        ['sft-6', 'Half Day', 'FIXED', '08:00:00', '12:00:00', 10, null, null, null, 0, false, self::EVERY_DAY],
        ['sft-7', 'Six-Day Day Shift', 'FIXED', '08:00:00', '17:00:00', 15, null, '12:00:00', '13:00:00', 60, true, self::EVERY_DAY],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $i => [$id, $name, $mode, $start, $end, $grace, $required, $breakStart, $breakEnd, $breakMinutes, $punchBreak, $week]) {
            $this->upsert(ShiftType::class, $this->codeMatch(ShiftType::class, 'code', $i + 1, 'SFT'), [
                'id' => $id,
                'name' => $name,
                'mode' => $mode,
                'work_days' => $week,
                'start_time' => $start,
                'end_time' => $end,
                'grace_minutes' => $grace,
                'required_hours' => $required,
                'break_start' => $breakStart,
                'break_end' => $breakEnd,
                'break_minutes' => $breakMinutes,
                'requires_break_punch' => $punchBreak,
                'created_at' => $this->at(300, 9, 0),
            ]);
        }
    }
}
