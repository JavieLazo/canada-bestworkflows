<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Models\PayrollEmployeeBenefit;
use Illuminate\Support\Facades\Schema;

/**
 * The benefits ASSIGNED to employees as payroll reads them: each member's
 * APPROVED assignments whose span reaches the period (`starts_on` on or
 * before its end, `ends_on` empty or on or after its start), captured in
 * the evidence as `benefit_assignments` so an approval stales the preview
 * and a replay never reads the live table. A draft proposes nothing.
 * Before the table exists (the window before the cron migrates
 * 2026_11_02_0010) nobody has one.
 */
final class BenefitAssignments
{
    private function __construct() {}

    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('payroll_employee_benefits'));
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, list<array{id: string, type_id: string, starts_on: string, ends_on: ?string, amount: ?float}>> employee id => the assignments reaching the period, oldest first; members with none absent
     */
    public static function forPayroll(array $employeeIds, string $periodStart, string $periodEnd): array
    {
        if ($employeeIds === [] || ! self::ready()) {
            return [];
        }
        $out = [];
        $rows = PayrollEmployeeBenefit::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', PayrollEmployeeBenefit::APPROVED)
            ->whereDate('starts_on', '<=', $periodEnd)
            ->where(static fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $periodStart))
            ->orderBy('starts_on')->orderBy('id')
            ->get();
        foreach ($rows as $row) {
            $out[$row->employee_id][] = self::entry($row);
        }

        return $out;
    }

    /** @return array{id: string, type_id: string, starts_on: string, ends_on: ?string, amount: ?float} */
    public static function entry(PayrollEmployeeBenefit $row): array
    {
        return [
            'id' => (string) $row->id,
            'type_id' => (string) $row->type_id,
            'starts_on' => $row->starts_on->format('Y-m-d'),
            'ends_on' => $row->ends_on?->format('Y-m-d'),
            'amount' => $row->amount,
        ];
    }

    /**
     * Whether a captured assignment of this type reaches the period, and
     * the amount it carries (null = the type's rule).
     *
     * @param  list<array{id: string, type_id: string, starts_on: string, ends_on: ?string, amount: ?float}>  $assignments
     * @return array{0: bool, 1: ?float}
     */
    public static function covering(array $assignments, string $typeId, string $periodStart, string $periodEnd): array
    {
        foreach ($assignments as $assignment) {
            if ($assignment['type_id'] !== $typeId || $assignment['starts_on'] > $periodEnd || ($assignment['ends_on'] !== null && $assignment['ends_on'] < $periodStart)) {
                continue;
            }

            return [true, $assignment['amount']];
        }

        return [false, null];
    }
}
