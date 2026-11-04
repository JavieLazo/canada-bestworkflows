<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Payroll\PayrollPeriod;
use App\Domain\Payroll\PayRules;
use App\Models\PayrollPayRule;
use App\Support\Access;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * What every Pay Basis & Rates version must satisfy, whoever it is for —
 * the company, an Employment status (SavePayRulesVersion) or one employee
 * (SaveEmployeePayRules): a date that is a cut-off's first day (the 1st or
 * the 16th) after every version of its scope, so no payroll period is
 * priced under two versions; a reason; and figures the engine reads back
 * exactly. The row is written inside the caller's transaction, after the
 * caller has locked the scope's latest version.
 */
final class PayRulesVersions
{
    public const int REASON_MAX = 160;

    private function __construct() {}

    /**
     * Why a date cannot start a version, or null. Any payroll date: a
     * version starting inside a period splits it, each day priced by the
     * version in force on it (DIVERGENCE 17).
     */
    public static function dateError(string $effectiveFrom): ?string
    {
        return PayrollPeriod::isPayrollDate($effectiveFrom) ? null : 'Enter the date the rules take effect.';
    }

    /** Why a reason cannot carry a version, or null. */
    public static function reasonError(string $reason): ?string
    {
        $reason = trim($reason);
        if ($reason === '') {
            return 'Enter the reason for this version.';
        }

        return mb_strlen($reason) > self::REASON_MAX ? 'The reason must be at most 160 characters.' : null;
    }

    /**
     * The figures as the engine will read them, or the refusal. A rate
     * below its usual minimum is allowed — the minimums do not bind every
     * kind of employment — and the reason every version carries says why.
     *
     * @param  array<string, mixed>  $figures
     * @return array<string, mixed>
     */
    public static function figures(array $figures): array
    {
        $error = PayRules::error($figures);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        try {
            return PayRules::fromArray($figures)->toArray();
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }
    }

    /** The scope's latest APPROVED version, locked: what a new one must start after. Inside a transaction. A draft never counts. */
    public static function latestLocked(string $scope): ?PayrollPayRule
    {
        return PayrollPayRule::query()->where('scope', $scope)
            ->when(PayRulesStore::approvalReady(), static fn ($query) => $query->where('status', PayrollPayRule::APPROVED))
            ->lockForUpdate()->orderByDesc('effective_from')->first();
    }

    /**
     * Writes the company's or a status's version after `$latest` (the
     * scope's latest, locked by the caller), in force at once: those
     * versions are saved approved by whoever manages the Payroll Settings.
     *
     * @param  array<string, mixed>  $figures
     */
    public static function insert(Access $access, string $scope, string $scopeType, string $effectiveFrom, string $reason, array $figures, ?PayrollPayRule $latest): PayrollPayRule
    {
        if ($latest !== null && $latest->effective_from->format('Y-m-d') >= $effectiveFrom) {
            throw new RuntimeException('A version already starts on '.PayrollPeriodText::day($latest->effective_from->format('Y-m-d')).'. Choose a later date.');
        }

        $row = self::row($access, $scope, $scopeType, $effectiveFrom, $reason, $figures);
        if (PayRulesStore::approvalReady()) {
            $row->status = PayrollPayRule::APPROVED;
            $row->approved_by = $row->created_by;
            $row->approved_by_name = $row->created_by_name;
            $row->approved_at = CarbonImmutable::now('UTC');
        }
        $row->save();

        return $row;
    }

    /**
     * Writes an employee's version as a DRAFT after `$latest` (their latest
     * approved version, locked by the caller) — or, when they already have
     * a draft on that date, replaces its figures and reason. Null figures
     * are a "follows" row.
     *
     * @param  array<string, mixed>|null  $figures
     */
    public static function insertDraft(Access $access, string $employeeId, string $effectiveFrom, string $reason, ?array $figures, ?PayrollPayRule $latest): PayrollPayRule
    {
        if ($latest !== null && $latest->effective_from->format('Y-m-d') >= $effectiveFrom) {
            throw new RuntimeException('An approved version already takes effect on '.PayrollPeriodText::day($latest->effective_from->format('Y-m-d')).' and never changes. Prepare one from a later date.');
        }
        $row = PayrollPayRule::query()->where('scope', $employeeId)->where('effective_from', $effectiveFrom)->lockForUpdate()->first()
            ?? self::row($access, $employeeId, PayrollPayRule::SCOPE_EMPLOYEE, $effectiveFrom, $reason, $figures);
        $row->fill(['reason' => trim($reason), 'params' => $figures]);
        $row->status = PayrollPayRule::DRAFT;
        $row->created_by = $access->userId === null ? null : (int) $access->userId;
        $row->created_by_name = mb_substr($access->fullName, 0, 200);
        $row->save();

        return $row;
    }

    /** @param array<string, mixed>|null $figures */
    private static function row(Access $access, string $scope, string $scopeType, string $effectiveFrom, string $reason, ?array $figures): PayrollPayRule
    {
        $row = new PayrollPayRule;
        $row->fill(['scope' => $scope, 'effective_from' => $effectiveFrom, 'reason' => trim($reason), 'params' => $figures]);
        if (PayRulesStore::employeeScopesReady()) {
            $row->scope_type = $scopeType;
        }
        $row->created_by = $access->userId === null ? null : (int) $access->userId;
        $row->created_by_name = mb_substr($access->fullName, 0, 200);

        return $row;
    }
}
