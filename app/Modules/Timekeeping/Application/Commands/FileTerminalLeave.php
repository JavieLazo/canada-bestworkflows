<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Models\LeaveCashOut;
use App\Modules\Timekeeping\Application\LeaveCashOuts;
use App\Modules\Timekeeping\Application\LeaveCreditWriter;
use App\Modules\Timekeeping\Application\TerminalLeave;
use App\Modules\Workforce\Contracts\EmployeeLeaveFacts;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A leave manager files TERMINAL LEAVE for an employee who has left
 * (TerminalLeave): all-data LEAVES, for an employee in their scope whose
 * record carries a Date Resigned. One PENDING cash conversion per leave
 * type paid out on separation, for the type's whole remaining balance,
 * none of the limits an employee's own request is held to. Refused while
 * another cash conversion of the employee waits for a decision, once
 * terminal leave was filed and not refused or withdrawn, and when nothing
 * is left to pay out. The requests are then decided like any other
 * (ReviewLeaveCashOut, which checks the balance again) and paid on a
 * payroll that includes the employee, priced from the highest salary on
 * record as the paying type values a day (payroll's cash-out line). The
 * employee cannot withdraw it (WithdrawLeaveCashOut); a leave manager
 * rejects it instead. Under the leave-credits lock, so a
 * charge or another filing cannot move the balance between the reading
 * and the rows. Audited best-effort after commit, and every LEAVES holder
 * is told, as for an employee's own request.
 */
final class FileTerminalLeave
{
    public function handle(Access $access, string $employeeId, string $note = ''): string
    {
        if (! $access->mayReadAllData(ModuleCode::LEAVES)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! TerminalLeave::ready()) {
            throw new RuntimeException('Terminal leave can be filed once this installation\'s upgrade finishes.');
        }
        $note = trim($note);
        if (mb_strlen($note) > 500) {
            throw new RuntimeException('Keep the note to 500 characters.');
        }
        $employee = app(OperationalDirectory::class)->find($access, $employeeId);
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        $name = EmployeeName::display($employee);
        if ((app(EmployeeLeaveFacts::class)->forPosting([$employeeId])[$employeeId]['date_resigned'] ?? null) === null) {
            throw new RuntimeException("Set {$name}'s Date Resigned on the employee record first: terminal leave pays out the balance of an employee who has left.");
        }

        return DB::transaction(static function () use ($access, $employeeId, $name, $note): string {
            LeaveCreditWriter::lock();
            $blocker = TerminalLeave::blocker($employeeId, $name);
            if ($blocker !== null) {
                throw new RuntimeException($blocker);
            }
            $lines = TerminalLeave::plan($employeeId);
            if ($lines === []) {
                throw new RuntimeException("{$name} has no leave credits to pay out. Only leave types set to be paid out on separation count.");
            }
            $now = CarbonImmutable::now('UTC');
            foreach ($lines as $line) {
                $row = new LeaveCashOut([
                    'employee_id' => $employeeId, 'request_type' => $line['token'], 'days' => $line['days'],
                    'status' => LeaveCashOut::PENDING, 'note' => $note === '' ? null : $note, 'requested_at' => $now,
                    'purpose' => LeaveCashOuts::TERMINAL,
                    ...(LeaveCashOuts::largeReady() ? ['large_request' => false] : []),
                ]);
                $row->requested_by = $access->userId === null ? null : (int) $access->userId;
                if (! $row->save()) {
                    throw new RuntimeException('Terminal leave could not be filed.');
                }
            }
            $what = TerminalLeave::text($lines);
            DB::afterCommit(static function () use ($access, $employeeId, $name, $what, $note): void {
                AuditService::record($access, AuditEvent::TERMINAL_LEAVE_FILED, 'leave_cash_outs', $employeeId, "{$name} · terminal leave of {$what} filed".($note === '' ? '' : " · {$note}"));
                Notifier::moduleHolders(ModuleCode::LEAVES, $access->userId, 'TERMINAL_LEAVE_FILED',
                    'Terminal leave filed', "{$name}: {$what}, waiting for approval.", '/leaves');
            });

            return "Terminal leave filed for {$name}: {$what}. Approve it under Cash conversions; it is paid on a payroll that includes {$name}.";
        });
    }
}
