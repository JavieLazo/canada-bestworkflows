<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Attendance\DayRecord;
use App\Domain\Enums\ModuleCode;
use App\Models\OvertimeApproval;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\Timekeeping\Contracts\OpenPunchLimit;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Modules\Timekeeping\Contracts\Views\OvertimeDayView;
use App\Modules\Timekeeping\Domain\Attendance\OvertimeApprovalPolicy;
use App\Support\Access;
use App\Support\ManilaClock;

/**
 * The overtime decisions behind a Daily Time Record, per day, as its OT
 * cells and the per-day dialog show them.
 *
 * Rows are scoped through the actor on EDTR (the attendance remarks' list),
 * so an all-data EDTR holder sees anyone's and a self-only viewer their own,
 * read-only. The days come from the caller's already-computed DTR, so the
 * status is judged against the SAME computed minutes the table shows. A day
 * with no overtime and no decision has no entry.
 */
final class OvertimeDecisions
{
    /**
     * @param  list<DayRecord>  $days
     * @return array<string, OvertimeDayView> keyed by date
     */
    public function forDays(Access $actor, string $employeeId, array $days, string $start, string $end): array
    {
        if ($days === [] || $employeeId === '' || $start === '' || $end === '' || $start > $end) {
            return [];
        }

        $rows = OvertimeApproval::query()
            ->toEmployee($actor, 'employee_id', ModuleCode::EDTR)
            ->where('employee_id', $employeeId)
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->get()
            ->keyBy(static fn (OvertimeApproval $row): string => $row->date->format('Y-m-d'));

        $reviewerIds = array_values(array_unique($rows->pluck('reviewed_by')->filter(static fn (mixed $id): bool => $id !== null)->map(static fn (mixed $id): int => (int) $id)->all()));
        $reviewers = $reviewerIds === [] ? collect() : app(AccountNames::class)->list($actor, $reviewerIds)->keyBy('id');
        $cap = app(OpenPunchLimit::class)->hours();
        $postedPeriods = app(PostedPayrollDates::class)->coveredPeriods($actor, [$employeeId], $start, $end)[$employeeId] ?? [];

        $out = [];
        foreach ($days as $day) {
            /** @var OvertimeApproval|null $row */
            $row = $rows->get($day->date);
            $ceiling = OvertimeApprovalPolicy::ceiling($day->overtimeMinutes, $cap);
            $status = OvertimeApprovalPolicy::status($row?->decision, $row?->approved_minutes, $row?->computed_minutes, $day->overtimeMinutes, $ceiling);
            if ($status === null) {
                continue;
            }

            $posted = false;
            foreach ($postedPeriods as $period) {
                if ($day->date >= $period['start'] && $day->date <= $period['end']) {
                    $posted = true;
                    break;
                }
            }

            $out[$day->date] = new OvertimeDayView(
                date: $day->date,
                status: $status,
                decision: $row?->decision,
                computed_minutes: OvertimeApprovalPolicy::wholeMinutes($day->overtimeMinutes),
                approved_minutes: $row?->approved_minutes,
                ceiling: $ceiling,
                payable_minutes: OvertimeApprovalPolicy::payable($row?->decision, $row?->approved_minutes, $ceiling),
                note: $row?->note,
                reviewer_name: $row?->reviewed_by !== null ? $reviewers->get($row->reviewed_by)?->name : null,
                decided_at: ($row->updated_at ?? $row?->created_at)?->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A'),
                posted: $posted,
            );
        }

        return $out;
    }
}
