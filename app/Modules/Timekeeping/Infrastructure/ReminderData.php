<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Domain\Reminders\ReminderSweep;
use App\Models\LeaveTravelRequest;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Platform\Notifications\ReminderSource;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;

/** Review reminders only reach authorized reviewers, never the request's own employee. */
final class ReminderData implements ReminderSource
{
    public function __construct(private readonly OperationalDirectory $employees) {}

    public function groups(): array
    {
        return ['leaves'];
    }

    public function candidates(Access $actor, string $today, string $group): iterable
    {
        foreach ($this->query($actor)->select(['id', 'code', 'status', 'employee_id', 'created_at'])->where('status', 'PENDING')->lazyById(self::BATCH_SIZE)->chunk(self::BATCH_SIZE) as $chunk) {
            $leaves = [];
            $ids = [];
            foreach ($chunk as $row) {
                $leaves[] = ['id' => $row->id, 'code' => $row->code, 'status' => $row->status,
                    'employee_id' => $row->employee_id, 'created_at' => $row->created_at];
                $ids[] = $row->employee_id;
            }
            $employees = $this->employees->list($actor, array_values(array_unique($ids)))
                ->map(static fn (DirectoryEmployee $row): array => $row->toArray())->values()->all();
            yield ReminderSweep::build($today, (string) $actor->userId, $actor->modules, $actor->employeeId,
                employees: array_values($employees), leaves: $leaves, group: 'leaves');
        }
    }

    public function visibleIds(Access $actor, string $group, array $ids): array
    {
        return $ids === [] ? [] : array_values($this->query($actor)->whereKey($ids)->pluck('id')->map(static fn ($id): string => (string) $id)->all());
    }

    /** @return Builder<LeaveTravelRequest> */
    private function query(Access $actor): Builder
    {
        $query = LeaveTravelRequest::query();
        if (! $actor->mayReadAllData(ModuleCode::LEAVES)) {
            return $query->whereRaw('1 = 0');
        }
        if ($actor->hasEmployeeLink()) {
            $query->where('employee_id', '!=', $actor->ownEmployeeId());
        }

        return $query;
    }
}
