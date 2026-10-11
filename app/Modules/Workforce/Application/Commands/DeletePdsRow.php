<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Application\PdsLists;
use App\Modules\Workforce\Application\PdsRowWriter;
use App\Platform\Records\AccountEmployeeLinks;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\DB;

/**
 * Owns the deletion of one Personal Data Sheet list row, under the same
 * rules as SavePdsRow: Employee Records all-data holders only, the row id
 * re-resolved under the employee, the profile row locked first and the row
 * deleted by its primary key, then one audit row naming the list, never the
 * row's values. The current job and the legacy College and Graduate lines
 * are not rows, so nothing here can delete them.
 */
final class DeletePdsRow
{
    /** The deleted row's name for the screen's toast, or null when nothing was deleted. */
    public function handle(Access $access, string $employeeId, string $list, string $rowId): ?string
    {
        abort_unless($access->mayReadAllData(ModuleCode::EMPLOYEES), 403);

        if (! in_array($list, PdsLists::KEYS, true)) {
            return null;
        }

        $spec = PdsLists::spec($list);
        $writer = app(PdsRowWriter::class);

        $deleted = DB::transaction(static function () use ($access, $employeeId, $list, $rowId, $writer): ?array {
            abort_unless(app(AccountEmployeeLinks::class)->isActive((int) $access->userId), 403);
            // The profile row first, as every writer of this employee's rows does.
            $employee = EmployeeProfile::query()->whereKey($employeeId)->lockForUpdate()->first();

            if ($employee === null || ! PdsLists::ready($list)) {
                return null;
            }

            $row = $writer->find($employee, $list, $rowId);

            if ($row === null) {
                return null;
            }

            $row->delete();

            return [$employee, PdsLists::label($list, $row->getAttributes())];
        });

        if ($deleted === null) {
            return null;
        }

        [$employee, $label] = $deleted;
        AuditService::record($access, AuditEvent::EMPLOYEE_UPDATED, 'employee_profiles', $employee->id, EmployeeName::display($employee).' — '.$spec['audit'].' updated');

        return $label;
    }
}
