<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\LeaveSignatory;
use App\Modules\Timekeeping\Application\LeaveSettingsAccess;
use App\Modules\Timekeeping\Application\Queries\LeaveSignatoryRecords;
use App\Modules\Timekeeping\Domain\Absence\LeaveSignatorySlot;
use App\Modules\Workforce\Contracts\EmployeeLeaveFacts;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\AuditService;
use RuntimeException;

/**
 * Sets the employee who signs a slot of the Application for Leave. The form
 * reads their name and position from Employee Records, so nothing is typed
 * here. Null empties the slot, so the form shows a blank line. A save that
 * changes nothing writes nothing and leaves no audit row, which also lets a
 * slot keep an employee whose record has since become inactive. The form
 * shows the slot as it stands; the audit trail holds the history.
 */
final class SaveLeaveSignatory
{
    private const int MAX_LENGTH = 200;

    /** @return bool whether anything changed */
    public function handle(Access $access, string $slot, ?string $employeeId): bool
    {
        if (! LeaveSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! LeaveSignatorySlot::isSlot($slot)) {
            throw new RuntimeException('Choose which officer this is.');
        }
        if (! LeaveSignatoryRecords::employeesReady()) {
            throw new RuntimeException('The officers can be set once this installation\'s upgrade finishes.');
        }

        // A plain read, never a locking one: a slot not set yet has no row,
        // and a locking read of a missing row takes a gap lock that two
        // first saves deadlock on. `lsig_slot_unq` refuses the second insert.
        $row = LeaveSignatory::query()->where('slot', $slot)->first();
        if ($row !== null && $row->employee_id === $employeeId && ($employeeId !== null || ((string) $row->name === '' && (string) $row->designation === ''))) {
            return false;
        }
        $who = $employeeId === null ? 'cleared' : $this->signatory($access, $employeeId);

        $row ??= new LeaveSignatory;
        $row->fill(['slot' => $slot, 'employee_id' => $employeeId, 'name' => null, 'designation' => null]);
        $row->updated_by = $access->userId === null ? null : (int) $access->userId;
        $row->updated_by_name = mb_substr($access->fullName, 0, self::MAX_LENGTH);
        $row->save();

        AuditService::record($access, AuditEvent::LEAVE_SIGNATORY_SET, 'leave_signatories', $slot, LeaveSignatorySlot::label($slot).": {$who}");

        return true;
    }

    /** "Name, Position" for the audit line, once the id resolves to an active employee the actor may see. */
    private function signatory(Access $access, string $employeeId): string
    {
        $employee = app(OperationalDirectory::class)->find($access, $employeeId);
        if ($employee === null || ! $employee->is_active) {
            throw new RuntimeException('Choose an active employee from the list.');
        }
        $person = app(EmployeeLeaveFacts::class)->signatories([$employeeId])[$employeeId];

        return $person['name'].($person['position'] === '' ? '' : ", {$person['position']}");
    }
}
