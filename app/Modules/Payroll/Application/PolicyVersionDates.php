<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Enums\PayrollRunKind;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\Payslip;
use Illuminate\Database\Eloquent\Builder;

/**
 * The dates an employee's dated pay policy version may start on. A
 * Contributions & tax version starts on a cut-off's first day (the 1st or
 * the 16th), because the whole period's contributions read ONE version
 * (`dateError`); a Pay Basis & Rates version of their own may start on
 * any day, each day priced by the version in force on it
 * (`PayRulesVersions::dateError`, divergence 17). Either kind must start
 * after any day a FINALIZED regular payroll of theirs has paid
 * (`postedError`), because a posted payslip keeps the figures it was
 * computed with and a version dated inside it would change nothing it
 * claims to. The difference such a version means is an adjustment's job.
 */
final class PolicyVersionDates
{
    private function __construct() {}

    /** Why a date cannot start a version, or null. */
    public static function dateError(string $effectiveFrom): ?string
    {
        if (! PayrollPeriod::isPayrollDate($effectiveFrom)) {
            return 'Enter the date it takes effect.';
        }

        return PayrollPeriod::isCutoffStart($effectiveFrom) ? null : 'A version takes effect on the 1st or the 16th of a month, the first day of a payroll period.';
    }

    /**
     * The last day a finalized regular payroll of the employee has paid,
     * or null when none has.
     */
    public static function postedThrough(string $employeeId): ?string
    {
        $last = Payslip::query()
            ->where('employee_id', $employeeId)
            ->where(static fn (Builder $query) => $query
                ->whereNull('run_id')
                ->orWhereHas('run', static fn (Builder $run) => $run->where('kind', PayrollRunKind::REGULAR)))
            ->max('period_end');

        return $last === null ? null : substr((string) $last, 0, 10);
    }

    /** Why a version from this date would reach a payroll already posted, or null. */
    public static function postedError(string $employeeId, string $effectiveFrom, string $name): ?string
    {
        $through = self::postedThrough($employeeId);
        if ($through === null || $through < $effectiveFrom) {
            return null;
        }

        return "A finalized payroll for {$name} already reaches ".PayrollPeriodText::day($through).'. Start the version on the next cut-off.';
    }
}
