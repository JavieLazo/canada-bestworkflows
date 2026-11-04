<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveCredits;
use App\Modules\Timekeeping\Application\Queries\LeaveCreditRecords;
use App\Modules\Timekeeping\Contracts\Views\LeaveBalanceView;
use App\Support\Access;
use Illuminate\Support\Str;

/**
 * Leave credits (leave_credits, 2026_09_26_0003) through the module's two
 * operations, called directly and never through a screen.
 *
 *   - SaveLeaveCredits gates on all_data + LEAVES, refuses a linked actor's
 *     own row, re-resolves the employee, re-validates every token and value
 *     BEFORE writing (one bad entry refuses the whole map), writes the rows
 *     that changed in one transaction and audits ONCE with the sentence an
 *     operator can read back — and writes nothing, audits nothing, on a
 *     refusal or a save that changes nothing.
 *   - LeaveCreditRecords derives a balance instead of storing one: entered
 *     credit minus the covered calendar days (a half day is 0.5 per day) of
 *     the APPROVED, with-pay requests of the same type whose start date
 *     falls in the year — unpaid, pending, rejected, withdrawn, official
 *     business and other years count for nothing, a range across New Year
 *     is charged whole to the year it starts, and a request lands on ONE
 *     credit key: its exact token first, the `_LEAVE` spelling otherwise.
 *     Both reads scope like the leave list (all_data + LEAVES sees
 *     everyone, a linked actor only themselves, an unlinked account no row
 *     at all through the impossible predicate) and `own()` pins the
 *     actor's employee on top of it, even for an all_data holder.
 *
 * Helpers are lcred-prefixed: Pest loads every test file into one process.
 */

/** @param list<string> $modules */
function lcredRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Lcred '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/**
 * A signed-in account, and the Access built fresh from it — the container's
 * scoped instance is forgotten first, so a test that switches actors never
 * reads the previous one.
 *
 * @param  list<string>  $modules
 */
