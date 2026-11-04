<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\PayrollPayRule;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Application\PayRulesVersions;
use App\Platform\Audit\RequiredAudit;
use App\Platform\Records\ReferenceCatalogs;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Saves a Pay Basis & Rates version: new figures for a scope (the company,
 * or one Employment status) from a date. A saved version's figures never
 * change, so this is the only way the rules change; the new version must
 * start after every existing version of its scope, on a cut-off's first
 * day (the 1st or the 16th), so no payroll period is priced under two
 * versions, and it carries the reason it was made — the rules are set once
 * and changed deliberately, with the reason in the audit row
 * (PayRulesVersions holds those rules, shared with SaveEmployeePayRules,
 * which saves one employee's). Draft payrolls whose period starts on or
 * after the date recompute and ask for a fresh review; finalized payrolls
 * keep the figures in their evidence.
 *
 * Audited with RequiredAudit on the same transaction: a version that cannot
 * be recorded is not saved.
 */
final class SavePayRulesVersion
{
    /**
     * @param  array<string, mixed>  $figures  the version's figures in PayRules shape
     */
    public function handle(Access $access, string $scope, string $effectiveFrom, string $reason, array $figures): string
    {
        if (! PayrollSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        // The company or an entry of the Employment list, never another id:
        // an employee's own rules are pay data, saved by SaveEmployeePayRules
        // under all-data PAYROLL, and resolve() reads every scope alike.
        $scope = trim($scope);
        if ($scope !== PayrollPayRule::COMPANY && app(ReferenceCatalogs::class)->for('employment')->find($access, $scope) === null) {
            throw new RuntimeException('Choose who the rules apply to.');
        }
        $error = PayRulesVersions::dateError($effectiveFrom) ?? PayRulesVersions::reasonError($reason);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        // Round-trips exactly: what the engine will read is what is saved.
        $figures = PayRulesVersions::figures($figures);
        $scopeType = $scope === PayrollPayRule::COMPANY ? PayrollPayRule::SCOPE_COMPANY : PayrollPayRule::SCOPE_STATUS;

        $row = DB::transaction(static function () use ($access, $scope, $scopeType, $effectiveFrom, $reason, $figures): PayrollPayRule {
            $row = PayRulesVersions::insert($access, $scope, $scopeType, $effectiveFrom, $reason, $figures, PayRulesVersions::latestLocked($scope));

            $who = $scope === PayrollPayRule::COMPANY ? 'company' : "employment status {$scope}";
            RequiredAudit::record($access, AuditEvent::PAY_RULES_SAVED, 'payroll_pay_rules', $row->id,
                'Pay Basis & Rates version from '.PayrollPeriodText::day($effectiveFrom)." saved for the {$who} (".trim($reason).')');

            return $row;
        });

        return 'Pay Basis & Rates version from '.PayrollPeriodText::day($row->effective_from->format('Y-m-d')).' saved.';
    }
}
