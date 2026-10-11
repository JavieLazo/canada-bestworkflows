<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Modules\Timekeeping\Contracts\Views\ScheduleDayView;
use App\Modules\Timekeeping\Infrastructure\ScheduleDayReader;
use App\Support\Access;

/**
 * The schedule each date of a Daily Time Record was measured against: its
 * hours, what decided them, and whether that is recorded history, an
 * assumption, or nobody's assigned schedule at all.
 *
 * Scoped exactly like the record it sits beside (`AttendanceSource::forDtr`):
 * EDTR or PAYROLL, and either all-data on one of them or the actor's own
 * employee. Anyone else is answered with nothing, never another person's
 * schedule.
 */
final class ScheduleDays
{
    /** @return array<string, ScheduleDayView> keyed by Y-m-d */
    public function forEmployee(Access $actor, string $employeeId, string $start, string $end): array
    {
        if ($employeeId === '' || ! $actor->hasModule(ModuleCode::EDTR, ModuleCode::PAYROLL)) {
            return [];
        }
        if (! $actor->mayReadAllData(ModuleCode::EDTR, ModuleCode::PAYROLL)
            && ! ($actor->hasEmployeeLink() && $employeeId === $actor->ownEmployeeId())) {
            return [];
        }

        return app(ScheduleDayReader::class)->between([$employeeId], $start, $end)[$employeeId] ?? [];
    }
}