function lcredActor(array $modules, bool $allData = false, ?string $employeeId = null): Access
{
    $user = User::factory()->create([
        'role_id' => lcredRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function lcredEmployee(string $first = 'Liza', string $last = 'Domingo'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'LC-'.random_int(10000, 99999),
        'first_name' => $first,
        'last_name' => $last,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

/** A Leave Settings row; the token requests and credits store is its normalised name ("Sick Leave" → SICK_LEAVE). */
function lcredType(string $name): LeaveType
{
    $type = new LeaveType;
    $type->forceFill(['code' => 'LC-'.Str::upper(Str::random(6)), 'name' => $name])->save();

    return $type;
}

/** One stored credit, written straight to the table — the fixture, never the command. */
function lcredCredit(EmployeeProfile $employee, int $year, string $key, float $credits): LeaveCredit
{
    $row = new LeaveCredit;
    $row->forceFill([
        'employee_id' => (string) $employee->getKey(),
        'year' => $year,
        'request_type' => $key,
        'credits' => $credits,
    ])->save();

    return $row;
}

/**
 * One request: APPROVED, with pay, whole days, one day long unless told
 * otherwise — the shape that draws on a credit, so every exclusion a test
 * wants is an explicit override.
 *
 * @param  array<string, mixed>  $overrides  column => value
 */
function lcredRequest(EmployeeProfile $employee, string $type, string $start, ?string $end = null, array $overrides = []): LeaveTravelRequest
{
    $request = new LeaveTravelRequest;
    $request->forceFill(array_merge([
        'code' => 'LC-'.Str::upper(Str::random(8)),
        'employee_id' => (string) $employee->getKey(),
        'request_type' => $type,
        'day_type' => 'WHOLE_DAY',
        'start_date' => $start,
        'end_date' => $end ?? $start,
        'reason' => 'Fixture',
        'status' => 'APPROVED',
        'is_paid' => true,
    ], $overrides))->save();

    return $request;
}

/**
 * What the table holds for one employee and year: token => days, in token
 * order, so a whole save is one assertion.
 *
 * @return array<string, float>
 */
function lcredStored(EmployeeProfile $employee, int $year): array
{
    $out = [];
    foreach (LeaveCredit::query()->where('employee_id', $employee->getKey())->where('year', $year)->orderBy('request_type')->get() as $row) {
        $out[$row->request_type] = (float) $row->credits;
    }

    return $out;
}

/**
 * Balance rows as plain tuples — [token, label, credited, used, remaining]
 * — so the maths of a whole row set is one strict comparison.
 *
 * @param  iterable<LeaveBalanceView>  $rows
 * @return list<array{0: string, 1: string, 2: float|null, 3: float, 4: float|null}>
 */
function lcredFlat(iterable $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $out[] = [$row->request_type, $row->label, $row->credited, $row->used, $row->remaining];
    }

    return $out;
}

/* |---| The command, called directly |---| */

it('saves one row per type and audits the exact sentence once, in Leave Settings order', function () {
    // Vacation is created first so that name order, not insertion order,
    // is what the sentence follows.
    lcredType('Vacation Leave');
    lcredType('Sick Leave');
    $liza = lcredEmployee();
    $actor = lcredActor([ModuleCode::LEAVES], allData: true);

    $message = app(SaveLeaveCredits::class)->handle($actor, (string) $liza->getKey(), 2026, [
        'VACATION_LEAVE' => '15',
        'SICK_LEAVE' => '7.5',
    ]);

    expect($message)->toBe("Liza Domingo's 2026 leave credits saved.");
    expect(lcredStored($liza, 2026))->toBe(['SICK_LEAVE' => 7.5, 'VACATION_LEAVE' => 15.0]);

    $audits = AuditLog::query()->where('action', 'LEAVE_CREDITS_SET')->get();
    expect($audits)->toHaveCount(1)
        ->and($audits[0]->entity)->toBe('leave_credits')
        ->and($audits[0]->entity_id)->toBe((string) $liza->getKey())
        ->and((int) $audits[0]->actor_id)->toBe((int) $actor->userId)
        ->and($audits[0]->details)->toBe('Liza Domingo · 2026 · Sick Leave 7.5 · Vacation Leave 15');
    expect(AuditLog::query()->count())->toBe(1);
});

it("clears a row on a blank and stores an explicit 0.00 on '0'", function () {
    lcredType('Sick Leave');
    lcredType('Vacation Leave');
    $liza = lcredEmployee();
    lcredCredit($liza, 2026, 'SICK_LEAVE', 7.5);
    lcredCredit($liza, 2026, 'VACATION_LEAVE', 15.0);
    $actor = lcredActor([ModuleCode::LEAVES], allData: true);
    $command = app(SaveLeaveCredits::class);
    $id = (string) $liza->getKey();

    expect($command->handle($actor, $id, 2026, ['SICK_LEAVE' => '', 'VACATION_LEAVE' => '0']))
        ->toBe("Liza Domingo's 2026 leave credits saved.");
    expect(lcredStored($liza, 2026))->toBe(['VACATION_LEAVE' => 0.0]);
    expect(AuditLog::query()->where('action', 'LEAVE_CREDITS_SET')->where('details', 'Liza Domingo · 2026 · Vacation Leave 0')->exists())->toBeTrue();

    // The last row cleared: the sentence says so, and no row is left.
    $command->handle($actor, $id, 2026, ['VACATION_LEAVE' => '']);
    expect(lcredStored($liza, 2026))->toBe([]);
    expect(AuditLog::query()->where('action', 'LEAVE_CREDITS_SET')->where('details', 'Liza Domingo · 2026 · no leave credits')->exists())->toBeTrue();
    expect(AuditLog::query()->count())->toBe(2);
});

it('writes no audit and answers the no-changes sentence when nothing changes', function () {
    lcredType('Sick Leave');
    lcredType('Vacation Leave');
    $liza = lcredEmployee();
    $actor = lcredActor([ModuleCode::LEAVES], allData: true);
    $command = app(SaveLeaveCredits::class);
    $id = (string) $liza->getKey();

    $command->handle($actor, $id, 2026, ['SICK_LEAVE' => '7.5']);
    $rowId = (string) LeaveCredit::query()->where('employee_id', $id)->value('id');
    expect(AuditLog::query()->count())->toBe(1);

    // The same save again, the same value spelled differently, a blank for
    // a type that has no row, and an empty map: none of them is a change.
    $sentence = "No changes to Liza Domingo's 2026 leave credits.";
    expect($command->handle($actor, $id, 2026, ['SICK_LEAVE' => '7.5']))->toBe($sentence);
    expect($command->handle($actor, $id, 2026, ['SICK_LEAVE' => ' 7.50 ', 'VACATION_LEAVE' => '']))->toBe($sentence);
    expect($command->handle($actor, $id, 2026, []))->toBe($sentence);

    expect(AuditLog::query()->count())->toBe(1);
    expect(lcredStored($liza, 2026))->toBe(['SICK_LEAVE' => 7.5]);
    // The same row, not deleted and re-inserted.
    expect(LeaveCredit::query()->whereKey($rowId)->exists())->toBeTrue();
});

it('refuses a LEAVES holder without all_data and an all_data holder without LEAVES, writing nothing', function () {
    lcredType('Sick Leave');
    $liza = lcredEmployee();
    $ramon = lcredEmployee('Ramon', 'Cruz');
    $command = app(SaveLeaveCredits::class);

    $ownRows = lcredActor([ModuleCode::LEAVES], allData: false, employeeId: (string) $ramon->getKey());
    expect(fn () => $command->handle($ownRows, (string) $liza->getKey(), 2026, ['SICK_LEAVE' => '5']))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');

    $noModule = lcredActor([ModuleCode::ROSTER, ModuleCode::PORTAL], allData: true);
    expect(fn () => $command->handle($noModule, (string) $liza->getKey(), 2026, ['SICK_LEAVE' => '5']))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');

    expect(LeaveCredit::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it("lets a linked all-data actor set their own credits as well as anyone else's", function () {
    lcredType('Sick Leave');
    $liza = lcredEmployee();
    $ramon = lcredEmployee('Ramon', 'Cruz');
    $actor = lcredActor([ModuleCode::LEAVES], allData: true, employeeId: (string) $liza->getKey());
    $command = app(SaveLeaveCredits::class);

    // Their own: one administrator runs the whole flow.
    $command->handle($actor, (string) $liza->getKey(), 2026, ['SICK_LEAVE' => '7']);
    expect(lcredStored($liza, 2026))->toBe(['SICK_LEAVE' => 7.0]);

    $command->handle($actor, (string) $ramon->getKey(), 2026, ['SICK_LEAVE' => '5']);
    expect(lcredStored($ramon, 2026))->toBe(['SICK_LEAVE' => 5.0])
        ->and(lcredStored($liza, 2026))->toBe(['SICK_LEAVE' => 7.0]);
});

it('refuses a token that is neither a creditable type nor one the employee already holds', function () {
    lcredType('Sick Leave');
    lcredType('Leave Without Pay');
    lcredType('Business Travel / OB');
    $liza = lcredEmployee();
    $actor = lcredActor([ModuleCode::LEAVES], allData: true);
    $command = app(SaveLeaveCredits::class);
    $id = (string) $liza->getKey();

    foreach ([
        ['MADE_UP' => '5'],
        // Leave Settings rows, but not creditable ones.
        ['LEAVE_WITHOUT_PAY' => '5'],
        ['BUSINESS_TRAVEL_OB' => '5'],
        // The legacy spelling: a stale token is accepted only while held.
        ['SICK' => '5'],
        // A list — an integer key — is a shape only the wire can deliver.
        ['5'],
        // One good token does not carry an unknown one.
        ['SICK_LEAVE' => '5', 'MADE_UP' => '5'],
    ] as $credits) {
        expect(fn () => $command->handle($actor, $id, 2026, $credits))
            ->toThrow(RuntimeException::class, 'That leave type no longer exists.');
    }

    expect(LeaveCredit::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('refuses a bad value before writing, so a valid one sent with it never lands', function () {
    lcredType('Sick Leave');
    lcredType('Vacation Leave');
    $liza = lcredEmployee();
    $actor = lcredActor([ModuleCode::LEAVES], allData: true);
    $command = app(SaveLeaveCredits::class);
    $id = (string) $liza->getKey();

    $step = 'Vacation Leave: Enter a number of days with up to four decimals.';
    $range = 'Vacation Leave: Credits must be between 0 and 999999.9999.';
    foreach ([
        ['12,5', $step],
        ['1.16675', $step],
        ['ten', $step],
        ['-1', $range],
        ['1000000', $range],
        // A non-scalar: a shape only the wire can deliver.
        [['15'], $step],
    ] as [$value, $error]) {
        expect(fn () => $command->handle($actor, $id, 2026, ['SICK_LEAVE' => '7.5', 'VACATION_LEAVE' => $value]))
            ->toThrow(RuntimeException::class, $error);
    }

    expect(LeaveCredit::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);

    // The shapes is_numeric admits are read as the days they name.
    $command->handle($actor, $id, 2026, ['SICK_LEAVE' => '.5', 'VACATION_LEAVE' => ' 3 ']);
    expect(lcredStored($liza, 2026))->toBe(['SICK_LEAVE' => 0.5, 'VACATION_LEAVE' => 3.0]);
});

it('refuses an employee the directory does not resolve', function () {
    lcredType('Sick Leave');
    $actor = lcredActor([ModuleCode::LEAVES], allData: true);

    expect(fn () => app(SaveLeaveCredits::class)->handle($actor, (string) Str::ulid(), 2026, ['SICK_LEAVE' => '5']))
        ->toThrow(RuntimeException::class, 'That employee no longer exists.');

    expect(LeaveCredit::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('lets a stale token be changed or cleared while it is held, and refuses it once it is gone', function () {
    lcredType('Sick Leave');
    $liza = lcredEmployee();
    lcredCredit($liza, 2026, 'SICK_LEAVE', 7.5);
    // A type deleted from Leave Settings since the credit was entered.
    lcredCredit($liza, 2026, 'BEREAVEMENT', 3.0);
    $actor = lcredActor([ModuleCode::LEAVES], allData: true);
    $command = app(SaveLeaveCredits::class);
    $id = (string) $liza->getKey();

    $command->handle($actor, $id, 2026, ['BEREAVEMENT' => '2']);
    expect(lcredStored($liza, 2026))->toBe(['BEREAVEMENT' => 2.0, 'SICK_LEAVE' => 7.5]);
    // The stale token after the types, title-cased because no name labels it.
    expect(AuditLog::query()->where('action', 'LEAVE_CREDITS_SET')->where('details', 'Liza Domingo · 2026 · Sick Leave 7.5 · Bereavement 2')->exists())->toBeTrue();

    $command->handle($actor, $id, 2026, ['BEREAVEMENT' => '']);
    expect(lcredStored($liza, 2026))->toBe(['SICK_LEAVE' => 7.5]);
    expect(AuditLog::query()->where('action', 'LEAVE_CREDITS_SET')->where('details', 'Liza Domingo · 2026 · Sick Leave 7.5')->exists())->toBeTrue();

    expect(fn () => $command->handle($actor, $id, 2026, ['BEREAVEMENT' => '1']))
        ->toThrow(RuntimeException::class, 'That leave type no longer exists.');
    expect(lcredStored($liza, 2026))->toBe(['SICK_LEAVE' => 7.5]);
    expect(AuditLog::query()->count())->toBe(2);
});

it('refuses a year outside 2000 to 2100 and accepts the bounds themselves', function () {
    lcredType('Sick Leave');
    $liza = lcredEmployee();
    $actor = lcredActor([ModuleCode::LEAVES], allData: true);
    $command = app(SaveLeaveCredits::class);
    $id = (string) $liza->getKey();

    foreach ([1999, 2101, 0] as $year) {
        expect(fn () => $command->handle($actor, $id, $year, ['SICK_LEAVE' => '5']))
            ->toThrow(RuntimeException::class, 'Choose a year between 2000 and 2100.');
    }
    expect(LeaveCredit::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);

    $command->handle($actor, $id, 2000, ['SICK_LEAVE' => '5']);
    $command->handle($actor, $id, 2100, ['SICK_LEAVE' => '6']);
    expect(lcredStored($liza, 2000))->toBe(['SICK_LEAVE' => 5.0])
        ->and(lcredStored($liza, 2100))->toBe(['SICK_LEAVE' => 6.0]);
});

/* |---| The query: the maths |---| */

it('charges a legacy SICK request to SICK_LEAVE credits once, the exact token first when both rows exist, at 0.5 per half day', function () {
    lcredType('Vacation Leave');
    lcredType('Sick Leave');
    $liza = lcredEmployee();
    $ramon = lcredEmployee('Ramon', 'Cruz');

    // Liza holds only today's spelling; her legacy SICK row counts against it.
    lcredCredit($liza, 2026, 'SICK_LEAVE', 10.0);
    lcredRequest($liza, 'SICK', '2026-08-10', '2026-08-11');                                     // 2 days
    lcredRequest($liza, 'SICK_LEAVE', '2026-03-02');                                             // 1 day
    lcredRequest($liza, 'SICK_LEAVE', '2026-05-04', '2026-05-05', ['day_type' => 'HALF_DAY']);   // 2 × 0.5
    lcredRequest($liza, 'SICK_LEAVE', '2026-06-01', null, ['day_type' => null]);                 // null reads as a whole day

    // Ramon holds BOTH: each request lands on its own token, never on both.
    lcredCredit($ramon, 2026, 'SICK', 5.0);
    lcredCredit($ramon, 2026, 'SICK_LEAVE', 10.0);
    lcredRequest($ramon, 'SICK', '2026-02-02', '2026-02-03');   // 2 days → SICK
    lcredRequest($ramon, 'SICK_LEAVE', '2026-02-09');           // 1 day → SICK_LEAVE

    $actor = lcredActor([ModuleCode::LEAVES], allData: true);
    $ids = [(string) $liza->getKey(), (string) $ramon->getKey()];
    $balances = app(LeaveCreditRecords::class)->balancesForEmployees($actor, 2026, $ids);

    expect(array_keys($balances))->toBe($ids);
    expect(lcredFlat($balances[$ids[0]]))->toBe([
        ['SICK_LEAVE', 'Sick Leave', 10.0, 5.0, 5.0],
        ['VACATION_LEAVE', 'Vacation Leave', null, 0.0, null],
    ]);
    // The stale SICK row comes last; the `_LEAVE` tolerance would label it
    // "Sick Leave" like the live row beside it, so the token is appended to
    // tell the two apart in the dialog and the audit sentence.
    expect(lcredFlat($balances[$ids[1]]))->toBe([
        ['SICK_LEAVE', 'Sick Leave', 10.0, 1.0, 9.0],
        ['VACATION_LEAVE', 'Vacation Leave', null, 0.0, null],
        ['SICK', 'Sick Leave (SICK)', 5.0, 2.0, 3.0],
    ]);
});

it('excludes unpaid, pending, rejected, withdrawn, official-business and other-year requests, and charges a New Year range to its start year', function () {
    lcredType('Sick Leave');
    lcredType('Vacation Leave');
    lcredType('Business Travel / OB');
    $liza = lcredEmployee();
    lcredCredit($liza, 2026, 'VACATION_LEAVE', 15.0);
    lcredCredit($liza, 2027, 'VACATION_LEAVE', 12.0);

    lcredRequest($liza, 'VACATION_LEAVE', '2026-02-02', '2026-02-03', ['is_paid' => false]);      // approved without pay
    lcredRequest($liza, 'VACATION_LEAVE', '2026-04-06', '2026-04-07', ['status' => 'PENDING']);
    lcredRequest($liza, 'VACATION_LEAVE', '2026-04-08', null, ['status' => 'REJECTED']);
    lcredRequest($liza, 'VACATION_LEAVE', '2026-04-09', null, ['status' => 'WITHDRAWN']);
    lcredRequest($liza, 'BUSINESS_TRAVEL', '2026-06-01', '2026-06-02', ['is_paid' => null]);      // official business
    lcredRequest($liza, 'VACATION_LEAVE', '2025-12-29', '2025-12-30');                            // last year
    lcredRequest($liza, 'VACATION_LEAVE', '2026-12-30', '2027-01-02');                            // 4 days, all of them 2026's
    lcredRequest($liza, 'VACATION_LEAVE', '2026-07-06', null, ['is_paid' => null]);               // null pay on leave reads as paid: 1 day

    $actor = lcredActor([ModuleCode::LEAVES], allData: true);
    $records = app(LeaveCreditRecords::class);
    $id = (string) $liza->getKey();

    expect(lcredFlat($records->balances($actor, $id, 2026)))->toBe([
        ['SICK_LEAVE', 'Sick Leave', null, 0.0, null],
        ['VACATION_LEAVE', 'Vacation Leave', 15.0, 5.0, 10.0],
    ]);
    expect(lcredFlat($records->balances($actor, $id, 2027)))->toBe([
        ['SICK_LEAVE', 'Sick Leave', null, 0.0, null],
        ['VACATION_LEAVE', 'Vacation Leave', 12.0, 0.0, 12.0],
    ]);
    // Business travel is not a column at all.
    expect(array_keys($records->creditableTypes($actor)))->toBe(['SICK_LEAVE', 'VACATION_LEAVE']);
});

it('lists the creditable types in name order, null where nothing is entered, then the stale tokens, for every employee asked', function () {
    lcredType('Vacation Leave');
    lcredType('Sick Leave');
    lcredType('Emergency Leave');
    lcredType('Leave Without Pay');
    lcredType('Business Travel / OB');
    $liza = lcredEmployee();
    $ramon = lcredEmployee('Ramon', 'Cruz');
    lcredCredit($liza, 2026, 'SICK_LEAVE', 10.0);
    lcredCredit($liza, 2026, 'BEREAVEMENT', 3.0);       // a type since deleted from Leave Settings
    lcredCredit($liza, 2025, 'VACATION_LEAVE', 15.0);   // another year: not one of 2026's rows
    lcredRequest($liza, 'BEREAVEMENT', '2026-03-02', '2026-03-04');   // 3 days against the stale row
    lcredRequest($liza, 'EMERGENCY_LEAVE', '2026-03-09');             // used is counted even with nothing entered

    $actor = lcredActor([ModuleCode::LEAVES], allData: true);
    $records = app(LeaveCreditRecords::class);
    $ids = [(string) $liza->getKey(), (string) $ramon->getKey()];

    expect($records->creditableTypes($actor))->toBe([
        'EMERGENCY_LEAVE' => 'Emergency Leave',
        'SICK_LEAVE' => 'Sick Leave',
        'VACATION_LEAVE' => 'Vacation Leave',
    ]);

    $balances = $records->balancesForEmployees($actor, 2026, $ids);
    expect(lcredFlat($balances[$ids[0]]))->toBe([
        ['EMERGENCY_LEAVE', 'Emergency Leave', null, 1.0, null],
        ['SICK_LEAVE', 'Sick Leave', 10.0, 0.0, 10.0],
        ['VACATION_LEAVE', 'Vacation Leave', null, 0.0, null],
        ['BEREAVEMENT', 'Bereavement', 3.0, 3.0, 0.0],
    ]);
    expect(lcredFlat($balances[$ids[1]]))->toBe([
        ['EMERGENCY_LEAVE', 'Emergency Leave', null, 0.0, null],
        ['SICK_LEAVE', 'Sick Leave', null, 0.0, null],
        ['VACATION_LEAVE', 'Vacation Leave', null, 0.0, null],
    ]);
    expect($balances[$ids[0]][0]->entered())->toBeFalse()
        ->and($balances[$ids[0]][1]->entered())->toBeTrue();

    // forYear carries only what was entered, as detached views.
    $entered = $records->forYear($actor, 2026, $ids);
    expect(array_keys($entered))->toBe([$ids[0]]);
    expect(array_keys($entered[$ids[0]]))->toEqualCanonicalizing(['SICK_LEAVE', 'BEREAVEMENT']);
    $view = $entered[$ids[0]]['SICK_LEAVE'];
    expect($view->employee_id)->toBe($ids[0])
        ->and($view->year)->toBe(2026)
        ->and($view->request_type)->toBe('SICK_LEAVE')
        ->and($view->credits)->toBe(10.0);

    expect($records->forYear($actor, 2026, []))->toBe([])
        ->and($records->balancesForEmployees($actor, 2026, []))->toBe([]);
});

/* |---| The query: scoping |---| */

it("pins own() to the actor's employee even for an all_data LEAVES holder, shows only entered rows, and is empty without a link or a row", function () {
    lcredType('Sick Leave');
    lcredType('Vacation Leave');
    $liza = lcredEmployee();
    $ramon = lcredEmployee('Ramon', 'Cruz');
    lcredCredit($liza, 2026, 'VACATION_LEAVE', 15.0);
    lcredCredit($ramon, 2026, 'VACATION_LEAVE', 12.0);
    lcredCredit($ramon, 2026, 'SICK_LEAVE', 8.0);
    lcredRequest($liza, 'VACATION_LEAVE', '2026-02-02', '2026-02-03');    // 2 days, hers
    lcredRequest($ramon, 'VACATION_LEAVE', '2026-02-02', '2026-02-04');   // 3 days, his: never hers
    lcredRequest($liza, 'SICK', '2026-03-02');                            // approved, nothing entered: not a portal row

    $records = app(LeaveCreditRecords::class);
    $lizaId = (string) $liza->getKey();
    $mine = [['VACATION_LEAVE', 'Vacation Leave', 15.0, 2.0, 13.0]];

    // Liza runs Leave Management: "mine" is still hers alone...
    $hr = lcredActor([ModuleCode::LEAVES, ModuleCode::PORTAL], allData: true, employeeId: $lizaId);
    expect(lcredFlat($records->own($hr, 2026)))->toBe($mine);
    // ...while her organisation-wide read still sees Ramon.
    expect(lcredFlat($records->balances($hr, (string) $ramon->getKey(), 2026)))->toBe([
        ['SICK_LEAVE', 'Sick Leave', 8.0, 0.0, 8.0],
        ['VACATION_LEAVE', 'Vacation Leave', 12.0, 3.0, 9.0],
    ]);

    // Liza holding PORTAL alone: the same rows through the own-rows arm.
    $self = lcredActor([ModuleCode::PORTAL], allData: false, employeeId: $lizaId);
    expect(lcredFlat($records->own($self, 2026)))->toBe($mine);
    expect($records->own($self, 2025)->isEmpty())->toBeTrue();

    // An unlinked all_data + LEAVES holder: nothing is "mine".
    $unlinked = lcredActor([ModuleCode::LEAVES, ModuleCode::PORTAL], allData: true);
    expect($records->own($unlinked, 2026)->isEmpty())->toBeTrue();
});

it("scopes every read to the actor's own employee without all_data, and to no row at all without a link", function () {
    lcredType('Sick Leave');
    $liza = lcredEmployee();
    $ramon = lcredEmployee('Ramon', 'Cruz');
    lcredCredit($liza, 2026, 'SICK_LEAVE', 10.0);
    lcredCredit($ramon, 2026, 'SICK_LEAVE', 12.0);
    lcredRequest($liza, 'SICK_LEAVE', '2026-02-02', '2026-02-03');
    lcredRequest($ramon, 'SICK_LEAVE', '2026-02-02', '2026-02-04');

    $records = app(LeaveCreditRecords::class);
    $ids = [(string) $liza->getKey(), (string) $ramon->getKey()];

    // Liza herself, holding LEAVES without all_data: her rows, and Ramon's
    // read as nothing entered and nothing used — neither number leaks.
    $self = lcredActor([ModuleCode::LEAVES], allData: false, employeeId: $ids[0]);
    expect(array_keys($records->forYear($self, 2026, $ids)))->toBe([$ids[0]]);
    $balances = $records->balancesForEmployees($self, 2026, $ids);
    expect(lcredFlat($balances[$ids[0]]))->toBe([['SICK_LEAVE', 'Sick Leave', 10.0, 2.0, 8.0]])
        ->and(lcredFlat($balances[$ids[1]]))->toBe([['SICK_LEAVE', 'Sick Leave', null, 0.0, null]]);

    // An unlinked account without LEAVES: the impossible predicate, never
    // IS NULL, and every read answers with nothing entered.
    $none = lcredActor([ModuleCode::PORTAL]);
    expect(strtolower(LeaveCredit::query()->toEmployee($none, 'employee_id', ModuleCode::LEAVES)->toRawSql()))
        ->toContain('1 = 0')
        ->not->toContain('is null');
    expect($records->forYear($none, 2026, $ids))->toBe([]);
    expect(lcredFlat($records->balances($none, $ids[0], 2026)))->toBe([['SICK_LEAVE', 'Sick Leave', null, 0.0, null]]);
    expect($records->own($none, 2026)->isEmpty())->toBeTrue();
});
