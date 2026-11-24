<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\RosterScheduleChange;
use App\Modules\Timekeeping\Application\AssignmentWriter;
use App\Modules\Timekeeping\Application\DatedChange;
use App\Modules\Timekeeping\Application\RosterChanges;
use App\Modules\Timekeeping\Application\ScheduleImpact;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cancels a change to the default shift or the weekly schedule BEFORE it
 * takes effect (D3). The change row stays in the history, cancelled with
 * who and when; its rows simply never apply, so the schedule that was in
 * effect continues.
 *
 * Only a change made by hand or by import, and only while it has judged no
 * day: dated after today, or today while today's cutoff allows (the guard
 * decides). A change already in effect is history — the way forward is a
 * new change. A copy made by a pattern edit is changed by editing the
 * pattern again, so every employee on it stays on the same hours.
 */
final class CancelScheduleChange
{
    public function __construct(private readonly EmployeeDirectory $employees, private readonly DatedChange $change) {}

    public function handle(Access $actor, string $changeId, ?string $confirmed = null): string
    {
        [$write, $employeeId, $from] = $this->parts($actor, $changeId);
        $impact = $this->change->run($actor, [$employeeId], $from, null, $write, $confirmed);

        return $impact->changed() ? 'Scheduled change cancelled. The current schedule continues.' : 'That scheduled change was already cancelled.';
    }

    public function preview(Access $actor, string $changeId): ScheduleImpact
    {
        [$write, $employeeId, $from] = $this->parts($actor, $changeId);

        return $this->change->preview($actor, [$employeeId], $from, null, $write);
    }

    /** @return array{0: Closure(string, string): bool, 1: string, 2: string} */
    private function parts(Access $actor, string $changeId): array
    {
        if (! $actor->mayReadAllData(ModuleCode::ROSTER)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        $saved = RosterScheduleChange::query()->find($changeId);
        $employee = $saved === null ? null : $this->employees->forRoster($actor, $saved->employee_id);
        if ($saved === null || $employee === null || $saved->effective_from === null) {
            throw new RuntimeException('That scheduled change no longer exists.');
        }
        if ($saved->source === RosterScheduleChange::SOURCE_PATTERN_EDIT) {
            throw new RuntimeException('That change came from editing a shift pattern. Edit the pattern in Shifting Settings to change it.');
        }
        if ($saved->source === RosterScheduleChange::SOURCE_REST_DAYS) {
            throw new RuntimeException('That change moved the rest days onto this employee. Change a rest day in the weekly schedule instead.');
        }
        $from = $saved->effective_from->format('Y-m-d');
        if ($from < LocalClock::todayKey()) {
            throw new RuntimeException('That change is already in effect. Make a new change instead of cancelling it.');
        }
        $name = EmployeeName::display($employee);

        $write = static function (string $employeeId, string $start) use ($actor, $changeId, $name): bool {
            $saved = RosterScheduleChange::query()->whereKey($changeId)->lockForUpdate()->first();
            if ($saved === null || $saved->status === RosterScheduleChange::STATUS_CANCELLED) {
                return false;
            }
            $saved->status = RosterScheduleChange::STATUS_CANCELLED;
            $saved->cancelled_at = CarbonImmutable::now('UTC');
            $saved->cancelled_by = RosterChanges::accountId($actor);
            $saved->save();
            AssignmentWriter::assertPayable($employeeId, $start);
            $description = "{$name} · schedule change from ".HrisFormat::date($start).' cancelled before it took effect';
            DB::afterCommit(static fn () => AuditService::record($actor, AuditEvent::ROSTER_CHANGE_CANCELLED, 'roster_schedule_changes', $employeeId, $description));

            return true;
        };

        return [$write, $saved->employee_id, $from];
    }
}
