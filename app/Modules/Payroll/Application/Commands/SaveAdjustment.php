<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollAdjustment;
use App\Models\PayrollAdjustmentCategory;
use App\Models\PayrollRun;
use App\Modules\Payroll\Application\Adjustments;
use App\Modules\Payroll\Domain\Adjustments\AdjustmentRule;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Prepares an adjustment — a difference to pay or recover on a later
 * payroll — as a PENDING line for one employee under a category: the
 * amount, the reason, an optional reference, and optionally the open
 * regular payroll it is meant for (else the next payroll finalized with
 * the employee on it pays it). A pending line of one's own may be
 * corrected in place until it is reviewed. All-data PAYROLL, for any
 * employee including the preparer's own. RequiredAudit on the same
 * transaction.
 */
final class SaveAdjustment
{
    /** @return string the line's id */
    public function handle(Access $access, ?string $id, string $employeeId, string $categoryId, mixed $amount, string $reason, ?string $reference, ?string $targetRunId): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! Adjustments::ready()) {
            throw new RuntimeException('Adjustments can be prepared once this installation\'s upgrade finishes.');
        }
        $employee = app(EmployeePayContexts::class)->get($access, [$employeeId])[0] ?? null;
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        $pesos = AdjustmentRule::amount($amount);
        if ($pesos === null) {
            throw new RuntimeException('Enter the amount as a peso figure above zero.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > AdjustmentRule::REASON_MAX) {
            throw new RuntimeException('Say why, in 1 to '.AdjustmentRule::REASON_MAX.' characters.');
        }
        $reference = trim((string) $reference);
        if (mb_strlen($reference) > AdjustmentRule::REFERENCE_MAX) {
            throw new RuntimeException('The reference is at most '.AdjustmentRule::REFERENCE_MAX.' characters.');
        }
        $reference = $reference === '' ? null : $reference;
        $targetRunId = trim((string) $targetRunId) === '' ? null : trim((string) $targetRunId);
        $name = EmployeeName::display($employee);

        return DB::transaction(static function () use ($access, $id, $employee, $categoryId, $pesos, $reason, $reference, $targetRunId, $name): string {
            $category = PayrollAdjustmentCategory::query()->whereKey($categoryId)->first();
            if ($category === null) {
                throw new RuntimeException('Choose the adjustment category.');
            }
            $target = $targetRunId === null ? null : PayrollRun::query()->whereKey($targetRunId)->first();
            if ($targetRunId !== null && ($target === null || $target->status !== 'DRAFT' || $target->kind !== 'REGULAR')) {
                throw new RuntimeException('Choose an open regular payroll, or leave the payroll blank for the next one.');
            }
            $row = $id === null ? null : PayrollAdjustment::query()->whereKey($id)->lockForUpdate()->first();
            if ($id !== null && $row === null) {
                throw new RuntimeException('That adjustment no longer exists.');
            }
            if ($row !== null && $row->status !== AdjustmentRule::PENDING) {
                throw new RuntimeException('A reviewed adjustment never changes. Prepare a new one.');
            }
            if ($row !== null && $row->employee_id !== $employee->id) {
                throw new RuntimeException('An adjustment stays with the employee it was prepared for.');
            }
            $row ??= new PayrollAdjustment(['employee_id' => $employee->id, 'source' => AdjustmentRule::SOURCE_MANUAL]);
            $row->fill(['category_id' => $category->id, 'amount' => $pesos, 'reason' => $reason, 'reference' => $reference]);
            $row->status = AdjustmentRule::PENDING;
            $row->target_run_id = $target?->id;
            $row->prepared_by = $access->userId === null ? null : (int) $access->userId;
            $row->prepared_by_name = mb_substr($access->fullName, 0, 200);
            if (! $row->save()) {
                throw new RuntimeException('The adjustment could not be saved.');
            }
            RequiredAudit::record($access, AuditEvent::ADJUSTMENT_SAVED, 'payroll_adjustments', $row->id,
                "{$name} · {$category->name} ".($category->kind === AdjustmentRule::KIND_DEDUCTION ? 'deduction' : 'earning').' of '.AdjustmentRule::peso($pesos).' '.($id === null ? 'prepared' : 'corrected').($target === null ? '' : " for payroll {$target->code}").": {$reason}");

            return $row->id;
        });
    }
}
