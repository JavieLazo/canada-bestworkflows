<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Application\EmploymentHistoryRecorder;
use App\Modules\Workforce\Application\SalaryRates;
use App\Platform\Records\AccountEmployeeLinks;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use App\Support\LocalClock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sets an employee's salary: the Rate Per Month and the Rate Per Day. AN
 * EMPLOYEE HAS ONE SALARY, NOT A LIST OF DATED VERSIONS: the rates replace
 * the ones in use, are written onto the record at once, and every draft
 * payroll prices its whole period at them on its next preview; no effective
 * date, reason or approval is asked. A finalized payslip keeps what it paid.
 * What the salary was is kept in Employment History and the audit trail
 * (SalaryRates::replace leaves one row, covering every date).
 *
 * All-data EMPLOYEES or all-data PAYROLL, one's own record included.
 * Audited with AuditService, never with the amounts.
 */
final class ReplaceSalary
{
    public function handle(Access $access, string $employeeId, mixed $monthly, mixed $daily): string
    {
        if (! $access->mayReadAllData(ModuleCode::EMPLOYEES) && ! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot change a salary.');
        }
        if (! SalaryRates::ready()) {
            throw new RuntimeException('The salary can be changed once this installation\'s upgrade finishes.');
        }
        $monthly = self::amount($monthly);
        $daily = self::amount($daily);
        $error = SalaryRates::ratesError($monthly, $daily);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        $today = LocalClock::todayKey();

        return DB::transaction(static function () use ($access, $employeeId, $monthly, $daily, $today): string {
            abort_unless(app(AccountEmployeeLinks::class)->isActive((int) $access->userId), 403);
            $employee = EmployeeProfile::query()->whereKey($employeeId)->lockForUpdate()->first();
            if ($employee === null) {
                throw new RuntimeException('That employee no longer exists.');
            }
            $name = EmployeeName::display($employee);
            $before = EmploymentHistoryRecorder::snapshot($employee);

            $employee->forceFill(['monthly_rate' => $monthly ?? 0.0, 'daily_rate' => $daily])->save();
            $row = SalaryRates::replace($access, $employee, 'Changed in Payroll Settings');

            app(EmploymentHistoryRecorder::class)->salaryChanged($access, $employee, $before, $today, $monthly, $daily);
            AuditService::record($access, AuditEvent::SALARY_CHANGE_RECORDED, 'employee_salary_rates', $row->id, "{$name} · salary replaced");

            return "Salary saved for {$name}. Draft payrolls now use it.";
        });
    }

    /** A typed amount as a float: blank is none, junk is refused by the rates rule. */
    private static function amount(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $value = trim(str_replace(',', '', $value));
            if ($value === '') {
                return null;
            }
        }

        return is_numeric($value) ? (float) $value : -1.0;
    }
}
