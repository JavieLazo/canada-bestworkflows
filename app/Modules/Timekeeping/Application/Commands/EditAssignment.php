<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\RosterDayOverride;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Timekeeping\Application\AssignmentConflict;
use App\Modules\Timekeeping\Application\AssignmentWriter;
use App\Modules\Timekeeping\Application\DatedChange;
use App\Modules\Timekeeping\Application\RosterChanges;
use App\Modules\Timekeeping\Application\ScheduleImpact;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use App\Support\ManilaClock;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Changes a saved temporary schedule.
 *
 *   - BEFORE IT STARTS its dates, shift and place can all change, under the
 *     same rules as a new one.
 *   - ONCE IT HAS STARTED the part already worked is kept: the change
 *     applies from the next eligible date only (today until today's cutoff,
 *     else the next date), `$from` is not read, and the original keeps its
 *     start date and its settings in the history, closed at the day before.
 *   - AFTER IT HAS ENDED it is history and cannot change.
 *
 * Either way nothing is overwritten: the saved row is cut and cancelled with
 * who and when, and the change is a new row naming it. Conflicts with OTHER
 * assignments are resolved explicitly, as in `SaveAssignment`.
 */
final class EditAssignment
{
    public function __construct(private readonly EmployeeDirectory $employees, private readonly DatedChange $change) {}

    /**
     * @param  string|array<array-key, mixed>  $shiftChoice  DEFAULT, REST, KEEP, a pattern id, or custom hours (`CustomHours`)
     * @param  list<string>  $replace  ids of OTHER saved assignments the person chose to replace on shared dates
     *
     * @throws AssignmentConflict
     */
    public function handle(Access $actor, string $assignmentId, string $from, ?string $to, string|array $shiftChoice, string $locationChoice, bool $workOnRestDays = false, array $replace = [], ?string $confirmed = null): string
    {
        [$prepare, $write, $employeeId, $requested, $end] = $this->parts($actor, $assignmentId, $from, $to, $shiftChoice, $locationChoice, $workOnRestDays, $replace);
        $impact = $this->change->run($actor, [$employeeId], $requested, $end, $write, $confirmed, $prepare);

        return $impact->changed()
            ? 'Temporary schedule changed, '.AssignmentWriter::span($impact->effective[$employeeId], $end).'.'
            : 'No changes to that temporary schedule.';
    }

    /**
     * @param  string|array<array-key, mixed>  $shiftChoice  DEFAULT, REST, KEEP, a pattern id, or custom hours (`CustomHours`)
     * @param  list<string>  $replace
     *
     * @throws AssignmentConflict
     */
    public function preview(Access $actor, string $assignmentId, string $from, ?string $to, string|array $shiftChoice, string $locationChoice, bool $workOnRestDays = false, array $replace = []): ScheduleImpact
    {
        [$prepare, $write, $employeeId, $requested, $end] = $this->parts($actor, $assignmentId, $from, $to, $shiftChoice, $locationChoice, $workOnRestDays, $replace);

        return $this->change->preview($actor, [$employeeId], $requested, $end, $write, $prepare);
    }

