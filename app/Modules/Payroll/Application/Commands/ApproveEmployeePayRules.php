<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollPayRule;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Application\PayRulesStore;
use App\Modules\Payroll\Application\PayRulesVersions;
use App\Modules\Payroll\Application\PolicyVersionDates;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Approves an employee's own Pay Basis & Rates draft: from its effective
 * date it prices them (drafts from that date recompute and ask for a fresh
 * review) and it never changes. Any all-data PAYROLL holder, the preparer
 * and the employee themselves included — one administrator must be able
 * to run the whole flow alone. Lock order: payroll's membership lock, then
 * the employee's versions. RequiredAudit on the same transaction.
 */
final class ApproveEmployeePayRules
{
    public function handle(Access $access, string $id): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! PayRulesStore::approvalReady()) {
            throw new RuntimeException('Versions can be approved once this installation\'s upgrade finishes.');
        }

        return DB::transaction(static function () use ($access, $id): string {
            if (! PayrollPayRule::query()->whereKey($id)->exists()) {
                throw new RuntimeException('That version no longer exists.');
            }
            PayrollData::lockMembership();
            $row = PayrollPayRule::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null || $row->scope_type !== PayrollPayRule::SCOPE_EMPLOYEE) {
                throw new RuntimeException('That version no longer exists.');
            }
            if ($row->status !== PayrollPayRule::DRAFT) {
                throw new RuntimeException('That version is already approved.');
            }
            $employee = app(EmployeePayContexts::class)->get($access, [$row->scope])[0] ?? null;
            if ($employee === null) {
                throw new RuntimeException('That employee no longer exists.');
            }
            $name = EmployeeName::display($employee);
            $from = $row->effective_from->format('Y-m-d');
            $error = PolicyVersionDates::postedError($row->scope, $from, $name);
            if ($error !== null) {
                throw new RuntimeException($error);
            }
            $latest = PayRulesVersions::latestLocked($row->scope);
            if ($latest !== null && $latest->effective_from->format('Y-m-d') >= $from) {
                throw new RuntimeException('An approved version already takes effect on '.PayrollPeriodText::day($latest->effective_from->format('Y-m-d')).'. Discard this draft and prepare one from a later date.');
            }
            if ($row->params === null && ($latest === null || $latest->params === null)) {
                throw new RuntimeException("{$name} has no rates of their own to end.");
            }
            $row->status = PayrollPayRule::APPROVED;
            $row->approved_by = $access->userId === null ? null : (int) $access->userId;
            $row->approved_by_name = mb_substr($access->fullName, 0, 200);
            $row->approved_at = CarbonImmutable::now('UTC');
            if (! $row->save()) {
                throw new RuntimeException('The version could not be approved.');
            }
            $day = PayrollPeriodText::day($from);
            RequiredAudit::record($access, AuditEvent::PAY_RULES_APPROVED, 'payroll_pay_rules', $row->id, $row->params === null
                ? "{$name} follows the employment status or company Pay Basis & Rates again from {$day}: approved (prepared by ".($row->created_by_name ?? 'an unnamed account').')'
                : "Pay Basis & Rates version from {$day} approved for employee {$name} (prepared by ".($row->created_by_name ?? 'an unnamed account').')');

            return $row->params === null
                ? "From {$day}, {$name} follows the general Pay Basis & Rates again: approved."
                : "{$name}'s Pay Basis & Rates from {$day} approved.";
        });
    }
}
