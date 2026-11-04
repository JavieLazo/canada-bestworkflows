<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollTaxBalance;
use App\Modules\Payroll\Application\TaxYearToDate;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records what a tax year already carried for one employee before this
 * system posted it — the go-live OPENING balance or a new hire's
 * PREVIOUS_EMPLOYER figures — as a DRAFT to approve (ApproveTaxBalance).
 * All-data PAYROLL, for any employee including the actor's own;
 * RequiredAudit on the same transaction. Employees never enter them.
 */
final class SaveTaxBalance
{
    public function handle(Access $access, string $employeeId, mixed $taxYear, string $kind, mixed $taxableIncome, mixed $taxWithheld, string $reason, ?string $reference = null): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! TaxYearToDate::balancesReady()) {
            throw new RuntimeException('Tax balances can be recorded once this installation\'s upgrade finishes.');
        }
        if (! is_numeric($taxYear) || (int) $taxYear < 2000 || (int) $taxYear > 2100 || (string) (int) $taxYear !== trim((string) $taxYear)) {
            throw new RuntimeException('Enter the tax year as a four-digit year.');
        }
        $year = (int) $taxYear;
        if (! array_key_exists($kind, PayrollTaxBalance::KINDS)) {
            throw new RuntimeException('Choose whether this is an opening balance or a previous employer\'s figures.');
        }
        $income = self::pesos($taxableIncome, 'Enter the taxable income as an amount at or above zero.');
        $withheld = self::pesos($taxWithheld, 'Enter the tax withheld as an amount at or above zero.');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 160) {
            throw new RuntimeException('Enter the reason for this balance, at most 160 characters.');
        }
        $reference = trim((string) $reference);
        if (mb_strlen($reference) > 120) {
            throw new RuntimeException('Keep the reference to 120 characters.');
        }
        $employee = app(EmployeePayContexts::class)->get($access, [$employeeId])[0] ?? null;
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        $name = EmployeeName::display($employee);
        $label = PayrollTaxBalance::KINDS[$kind];

        return DB::transaction(static function () use ($access, $employeeId, $year, $kind, $income, $withheld, $reason, $reference, $name, $label): string {
            $row = new PayrollTaxBalance([
                'employee_id' => $employeeId, 'tax_year' => $year, 'kind' => $kind, 'taxable_income' => $income, 'tax_withheld' => $withheld,
                'reason' => $reason, 'reference' => $reference === '' ? null : $reference, 'status' => PayrollTaxBalance::DRAFT,
            ]);
            $row->prepared_by = $access->userId === null ? null : (int) $access->userId;
            $row->prepared_by_name = mb_substr($access->fullName, 0, 200);
            if (! $row->save()) {
                throw new RuntimeException('The balance could not be saved.');
            }
            RequiredAudit::record($access, AuditEvent::TAX_BALANCE_RECORDED, 'payroll_tax_balances', $row->id,
                "{$name} · {$label} for {$year} recorded as a draft ({$reason})");

            return "{$label} for {$year} recorded for {$name}. The year counts it once approved.";
        });
    }

    private static function pesos(mixed $amount, string $error): float
    {
        if (is_string($amount)) {
            $amount = trim(str_replace(',', '', $amount));
        }
        if ($amount === '' || $amount === null) {
            $amount = 0;
        }
        if (! is_numeric($amount) || (float) $amount < 0.0 || (float) $amount > 99_999_999.99) {
            throw new RuntimeException($error);
        }

        return Money::round($amount);
    }
}
