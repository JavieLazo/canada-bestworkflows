<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Models\LeaveSignatory;
use App\Modules\Timekeeping\Contracts\Views\LeaveSignatoryView;
use App\Modules\Timekeeping\Domain\Absence\LeaveSignatorySlot;
use App\Modules\Workforce\Contracts\EmployeeLeaveFacts;
use App\Support\Access;
use Illuminate\Support\Facades\Schema;

/**
 * The officers the Application for Leave names, one view per slot in the
 * form's order. Readable by any signed-in account, because every employee's
 * form shows them; written only through SaveLeaveSignatory. A slot with a
 * picked employee shows that employee's name and position as the record
 * stands. One HR has never set shows the officer printed on the paper form,
 * one HR emptied a blank line, and one typed before employees could be picked
 * what was typed.
 *
 * Files reach the host before the cron runs their migration, so while the
 * table is absent every slot reads as printed, while the employee column is
 * absent a slot reads as typed, and the page says so.
 */
final class LeaveSignatoryRecords
{
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('leave_signatories'));
    }

    /** Whether a slot can name an employee yet (2026_11_11_0005). */
    public static function employeesReady(): bool
    {
        return once(static fn (): bool => self::ready() && Schema::hasColumn('leave_signatories', 'employee_id'));
    }

    /** @return array<string, LeaveSignatoryView> keyed by slot, in the form's order */
    public function all(Access $actor): array
    {
        abort_if($actor->userId === null, 403);
        $rows = self::ready() ? LeaveSignatory::query()->get()->keyBy('slot') : collect();
        $linked = self::employeesReady() ? array_values($rows->pluck('employee_id')->filter()->map(strval(...))->all()) : [];
        $people = app(EmployeeLeaveFacts::class)->signatories($linked);

        $views = [];
        foreach (LeaveSignatorySlot::all() as $slot) {
            $row = $rows->get($slot);
            $employeeId = self::employeesReady() ? (string) ($row->employee_id ?? '') : '';
            $person = $people[$employeeId] ?? null;
            [$name, $designation] = match (true) {
                $person !== null => [$person['name'], $person['position']],
                $row === null => LeaveSignatorySlot::printed($slot),
                default => [(string) ($row->name ?? ''), (string) ($row->designation ?? '')],
            };
            $views[$slot] = new LeaveSignatoryView($slot, LeaveSignatorySlot::label($slot), $name, $designation, $person === null ? '' : $employeeId, $person['is_active'] ?? true);
        }

        return $views;
    }
}
