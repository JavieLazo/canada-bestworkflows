<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\LeaveCashOut;
use App\Modules\Timekeeping\Application\LeaveCashOuts;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The employee takes back their own cash-out while it is still pending.
 * The state guard sits in the UPDATE: a request a manager decided in the
 * meantime is reported as already decided, never overwritten. Terminal
 * leave is HR's filing (FileTerminalLeave), so the employee cannot
 * withdraw it; a leave manager rejects it instead.
 */
final class WithdrawLeaveCashOut
{
    public function handle(Access $access, string $id): string
    {
        if (! $access->hasModule(ModuleCode::PORTAL, ModuleCode::LEAVES)) {
            throw new RuntimeException('You do not have access to that.');
        }
        if (! $access->hasEmployeeLink()) {
            throw new RuntimeException('Your login is not linked to an employee record.');
        }
        if (! LeaveCashOuts::ready()) {
            throw new RuntimeException('Leave credits can be converted to cash once this installation\'s upgrade finishes.');
        }
        $employeeId = $access->ownEmployeeId();

        return DB::transaction(static function () use ($access, $employeeId, $id): string {
            $row = LeaveCashOut::query()->whereKey($id)->where('employee_id', $employeeId)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That cash conversion request no longer exists.');
            }
            if ($row->purpose === LeaveCashOuts::TERMINAL) {
                throw new RuntimeException('Terminal leave is filed by a leave manager; ask one to reject it.');
            }
            $updated = LeaveCashOut::query()->whereKey($id)->where('status', LeaveCashOut::PENDING)->update(['status' => LeaveCashOut::WITHDRAWN]);
            if ($updated === 0) {
                throw new RuntimeException('That cash conversion has already been decided.');
            }
            $what = LeaveQuantity::format($row->days).' '.($row->days === 1.0 ? 'day' : 'days');
            DB::afterCommit(static fn () => AuditService::record($access, AuditEvent::LEAVE_CASH_OUT_WITHDRAWN, 'leave_cash_outs', $employeeId, "Cash-out of {$what} withdrawn"));

            return 'Cash conversion request withdrawn.';
        });
    }
}
