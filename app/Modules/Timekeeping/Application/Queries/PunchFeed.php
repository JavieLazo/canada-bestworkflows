<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\AttendanceLog;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Timekeeping\Contracts\Views\AttendanceLogView;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** The kiosk and personal feed share scope, ordering and stamps; the portal adds an own-row pin. */
final class PunchFeed
{
    /** @return Collection<int, AttendanceLogView> */
    public function list(Access $actor, int $limit, bool $own = false): Collection
    {
        $query = $this->query($actor, $own);
        if (! $own) {
            $query->where('source', '!=', AttendanceLog::SOURCE_CORRECTION);
        }
        $rows = $query->orderByDesc('logged_at')->orderByDesc('id')->limit(max(1, min(1000, $limit)))->get();
        $employees = app(OperationalDirectory::class)->list($actor, array_values($rows->map(static fn (AttendanceLog $row): string => $row->employee_id)->unique()->all()))->keyBy('id');
        $sites = app(OrganizationDirectory::class)->sites($actor)->keyBy('id');

        return $rows->map(static fn (AttendanceLog $row): AttendanceLogView => Snapshot::of($row, AttendanceLogView::class, [
            'employee' => $employees->get($row->employee_id),
            'site' => $sites->get($row->site_id),
            'hand_entered' => in_array($row->source, ['MANUAL', AttendanceLog::SOURCE_CORRECTION], true),
        ]));
    }

    public function stamp(Access $actor, bool $own = false): string
    {
        if ($own && ! $actor->hasEmployeeLink()) {
            return 'unlinked';
        }
        $stamp = $this->query($actor, $own)->toBase()
            ->selectRaw('COUNT(*) AS punches, MAX(logged_at) AS newest, MAX(id) AS newest_id')->first();

        return ((int) ($stamp->punches ?? 0)).':'.((string) ($stamp->newest ?? '')).':'.((string) ($stamp->newest_id ?? ''));
    }

    /** @return Builder<AttendanceLog> */
    private function query(Access $actor, bool $own): Builder
    {
        $query = AttendanceLog::query()->toEmployee($actor, 'employee_id', ModuleCode::EDTR, ModuleCode::PAYROLL);
        if ($own) {
            $actor->hasEmployeeLink() ? $query->where('employee_id', '=', $actor->ownEmployeeId()) : $query->whereRaw('1 = 0');
        }

        return $query;
    }
}
