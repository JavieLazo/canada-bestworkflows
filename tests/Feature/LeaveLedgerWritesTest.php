<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\RecordLeaveCredit;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveCredits;
use App\Support\Access;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Manual movements are authorized, atomic, retry-safe and reconcile to the annual allocation. */
function ledgerWriteEmployee(): EmployeeProfile
{
    $row = new EmployeeProfile;
    $row->forceFill(['employee_code' => 'LED-'.Str::random(10), 'first_name' => 'Ledger', 'last_name' => 'Employee', 'date_hired' => '2024-01-01', 'is_active' => true])->save();

    return $row;
}

/** @param list<string> $modules */
function ledgerWriteActor(array $modules = [ModuleCode::LEAVES], bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Ledger '.Str::random(10), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function ledgerWriteType(): void
{
    $type = new LeaveType;
    $type->forceFill(['code' => 'LED-SICK', 'name' => 'Sick Leave'])->save();
}

function ledgerWriteCredit(EmployeeProfile $employee, float $days, string $type = 'SICK_LEAVE'): LeaveCredit
{
    $credit = new LeaveCredit;
    $credit->forceFill(['employee_id' => $employee->id, 'year' => 2026, 'request_type' => $type, 'credits' => $days])->save();

    return $credit;
}

it('records manual opening accrual carry-forward and signed adjustments without automatic rollover', function () {
    ledgerWriteType();
    $employee = ledgerWriteEmployee();
    $actor = ledgerWriteActor();
    $command = app(RecordLeaveCredit::class);
    foreach ([['OPENING', '10'], ['ACCRUAL', '1.5'], ['CARRY_FORWARD', '3'], ['ADJUSTMENT', '-0.5']] as [$kind, $days]) {
        $command->handle($actor, $employee->id, 2026, 'SICK_LEAVE', $kind, $days, 'HR approved entry', (string) Str::ulid());
    }

    expect(LeaveCredit::query()->sole()->credits)->toBe(14.0)
        ->and((float) LeaveCreditEntry::query()->sum('days'))->toBe(14.0)
        ->and(LeaveCreditEntry::query()->where('kind', 'CARRY_FORWARD')->sole()->source_year)->toBe(2025)
        ->and(LeaveCreditEntry::query()->where('kind', '<>', 'CARRY_FORWARD')->whereNotNull('source_year')->count())->toBe(0)
        ->and(LeaveCreditEntry::query()->where('recorded_by', $actor->userId)->count())->toBe(4)
        ->and(AuditLog::query()->where('action', 'LEAVE_CREDITS_SET')->count())->toBe(4)
        ->and(LeaveCredit::query()->where('year', 2025)->exists())->toBeFalse();
});

it('applies an identical dialog retry once and refuses reuse with different details or an actor', function () {
    ledgerWriteType();
    $employee = ledgerWriteEmployee();
    $actor = ledgerWriteActor();
    $command = app(RecordLeaveCredit::class);
    $id = (string) Str::ulid();
    $command->handle($actor, $employee->id, 2026, 'SICK_LEAVE', 'ACCRUAL', '2.5', ' Earned manually ', $id);
    expect($command->handle($actor, $employee->id, 2026, 'SICK_LEAVE', 'ACCRUAL', 2.5, 'Earned manually', $id))->toContain('already recorded');
    expect(fn () => $command->handle($actor, $employee->id, 2026, 'SICK_LEAVE', 'ACCRUAL', '3', 'Earned manually', $id))
        ->toThrow(RuntimeException::class, 'different details');
    $otherActor = ledgerWriteActor();
    expect(fn () => $command->handle($otherActor, $employee->id, 2026, 'SICK_LEAVE', 'ACCRUAL', '2.5', 'Earned manually', $id))
        ->toThrow(RuntimeException::class, 'different details');
    expect(LeaveCredit::query()->sole()->credits)->toBe(2.5)
        ->and(LeaveCreditEntry::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'LEAVE_CREDITS_SET')->count())->toBe(1);
});

