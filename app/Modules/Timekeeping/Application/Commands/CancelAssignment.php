<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\RosterDayOverride;
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
 * Cancels a temporary schedule (D3). The row stays in the history with who
 * cancelled it and when.
 *
 *   - BEFORE IT STARTS it is cancelled whole: it never applies.
 *   - ONCE IT HAS STARTED it ends from the next eligible date (today until
 *     today's cutoff, else the next date), so a shift already started keeps
 *     its hours and the dates already worked keep theirs.
 *   - AFTER IT HAS ENDED it is history and cannot be cancelled.
 *
 * The weekly schedule that resumes is held to the same guard as any change:
 * it must not overlap the last shift of the cancelled part, alter an earlier
 * date, or leave a month with no working day.
 */
final class CancelAssignment
{
    public function __construct(private readonly EmployeeDirectory $employees, private readonly DatedChange $change) {}

    public function handle(Access $actor, string $assignmentId, ?string $confirmed = null): string
    {
        [$write, $employeeId, $requested, $last] = $this->parts($actor, $assignmentId);
        $impact = $this->change->run($actor, [$employeeId], $requested, $last, $write, $confirmed);
        $from = $impact->effective[$employeeId] ?? null;

        return $from === null
            ? 'No changes to that temporary schedule.'
            : ($requested !== null ? 'Temporary schedule cancelled.' : 'Temporary schedule ended. The weekly schedule resumes on '.HrisFormat::date($from).'.');
    }

    public function preview(Access $actor, string $assignmentId): ScheduleImpact
    {
        [$write, $employeeId, $requested, $last] = $this->parts($actor, $assignmentId);

        return $this->change->preview($actor, [$employeeId], $requested, $last, $write);
    }

    /** @return array{0: Closure(string, string): bool, 1: string, 2: string|null, 3: string} */
    private function parts(Access $actor, string $assignmentId): array
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
            throw new RuntimeException('That temporary schedule is already cancelled.');
        }
        if ($last < $today) {
            throw new RuntimeException('That temporary schedule has ended. Its dates are history and cannot be cancelled.');
        }
        $name = EmployeeName::display($employee);

        $write = static function (string $employeeId, string $start) use ($actor, $assignmentId, $name): bool {
            $saved = RosterDayOverride::query()->whereKey($assignmentId)->lockForUpdate()->first();
            if ($saved === null || RosterVersions::appliesThrough($saved) < $start) {
                return false;
            }
            $first = $saved->date->format('Y-m-d');
            $last = RosterVersions::appliesThrough($saved);
            RosterChanges::cancelAssignment($actor, $saved, CalendarDays::previous(max($start, $first)));
            AssignmentWriter::assertPayable($employeeId, $start);
            $description = "{$name} · ".AssignmentWriter::span($first, $last).' · '.($saved->is_rest_day ? 'Rest day' : ($saved->shift_name ?? 'weekly shift'))
                .($start > $first ? ' · ended after '.HrisFormat::date(CalendarDays::previous($start)) : ' · cancelled before it began');
            DB::afterCommit(static fn () => AuditService::record($actor, AuditEvent::ROSTER_PERIOD_CANCELLED, 'roster_day_overrides', $employeeId, $description));

            return true;
        };

        // Not begun: its own first date. Begun: the next eligible date.
        return [$write, $saved->employee_id, $first > $today ? $first : null, $last];
    }
}
