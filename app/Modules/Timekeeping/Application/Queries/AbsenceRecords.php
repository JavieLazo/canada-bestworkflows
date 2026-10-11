<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Enums\ModuleCode;
use App\Models\LeaveRequestCancellation;
use App\Models\LeaveTravelRequest;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\Timekeeping\Contracts\Views\LeaveCancellationView;
use App\Modules\Timekeeping\Contracts\Views\LeaveTravelRequestView;
use App\Modules\Timekeeping\Domain\Absence\AbsencePolicy;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Collection;

/** Absence screens share the same scoped dataset; own mode can never widen a portal list. */
final class AbsenceRecords
{
    public function visible(Access $actor, string $id): bool
    {
        return LeaveTravelRequest::query()->toEmployee($actor, 'employee_id', ModuleCode::LEAVES)->whereKey($id)->exists();
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
                'cancelled_dates' => $cancelled, 'cancellable_dates' => $eligible,
                'cancellations' => $row->cancellations->map(static fn (LeaveRequestCancellation $item): LeaveCancellationView => Snapshot::of($item, LeaveCancellationView::class, [
                    'canceller' => $reviewers->get($item->cancelled_by),
                ]))->all(),
            ]);
        });
    }
}
