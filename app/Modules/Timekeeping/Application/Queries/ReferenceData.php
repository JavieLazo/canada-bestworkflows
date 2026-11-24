<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\AttendanceLog;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Modules\Timekeeping\Contracts\Views\LeaveTypeView;
use App\Modules\Timekeeping\Contracts\Views\ShiftTypeView;
use App\Modules\Timekeeping\Contracts\Views\ShiftView;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\LocalClock;
use Illuminate\Support\Collection;

/** Timekeeping reference facts retain each table's established row scope. */
final class ReferenceData
{
    /** @return Collection<int, ShiftTypeView> */
    public function shiftTypes(Access $actor, bool $ordered = false): Collection
    {
        abort_if($actor->userId === null, 403);

        return ShiftType::query()->when($ordered, static fn ($query) => $query->orderBy('name'))
            ->get()->map(static fn (ShiftType $row): ShiftTypeView => Snapshot::of($row, ShiftTypeView::class));
    }

    public function shiftType(Access $actor, string $id): ?ShiftTypeView
    {
        abort_if($actor->userId === null, 403);
        $row = ShiftType::query()->find($id);

        return $row === null ? null : Snapshot::of($row, ShiftTypeView::class);
    }

    /**
     * The default shift in effect on `$asOf` — today when null or not a date
     * — for every employee the actor may see (a change dated later is not
     * that date's shift).
     *
     * @return Collection<int, ShiftView>
     */
    public function shifts(Access $actor, ?string $asOf = null): Collection
    {
        return $this->standing($actor, null, $asOf);
    }

    /**
     * The same read for the employees named — a page of a roster table reads
     * the shifts of its own rows only. Scoped exactly like `shifts()`.
     *
     * @param  list<string>  $employeeIds
     * @return Collection<int, ShiftView>
     */
    public function shiftsFor(Access $actor, array $employeeIds, ?string $asOf = null): Collection
    {
        return $employeeIds === [] ? collect() : $this->standing($actor, $employeeIds, $asOf);
    }

    /**
     * @param  list<string>|null  $employeeIds
     * @return Collection<int, ShiftView>
     */
    private function standing(Access $actor, ?array $employeeIds, ?string $asOf): Collection
    {
        $query = Shift::query()->toEmployee($actor, 'employee_id', ModuleCode::ROSTER, ModuleCode::EDTR, ModuleCode::PAYROLL);
        if ($employeeIds !== null) {
            $query->whereIn('employee_id', $employeeIds);
        }
        $visible = $query->distinct()->pluck('employee_id')->all();
        $rows = app(RosterVersions::class)->standingAsOf(array_values($visible), RosterVersions::dateOrToday($asOf));

        return collect(array_values($rows))->map(static fn (Shift $row): ShiftView => Snapshot::of($row, ShiftView::class));
    }

    public function shift(Access $actor, string $employeeId, ?string $asOf = null): ?ShiftView
    {
        $visible = Shift::query()->toEmployee($actor, 'employee_id', ModuleCode::ROSTER, ModuleCode::EDTR, ModuleCode::PAYROLL)->where('employee_id', $employeeId)->exists();
        $row = $visible ? app(RosterVersions::class)->standingAsOf([$employeeId], RosterVersions::dateOrToday($asOf))[$employeeId] ?? null : null;

        return $row === null ? null : Snapshot::of($row, ShiftView::class);
    }

    /**
     * How many distinct employees a copy of each pattern still reaches from
     * today — their default shift or weekday hours in effect today or dated
     * later, or an assignment applying today or later — keyed by pattern id.
     * Dates already past keep their own copy, so they never count.
     *
     * Counts only — no employee row leaves the module — and DELIBERATELY
     * UNSCOPED, unlike `shifts()`: the Records badge, its Delete guard and
     * the count `SaveShiftType` re-copies onto must agree, and that command
     * rewrites every linked copy regardless of who the actor may see. Open to
     * any session, as the reference list reads are.
     *
     * @return array<string, int>
     */
    public function shiftTypeUsage(Access $actor): array
    {
        abort_if($actor->userId === null, 403);

        $today = LocalClock::todayKey();
        $versions = app(RosterVersions::class);
        $linkedIds = array_values(array_unique(array_merge(
            RosterVersions::live(Shift::query())->whereNotNull('shift_type_id')->distinct()->pluck('employee_id')->all(),
            RosterVersions::live(RosterShift::query())->whereNotNull('shift_type_id')->distinct()->pluck('employee_id')->all(),
        )));
        $rows = array_values($versions->standingAsOf($linkedIds, $today));
        foreach ($versions->weekdaysAsOf($linkedIds, $today) as $days) {
            array_push($rows, ...array_values($days));
        }
        array_push($rows,
            ...$versions->pendingStanding([], $today)->all(),
            ...$versions->pendingWeekdays([], $today)->all(),
            ...$versions->assignments(null, $today, null)->all(),
        );
        $employees = [];
        foreach ($rows as $row) {
            if ($row->shift_type_id !== null) {
                $employees[$row->shift_type_id][$row->employee_id] = true;
            }
        }

        return array_map(count(...), $employees);
    }

    /** @return Collection<int, LeaveTypeView> */
    public function leaveTypes(Access $actor): Collection
    {
        abort_if($actor->userId === null, 403);

        return LeaveType::query()->orderBy('name')->get()->map(static fn (LeaveType $row): LeaveTypeView => Snapshot::of($row, LeaveTypeView::class));
    }

    /** @return list<string> */
    public function requestTypes(Access $actor): array
    {
        return array_values(LeaveTravelRequest::query()->toEmployee($actor, 'employee_id', ModuleCode::LEAVES)->get(['request_type'])->map(static fn (LeaveTravelRequest $row): string => $row->request_type)->all());
    }

    /** @return array<string, int> */
    public function punchCounts(Access $actor): array
    {
        $ids = AttendanceLog::query()->toEmployee($actor, 'employee_id', ModuleCode::EDTR, ModuleCode::PAYROLL)
            ->orderByDesc('logged_at')->limit(1000)->pluck('site_id');
        $counts = [];
        foreach ($ids as $id) {
            if ($id !== null && $id !== '') {
                $counts[(string) $id] = ($counts[(string) $id] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
