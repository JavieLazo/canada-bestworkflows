<?php

declare(strict_types=1);

use App\Models\AttendanceLog;
use App\Models\AttendanceRemark;
use App\Models\EmployeeDocument;
use App\Models\LeaveTravelRequest;
use App\Models\Payslip;
use App\Models\ProjectTask;
use App\Models\Shift;
use App\Support\Access;

/**
 * The non-negotiable regression test named in 01-STARTER-PACK.md section 5.3 and in
 * the ScopesToActor docblock, and listed in the plan's risk register as the
 * EARLY WARNING that the RBAC scoping leak has returned:
 *
 *   "no test asserts that an employee-less account gets an empty collection
 *    from every scoped screen"
 *
 * The leak: where($column, null) compiles to IS NULL, so an account with no
 * linked employee record is handed EVERY unassigned row - including a freshly
 * bootstrapped admin, before anyone has assigned them anything.
 *
 * These assert the generated SQL, not merely a row count, because a table that
 * happens to be empty makes a leaking query look correct.
 */

/** Every directly scoped model, with its employee foreign key and module code. */
function scopedModels(): array
{
    return [
        'AttendanceLog' => [AttendanceLog::class, 'employee_id', 'EDTR'],
        'AttendanceRemark' => [AttendanceRemark::class, 'employee_id', 'EDTR'],
        'Shift' => [Shift::class, 'employee_id', 'ROSTER'],
        'LeaveTravelRequest' => [LeaveTravelRequest::class, 'employee_id', 'LEAVES'],
        'EmployeeDocument' => [EmployeeDocument::class, 'employee_id', 'EMPLOYEES'],
        'Payslip' => [Payslip::class, 'employee_id', 'PAYROLL'],
        'ProjectTask' => [ProjectTask::class, 'assignee_id', 'TASKS'],
    ];
}

/** A signed-in account holding the grant but with NO employee link. */
function employeelessActor(array $modules, bool $allData = false): Access
{
    return new Access(
        userId: '1',
        employeeId: null,
        fullName: 'Unlinked Account',
        roleName: 'Admin',
        allData: $allData,
        modules: $modules,
    );
}

it('reports ROWS_NONE for an account with no employee link', function () {
    $actor = employeelessActor(['EDTR']);

    expect($actor->hasEmployeeLink())->toBeFalse();
    expect($actor->rowVisibility('EDTR'))->toBe(Access::ROWS_NONE);
});

it('compiles to an impossible predicate, never IS NULL, on every scoped model', function () {
    $leaks = [];
    $missing = [];

    foreach (scopedModels() as $label => [$class, $column, $module]) {
        $sql = strtolower(
            $class::query()
                ->toEmployee(employeelessActor([$module]), $column, $module)
                ->toRawSql()
        );

        if (! str_contains($sql, '1 = 0')) {
            $missing[] = "{$label}: {$sql}";
        }

        // The leak signature, in either quoting style.
        if (str_contains($sql, "\"{$column}\" is null") || str_contains($sql, "`{$column}` is null")) {
            $leaks[] = "{$label}: {$sql}";
        }
    }

    expect($leaks)->toBe([], 'DATA LEAK — a scope compiled to IS NULL for an employee-less actor.');
    expect($missing)->toBe([], 'A scoped model did not narrow to the impossible predicate.');
});

it('returns an empty collection even when unassigned rows exist', function () {
    $actor = employeelessActor(['TASKS']);

    // An unassigned row is precisely what IS NULL would have handed over.
    $count = ProjectTask::query()->toEmployee($actor, 'assignee_id', 'TASKS')->count();

    expect($count)->toBe(0);
});

it('widens to every row only when the role carries all_data', function () {
    $admin = employeelessActor(['EDTR'], allData: true);

    expect($admin->rowVisibility('EDTR'))->toBe(Access::ROWS_ALL);

    $sql = AttendanceLog::query()->toEmployee($admin, 'employee_id', 'EDTR')->toRawSql();

    expect($sql)->not->toContain('1 = 0');
});

it('fails closed for a guest', function () {
    $guest = Access::forGuest();

    expect($guest->isSignedIn())->toBeFalse();
    expect($guest->hasEmployeeLink())->toBeFalse();
    expect($guest->rowVisibility('EDTR'))->toBe(Access::ROWS_NONE);
});
