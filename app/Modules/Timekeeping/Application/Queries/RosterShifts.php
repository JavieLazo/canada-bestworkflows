<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\RosterShift;
use App\Modules\Timekeeping\Contracts\Views\RosterShiftView;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;

/**
 * Weekly shift reads use the roster's established employee scope before
 * filtering, and show the weekday hours in effect on `$asOf` — today when
 * null: a weekday gone back to the default shift has no entry.
 */
final class RosterShifts
{
    /** @return array<int, RosterShiftView> */
    public function weekly(Access $actor, string $employeeId, ?string $asOf = null): array
    {
        return $this->weeklyForEmployees($actor, [$employeeId], $asOf)[$employeeId] ?? [];
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, array<int, RosterShiftView>>
     */
    public function weeklyForEmployees(Access $actor, array $employeeIds, ?string $asOf = null): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $visible = RosterShift::query()
            ->toEmployee($actor, 'employee_id', ModuleCode::ROSTER, ModuleCode::EDTR, ModuleCode::PAYROLL)
            ->whereIn('employee_id', $employeeIds)->distinct()->pluck('employee_id')->all();
        $out = [];
        foreach (app(RosterVersions::class)->weekdaysAsOf(array_values($visible), RosterVersions::dateOrToday($asOf)) as $employeeId => $days) {
            ksort($days);
            foreach ($days as $weekday => $row) {
                $out[$employeeId][$weekday] = Snapshot::of($row, RosterShiftView::class);
            }
        }

        return $out;
    }
}