    /**
     * @param  string|array<array-key, mixed>  $shiftChoice  DEFAULT, REST, KEEP, a pattern id, or custom hours (`CustomHours`)
     * @param  list<string>  $replace
     * @return array{0: Closure(): void, 1: Closure(string, string): bool, 2: string, 3: string|null, 4: string}
     */
    private function parts(Access $actor, string $assignmentId, string $from, ?string $to, string|array $shiftChoice, string $locationChoice, bool $workOnRestDays, array $replace): array
    {
        if (! $actor->mayReadAllData(ModuleCode::ROSTER)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        $saved = RosterDayOverride::query()->find($assignmentId);
        $employee = $saved === null ? null : $this->employees->forRoster($actor, $saved->employee_id);
        if ($saved === null || $employee === null) {
            throw new RuntimeException('That temporary schedule no longer exists.');
        }
        $today = ManilaClock::todayKey();
        $first = $saved->date->format('Y-m-d');
        $last = RosterVersions::appliesThrough($saved);
        if ($last < $first) {
            throw new RuntimeException('That temporary schedule was cancelled and can no longer change.');
        }
        if ($last < $today) {
            throw new RuntimeException('That temporary schedule has ended. Its dates are history and can no longer change.');
        }
        $started = $first <= $today;
        $to = $to === null || $to === '' ? ($started ? $last : $from) : $to;
        if (! PayrollPeriod::isPayrollDate($to) || (! $started && ! PayrollPeriod::isPayrollDate($from))) {
            throw new RuntimeException('Choose a valid calendar date.');
        }
        if (! $started && $to < $from) {
            throw new RuntimeException('The last date cannot be before the first date.');
        }
        if (count(CalendarDays::each($started ? $first : $from, max($to, $started ? $first : $from))) > SaveAssignment::MAX_DAYS) {
            throw new RuntimeException('A temporary schedule covers at most one year. For a lasting change, change the default shift or the weekly schedule.');
        }
        $shiftChoice = AssignmentWriter::choice($shiftChoice);
        if ($shiftChoice === '') {
            throw new RuntimeException('Pick a shift for these dates.');
        }
        $place = AssignmentWriter::place(trim($locationChoice));
        if ($shiftChoice === 'DEFAULT' && $place['location_kind'] === null) {
            throw new RuntimeException('Choose a shift or a location that differs from the weekly schedule, or cancel this temporary schedule.');
        }
        $name = EmployeeName::display($employee);
        $pattern = null;

        $prepare = static function () use ($shiftChoice, &$pattern): void {
            $pattern = AssignmentWriter::lockPattern($shiftChoice);
        };
        $write = static function (string $employeeId, string $start) use ($actor, $assignmentId, $to, $shiftChoice, $place, $name, $workOnRestDays, $replace, &$pattern): bool {
            $versions = app(RosterVersions::class);
            $versions->standingAsOf([$employeeId], max($to, $start), lock: true);
            $versions->weekdaysAsOf([$employeeId], max($to, $start), lock: true, withDefaults: true);
            $saved = RosterDayOverride::query()->whereKey($assignmentId)->lockForUpdate()->first();
            if ($saved === null || RosterVersions::appliesThrough($saved) < $start) {
                throw new RuntimeException('That temporary schedule has already ended or was cancelled. Reload the page.');
            }
            if ($to < $start) {
                throw new RuntimeException('The last date cannot be before '.HrisFormat::date($start).', the earliest date this change can start.');
            }
            if ($place['site_id'] !== null && ! app(OrganizationReferences::class)->siteAssignable($place['site_id'], $saved->site_id)) {
                throw new RuntimeException(AssignmentWriter::INACTIVE_SITE);
            }
            $hours = AssignmentWriter::hours($employeeId, $start, $shiftChoice, $pattern, $saved);
            $includeRestDays = AssignmentWriter::includeRestDays($employeeId, $name, $start, $to, $hours, $workOnRestDays);
            // The same dates, shift, place and rest-day choice: nothing to do.
            $begun = $saved->date->format('Y-m-d') <= ManilaClock::todayKey();
            if (($begun || $saved->date->format('Y-m-d') === $start) && RosterVersions::appliesThrough($saved) === $to
                && ! (clone $saved)->fill($hours + $place + ['include_rest_days' => $includeRestDays])->isDirty()) {
                return false;
            }
            $conflicts = AssignmentWriter::overlapping($employeeId, $start, $to, except: $assignmentId);
            if ($conflicts->reject(static fn (RosterDayOverride $row): bool => in_array($row->id, $replace, true))->isNotEmpty()) {
                throw new AssignmentConflict(AssignmentWriter::describe($actor, $conflicts, $start, $to));
            }
            foreach ($conflicts as $row) {
                AssignmentWriter::release($actor, $row, $start, $to);
            }
            // The saved one keeps the dates it already worked, up to the day
            // before the change starts. One that had not begun keeps none,
            // even when the change starts later than it did.
            $first = $saved->date->format('Y-m-d');
            RosterChanges::cancelAssignment($actor, $saved, CalendarDays::previous($first <= ManilaClock::todayKey() ? $start : min($first, $start)));
            AssignmentWriter::create($actor, $employeeId, $start, $to, $hours, $place, $includeRestDays, $saved->id);
            AssignmentWriter::assertPayable($employeeId, $start);
            $description = AssignmentWriter::sentence($actor, $name, $start, $to, $hours, $place).' · changed'
                .($includeRestDays ? ' · rest days worked' : '');
            DB::afterCommit(static fn () => AuditService::record($actor, AuditEvent::ROSTER_PERIOD_SET, 'roster_day_overrides', $employeeId, $description));

            return true;
        };

        // Not begun: the date asked for. Begun: the next eligible date.
        return [$prepare, $write, $saved->employee_id, $started ? null : $from, $to];
    }
}
