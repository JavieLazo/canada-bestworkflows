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
use App\Modules\Timekeeping\Application\LeaveEligibilities;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Domain\Credits\LeaveCashOutPolicy;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * An employee asks to turn part of ONE leave type's credits into pay
 * (LeaveCashOutPolicy): their own employee only, a type Leave Settings
 * allows, whole or half days, no more than the live balance less the days
 * already spoken for, and within the limits HR set on the type
 * (LeaveCashOutLimits) — counted per type over the Manila calendar year.
 * A request at or above the share of the balance the type asks a reason
 * for is LARGE: its note is that reason, and it is kept marked so the
 * reviewer sees it. One pending request per type at a time. The request
 * waits for a leave manager (ReviewLeaveCashOut); nothing moves on the
 * ledger until the payroll that pays it posts. Audited best-effort after
 * commit, and every LEAVES holder is told. It is a MONETIZATION of leave
 * credits; terminal leave is HR's to file (FileTerminalLeave).
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
            throw new RuntimeException('Leave credits can be converted to cash once this installation\'s upgrade finishes.');
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
        // Credits of a type the employee may not use (LeaveEligibilities)
        // stay where they are: they are not converted to cash. Terminal
        // leave still pays them out (FileTerminalLeave).
        $refusal = LeaveEligibilities::refusal($employeeId, $token, ManilaClock::todayKey());
        if ($refusal !== null) {
            throw new RuntimeException($refusal.' Its credits cannot be converted to cash.');
        }
        $year = (int) substr(ManilaClock::todayKey(), 0, 4);

        return DB::transaction(static function () use ($access, $employeeId, $token, $days, $note, $entry, $year): string {
            LeaveCreditWriter::lock();
            if (LeaveCashOut::query()->where('employee_id', $employeeId)->where('request_type', $token)->where('status', LeaveCashOut::PENDING)->lockForUpdate()->exists()) {
                throw new RuntimeException('You already have a pending cash conversion for this leave type.');
            }
            $liveYear = LeaveCharges::liveYear($employeeId, $year);
            $credited = LeaveCredit::query()->where('employee_id', $employeeId)->where('year', $liveYear)->where('request_type', $token)->exists();
            $available = $credited ? LeaveBalances::available($employeeId, $liveYear, $token) : null;
            $limits = $entry['rules']->cashOutLimits;
            $asked = LeaveCashOuts::inYear($employeeId, $token, $year);
            $error = LeaveCashOutPolicy::error($days, $entry['rules']->canCashOut, $available, $limits, $asked['days'], $asked['requests'], $note);
            if ($error !== null) {
                throw new RuntimeException($error);
            }
            $wanted = LeaveQuantity::parse($days) ?? 0.0;
            $large = LeaveCashOutPolicy::isLarge($wanted, (float) $available, $limits);
            $row = new LeaveCashOut([
                'employee_id' => $employeeId, 'request_type' => $token, 'days' => $wanted,
                'status' => LeaveCashOut::PENDING, 'note' => $note === '' ? null : $note,
                'requested_at' => CarbonImmutable::now('UTC'),
                ...(LeaveCashOuts::purposeReady() ? ['purpose' => LeaveCashOuts::MONETIZATION] : []),
                ...(LeaveCashOuts::largeReady() ? ['large_request' => $large] : []),
            ]);
            $row->requested_by = $access->userId === null ? null : (int) $access->userId;
            if (! $row->save()) {
                throw new RuntimeException('The request could not be saved.');
            }
            $what = LeaveQuantity::format($wanted).' '.($wanted === 1.0 ? 'day' : 'days').' of '.$entry['name'].($large ? ' (a large request)' : '');
            DB::afterCommit(static function () use ($access, $employeeId, $what, $note): void {
                AuditService::record($access, AuditEvent::LEAVE_CASH_OUT_REQUESTED, 'leave_cash_outs', $employeeId, "Cash-out of {$what} requested".($note === '' ? '' : " · {$note}"));
                Notifier::moduleHolders(ModuleCode::LEAVES, $access->userId, 'LEAVE_CASH_OUT_REQUESTED',
                    'Leave credit cash conversion requested', $access->fullName.' asks to convert '.$what.' to cash.', '/leaves');
            });

            return "Cash conversion of {$what} requested.";
        });
    }
}
