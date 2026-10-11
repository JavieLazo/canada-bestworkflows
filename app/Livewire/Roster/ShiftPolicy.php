<?php

declare(strict_types=1);

namespace App\Livewire\Roster;

use App\Models\ShiftType;

/** Compatibility adapter; implementation belongs to \App\Modules\Timekeeping\Application\ShiftPolicy. */
final class ShiftPolicy
{
    /**
     * The row a pattern becomes when it is assigned — the union of
     * `shiftFromPattern` (lib/data/attendance.ts:87-102) and the server's own
     * re-derivation (api/shifts/[employeeId]/route.ts:58-71). A FLEXIBLE
     * pattern carries no clock times, so the default working day stands in.
     *
     * Both writers pass the result through `ShiftShapePolicy::shapeError()`
     * before storing it — `AssignShift::handle` at assignment and
     * `SaveShiftType::handle` when it re-copies an edited pattern onto the
     * rows linked to it — so a pattern that Records let through is still
     * refused here.
     *
     * @return array<string, mixed>
     */
    public static function attributesFromPattern(ShiftType $pattern): array
    {
        return \App\Modules\Timekeeping\Application\ShiftPolicy::attributesFromPattern($pattern);
    }
}
