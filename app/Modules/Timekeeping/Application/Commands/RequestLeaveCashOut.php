<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Models\LeaveCashOut;
use App\Models\LeaveCredit;
use App\Modules\Timekeeping\Application\LeaveBalances;
use App\Modules\Timekeeping\Application\LeaveCashOuts;
use App\Modules\Timekeeping\Application\LeaveCharges;
use App\Modules\Timekeeping\Application\LeaveCreditWriter;
use App\Modules\Timekeeping\Application\LeavePolicyStore;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Domain\Credits\LeaveCashOutPolicy;
use App\Modules\Timekeeping\Domain\Credits\LeavePolicy;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * An employee asks to turn part of ONE leave type's credits into pay
 * (LeaveCashOutPolicy): their own employee only, a type Leave Credits
 * allows, whole or half days, no more than the live balance less the days
 * already spoken for, and within their policy's yearly maximum. One
 * pending request per type at a time. The request waits for a leave
 * manager (ReviewLeaveCashOut); nothing moves on the ledger until the
 * payroll that pays it posts. Audited best-effort after commit, and every
 * LEAVES holder is told.
 */
final class RequestLeaveCashOut
{
    public function handle(Access $access, string $token, string $days, string $note): string
    {
        if (! $access->hasModule(ModuleCode::PORTAL, ModuleCode::LEAVES)) {
            throw new RuntimeException('You do not have access to that.');
        }
        if (! $access->hasEmployeeLink()) {
            throw new RuntimeException('Your login is not linked to an employee record.');
        }
        if (! LeaveCashOuts::ready() || ! LeaveTypeCreditRecords::ready() || ! LeaveTypeCreditRecords::cashOutReady()) {
            throw new RuntimeException('Leave credits can be cashed out once this installation\'s upgrade finishes.');
        }
        $note = trim($note);
        if (mb_strlen($note) > 500) {
            throw new RuntimeException('Keep the note to 500 characters.');
        }
        $employeeId = $access->ownEmployeeId();
        $entry = LeaveTypeCreditRecords::rules()[$token] ?? null;
        if ($entry === null) {
            throw new RuntimeException('That leave type no longer exists.');
        }
        $today = ManilaClock::todayKey();
        $year = (int) substr($today, 0, 4);

        return DB::transaction(static function () use ($access, $employeeId, $token, $days, $note, $entry, $today, $year): string {
            LeaveCreditWriter::lock();
            if (LeaveCashOut::query()->where('employee_id', $employeeId)->where('request_type', $token)->where('status', LeaveCashOut::PENDING)->lockForUpdate()->exists()) {
                throw new RuntimeException('You already have a pending cash-out for this leave type.');
            }
            $policy = LeavePolicyStore::ready() ? LeavePolicyStore::policy(LeavePolicyStore::inForce($employeeId, $today)) : LeavePolicy::none();
            $liveYear = LeaveCharges::liveYear($employeeId, $year);
            $credited = LeaveCredit::query()->where('employee_id', $employeeId)->where('year', $liveYear)->where('request_type', $token)->exists();
            $available = $credited ? LeaveBalances::available($employeeId, $liveYear, $token) : null;
            $error = LeaveCashOutPolicy::error($days, $entry['rules']->canCashOut, $available, $policy->cashOutMaxPerYear(), LeaveCashOuts::askedInYear($employeeId, $year));
            if ($error !== null) {
                throw new RuntimeException($error);
            }
            $wanted = LeaveQuantity::parse($days) ?? 0.0;
            $row = new LeaveCashOut([
                'employee_id' => $employeeId, 'request_type' => $token, 'days' => $wanted,
                'status' => LeaveCashOut::PENDING, 'note' => $note === '' ? null : $note,
                'requested_at' => CarbonImmutable::now('UTC'),
            ]);
            $row->requested_by = $access->userId === null ? null : (int) $access->userId;
            if (! $row->save()) {
                throw new RuntimeException('The request could not be saved.');
            }
            $what = LeaveQuantity::format($wanted).' '.($wanted === 1.0 ? 'day' : 'days').' of '.$entry['name'];
            DB::afterCommit(static function () use ($access, $employeeId, $what, $note): void {
                AuditService::record($access, AuditEvent::LEAVE_CASH_OUT_REQUESTED, 'leave_cash_outs', $employeeId, "Cash-out of {$what} requested".($note === '' ? '' : " · {$note}"));
                Notifier::moduleHolders(ModuleCode::LEAVES, $access->userId, 'LEAVE_CASH_OUT_REQUESTED',
                    'Leave credit cash-out requested', $access->fullName.' asks to cash out '.$what.'.', '/leaves');
            });

            return "Cash-out of {$what} requested.";
        });
    }
}
