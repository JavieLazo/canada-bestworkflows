<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Models\ShiftType;

/**
 * The derivation the PUT /api/shifts/[employeeId] route performs when
 * `shift_type_id` is set (route.ts:58-71): the pattern is the authority, and
 * its hours, grace and break are copied onto the employee's row.
 *
 * The shape rules the same route then applies to that row live in
 * `App\Domain\Attendance\ShiftShapePolicy`, shared with Records' shift-type
 * editor. This class stays in the module because it needs the Eloquent
 * `ShiftType`, which the Domain layer must never import.
 */
final class ShiftPolicy
{
    /**
     * The row a pattern becomes when it is assigned — the union of
     * `shiftFromPattern` (lib/data/attendance.ts:87-102) and the server's own
     * re-derivation (api/shifts/[employeeId]/route.ts:58-71). A FLEXIBLE
     * pattern carries no clock times, so the default working day stands in.
     *
     * Standing and weekday assignments, and pattern-edit fan-out, pass the
     * copy through `ShiftShapePolicy::shapeError()` before storing it. A
     * weekday assignment then pins work_days to the selected weekday.
     *
     * @return array<string, mixed>
     */
    public static function attributesFromPattern(ShiftType $pattern): array
    {
        return [
            'shift_type_id' => $pattern->id,
            'mode' => $pattern->mode,
            'required_hours' => $pattern->required_hours,
            'work_days' => $pattern->work_days,
            'start_time' => $pattern->start_time ?? '08:00:00',
            'end_time' => $pattern->end_time ?? '17:00:00',
            'grace_minutes' => $pattern->grace_minutes,
            'break_start' => $pattern->break_start,
            'break_end' => $pattern->break_end,
            'break_minutes' => $pattern->break_minutes,
            'requires_break_punch' => $pattern->requires_break_punch,
        ];
    }
}
