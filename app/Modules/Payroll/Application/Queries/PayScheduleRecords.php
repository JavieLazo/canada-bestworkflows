<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Models\PayrollPaySchedule;
use App\Modules\Payroll\Application\PaySchedules;
use App\Modules\Payroll\Contracts\Views\PayScheduleView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

/**
 * The Pay Schedules page's reads: every schedule by name, one by id, the
 * default. Readable by any signed-in account (the page sits behind the
 * PAYROLL gate); writes go through the commands, which check
 * PayrollSettingsAccess.
 */
final class PayScheduleRecords
{
    /** @return Collection<int, PayScheduleView> by name */
    public function list(Access $actor): Collection
    {
        abort_if($actor->userId === null, 403);
        if (! PaySchedules::ready()) {
            return new Collection;
        }

        return PayrollPaySchedule::query()->withCount('runs')->orderBy('name')->orderBy('id')->get()
            ->map(static fn (PayrollPaySchedule $row): PayScheduleView => Snapshot::of($row, PayScheduleView::class));
    }

    public function find(Access $actor, string $id): ?PayScheduleView
    {
        abort_if($actor->userId === null, 403);
        if (! PaySchedules::ready()) {
            return null;
        }
        $row = PayrollPaySchedule::query()->withCount('runs')->whereKey($id)->first();

        return $row === null ? null : Snapshot::of($row, PayScheduleView::class);
    }

    public function default(Access $actor): ?PayScheduleView
    {
        abort_if($actor->userId === null, 403);
        $row = PaySchedules::default();

        return $row === null ? null : Snapshot::of($row->loadCount('runs'), PayScheduleView::class);
    }
}
