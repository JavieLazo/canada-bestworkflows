<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollAdjustment;
use App\Modules\Payroll\Application\Adjustments;
use App\Modules\Payroll\Domain\Adjustments\AdjustmentRule;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Discards a PENDING adjustment: it is deleted, the audit row keeping what
 * went. A reviewed line is never discarded — a rejected one is the
 * record of its refusal, an approved one pays or has paid. All-data PAYROLL.
 */
final class DiscardAdjustment
{
    public function handle(Access $access, string $id): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! Adjustments::ready()) {
            throw new RuntimeException('Adjustments can be discarded once this installation\'s upgrade finishes.');
        }

        return DB::transaction(static function () use ($access, $id): string {
            $row = PayrollAdjustment::query()->with('category')->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That adjustment no longer exists.');
            }
            if ($row->status !== AdjustmentRule::PENDING) {
                throw new RuntimeException('A reviewed adjustment stays on record.');
            }
            $employee = app(EmployeePayContexts::class)->get($access, [$row->employee_id])[0] ?? null;
            $name = $employee === null ? 'a removed employee' : EmployeeName::display($employee);
            $what = "{$row->category->name} of ".AdjustmentRule::peso($row->amount);
            if (! $row->delete()) {
                throw new RuntimeException('The adjustment could not be discarded.');
            }
            RequiredAudit::record($access, AuditEvent::ADJUSTMENT_DISCARDED, 'payroll_adjustments', $id, "{$name} · {$what} discarded before review ({$row->reason})");

            return 'Adjustment discarded.';
        });
    }
}
