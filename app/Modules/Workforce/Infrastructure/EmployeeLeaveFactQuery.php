<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Domain\Payroll\PayrollPeriod;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Contracts\EmployeeLeaveFacts;

/** Answers Timekeeping's leave earning job from Workforce's own persistence: employment dates and the active flag, nothing else. */
final class EmployeeLeaveFactQuery implements EmployeeLeaveFacts
{
    public function forPosting(?array $ids = null): array
    {
        $out = [];
        $rows = EmployeeProfile::query()
            ->when($ids !== null, static fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('id')
            ->get(['id', 'date_hired', 'date_resigned', 'is_active']);
        foreach ($rows as $row) {
            $out[(string) $row->id] = [
                'id' => (string) $row->id,
                'date_hired' => PayrollPeriod::dateKeyFromDate($row->date_hired),
                'date_resigned' => $row->date_resigned === null ? null : PayrollPeriod::dateKeyFromDate($row->date_resigned),
                'is_active' => (bool) $row->is_active,
            ];
        }

        return $out;
    }
}
