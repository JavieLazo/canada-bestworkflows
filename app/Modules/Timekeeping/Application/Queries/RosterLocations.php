<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\AttendanceLog;
use App\Models\RosterLocation;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\Views\WorkSiteView;
use App\Modules\Timekeeping\Application\PunchPairing;
use App\Modules\Timekeeping\Contracts\Views\LocationOnDateView;
use App\Modules\Timekeeping\Contracts\Views\RosterLocationView;
use App\Modules\Timekeeping\Domain\Attendance\PunchWorkDate;
use App\Modules\Timekeeping\Domain\Roster\ResolvedLocation;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Modules\Workforce\Contracts\PunchEmployees;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads of the weekly location roster (roster_locations, 2026_09_26_0001).
 *
 * Two scopes, deliberately different:
 *
 *   - weekly() / weeklyForEmployees() read behind the roster list's scope —
 *     all_data + (ROSTER | EDTR | PAYROLL) sees everyone, otherwise only the
 *     row you are rostered on, an unlinked account nothing — exactly as
 *     ReferenceData::shifts does for the sibling table. They show the places
 *     in effect on `$asOf` — today when null; a weekday back on the
 *     Assigned Worksite has no entry.
 *   - forStation() answers the punch station's "where am I punching today?"
 *     and is gated the way the punch itself is (PunchEmployees::find: your
 *     own employee, or anyone for all_data + EDTR), because the station shows
 *     it to PORTAL-only employees who hold none of the roster modules.
 *
 * Detached views only; site names come from OrganizationDirectory.
 */
final class RosterLocations
{
    public function __construct(private readonly ShiftRoster $roster) {}

    /** @return array<int, RosterLocationView> keyed by weekday, 0 Sunday … 6 Saturday */
    public function weekly(Access $actor, string $employeeId, ?string $asOf = null): array
    {
        return $this->weeklyForEmployees($actor, [$employeeId], $asOf)[$employeeId] ?? [];
    }

    /**
     * One query for every employee on a roster page, then one sites lookup.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array<int, RosterLocationView>> employee id => weekday => view; employees with no row are absent
     */
    public function weeklyForEmployees(Access $actor, array $employeeIds, ?string $asOf = null): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $sites = app(OrganizationDirectory::class)->sites($actor)->keyBy('id');
        $visible = RosterLocation::query()
            ->toEmployee($actor, 'employee_id', ModuleCode::ROSTER, ModuleCode::EDTR, ModuleCode::PAYROLL)
            ->whereIn('employee_id', $employeeIds)
            ->distinct()->pluck('employee_id')->all();

        $out = [];
        foreach (app(RosterVersions::class)->placesAsOf(array_values($visible), RosterVersions::dateOrToday($asOf)) as $employeeId => $days) {
            ksort($days);
            foreach ($days as $weekday => $row) {
                $out[$employeeId][$weekday] = Snapshot::of($row, RosterLocationView::class, [
                    'site_name' => $row->site_id === null ? null : $sites->get($row->site_id)?->name,
                ]);
            }
        }

        return $out;
    }

    /**
     * Where a punch pressed NOW would be judged — the station's preview.
     *
     * Keyed like a Time In, including a completed previous night handing
     * the clock to today's different early window. The punch window is read
     * without locks here; RecordPunch rechecks it under lock, and a Time Out
     * may still close an earlier Time In. `has_shift` says whether that date
     * has a shift at all, without which RecordPunch refuses a Time In, and
     * `open_time_in` whether a Time In is open for a Time Out to close —
     * read the way RecordPunch pairs (`PunchPairing`), so the station turns
     * Time Out off exactly when RecordPunch would refuse it.
     * `checks_location` says whether such a punch is measured against a
     * site at all, so the station asks the browser for a location only when
     * RecordPunch would use one. Null when the actor may not punch for this
     * employee, or the employee is gone.
     */
    public function forStation(Access $actor, string $employeeId): ?LocationOnDateView
    {
        $employee = app(PunchEmployees::class)->find($actor, $employeeId);
        if ($employee === null) {
            return null;
        }
        $today = LocalClock::todayKey();
        $previous = PayrollPeriod::addCalendarDays($today, -2);
        $schedule = $this->roster->scheduleForEmployee($employee->id, $previous, $today);
        $span = PayrollPeriod::attendanceInstants($previous, $today);
        $logs = AttendanceLog::query()->where('employee_id', $employee->id)
            ->whereBetween('logged_at', [$span['gte'], $span['lte']])
            ->orderBy('logged_at')->orderBy('id')->get(['id', 'log_type', 'logged_at'])
            ->map(static fn (AttendanceLog $row): DomainLog => DomainLog::fromArray([
                'id' => $row->id, 'log_type' => $row->log_type, 'logged_at' => $row->logged_at,
            ]))->all();
        $now = CarbonImmutable::now(LocalClock::zone());
        $workDate = PunchWorkDate::forScheduledPunch(array_values($logs), DomainLog::TIME_IN, $now, $schedule);
        $resolved = $this->roster->locationForDate($employee->id, $workDate)
            ?? ResolvedLocation::assigned($employee->work_site_id);
        $sites = app(OrganizationDirectory::class)->sites($actor)->keyBy('id');
        $view = self::onDate($workDate, $resolved, $sites);
        $openTimeIn = PunchPairing::anomaly(array_values($logs), DomainLog::TIME_OUT, $now, $schedule, $today) === null;

        // A Time Out is judged where the Time In it closes was recorded for
        // — a night begun yesterday keeps yesterday's place — so an open
        // Time In at a place that checks location keeps the check on even
        // when today's place has none.
        $checksLocation = self::checksLocation($resolved, $sites);
        if (! $checksLocation && $openTimeIn) {
            $closingDate = PunchWorkDate::forScheduledPunch(array_values($logs), DomainLog::TIME_OUT, $now, $schedule);
            $checksLocation = $closingDate !== $workDate && self::checksLocation(
                $this->roster->locationForDate($employee->id, $closingDate) ?? ResolvedLocation::assigned($employee->work_site_id),
                $sites,
            );
        }

        // RecordPunch's own tests: "no shift, no Time In", and the pairing.
        return new LocationOnDateView($view->date, $view->kind, $view->site_id, $view->site_name, $view->from_roster,
            has_shift: $this->roster->rosteredShift($employee->id, $workDate, $schedule) !== null,
            open_time_in: $openTimeIn,
            checks_location: $checksLocation);
    }

    /**
     * Whether RecordPunch measures a punch at this place against a site:
     * never on a work-from-home day, nor at a site that does not check
     * location. A site that does not resolve reads as checked — the punch is
     * refused there anyway, and the station must not promise otherwise.
     *
     * @param  Collection<array-key, WorkSiteView>  $sites  keyed by id
     */
    private static function checksLocation(ResolvedLocation $location, Collection $sites): bool
    {
        if ($location->isHome()) {
            return false;
        }
        $site = $location->siteId === null ? null : $sites->get($location->siteId);

        return $site === null || ! $site->geofence_exempt;
    }

    /**
     * The view for one resolved date — shared with DayCorrections, which
     * resolves the corrected date the same way.
     *
     * @param  Collection<array-key, WorkSiteView>  $sites  keyed by id
     */
    public static function onDate(string $date, ResolvedLocation $location, Collection $sites): LocationOnDateView
    {
        return new LocationOnDateView(
            date: $date,
            kind: $location->kind,
            site_id: $location->siteId,
            site_name: $location->siteId === null ? null : $sites->get($location->siteId)?->name,
            from_roster: $location->fromRoster,
        );
    }
}
