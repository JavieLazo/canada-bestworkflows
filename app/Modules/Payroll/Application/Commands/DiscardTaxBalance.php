<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollTaxBalance;
use App\Modules\Payroll\Application\TaxYearToDate;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Discards a tax balance still in DRAFT: it is deleted, the audit row
 * keeping what went. An approved balance is never discarded — it is tax
 * history a payroll may already have priced. All-data PAYROLL;
 * RequiredAudit on the same transaction.
 */
final class DiscardTaxBalance
{
    public function handle(Access $access, string $id): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! TaxYearToDate::balancesReady()) {
            throw new RuntimeException('Tax balances can be discarded once this installation\'s upgrade finishes.');
        }

        return DB::transaction(static function () use ($access, $id): string {
            $row = PayrollTaxBalance::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That balance no longer exists.');
            }
            if ($row->status !== PayrollTaxBalance::DRAFT) {
                throw new RuntimeException('An approved balance is not discarded.');
            }
            $label = PayrollTaxBalance::KINDS[$row->kind] ?? $row->kind;
            $year = $row->tax_year;
            $employeeId = $row->employee_id;
            if (! $row->delete()) {
                throw new RuntimeException('The balance could not be discarded.');
            }
            RequiredAudit::record($access, AuditEvent::TAX_BALANCE_DISCARDED, 'payroll_tax_balances', $id,
                "{$label} for {$year} discarded as a draft (employee {$employeeId})");

            return "Draft discarded: {$label} for {$year}.";
        });
    }
}
