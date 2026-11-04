<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Modules\Timekeeping\Domain\Roster\ResolvedLocation;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Support\Access;

/**
 * What one date resolves to for the Shift Roster's date dialog — its hours,
 * whether it is worked and where — read only by a roster all-data holder,
 * for an employee the roster lists.
 */
final class RosterDates
{
    /**
     * What a date resolves to now, and whether the weekly schedule alone
     * works it (`usually_works`): a shift change keeps rest days, so the
     * dialog offers "Schedule work on this rest day" only where that is false.
     *
     * @return array{start_time: string, end_time: string, mode: string|null, required_hours: float, is_work_day: bool, usually_works: bool, location_label: string}|null
     */
    public function effective(Access $actor, string $employeeId, string $date): ?array
    {
        if (! $actor->mayReadAllData(ModuleCode::ROSTER) || ! PayrollPeriod::isPayrollDate($date)) {
            return null;
        }
        $employee = app(EmployeeDirectory::class)->forRoster($actor, $employeeId);
        if ($employee === null) {
            return null;
        }
        $roster = app(ShiftRoster::class);
        $shift = $roster->forDate($employeeId, $date) ?? DomainShift::default();
        $location = $roster->locationForDate($employeeId, $date) ?? ResolvedLocation::assigned($employee->work_site_id);
        $siteName = $location->siteId === null ? null : app(OrganizationDirectory::class)->sites($actor)->firstWhere('id', $location->siteId)?->name;

        $weekly = app(RosterVersions::class)->timelines([$employeeId], $date)[$employeeId]->scheduleAt($date) ?? DomainShift::default();

        return ['start_time' => $shift->startTime, 'end_time' => $shift->endTime, 'mode' => $shift->mode, 'required_hours' => $shift->requiredHoursOrDefault(),
            'is_work_day' => ShiftResolver::worksOnDate($date, $shift), 'usually_works' => ShiftResolver::worksOnDate($date, $weekly),
            'location_label' => LocationKind::label($location->kind, $siteName)];
    }
}
