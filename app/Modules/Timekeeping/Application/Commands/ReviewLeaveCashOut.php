<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Models\LeaveCashOut;
use App\Modules\Timekeeping\Application\LeaveBalances;
use App\Modules\Timekeeping\Application\LeaveCashOuts;
use App\Modules\Timekeeping\Application\LeaveCharges;
use App\Modules\Timekeeping\Application\LeaveCreditWriter;
use App\Modules\Timekeeping\Application\LeaveEligibilities;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A leave manager approves or rejects an employee's cash-out: all-data
 * LEAVES, the manager's own request included — one administrator must be
 * able to run the whole flow alone. Approval re-checks, under the
 * leave-credits lock, that the live balance less the other days spoken
 * for still covers it, because credits may have been charged or filed
 * since the request. An approved cash-out waits for the employee's next
 * regular payroll — terminal leave (FileTerminalLeave) for a payroll that
 * includes the employee who left. A RequiredAudit row on the same
 * transaction, and the employee is told after commit.
 */
final class ReviewLeaveCashOut
{
    public function handle(Access $access, string $id, bool $approve, string $note = ''): string
    {
        if (! $access->mayReadAllData(ModuleCode::LEAVES)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! LeaveCashOuts::ready()) {
            throw new RuntimeException('Leave credits can be converted to cash once this installation\'s upgrade finishes.');
        }
        $note = trim($note);
        if (mb_strlen($note) > 500) {
            throw new RuntimeException('Keep the note to 500 characters.');
        }

        return DB::transaction(static function () use ($access, $id, $approve, $note): string {
            LeaveCreditWriter::lock();
            $row = LeaveCashOut::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That cash conversion request no longer exists.');
            }
            if ($row->status !== LeaveCashOut::PENDING) {
                throw new RuntimeException('That cash conversion has already been decided.');
            }
            $employee = app(OperationalDirectory::class)->find($access, $row->employee_id);
            if ($employee === null) {
                throw new RuntimeException('That employee no longer exists.');
            }
            $name = EmployeeName::display($employee);
            $typeName = LeaveTypeCreditRecords::rules()[$row->request_type]['name'] ?? $row->request_type;
            $terminal = $row->purpose === LeaveCashOuts::TERMINAL;
            $what = ($terminal ? 'terminal leave of ' : '').LeaveQuantity::format($row->days).' '.($row->days === 1.0 ? 'day' : 'days').' of '.$typeName;
            if ($approve) {
                // A monetization of a type the employee may no longer use
                // (LeaveEligibilities) — their status or sex changed since
                // the request, or the type's settings did — is not approved;
                // terminal leave still pays what is held.
                $refusal = $terminal ? null : LeaveEligibilities::refusal($row->employee_id, $row->request_type, ManilaClock::todayKey());
                if ($refusal !== null) {
                    throw new RuntimeException($refusal.' Its credits cannot be converted to cash: reject the request instead.');
                }
                $year = LeaveCharges::liveYear($row->employee_id, (int) substr(ManilaClock::todayKey(), 0, 4));
                $others = LeaveQuantity::normalize((LeaveCashOuts::spokenFor($row->employee_id)[$row->request_type] ?? 0.0) - $row->days);
                $available = LeaveQuantity::normalize(LeaveBalances::balance($row->employee_id, $year, $row->request_type) - $others);
                if ($available + 1e-9 < $row->days) {
                    throw new RuntimeException('Only '.LeaveQuantity::credits(max(0.0, $available))." days of {$typeName} are left to convert to cash; the credits were used since the request.");
                }
            }
            $row->status = $approve ? LeaveCashOut::APPROVED : LeaveCashOut::REJECTED;
            $row->review_note = $note === '' ? null : $note;
            $row->reviewed_by = $access->userId === null ? null : (int) $access->userId;
            $row->reviewed_by_name = $access->fullName;
            $row->reviewed_at = CarbonImmutable::now('UTC');
            if (! $row->save()) {
                throw new RuntimeException('The decision could not be saved.');
            }
            $verb = $approve ? 'approved' : 'rejected';
            RequiredAudit::record($access, AuditEvent::LEAVE_CASH_OUT_REVIEWED, 'leave_cash_outs', $row->employee_id,
                "{$name} · cash-out of {$what} {$verb}".($note === '' ? '' : " · {$note}"));
            DB::afterCommit(static fn () => Notifier::employee($row->employee_id, 'LEAVE_CASH_OUT_REVIEWED',
                "Cash conversion of {$what} {$verb}",
                $approve ? ($terminal ? 'It is paid on your final payroll.' : 'It is paid on your next regular payroll.') : ($note === '' ? null : $note), '/portal?tab=requests'));

            return "Cash conversion of {$what} {$verb} for {$name}.";
        });
    }
}
