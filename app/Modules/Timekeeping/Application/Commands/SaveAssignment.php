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
use App\Modules\Timekeeping\Application\ScheduleImpact;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A temporary schedule for a date or a From–To period: a different shift, a
 * different place, or both, after which the employee is back on the weekly
 * schedule in effect then. No TypeScript counterpart.
 *
 *   - The shift applies on the dates the weekly schedule works and keeps its
 *     rest days; `$workOnRestDays` is the separate, explicit choice to work
 *     them. A place applies on every date in the period.
 *   - DATED AND GUARDED through `DatedChange`: today or later, today only
 *     until today's cutoff, the night before left as it was, and no shift
 *     left running into the next — including the period's last night, which
 *     finishes normally before the weekly schedule resumes.
 *   - NEVER A SILENT REPLACEMENT (D1): dates shared with a saved assignment
 *     are a conflict. Nothing is written until every one of them is named in
 *     `$replace`; each then gives up only the shared dates and keeps its own
 *     before and after, cancelled with who and when.
 *
 * Gate, locks and audit as the other roster writes: all_data + ROSTER;
 * pattern, employee, schedule rows; best-effort audit after commit.
 */
final class SaveAssignment
{
    /** A period longer than this is a schedule change, not a temporary one. */
    public const MAX_DAYS = 366;

    public function __construct(private readonly EmployeeDirectory $employees, private readonly DatedChange $change) {}

    /**
     * @param  string|array<array-key, mixed>  $shiftChoice  DEFAULT, REST, KEEP, a pattern id, or custom hours (`CustomHours`)
     * @param  list<string>  $replace  ids of the saved assignments the person chose to replace on the shared dates
     *
     * @throws AssignmentConflict
     */
    public function handle(Access $actor, string $employeeId, string $from, ?string $to, string|array $shiftChoice, string $locationChoice, bool $workOnRestDays = false, array $replace = [], ?string $confirmed = null): string
    {
        $to = $to === null || $to === '' ? $from : $to;
        [$prepare, $write, $name] = $this->parts($actor, $employeeId, $from, $to, $shiftChoice, $locationChoice, $workOnRestDays, $replace);
        $impact = $this->change->run($actor, [$employeeId], $from, $to, $write, $confirmed, $prepare);

        return $impact->changed()
            ? 'Temporary schedule saved for '.$name.', '.AssignmentWriter::span($from, $to).'.'
            : 'No changes to the schedule for '.AssignmentWriter::span($from, $to).'.';
    }

    /**
     * What the save would do and affect, with nothing saved.
     *
     * @param  string|array<array-key, mixed>  $shiftChoice  DEFAULT, REST, KEEP, a pattern id, or custom hours (`CustomHours`)
     * @param  list<string>  $replace
     *
     * @throws AssignmentConflict
     */
    public function preview(Access $actor, string $employeeId, string $from, ?string $to, string|array $shiftChoice, string $locationChoice, bool $workOnRestDays = false, array $replace = []): ScheduleImpact
    {
        $to = $to === null || $to === '' ? $from : $to;
        [$prepare, $write] = $this->parts($actor, $employeeId, $from, $to, $shiftChoice, $locationChoice, $workOnRestDays, $replace);

        return $this->change->preview($actor, [$employeeId], $from, $to, $write, $prepare);
    }

    /**
     * @param  string|array<array-key, mixed>  $shiftChoice  DEFAULT, REST, KEEP, a pattern id, or custom hours (`CustomHours`)
     * @param  list<string>  $replace
     * @return array{0: Closure(): void, 1: Closure(string, string): bool, 2: string}
     */
    private function parts(Access $actor, string $employeeId, string $from, string $to, string|array $shiftChoice, string $locationChoice, bool $workOnRestDays, array $replace): array
    {
        if (! $actor->mayReadAllData(ModuleCode::ROSTER)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        $employee = $this->employees->forRoster($actor, $employeeId);
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        if (! PayrollPeriod::isPayrollDate($from) || ! PayrollPeriod::isPayrollDate($to)) {
            throw new RuntimeException('Choose a valid calendar date.');
        }
        if ($to < $from) {
            throw new RuntimeException('The last date cannot be before the first date.');
        }
        if (count(CalendarDays::each($from, $to)) > self::MAX_DAYS) {
            throw new RuntimeException('A temporary schedule covers at most one year. For a lasting change, change the default shift or the weekly schedule.');
        }
        $shiftChoice = AssignmentWriter::choice($shiftChoice);
        if ($shiftChoice === '' || $shiftChoice === 'KEEP') {
            throw new RuntimeException('Pick a shift for these dates.');
        }
        $place = AssignmentWriter::place(trim($locationChoice));
        if ($shiftChoice === 'DEFAULT' && $place['location_kind'] === null) {
            throw new RuntimeException('Choose a shift or a location that differs from the weekly schedule.');
        }
        $name = EmployeeName::display($employee);
        $pattern = null;

        $prepare = static function () use ($shiftChoice, &$pattern): void {
            $pattern = AssignmentWriter::lockPattern($shiftChoice);
        };
        $write = static function (string $employeeId, string $start) use ($actor, $to, $shiftChoice, $place, $name, $workOnRestDays, $replace, &$pattern): bool {
            $versions = app(RosterVersions::class);
            $versions->standingAsOf([$employeeId], $to, lock: true);
            $versions->weekdaysAsOf([$employeeId], $to, lock: true, withDefaults: true);
            if ($place['site_id'] !== null && ! app(OrganizationReferences::class)->siteAssignable($place['site_id'])) {
                throw new RuntimeException(AssignmentWriter::INACTIVE_SITE);
            }
            $conflicts = AssignmentWriter::overlapping($employeeId, $start, $to);
            $unresolved = $conflicts->reject(static fn (RosterDayOverride $row): bool => in_array($row->id, $replace, true))->values();
            if ($unresolved->isNotEmpty()) {
                throw new AssignmentConflict(AssignmentWriter::describe($actor, $conflicts, $start, $to));
            }
            $hours = AssignmentWriter::hours($employeeId, $start, $shiftChoice, $pattern, null);
            $includeRestDays = AssignmentWriter::includeRestDays($employeeId, $name, $start, $to, $hours, $workOnRestDays);
            foreach ($conflicts as $row) {
                AssignmentWriter::release($actor, $row, $start, $to);
            }
            AssignmentWriter::create($actor, $employeeId, $start, $to, $hours, $place, $includeRestDays);
            AssignmentWriter::assertPayable($employeeId, $start);
            $description = AssignmentWriter::sentence($actor, $name, $start, $to, $hours, $place)
                .($includeRestDays ? ' · rest days worked' : '')
                .($conflicts->isEmpty() ? '' : ' · replaced '.$conflicts->count().' on the shared dates');
            $event = $start === $to ? AuditEvent::ROSTER_DATE_SET : AuditEvent::ROSTER_PERIOD_SET;
            DB::afterCommit(static fn () => AuditService::record($actor, $event, 'roster_day_overrides', $employeeId, $description));

            return true;
        };

        return [$prepare, $write, $name];
    }
}
