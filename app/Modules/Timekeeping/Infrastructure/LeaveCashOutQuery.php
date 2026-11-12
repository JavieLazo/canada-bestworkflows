<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Infrastructure;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Models\LeaveCashOut;
use App\Models\LeaveCredit;
use App\Modules\Timekeeping\Application\LeaveBalances;
use App\Modules\Timekeeping\Application\LeaveCashOuts;
use App\Modules\Timekeeping\Application\LeaveCharges;
use App\Modules\Timekeeping\Application\LeaveCreditWriter;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Contracts\LeaveCashOutPoster;
use App\Modules\Timekeeping\Contracts\LeaveCashOutSource;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\ManilaClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Payroll's view of leave credit cash-outs: the approved, unpaid requests a
 * run pays (LeaveCashOutSource), and the posting that marks them PAID and
 * takes the days off the live ledger when the run finalizes
 * (LeaveCashOutPoster). Gated exactly like AttendanceSource::forPayroll:
 * PAYROLL, all-data or the actor's own employee only.
 */
final class LeaveCashOutQuery implements LeaveCashOutPoster, LeaveCashOutSource
{
    public function forPayroll(Access $actor, array $employeeIds): array
    {
        abort_unless($actor->hasModule(ModuleCode::PAYROLL), 403);
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            abort_unless($actor->hasEmployeeLink() && array_diff($employeeIds, [$actor->ownEmployeeId()]) === [], 403);
        }
        if ($employeeIds === [] || ! LeaveCashOuts::ready()) {
            return [];
        }
        $names = [];
        foreach (LeaveTypeCreditRecords::rules() as $token => $entry) {
            $names[$token] = $entry['name'];
        }
        $out = [];
        $rows = LeaveCashOut::query()->whereIn('employee_id', $employeeIds)->where('status', LeaveCashOut::APPROVED)
            ->orderBy('requested_at')->orderBy('id')->get();
        foreach ($rows as $row) {
            $out[$row->employee_id][] = [
                'id' => $row->id,
                'request_type' => $row->request_type,
                'name' => $names[$row->request_type] ?? $row->request_type,
                'days' => (float) $row->days,
                'purpose' => $row->purpose === LeaveCashOuts::TERMINAL ? self::TERMINAL : self::MONETIZATION,
            ];
        }

        return $out;
    }

    public function post(Access $actor, string $employeeId, string $payslipId, string $runCode, array $lines): void
    {
        if ($lines === []) {
            return;
        }
        if (! LeaveCashOuts::ready()) {
            throw new RuntimeException('Leave credits cannot be converted to cash until this installation\'s upgrade finishes.');
        }
        LeaveCreditWriter::lock();
        $year = LeaveCharges::liveYear($employeeId, (int) substr(ManilaClock::todayKey(), 0, 4));
        $names = [];
        foreach (LeaveTypeCreditRecords::rules() as $token => $entry) {
            $names[$token] = $entry['name'];
        }
        $parts = [];
        foreach ($lines as $line) {
            $row = LeaveCashOut::query()->whereKey($line['id'])->lockForUpdate()->first();
            if ($row === null || $row->employee_id !== $employeeId || $row->status !== LeaveCashOut::APPROVED) {
                throw new RuntimeException('A leave credit cash conversion on this payroll is no longer approved. Review the payroll again.');
            }
            $days = LeaveQuantity::normalize((float) $row->days);
            // The credits must still be there: leave approved or charged
            // since the review may have used them. Paying anyway would pay
            // the same days twice, so the finalization stops with the reason.
            $others = LeaveQuantity::normalize((LeaveCashOuts::spokenFor($employeeId)[$row->request_type] ?? 0.0) - $days);
            $left = LeaveQuantity::normalize(LeaveBalances::balance($employeeId, $year, $row->request_type) - $others);
            if ($left + 1e-9 < $days) {
                throw new RuntimeException('Only '.LeaveQuantity::credits(max(0.0, $left)).' days of '.($names[$row->request_type] ?? $row->request_type)
                    .' are left to convert to cash for this employee; the credits were used since the request was approved. Review the cash conversion before finalizing.');
            }
            $credit = LeaveCredit::query()->where('employee_id', $employeeId)->where('year', $year)->where('request_type', $row->request_type)->lockForUpdate()->first();
            if ($credit !== null) {
                LeaveCreditWriter::baseline($credit);
            }
            $credit ??= new LeaveCredit(['employee_id' => $employeeId, 'year' => $year, 'request_type' => $row->request_type]);
            $credit->credits = LeaveQuantity::normalize(($credit->credits ?? 0.0) - $days);
            if (! $credit->save()) {
                throw new RuntimeException('The leave credits could not be converted to cash.');
            }
            $entryId = (string) Str::ulid();
            $typeName = $names[$row->request_type] ?? $row->request_type;
            LeaveCreditWriter::append($actor, $employeeId, $year, $row->request_type, 'CASH_OUT', -$days, "Converted to cash · payroll {$runCode}", $entryId);
            $row->forceFill(['status' => LeaveCashOut::PAID, 'payslip_id' => $payslipId, 'amount' => $line['amount']]);
            if (! $row->save()) {
                throw new RuntimeException('The cash conversion could not be marked paid.');
            }
            $parts[] = $typeName.' '.LeaveQuantity::format($days).' '.($days === 1.0 ? 'day' : 'days').' · PHP '.number_format($line['amount'], 2);
        }
        $sentence = "Payroll {$runCode}: ".implode(' · ', $parts).' cashed out of leave credits';
        DB::afterCommit(static function () use ($actor, $employeeId, $runCode, $sentence, $parts): void {
            AuditService::record($actor, AuditEvent::LEAVE_CASH_OUT_PAID, 'leave_cash_outs', $employeeId, $sentence);
            Notifier::employee($employeeId, 'LEAVE_CASH_OUT_PAID', "Leave credits converted to cash on payroll {$runCode}", implode(' · ', $parts), '/portal?tab=payslips');
        });
    }
}
