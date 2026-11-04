<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Models\PayrollAdjustment;
use App\Modules\Payroll\Domain\Adjustments\AdjustmentRule;
use Illuminate\Support\Facades\Schema;

/**
 * The adjustment ledger as payroll reads it: whether the tables exist yet
 * (the window before the cron migrates 2026_11_02_0005), the APPROVED and
 * unposted lines a payroll pays — each line targeted at this run, or at
 * no run in particular, for its members — as the evidence carries them,
 * and the PENDING lines that hold a payroll back.
 */
final class Adjustments
{
    private function __construct() {}

    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('payroll_adjustments') && Schema::hasColumn('payslip_deductions', 'adjustment_id'));
    }

    /**
     * The approved, unposted lines this payroll pays, by member, in the
     * order they were approved.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, list<array{id: string, category_id: string, code: string, name: string, kind: string, tax: ?string, amount: float, reason: string}>>
     */
    public static function forPayroll(array $employeeIds, ?string $runId): array
    {
        if ($employeeIds === [] || ! self::ready()) {
            return [];
        }
        $out = [];
        $rows = PayrollAdjustment::query()->with('category')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', AdjustmentRule::APPROVED)
            ->where(static function ($query) use ($runId): void {
                $query->whereNull('target_run_id');
                if ($runId !== null) {
                    $query->orWhere('target_run_id', $runId);
                }
            })
            ->orderBy('approved_at')->orderBy('id')
            ->get();
        foreach ($rows as $row) {
            $out[$row->employee_id][] = self::entry($row);
        }

        return $out;
    }

    /**
     * The members with a PENDING line that this payroll would have to
     * wait for — targeted at it, or at no run in particular — by employee.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, int>
     */
    public static function pendingFor(array $employeeIds, ?string $runId): array
    {
        if ($employeeIds === [] || ! self::ready()) {
            return [];
        }
        $counts = [];
        $rows = PayrollAdjustment::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', AdjustmentRule::PENDING)
            ->where(static function ($query) use ($runId): void {
                $query->whereNull('target_run_id');
                if ($runId !== null) {
                    $query->orWhere('target_run_id', $runId);
                }
            })
            ->get(['employee_id']);
        foreach ($rows as $row) {
            $counts[$row->employee_id] = ($counts[$row->employee_id] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * A line as the evidence carries it: the category copied (code = its
     * id's tail, name, kind, tax), the amount and the reason.
     *
     * @return array{id: string, category_id: string, code: string, name: string, kind: string, tax: ?string, amount: float, reason: string}
     */
    public static function entry(PayrollAdjustment $row): array
    {
        return [
            'id' => (string) $row->id,
            'category_id' => (string) $row->category_id,
            'code' => 'ADJ',
            'name' => $row->category->name,
            'kind' => $row->category->kind,
            'tax' => $row->category->tax,
            'amount' => $row->amount,
            'reason' => $row->reason,
        ];
    }
}
