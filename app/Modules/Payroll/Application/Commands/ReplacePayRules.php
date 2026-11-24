<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\Statutory;
use App\Models\PayrollPayRule;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Application\PayRulesStore;
use App\Modules\Payroll\Application\PayRulesVersions;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Platform\Records\ReferenceCatalogs;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sets the Pay Basis & Rates of the company, of one Employment status or of
 * one employee. EACH HAS ONE SET OF RULES, NOT A LIST OF DATED VERSIONS: the
 * figures replace the ones in use and take effect at once for every draft
 * payroll (which reprices on its next preview and asks for a fresh review);
 * no effective date, reason or approval is asked. A finalized payroll keeps
 * the rules its evidence captured, so nothing already paid moves. What the
 * rules were is kept in the audit trail alone: the row the change is written
 * on stays, every other row of that scope is removed, and the kept row
 * covers every date they covered.
 *
 * `$employeeId` names one employee, whose rules replace their status's and
 * the company's for them alone; null `$figures` there REMOVES their own
 * rules, so they follow the general ones again.
 *
 * The company's and a status's rules are written by whoever manages payroll
 * (PayrollSettingsAccess); an employee's are pay data, written by an
 * all-data PAYROLL holder — the same grant today. RequiredAudit on the same
 * transaction, after payroll's membership lock.
 */
final class ReplacePayRules
{
    /**
     * @param  array<string, mixed>|null  $figures  the rules in PayRules shape; null removes an employee's own
     */
    public function handle(Access $access, string $scope, ?string $employeeId, ?array $figures): string
    {
        $name = null;
        if ($employeeId !== null) {
            if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
                throw new RuntimeException('Your role cannot manage employee pay settings.');
            }
            if (! PayRulesStore::approvalReady()) {
                throw new RuntimeException('Rates for one employee can be saved once this installation\'s upgrade finishes.');
            }
            $employee = app(EmployeePayContexts::class)->get($access, [$employeeId])[0] ?? null;
            if ($employee === null) {
                throw new RuntimeException('That employee no longer exists.');
            }
            $name = EmployeeName::display($employee);
            $scope = $employee->id;
            $scopeType = PayrollPayRule::SCOPE_EMPLOYEE;
        } else {
            if (! PayrollSettingsAccess::mayManage($access)) {
                throw new RuntimeException('Your role cannot manage organization-wide data.');
            }
            // The company or an entry of the Employment list, never another id.
            $scope = trim($scope);
            if ($scope !== PayrollPayRule::COMPANY && app(ReferenceCatalogs::class)->for('employment')->find($access, $scope) === null) {
                throw new RuntimeException('Choose who the rules apply to.');
            }
            if ($figures === null) {
                throw new RuntimeException('Enter the rules to save.');
            }
            $scopeType = $scope === PayrollPayRule::COMPANY ? PayrollPayRule::SCOPE_COMPANY : PayrollPayRule::SCOPE_STATUS;
        }
        if ($figures !== null) {
            // Round-trips exactly: what the engine will read is what is saved.
            $figures = PayRulesVersions::figures($figures);
        }
        $today = LocalClock::todayKey();

        return DB::transaction(static function () use ($access, $scope, $scopeType, $figures, $name, $today): string {
            PayrollData::lockMembership();
            $rows = PayrollPayRule::query()->where('scope', $scope)->lockForUpdate()->orderBy('effective_from')->orderBy('id')->get();

            if ($figures === null) {
                if ($rows->isEmpty()) {
                    throw new RuntimeException("{$name} has no rates of their own to remove.");
                }
                $id = (string) $rows->last()->id;
                PayrollPayRule::query()->where('scope', $scope)->delete();
                RequiredAudit::record($access, AuditEvent::PAY_RULES_SAVED, 'payroll_pay_rules', $id,
                    "{$name}'s own Pay Basis & Rates removed: they follow the employment status or company rules again");

                return "{$name} follows the general rules again.";
            }

            // The row in use today, else the earliest: the one the change is written on.
            $kept = $rows->last(static fn (PayrollPayRule $row): bool => $row->effective_from->format('Y-m-d') <= $today
                && (! PayRulesStore::approvalReady() || $row->status === PayrollPayRule::APPROVED)) ?? $rows->first();
            $removed = 0;
            if ($kept === null) {
                // A first set of rules for this scope covers every date the
                // company's do, so no draft period falls before it.
                $from = PayrollPayRule::query()->where('scope', PayrollPayRule::COMPANY)->min('effective_from') ?? Statutory::EFFECTIVE_FROM;
                $kept = new PayrollPayRule;
                $kept->fill(['scope' => $scope, 'effective_from' => CarbonImmutable::parse((string) $from, 'UTC')->format('Y-m-d')]);
                if (PayRulesStore::employeeScopesReady()) {
                    $kept->scope_type = $scopeType;
                }
            } else {
                // One row covers every date the list covered.
                $kept->effective_from = $rows->first()->effective_from;
                $removed = PayrollPayRule::query()->where('scope', $scope)->whereKeyNot($kept->id)->delete();
            }
            $kept->fill(['reason' => 'Set in Payroll Settings', 'params' => $figures]);
            $kept->created_by = $access->userId === null ? null : (int) $access->userId;
            $kept->created_by_name = mb_substr($access->fullName, 0, 200);
            if (PayRulesStore::approvalReady()) {
                $kept->status = PayrollPayRule::APPROVED;
                $kept->approved_by = $kept->created_by;
                $kept->approved_by_name = $kept->created_by_name;
                $kept->approved_at = CarbonImmutable::now('UTC');
            }
            if (! $kept->save()) {
                throw new RuntimeException('The rules could not be saved.');
            }

            $who = match (true) {
                $name !== null => "employee {$name}",
                $scope === PayrollPayRule::COMPANY => 'the company',
                default => "employment status {$scope}",
            };
            RequiredAudit::record($access, AuditEvent::PAY_RULES_SAVED, 'payroll_pay_rules', $kept->id,
                "Pay Basis & Rates replaced for {$who}".($removed > 0 ? ', '.$removed.' earlier or later '.($removed === 1 ? 'version' : 'versions').' removed' : ''));

            return $name !== null ? "{$name}'s own rates saved. Draft payrolls now use them." : 'Pay Basis & Rates saved. Draft payrolls now use them.';
        });
    }
}
