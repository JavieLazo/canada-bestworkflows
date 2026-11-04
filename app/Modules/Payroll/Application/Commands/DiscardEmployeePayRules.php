<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollPayRule;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Application\PayRulesStore;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Discards an employee's own Pay Basis & Rates DRAFT: it is deleted, the
 * audit row keeping what went. An approved version is never discarded (a
 * version is never deleted once it prices anyone). All-data PAYROLL.
 */
final class DiscardEmployeePayRules
{
    public function handle(Access $access, string $id): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! PayRulesStore::approvalReady()) {
            throw new RuntimeException('Drafts can be discarded once this installation\'s upgrade finishes.');
        }

        return DB::transaction(static function () use ($access, $id): string {
            $row = PayrollPayRule::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null || $row->scope_type !== PayrollPayRule::SCOPE_EMPLOYEE) {
                throw new RuntimeException('That version no longer exists.');
            }
            if ($row->status !== PayrollPayRule::DRAFT) {
                throw new RuntimeException('An approved version cannot be discarded.');
            }
            $employee = app(EmployeePayContexts::class)->get($access, [$row->scope])[0] ?? null;
            $name = $employee === null ? 'a removed employee' : EmployeeName::display($employee);
            $day = PayrollPeriodText::day($row->effective_from->format('Y-m-d'));
            $reason = (string) $row->reason;
            if (! $row->delete()) {
                throw new RuntimeException('The draft could not be discarded.');
            }
            RequiredAudit::record($access, AuditEvent::PAY_RULES_DISCARDED, 'payroll_pay_rules', $id,
                "Pay Basis & Rates draft from {$day} for employee {$name} discarded ({$reason})");

            return "Draft from {$day} discarded.";
        });
    }
}
