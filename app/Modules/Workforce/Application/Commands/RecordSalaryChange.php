<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryRate;
use App\Modules\Workforce\Application\EmploymentHistoryRecorder;
use App\Modules\Workforce\Application\SalaryRates;
use App\Platform\Records\AccountEmployeeLinks;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records a dated salary change for one employee: the Rate Per Month and
 * the Rate Per Day in force from a date, with the reason and the paper
 * behind it (`employee_salary_rates`). Who: an all-data EMPLOYEES or
 * all-data PAYROLL holder — the two roles that set pay — on any record,
 * their own included. A change dated today or later is APPROVED as
 * recorded and, once its date comes, is the record's rate (today's at
 * once; a later date through `salary:apply-due`); a change dated in the
 * PAST waits PENDING for a review (ReviewSalaryChange), because it claims
 * days already judged. The date must follow every approved version's.
 *
 * Employment History gets the change on its effective date. Payroll prices
 * each day by the version in force on it (divergence 18); a finalized
 * payslip keeps what it paid, and the difference a past-dated change means
 * is an adjustment's job. Audited with AuditService, never with the
 * amounts (EMPLOYEE_UPDATED never carries them either).
 */
final class RecordSalaryChange
{
    public function handle(Access $access, string $employeeId, string $effectiveFrom, mixed $monthly, mixed $daily, string $reason, ?string $reference = null): string
    {
        if (! $access->mayReadAllData(ModuleCode::EMPLOYEES) && ! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot record a salary change.');
        }
        if (! SalaryRates::ready()) {
            throw new RuntimeException('Salary changes can be recorded once this installation\'s upgrade finishes.');
        }
        $monthly = self::amount($monthly);
        $daily = self::amount($daily);
        $error = SalaryRates::dateError($effectiveFrom) ?? SalaryRates::ratesError($monthly, $daily) ?? SalaryRates::reasonError($reason);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        $reference = trim((string) $reference);
        if (mb_strlen($reference) > SalaryRates::REFERENCE_MAX) {
            throw new RuntimeException('Keep the reference to 120 characters.');
        }
        $today = LocalClock::todayKey();

        return DB::transaction(static function () use ($access, $employeeId, $effectiveFrom, $monthly, $daily, $reason, $reference, $today): string {
            abort_unless(app(AccountEmployeeLinks::class)->isActive((int) $access->userId), 403);
            $employee = EmployeeProfile::query()->whereKey($employeeId)->lockForUpdate()->first();
            if ($employee === null) {
                throw new RuntimeException('That employee no longer exists.');
            }
            $name = EmployeeName::display($employee);
            // An employee hired before the versions existed gets their record's
            // rates as the first version, so the history starts whole.
            SalaryRates::ensureBaseline($employee, $employee->monthly_rate, $employee->daily_rate);
            $latest = EmployeeSalaryRate::query()->where('employee_id', $employeeId)->where('status', EmployeeSalaryRate::APPROVED)
                ->lockForUpdate()->orderByDesc('effective_from')->orderByDesc('id')->first();
            if ($latest !== null && $latest->effective_from->format('Y-m-d') >= $effectiveFrom) {
                throw new RuntimeException('A salary version already takes effect on '.self::day($latest->effective_from->format('Y-m-d')).'. Record the change from a later date.');
            }
            if (EmployeeSalaryRate::query()->where('employee_id', $employeeId)->where('status', EmployeeSalaryRate::PENDING)->lockForUpdate()->exists()) {
                throw new RuntimeException("A salary change for {$name} is still awaiting approval. Review it first.");
            }
            $past = $effectiveFrom < $today;
            $row = new EmployeeSalaryRate([
                'employee_id' => $employeeId,
                'effective_from' => $effectiveFrom,
                'monthly_rate' => $monthly,
                'daily_rate' => $daily,
                'reason' => trim($reason),
                'reference' => $reference === '' ? null : $reference,
                'status' => $past ? EmployeeSalaryRate::PENDING : EmployeeSalaryRate::APPROVED,
            ]);
            $row->prepared_by = (int) $access->userId;
            $row->prepared_by_name = mb_substr($access->fullName, 0, 200);
            if (! $past) {
                $row->approved_by = (int) $access->userId;
                $row->approved_by_name = mb_substr($access->fullName, 0, 200);
                $row->approved_at = CarbonImmutable::now('UTC');
            }
            if (! $row->save()) {
                throw new RuntimeException('The salary change could not be saved.');
            }
            $day = self::day($effectiveFrom);
            if ($past) {
                AuditService::record($access, AuditEvent::SALARY_CHANGE_RECORDED, 'employee_salary_rates', $row->id,
                    "{$name} · salary change from {$day} recorded, awaiting approval (".trim($reason).')');

                return "Salary change from {$day} recorded for {$name}. It waits for approval, because it is dated in the past.";
            }
            $before = EmploymentHistoryRecorder::snapshot($employee);
            if ($effectiveFrom <= $today) {
                SalaryRates::copy($employee, $row);
            }
            app(EmploymentHistoryRecorder::class)->salaryChanged($access, $employee, $before, $effectiveFrom, $monthly, $daily);
            AuditService::record($access, AuditEvent::SALARY_CHANGE_RECORDED, 'employee_salary_rates', $row->id,
                "{$name} · salary change from {$day} recorded (".trim($reason).')'.($reference === '' ? '' : " · {$reference}"));

            return $effectiveFrom <= $today
                ? "Salary change recorded for {$name}, in force from {$day}."
                : "Salary change recorded for {$name}; it takes effect on {$day}.";
        });
    }

    /** A typed amount as a float (commas allowed), blank as null, anything else as typed for the refusal. */
    private static function amount(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim(str_replace(',', '', $value));
        }
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : $value;
    }

    private static function day(string $date): string
    {
        return (DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: new DateTimeImmutable('@0'))->format('d M Y');
    }
}
