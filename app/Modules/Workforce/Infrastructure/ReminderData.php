<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Domain\Reminders\ReminderSweep;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Platform\Notifications\ReminderSource;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;

/** Private HR reminder facts follow the same employee scope as their destination screen. */
final class ReminderData implements ReminderSource
{
    public function groups(): array
    {
        return ['documents', 'probation'];
    }

    public function candidates(Access $actor, string $today, string $group): iterable
    {
        $query = $this->query($actor, $group);
        if ($group === 'documents') {
            $query->select(['id', 'employee_id', 'name', 'expires_on'])->whereNotNull('expires_on')
                ->with('employee:id,first_name,last_name,suffix,is_active,employment_status,date_hired,date_regular');
        } else {
            $query->select(['id', 'first_name', 'last_name', 'suffix', 'is_active', 'employment_status', 'date_hired', 'date_regular'])->where('is_active', true);
        }
        foreach ($query->lazyById(self::BATCH_SIZE)->chunk(self::BATCH_SIZE) as $chunk) {
            $documents = [];
            $employees = [];
            foreach ($chunk as $row) {
                if ($row instanceof EmployeeDocument) {
                    $documents[] = ['id' => $row->id, 'employee_id' => $row->employee_id,
                        'name' => $row->name, 'expires_on' => $row->expires_on?->format('Y-m-d')];
                    $employee = $row->employee;
                } else {
                    $employee = $row;
                }
                if ($employee !== null) {
                    $employees[$employee->id] = ['id' => $employee->id, 'first_name' => $employee->first_name,
                        'last_name' => $employee->last_name, 'suffix' => $employee->suffix, 'is_active' => $employee->is_active,
                        'employment_status' => $employee->employment_status, 'date_hired' => $employee->date_hired->format('Y-m-d'),
                        'date_regular' => $employee->date_regular?->format('Y-m-d')];
                }
            }
            yield ReminderSweep::build($today, (string) $actor->userId, $actor->modules, $actor->employeeId,
                employees: array_values($employees), documents: $documents, group: $group);
        }
    }

    public function visibleIds(Access $actor, string $group, array $ids): array
    {
        return $ids === [] ? [] : array_values($this->query($actor, $group)->whereKey($ids)->pluck('id')->map(static fn ($id): string => (string) $id)->all());
    }

    /** @return Builder<EmployeeDocument>|Builder<EmployeeProfile> */
    private function query(Access $actor, string $group): Builder
    {
        if ($group === 'documents') {
            $query = EmployeeDocument::query()->toEmployee($actor, 'employee_id', ModuleCode::EMPLOYEES);
        } else {
            $query = EmployeeProfile::query();
            match ($actor->rowVisibility(ModuleCode::EMPLOYEES)) {
                Access::ROWS_ALL => null,
                Access::ROWS_OWN => $query->whereKey($actor->ownEmployeeId()),
                Access::ROWS_NONE => $query->whereRaw('1 = 0'),
            };
        }

        return $actor->hasModule(ModuleCode::EMPLOYEES) ? $query : $query->whereRaw('1 = 0');
    }
}
