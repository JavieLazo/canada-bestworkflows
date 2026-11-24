<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollAdjustment;
use App\Models\PayrollRun;
use App\Modules\Payroll\Application\Adjustments;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Domain\Adjustments\AdjustmentRule;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Approves or rejects a PENDING adjustment, with a note. Any all-data
 * PAYROLL holder, the preparer and the employee themselves included — one
 * administrator must be able to run the whole flow alone; the approver
 * may pick the open regular payroll it goes on, or leave it to the next
 * one. Lock order: payroll's membership lock — so a finalize cannot slip
 * between the check and the approval — then the line. An approved line
 * never changes; the next payroll with the employee on it pays it.
 * RequiredAudit on the same transaction.
 */
final class ReviewAdjustment
{
    public function handle(Access $access, string $id, bool $approve, string $note = '', ?string $targetRunId = null): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! Adjustments::ready()) {
            throw new RuntimeException('Adjustments can be reviewed once this installation\'s upgrade finishes.');
        }
        $note = trim($note);
        if (mb_strlen($note) > AdjustmentRule::REASON_MAX) {
            throw new RuntimeException('The note is at most '.AdjustmentRule::REASON_MAX.' characters.');
        }
        if (! $approve && $note === '') {
            throw new RuntimeException('Say why the adjustment is rejected.');
        }
        $targetRunId = trim((string) $targetRunId) === '' ? null : trim((string) $targetRunId);

        return DB::transaction(static function () use ($access, $id, $approve, $note, $targetRunId): string {
            if (! PayrollAdjustment::query()->whereKey($id)->exists()) {
                throw new RuntimeException('That adjustment no longer exists.');
            }
            PayrollData::lockMembership();
            $row = PayrollAdjustment::query()->with('category')->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That adjustment no longer exists.');
            }
            if ($row->status !== AdjustmentRule::PENDING) {
                throw new RuntimeException('That adjustment was already reviewed.');
            }
            $employee = app(EmployeePayContexts::class)->get($access, [$row->employee_id])[0] ?? null;
            if ($employee === null) {
                throw new RuntimeException('That employee no longer exists.');
            }
            $target = null;
            if ($approve) {
                $targetRunId ??= $row->target_run_id;
                $target = $targetRunId === null ? null : PayrollRun::query()->whereKey($targetRunId)->first();
                if ($targetRunId !== null && ($target === null || $target->status !== 'DRAFT' || $target->kind !== 'REGULAR')) {
                    throw new RuntimeException('Choose an open regular payroll, or leave the payroll blank for the next one.');
                }
                $row->target_run_id = $target?->id;
            }
            $row->status = $approve ? AdjustmentRule::APPROVED : AdjustmentRule::REJECTED;
            $row->approved_by = $access->userId === null ? null : (int) $access->userId;
            $row->approved_by_name = mb_substr($access->fullName, 0, 200);
            $row->approved_at = CarbonImmutable::now('UTC');
            $row->review_note = $note === '' ? null : $note;
            if (! $row->save()) {
                throw new RuntimeException('The adjustment could not be reviewed.');
            }
            $name = EmployeeName::display($employee);
            $what = "{$row->category->name} ".($row->category->kind === AdjustmentRule::KIND_DEDUCTION ? 'deduction' : 'earning').' of '.AdjustmentRule::money($row->amount);
            RequiredAudit::record($access, $approve ? AuditEvent::ADJUSTMENT_APPROVED : AuditEvent::ADJUSTMENT_REJECTED, 'payroll_adjustments', $row->id,
                "{$name} · {$what} ".($approve ? 'approved' : 'rejected').' (prepared by '.($row->prepared_by_name ?? 'an unnamed account').')'
                .($target === null ? '' : " for payroll {$target->code}").($note === '' ? '' : ": {$note}"));

            return $approve
                ? "Adjustment approved: {$name}'s {$what}".($target === null ? ' goes on their next payroll.' : " goes on payroll {$target->code}.")
                : "Adjustment rejected: {$name}'s {$what}.";
        });
    }
}
