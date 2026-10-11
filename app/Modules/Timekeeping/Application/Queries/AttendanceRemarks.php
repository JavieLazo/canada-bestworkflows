<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\AttendanceRemark;
use App\Support\Access;

/** Remark reads use the eDTR scope even when called outside its page. */
final class AttendanceRemarks
{
    /** @return array<string, string> */
    public function between(Access $actor, string $employeeId, string $start, string $end): array
    {
        if ($start === '' || $end === '' || $start > $end) {
            return [];
        }

        return AttendanceRemark::query()->toEmployee($actor, 'employee_id', ModuleCode::EDTR)
            ->where('employee_id', $employeeId)->whereDate('date', '>=', $start)->whereDate('date', '<=', $end)
            ->get(['date', 'note'])->mapWithKeys(static fn (AttendanceRemark $row): array => [$row->date->format('Y-m-d') => (string) $row->note])->all();
    }
}
