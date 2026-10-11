<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\RosterDayOverride;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Timekeeping\Contracts\Views\RosterDayOverrideView;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Modules\Timekeeping\Domain\Roster\ResolvedLocation;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use InvalidArgumentException;

/** Date changes are scoped like the weekly roster before choosing an employee or date. */
final class RosterDates
{
    /**
     * @param  list<string>  $employeeIds
     * @return array<string, int>
     */
    public function upcomingCounts(Access $actor, array $employeeIds, string $fromDate): array
    {
        if (! PayrollPeriod::isPayrollDate($fromDate)) {
            throw new InvalidArgumentException('Choose a valid calendar date.');
        }
        if ($employeeIds === []) {
            return [];
        }
        $visible = RosterDayOverride::query()->toEmployee($actor, 'employee_id', ModuleCode::ROSTER, ModuleCode::EDTR, ModuleCode::PAYROLL)
            ->whereIn('employee_id', $employeeIds)->distinct()->pluck('employee_id')->all();
        $counts = [];
        foreach (app(RosterVersions::class)->assignments(array_values($visible), $fromDate, null) as $row) {
            if ($row->date->format('Y-m-d') >= $fromDate) {
                $counts[$row->employee_id] = ($counts[$row->employee_id] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** @return array<string, RosterDayOverrideView> keyed by Y-m-d */
    public function forEmployee(Access $actor, string $employeeId): array
    {
        return $this->read($actor, $employeeId);
    }

    public function forDate(Access $actor, string $employeeId, string $date): ?RosterDayOverrideView
    {
        if (! PayrollPeriod::isPayrollDate($date)) {
            throw new InvalidArgumentException('Choose a valid calendar date.');
        }

        return $this->read($actor, $employeeId, $date)[$date] ?? null;
    }

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

    /**
     * The date changes still applying, keyed by their date; a cancelled or
     * replaced one stays in the history but is not listed.
     *
     * @return array<string, RosterDayOverrideView>
     */
    private function read(Access $actor, string $employeeId, ?string $date = null): array
    {
        $visible = RosterDayOverride::query()->toEmployee($actor, 'employee_id', ModuleCode::ROSTER, ModuleCode::EDTR, ModuleCode::PAYROLL)
            ->where('employee_id', $employeeId)->exists();
        $rows = $visible ? app(RosterVersions::class)->assignments([$employeeId], $date, $date)->sortBy(static fn (RosterDayOverride $row): string => $row->date->format('Y-m-d').'|'.$row->id) : [];
        $sites = app(OrganizationDirectory::class)->sites($actor)->keyBy('id');
        $out = [];
        foreach ($rows as $row) {
            // Asked for one date, the assignment covering it answers even when
            // it began earlier; listed, each is keyed by its first date.
            $out[$date ?? $row->date->format('Y-m-d')] = Snapshot::of($row, RosterDayOverrideView::class, ['site_name' => $row->site_id === null ? null : $sites->get($row->site_id)?->name]);
        }

        return $out;
    }
}