it('preserves legacy baseline before a change and keeps history when an allocation is cleared', function () {
    test()->travelTo(new DateTimeImmutable('2026-10-01T00:00:00+00:00'));
    ledgerWriteType();
    $employee = ledgerWriteEmployee();
    $credit = ledgerWriteCredit($employee, 7.5);
    test()->travelTo(new DateTimeImmutable('2026-10-02T00:00:00+00:00'));
    $actor = ledgerWriteActor();
    $set = app(SaveLeaveCredits::class);
    $set->handle($actor, $employee->id, 2026, ['SICK_LEAVE' => '7.5']);
    expect(LeaveCreditEntry::query()->count())->toBe(0);
    $set->handle($actor, $employee->id, 2026, ['SICK_LEAVE' => '9']);
    $baseline = LeaveCreditEntry::query()->where('kind', 'BASELINE')->sole();
    expect($baseline->id)->toBe($credit->id)
        ->and($baseline->days)->toBe(7.5)
        ->and($baseline->recorded_by)->toBeNull()
        ->and($baseline->created_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-02 00:00:00.000');
    $set->handle($actor, $employee->id, 2026, ['SICK_LEAVE' => '']);
    expect(LeaveCredit::query()->count())->toBe(0)
        ->and(LeaveCreditEntry::query()->count())->toBe(3)
        ->and((float) LeaveCreditEntry::query()->sum('days'))->toBe(0.0);
    $set->handle($actor, $employee->id, 2026, ['SICK_LEAVE' => '4']);
    expect(LeaveCreditEntry::query()->where('kind', 'OPENING')->count())->toBe(0)
        ->and((float) LeaveCreditEntry::query()->sum('days'))->toBe(4.0);
});

it('keeps explicit zero allocations distinct and permits only one opening after any prior history', function () {
    ledgerWriteType();
    $employee = ledgerWriteEmployee();
    $actor = ledgerWriteActor();
    $set = app(SaveLeaveCredits::class);
    $set->handle($actor, $employee->id, 2026, ['SICK_LEAVE' => '0']);
    expect(LeaveCredit::query()->sole()->credits)->toBe(0.0)
        ->and(LeaveCreditEntry::query()->sole()->kind)->toBe('OPENING')
        ->and(LeaveCreditEntry::query()->sole()->days)->toBe(0.0);
    $set->handle($actor, $employee->id, 2026, ['SICK_LEAVE' => '']);
    expect(LeaveCredit::query()->count())->toBe(0)->and(LeaveCreditEntry::query()->count())->toBe(1);
    expect(fn () => app(RecordLeaveCredit::class)->handle($actor, $employee->id, 2026, 'SICK_LEAVE', 'OPENING', 2, 'New opening', (string) Str::ulid()))
        ->toThrow(RuntimeException::class, 'already exists');
});

it('refuses unauthorized own-record missing employee invalid year and unknown type writes', function () {
    ledgerWriteType();
    $employee = ledgerWriteEmployee();
    $command = app(RecordLeaveCredit::class);
    foreach ([ledgerWriteActor(allData: false), ledgerWriteActor([ModuleCode::ROSTER]), ledgerWriteActor(employeeId: $employee->id)] as $actor) {
        expect(fn () => $command->handle($actor, $employee->id, 2026, 'SICK_LEAVE', 'ACCRUAL', 1, 'HR entry', (string) Str::ulid()))->toThrow(RuntimeException::class);
    }
    $actor = ledgerWriteActor();
    foreach ([[(string) Str::ulid(), 2026, 'SICK_LEAVE'], [$employee->id, 1999, 'SICK_LEAVE'], [$employee->id, 2101, 'SICK_LEAVE'], [$employee->id, 2026, 'UNKNOWN']] as [$id, $year, $type]) {
        expect(fn () => $command->handle($actor, $id, $year, $type, 'ACCRUAL', 1, 'HR entry', (string) Str::ulid()))->toThrow(RuntimeException::class);
    }
    expect(LeaveCredit::query()->count())->toBe(0)->and(LeaveCreditEntry::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
});

it('requires notes valid kinds positive additions and nonzero half-day adjustments', function () {
    ledgerWriteType();
    $employee = ledgerWriteEmployee();
    $actor = ledgerWriteActor();
    $command = app(RecordLeaveCredit::class);
    foreach ([
        ['BASELINE', 1, 'Not a manual kind'], ['OTHER', 1, 'Not a kind'],
        ['ACCRUAL', 0, 'Zero'], ['OPENING', -1, 'Negative'], ['CARRY_FORWARD', -1, 'Negative'],
        ['ADJUSTMENT', 0, 'Zero'], ['ACCRUAL', 0.25, 'Quarter'], ['ACCRUAL', ['1'], 'Array'],
        ['ACCRUAL', true, 'Boolean'], ['ACCRUAL', '', 'Blank'], ['ACCRUAL', 'NaN', 'Not a number'],
        ['ACCRUAL', 10000, 'Over limit'], ['ACCRUAL', 1, '  '], ['ACCRUAL', 1, str_repeat('n', 501)],
    ] as [$kind, $days, $note]) {
        expect(fn () => $command->handle($actor, $employee->id, 2026, 'SICK_LEAVE', $kind, $days, $note, (string) Str::ulid()))->toThrow(RuntimeException::class);
    }
    expect(fn () => $command->handle($actor, $employee->id, 2026, 'SICK_LEAVE', 'ACCRUAL', 1, 'HR entry', 'not-an-id'))->toThrow(RuntimeException::class);
    expect(LeaveCredit::query()->count())->toBe(0)->and(LeaveCreditEntry::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
});

it('refuses an out-of-range resulting allocation without adding even a lazy baseline', function () {
    ledgerWriteType();
    $employee = ledgerWriteEmployee();
    ledgerWriteCredit($employee, 9999.5);
    $actor = ledgerWriteActor();
    $command = app(RecordLeaveCredit::class);
    expect(fn () => $command->handle($actor, $employee->id, 2026, 'SICK_LEAVE', 'ACCRUAL', 0.5, 'Excess', (string) Str::ulid()))->toThrow(RuntimeException::class, 'outside');
    expect(LeaveCreditEntry::query()->count())->toBe(0);
    LeaveCredit::query()->update(['credits' => 1]);
    expect(fn () => $command->handle($actor, $employee->id, 2026, 'SICK_LEAVE', 'ADJUSTMENT', -1.5, 'Excess reduction', (string) Str::ulid()))->toThrow(RuntimeException::class, 'outside');
    expect(LeaveCredit::query()->sole()->credits)->toBe(1.0)->and(LeaveCreditEntry::query()->count())->toBe(0);
});

it('accepts a held stale token while refusing invented or formerly held tokens after clearing', function () {
    $employee = ledgerWriteEmployee();
    ledgerWriteCredit($employee, 3, 'RETIRED_TYPE');
    $actor = ledgerWriteActor();
    app(RecordLeaveCredit::class)->handle($actor, $employee->id, 2026, 'RETIRED_TYPE', 'ADJUSTMENT', -0.5, 'Correct old allocation', (string) Str::ulid());
    expect(LeaveCredit::query()->sole()->credits)->toBe(2.5);
    app(SaveLeaveCredits::class)->handle($actor, $employee->id, 2026, ['RETIRED_TYPE' => '']);
    expect(fn () => app(RecordLeaveCredit::class)->handle($actor, $employee->id, 2026, 'RETIRED_TYPE', 'ACCRUAL', 1, 'Cannot revive removed type', (string) Str::ulid()))->toThrow(RuntimeException::class, 'no longer exists');
});

it('rolls the aggregate and lazy baseline back if the requested entry cannot be inserted', function () {
    ledgerWriteType();
    $employee = ledgerWriteEmployee();
    $credit = ledgerWriteCredit($employee, 4);
    $actor = ledgerWriteActor();
    // The baseline owns the legacy credit id. A collision on the subsequent
    // insert must undo the whole movement, including the aggregate update.
    expect(fn () => app(RecordLeaveCredit::class)->handle($actor, $employee->id, 2026, 'SICK_LEAVE', 'ACCRUAL', 1, 'Collision test', $credit->id))->toThrow(QueryException::class);
    expect($credit->fresh()->credits)->toBe(4.0)
        ->and(LeaveCreditEntry::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('fails closed for both credit writers when the serialization sentinel is missing', function () {
    ledgerWriteType();
    $employee = ledgerWriteEmployee();
    $actor = ledgerWriteActor();
    DB::table('locks')->where('name', 'copems:leave-credits')->delete();
    expect(fn () => app(RecordLeaveCredit::class)->handle($actor, $employee->id, 2026, 'SICK_LEAVE', 'ACCRUAL', 1, 'HR entry', (string) Str::ulid()))->toThrow(RuntimeException::class, 'not ready');
    expect(fn () => app(SaveLeaveCredits::class)->handle($actor, $employee->id, 2026, ['SICK_LEAVE' => 1]))->toThrow(RuntimeException::class, 'not ready');
    expect(LeaveCredit::query()->count())->toBe(0)->and(LeaveCreditEntry::query()->count())->toBe(0);
});

it('rolls both writers back when a model event refuses the aggregate save without throwing', function () {
    ledgerWriteType();
    $employee = ledgerWriteEmployee();
    $actor = ledgerWriteActor();
    // Boot both models before cloning the dispatcher, preserving their
    // normal ULID listeners when the temporary event veto is removed.
    new LeaveCredit;
    new LeaveCreditEntry;
    $original = LeaveCredit::getEventDispatcher();
    if ($original === null) {
        throw new RuntimeException('The test requires Eloquent events.');
    }
    LeaveCredit::setEventDispatcher(clone $original);
    try {
        LeaveCredit::saving(static fn (): bool => false);
        expect(fn () => app(SaveLeaveCredits::class)->handle($actor, $employee->id, 2026, ['SICK_LEAVE' => 5]))->toThrow(RuntimeException::class, 'could not be saved');
        expect(fn () => app(RecordLeaveCredit::class)->handle($actor, $employee->id, 2026, 'SICK_LEAVE', 'ACCRUAL', 1, 'Vetoed save', (string) Str::ulid()))->toThrow(RuntimeException::class, 'could not be saved');
    } finally {
        LeaveCredit::setEventDispatcher($original);
    }
    expect(LeaveCredit::query()->count())->toBe(0)->and(LeaveCreditEntry::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
});

it('rolls an updated allocation back when a ledger event refuses its entry without throwing', function () {
    ledgerWriteType();
    $employee = ledgerWriteEmployee();
    $actor = ledgerWriteActor();
    app(SaveLeaveCredits::class)->handle($actor, $employee->id, 2026, ['SICK_LEAVE' => 5]);
    $original = LeaveCreditEntry::getEventDispatcher();
    if ($original === null) {
        throw new RuntimeException('The test requires Eloquent events.');
    }
    LeaveCreditEntry::setEventDispatcher(clone $original);
    try {
        LeaveCreditEntry::saving(static fn (): bool => false);
        expect(fn () => app(RecordLeaveCredit::class)->handle($actor, $employee->id, 2026, 'SICK_LEAVE', 'ACCRUAL', 1, 'Vetoed movement', (string) Str::ulid()))->toThrow(RuntimeException::class, 'could not be saved');
    } finally {
        LeaveCreditEntry::setEventDispatcher($original);
    }
    expect(LeaveCredit::query()->sole()->credits)->toBe(5.0)
        ->and(LeaveCreditEntry::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(1);
});

it('rolls clearing adjustments back when an aggregate deletion is vetoed', function () {
    ledgerWriteType();
    $employee = ledgerWriteEmployee();
    $actor = ledgerWriteActor();
    app(SaveLeaveCredits::class)->handle($actor, $employee->id, 2026, ['SICK_LEAVE' => 5]);
    $original = LeaveCredit::getEventDispatcher();
    if ($original === null) {
        throw new RuntimeException('The test requires Eloquent events.');
    }
    LeaveCredit::setEventDispatcher(clone $original);
    try {
        LeaveCredit::deleting(static fn (): bool => false);
        expect(fn () => app(SaveLeaveCredits::class)->handle($actor, $employee->id, 2026, ['SICK_LEAVE' => '']))->toThrow(RuntimeException::class, 'could not be cleared');
    } finally {
        LeaveCredit::setEventDispatcher($original);
    }
    expect(LeaveCredit::query()->sole()->credits)->toBe(5.0)
        ->and(LeaveCreditEntry::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(1);
});
