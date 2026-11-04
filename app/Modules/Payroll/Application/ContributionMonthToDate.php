<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Enums\PayrollRunKind;
use App\Models\Payslip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * What a contribution month's POSTED payslips already took of each
 * employee's SSS, PhilHealth and Pag-IBIG shares (DIVERGENCE 19): the
 * figure the HALF_THEN_REST and WHOLE_FIRST schedules take the rest
 * from. Finalized regular payslips only — a draft never counts, and the
 * 13th month run deducts none. A payslip posted before
 * `payslips.contribution_month` existed counts toward its period's start
 * month. Captured in the evidence, so a payslip posted meanwhile stales
 * the preview and a replay never reads the live table.
 */
final class ContributionMonthToDate
{
    /** The six shares, in payslip column order. */
    public const array LINES = ['sss', 'sss_er', 'philhealth', 'philhealth_er', 'pagibig', 'pagibig_er'];

    private function __construct() {}

    /** Whether the month column exists yet (the window before the cron migrates 2026_11_02_0009). */
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('payslips', 'contribution_month'));
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, float>> employee id => line => the sum posted this month; employees with nothing posted are absent
     */
    public static function posted(array $employeeIds, string $month): array
    {
        if ($employeeIds === [] || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return [];
        }
        $query = Payslip::query()
            ->whereIn('employee_id', $employeeIds)
            ->where(static fn (Builder $q) => $q
                ->whereNull('run_id')
                ->orWhereHas('run', static fn (Builder $run) => $run->where('kind', PayrollRunKind::REGULAR)->where('status', 'FINALIZED')));
        if (self::ready()) {
            $query->where(static fn (Builder $q) => $q
                ->where('contribution_month', $month)
                ->orWhere(static fn (Builder $older) => $older->whereNull('contribution_month')->whereBetween('period_start', [$month.'-01', $month.'-31'])));
        } else {
            $query->whereBetween('period_start', [$month.'-01', $month.'-31']);
        }

        $out = [];
        foreach ($query->get(['employee_id', ...self::LINES]) as $payslip) {
            $sums = $out[$payslip->employee_id] ?? array_fill_keys(self::LINES, 0.0);
            foreach (self::LINES as $line) {
                $sums[$line] += (float) ($payslip->{$line} ?? 0.0);
            }
            $out[$payslip->employee_id] = $sums;
        }

        return $out;
    }
}
