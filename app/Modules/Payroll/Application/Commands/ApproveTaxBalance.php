<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollTaxBalance;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\TaxYearToDate;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Approves a drafted tax balance: from then on the year's figures include
 * it (TaxYearToDate). Any all-data PAYROLL holder, the preparer and the
 * employee's own account included — one administrator must be able to
 * run the whole flow alone; the row is taken under payroll's membership
 * lock so no payroll prices the year while it flips; RequiredAudit on the
 * same transaction.
 */
final class ApproveTaxBalance
{
    public function handle(Access $access, string $id): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! TaxYearToDate::balancesReady()) {
            throw new RuntimeException('Tax balances can be approved once this installation\'s upgrade finishes.');
        }

        return DB::transaction(static function () use ($access, $id): string {
            if (! PayrollTaxBalance::query()->whereKey($id)->exists()) {
                throw new RuntimeException('That balance no longer exists.');
            }
            PayrollData::lockMembership();
            $row = PayrollTaxBalance::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That balance no longer exists.');
            }
            if ($row->status !== PayrollTaxBalance::DRAFT) {
                throw new RuntimeException('That balance is already approved.');
            }
            $employee = app(EmployeePayContexts::class)->get($access, [$row->employee_id])[0] ?? null;
            if ($employee === null) {
                throw new RuntimeException('That employee no longer exists.');
            }
            $row->status = PayrollTaxBalance::APPROVED;
            $row->approved_by = $access->userId === null ? null : (int) $access->userId;
            $row->approved_by_name = mb_substr($access->fullName, 0, 200);
            $row->approved_at = CarbonImmutable::now('UTC');
            if (! $row->save()) {
                throw new RuntimeException('The balance could not be approved.');
            }
            $name = EmployeeName::display($employee);
            $label = PayrollTaxBalance::KINDS[$row->kind] ?? $row->kind;
            RequiredAudit::record($access, AuditEvent::TAX_BALANCE_APPROVED, 'payroll_tax_balances', $row->id,
                "{$name} · {$label} for {$row->tax_year} approved (prepared by ".($row->prepared_by_name ?? 'an unnamed account').')');

            return "{$label} for {$row->tax_year} approved for {$name}.";
        });
    }
}
