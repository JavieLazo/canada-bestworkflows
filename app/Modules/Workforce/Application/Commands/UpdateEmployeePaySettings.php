<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Application\EmployeePaySettingsInput;
use App\Platform\Audit\RequiredAudit;
use App\Platform\Records\AccountEmployeeLinks;
use App\Support\Access;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Saves one employee's hourly rate override (EmployeePaySettingsInput),
 * the one pay setting still edited in place on the record. A payroll
 * officer's write, not an Employee Records one — all-data PAYROLL — and
 * it carries the reason it was made, because the value changes what
 * payroll pays. The contribution and withholding settings are dated,
 * approved versions under Payroll (SaveGovDeductionPolicy), never written
 * here.
 *
 * The value is current, not dated: every draft payroll computes with it
 * on its next preview and asks for a fresh review (the payroll evidence
 * captures it), and a finalized payroll keeps what it was computed with.
 * Audited with RequiredAudit on the same transaction, naming the setting
 * that changed (never its amount, as EMPLOYEE_UPDATED never does) and the
 * reason. Nothing changed writes nothing.
 */
final class UpdateEmployeePaySettings
{
    /** @param array<string, mixed> $form the page's form (EmployeePaySettingsInput::toForm() shape) */
    public function handle(Access $access, string $employeeId, array $form, string $reason): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        $columns = EmployeePaySettingsInput::columns($form);
        if (is_string($columns)) {
            throw new RuntimeException($columns);
        }

        return DB::transaction(static function () use ($access, $employeeId, $columns, $reason): string {
            abort_unless(app(AccountEmployeeLinks::class)->isActive((int) $access->userId), 403);
            $employee = EmployeeProfile::query()->whereKey($employeeId)->lockForUpdate()->first();
            if ($employee === null) {
                throw new RuntimeException('That employee no longer exists.');
            }
            $changed = EmployeePaySettingsInput::changed(EmployeePaySettingsInput::normalized($employee), $columns);
            if ($changed === []) {
                return 'Nothing changed.';
            }
            $reasonError = EmployeePaySettingsInput::reasonError($reason);
            if ($reasonError !== null) {
                throw new RuntimeException($reasonError);
            }
            $employee->fill(array_intersect_key($columns, array_flip($changed)))->save();

            $name = EmployeeName::display($employee);
            $labels = array_map(static fn (string $key): string => EmployeePaySettingsInput::LABELS[$key], $changed);
            RequiredAudit::record($access, AuditEvent::PAY_SETTINGS_UPDATED, 'employee_profiles', $employee->id,
                "{$name} — ".implode(', ', array_values(array_unique($labels))).' changed ('.trim($reason).')');

            return "Pay settings saved for {$name}.";
        });
    }
}
