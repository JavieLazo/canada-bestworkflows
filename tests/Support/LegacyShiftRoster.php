<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\RosterDayOverride;
use App\Models\RosterLocation;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Modules\Timekeeping\Domain\Roster\ResolvedLocation;
use InvalidArgumentException;

/**
 * The single-version roster — ShiftRoster's resolution with no effective
 * dates: every row applies to every date. Tests keep it to prove the dated
 * roster reproduces it, byte for byte, wherever nothing was changed after the
 * baseline — the migration's promise that no DTR result moves. It reads rows
 * as they are, so it is only a valid oracle for data with no dated or
 * cancelled rows.
 */
final class LegacyShiftRoster
{
    public function scheduleForEmployee(string $employeeId, ?string $start = null, ?string $end = null): ?DomainShift
    {
        return $this->scheduleForEmployees([$employeeId], $start, $end)[$employeeId] ?? null;
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, DomainShift>
     */
    public function scheduleForEmployees(array $employeeIds, ?string $start = null, ?string $end = null, bool $lock = false): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $out = [];
        foreach (Shift::query()->whereIn('employee_id', $employeeIds)->orderBy('employee_id')->when($lock, static fn ($query) => $query->lockForUpdate())->get() as $row) {
            $out[$row->employee_id] = DomainShift::fromArray($row->toArray());
        }
        $weekly = [];
        foreach (RosterShift::query()->whereIn('employee_id', $employeeIds)->orderBy('employee_id')->orderBy('weekday')->when($lock, static fn ($query) => $query->lockForUpdate())->get() as $row) {
            $weekly[$row->employee_id][$row->weekday] = DomainShift::fromArray(array_replace($row->shift_data, [
                'shift_type_id' => $row->shift_type_id,
                'work_days' => $row->is_rest_day ? [-1] : [$row->weekday],
            ]));
        }
        foreach ($weekly as $employeeId => $overrides) {
            $out[$employeeId] = ($out[$employeeId] ?? DomainShift::default())->withWeekdayShifts($overrides);
        }
        $dated = [];
        $rows = RosterDayOverride::query()->whereIn('employee_id', $employeeIds)->whereNotNull('shift_data')
            ->when($start !== null, static fn ($query) => $query->where('date', '>=', $start))
            ->when($end !== null, static fn ($query) => $query->where('date', '<=', $end))->orderBy('employee_id')->orderBy('date')
            ->when($lock, static fn ($query) => $query->lockForUpdate())->get();
        foreach ($rows as $row) {
            $date = $row->date->format('Y-m-d');
            $dated[$row->employee_id][$date] = DomainShift::fromArray(array_replace($row->shift_data ?? [], [
                'shift_type_id' => $row->shift_type_id,
                'work_days' => $row->is_rest_day ? [-1] : [CalendarDays::dayOfWeek($date)],
            ]));
        }
        foreach ($dated as $employeeId => $overrides) {
            $out[$employeeId] = ($out[$employeeId] ?? DomainShift::default())->withDateShifts($overrides);
        }

        return $out;
    }

    public function locationForDate(string $employeeId, string $date): ?ResolvedLocation
    {
        if (! PayrollPeriod::isPayrollDate($date)) {
            throw new InvalidArgumentException("ShiftRoster::locationForDate() needs a Y-m-d calendar date, got \"{$date}\".");
        }

        $dated = RosterDayOverride::query()->where('employee_id', $employeeId)->where('date', $date)->whereNotNull('location_kind')->first();
        if ($dated !== null && $dated->location_kind !== null) {
            return ResolvedLocation::roster($dated->location_kind, $dated->site_id, fromDateOverride: true);
        }
        $row = RosterLocation::query()
            ->where('employee_id', $employeeId)
            ->where('weekday', CalendarDays::dayOfWeek($date))
            ->first();

        return $row === null ? null : ResolvedLocation::roster($row->kind, $row->site_id);
    }
}
