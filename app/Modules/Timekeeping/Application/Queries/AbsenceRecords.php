<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Enums\ModuleCode;
use App\Models\LeaveRequestCancellation;
use App\Models\LeaveTravelRequest;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\Timekeeping\Application\LeaveScheduleHold;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Modules\Timekeeping\Contracts\Views\LeaveCancellationView;
use App\Modules\Timekeeping\Contracts\Views\LeaveTravelRequestView;
use App\Modules\Timekeeping\Domain\Absence\AbsencePolicy;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\ManilaClock;
use Illuminate\Support\Collection;

/** Absence screens share the same scoped dataset; own mode can never widen a portal list. */
final class AbsenceRecords
{
    public function visible(Access $actor, string $id): bool
    {
        return LeaveTravelRequest::query()->toEmployee($actor, 'employee_id', ModuleCode::LEAVES)->whereKey($id)->exists();
    }

    /**
     * The dates of a pending request that a posted payslip already covers,
     * oldest first, for the reviewer to see before approving: the approval
     * still lands, but never changes that payslip (Commands\ReviewAbsence,
     * which asks again under payroll's lock). Empty for anything not
     * pending or not the actor's to review.
     *
     * @return list<string>
     */
    public function paidDates(Access $actor, LeaveTravelRequestView $request): array
    {
        if ($request->status !== 'PENDING' || ! $actor->mayReadAllData(ModuleCode::LEAVES)) {
            return [];
        }
        $start = $request->start_date->format('Y-m-d');
        $end = $request->end_date->format('Y-m-d');
        $dates = [];
        foreach (app(PostedPayrollDates::class)->coveredPeriods($actor, [$request->employee_id], $start, $end, ModuleCode::LEAVES)[$request->employee_id] ?? [] as $period) {
            foreach (CalendarDays::each($period['start'], $period['end']) as $date) {
                $dates[$date] = true;
            }
        }
        ksort($dates, SORT_STRING);

        return array_keys($dates);
    }

    /**
     * Why a pending request cannot be approved yet: the sentence naming its
     * dates with no shift (LeaveScheduleHold), or null when every date has
     * one. Read for the reviewer before they click; `ReviewAbsence` asks
     * again under its lock. Null for anything not pending or not the
     * actor's to review.
     */
    public function noShiftHold(Access $actor, LeaveTravelRequestView $request): ?string
    {
        if ($request->status !== 'PENDING' || ! $actor->mayReadAllData(ModuleCode::LEAVES)) {
            return null;
        }
        $dates = LeaveScheduleHold::dates($request->employee_id, $request->start_date->format('Y-m-d'), $request->end_date->format('Y-m-d'));

        return $dates === [] ? null : LeaveScheduleHold::sentence(EmployeeName::display($request->employee, 'The employee'), $dates);
    }

    /** @return Collection<int, LeaveTravelRequestView> */
    public function list(Access $actor, bool $own = false): Collection
    {
        if ($own && ! $actor->hasEmployeeLink()) {
            return collect();
        }
        $query = LeaveTravelRequest::query()->toEmployee($actor, 'employee_id', ModuleCode::LEAVES)->orderByDesc('created_at');
        if ($own) {
            $query->where('employee_id', '=', $actor->ownEmployeeId());
        }
        $rows = $query->with(['cancellations' => static fn ($query) => $query->orderBy('date')])->get();
        $employeeIds = [];
        $reviewerIds = [];
        foreach ($rows as $row) {
            $employeeIds[] = $row->employee_id;
            if ($row->reviewed_by !== null) {
                $reviewerIds[] = $row->reviewed_by;
            }
            if ($row->recommended_by !== null) {
                $reviewerIds[] = $row->recommended_by;
            }
            foreach ($row->cancellations as $cancellation) {
                $reviewerIds[] = $cancellation->cancelled_by;
            }
        }
        $employees = app(OperationalDirectory::class)->list($actor, array_values(array_unique($employeeIds)))->keyBy('id');
        $reviewers = app(AccountNames::class)->list($actor, array_values(array_unique($reviewerIds)))->keyBy('id');

        $today = ManilaClock::todayKey();

        return $rows->map(static function (LeaveTravelRequest $row) use ($employees, $reviewers, $actor, $today): LeaveTravelRequestView {
            $cancelled = $row->cancellations->map(static fn (LeaveRequestCancellation $item): string => $item->date->format('Y-m-d'))->all();
            $eligible = [];
            if ($actor->mayReadAllData(ModuleCode::LEAVES) && $row->status === 'APPROVED' && $row->request_type !== AbsencePolicy::OFFICIAL_BUSINESS) {
                $eligible = array_values(array_filter(
                    CalendarDays::each($row->start_date->format('Y-m-d'), $row->end_date->format('Y-m-d')),
                    static fn (string $date): bool => $date > $today && ! in_array($date, $cancelled, true),
                ));
            }

            return Snapshot::of($row, LeaveTravelRequestView::class, [
                'employee' => $employees->get($row->employee_id), 'reviewer' => $reviewers->get($row->reviewed_by),
                'recommender' => $row->recommended_by === null ? null : $reviewers->get($row->recommended_by),
                'cancelled_dates' => $cancelled, 'cancellable_dates' => $eligible,
                'cancellations' => $row->cancellations->map(static fn (LeaveRequestCancellation $item): LeaveCancellationView => Snapshot::of($item, LeaveCancellationView::class, [
                    'canceller' => $reviewers->get($item->cancelled_by),
                ]))->all(),
            ]);
        });
    }
}
