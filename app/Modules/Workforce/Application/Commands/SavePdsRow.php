<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Application\EmployeeDetailRows;
use App\Modules\Workforce\Application\PdsLists;
use App\Modules\Workforce\Application\PdsRowWriter;
use App\Platform\Records\AccountEmployeeLinks;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns one Personal Data Sheet list row's save — a school, an eligibility,
 * a previous job, a training or a voluntary work entry: its validation,
 * authorization, lock order and audit. The record's dialog checks the same
 * rules (PdsLists::errors()) as feedback only; this command is the
 * authority, safe to call without a page.
 *
 * Only Employee Records all-data holders directly write these rows, including
 * their own linked record. Profile changes go through independent review. The
 * row id is re-resolved under the employee, so another employee's row id finds
 * nothing. Account eligibility is locked before the profile row, then every
 * list row is read plainly and written by primary key (PdsRowWriter). No legacy column is
 * written — the stored College and Graduate lines stay as they are.
 */
final class SavePdsRow
{
    /**
     * Saves a new row (`$rowId` null) or edits one, returning the row's
     * name for the screen's toast ("school"), or null when the list or the
     * employee is unknown. A refused row throws a RuntimeException whose
     * message is the user-facing reason.
     *
     * @param  array<string, mixed>  $form
     */
    public function handle(Access $access, string $employeeId, string $list, ?string $rowId, array $form): ?string
    {
        abort_unless($access->mayReadAllData(ModuleCode::EMPLOYEES), 403);

        if (! in_array($list, PdsLists::KEYS, true)) {
            return null;
        }

        $spec = PdsLists::spec($list);
        $errors = PdsLists::errors($list, $form);

        if ($errors !== []) {
            throw new RuntimeException((string) reset($errors));
        }

        $columns = PdsLists::columns($list, $form);
        $writer = app(PdsRowWriter::class);

        $employee = DB::transaction(static function () use ($access, $employeeId, $list, $rowId, $columns, $spec, $writer): ?EmployeeProfile {
            abort_unless(app(AccountEmployeeLinks::class)->isActive((int) $access->userId), 403);
            // The profile row first, as every writer of this employee's rows does.
            $employee = EmployeeProfile::query()->whereKey($employeeId)->lockForUpdate()->first();

            if ($employee === null) {
                return null;
            }

            if (! PdsLists::ready($list)) {
                throw new RuntimeException(EmployeeDetailRows::NOT_READY);
            }

            if ($rowId !== null) {
                $row = $writer->find($employee, $list, $rowId);

                if ($row === null) {
                    throw new RuntimeException('That '.$spec['one'].' was removed. Reload the record.');
                }

                $row->fill($columns)->save();

                return $employee;
            }

            if ($writer->count($employee, $list) >= PdsLists::MAX_ROWS) {
                throw new RuntimeException(PdsLists::capMessage($list));
            }

            $writer->insert($employee, $list, $columns);

            return $employee;
        });

        if ($employee === null) {
            return null;
        }

        AuditService::record($access, AuditEvent::EMPLOYEE_UPDATED, 'employee_profiles', $employee->id, EmployeeName::display($employee).' — '.$spec['audit'].' updated');

        return $spec['one'];
    }
}
