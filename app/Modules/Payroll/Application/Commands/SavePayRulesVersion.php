<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Domain\Payroll\PayRules;
use App\Models\PayrollPayRule;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Platform\Audit\RequiredAudit;
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
 * and changed deliberately, with the reason in the audit row. Draft
 * payrolls whose period starts on or after the date recompute and ask for
 * a fresh review; finalized payrolls keep the figures in their evidence.
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
        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        $scope = trim($scope);
        if ($scope === '' || mb_strlen($scope) > 26) {
            throw new RuntimeException('Choose who the rules apply to.');
        }
        if (! PayrollPeriod::isPayrollDate($effectiveFrom)) {
            throw new RuntimeException('Enter the date the rules take effect.');
        }
        if (! PayrollPeriod::isCutoffStart($effectiveFrom)) {
            throw new RuntimeException('A version takes effect on the 1st or the 16th of a month, the first day of a payroll period.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Enter the reason for this version.');
        }
        if (mb_strlen($reason) > 160) {
            throw new RuntimeException('The reason must be at most 160 characters.');
        }
        $figureError = PayRules::error($figures);
        if ($figureError !== null) {
            throw new RuntimeException($figureError);
        }
        // A rate below its usual minimum is allowed — the minimums do not
        // bind every kind of employment — and the reason every version
        // carries says why. Round-trips exactly: what the engine will read
        // is what is saved.
        $rules = PayRules::fromArray($figures);
        $figures = $rules->toArray();

        $row = DB::transaction(static function () use ($access, $scope, $effectiveFrom, $reason, $figures): PayrollPayRule {
            $latest = PayrollPayRule::query()->where('scope', $scope)->lockForUpdate()->orderByDesc('effective_from')->first();
            if ($latest !== null && $latest->effective_from->format('Y-m-d') >= $effectiveFrom) {
                throw new RuntimeException('A version already starts on '.PayrollPeriodText::day($latest->effective_from->format('Y-m-d')).'. Choose a later date.');
            }

            $row = new PayrollPayRule;
            $row->fill(['scope' => $scope, 'effective_from' => $effectiveFrom, 'reason' => $reason, 'params' => $figures]);
            $row->created_by = $access->userId === null ? null : (int) $access->userId;
            $row->created_by_name = mb_substr($access->fullName, 0, 200);
            $row->save();

            $who = $scope === PayrollPayRule::COMPANY ? 'company' : "employment status {$scope}";
            RequiredAudit::record($access, AuditEvent::PAY_RULES_SAVED, 'payroll_pay_rules', $row->id,
                'Pay Basis & Rates version from '.PayrollPeriodText::day($effectiveFrom)." saved for the {$who} ({$reason})");

            return $row;
        });

        return 'Pay Basis & Rates version from '.PayrollPeriodText::day($row->effective_from->format('Y-m-d')).' saved.';
    }
}
