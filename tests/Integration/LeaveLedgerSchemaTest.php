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
use App\Modules\Timekeeping\Application\LeaveCreditWriter;
use App\Platform\Database\TransactionMutex;
use App\Support\Access;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** MariaDB enforces ledger shape, retry guards, rollback and the first-allocation writer mutex. */
function ledgerSchemaEmployee(): EmployeeProfile
{
    $row = new EmployeeProfile;
    $row->forceFill(['employee_code' => 'LGS-'.Str::random(10), 'first_name' => 'Ledger', 'last_name' => 'Schema', 'date_hired' => '2024-01-01', 'is_active' => true])->save();

    return $row;
}

function ledgerSchemaActor(): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Ledger schema '.Str::random(10), 'all_data' => true, 'is_system' => false])->save();
    (new RoleModuleGrant)->forceFill(['role_id' => $role->id, 'module' => ModuleCode::LEAVES])->save();
    (new LeaveType)->forceFill(['code' => 'LGS-SICK', 'name' => 'Sick Leave'])->save();
    $user = User::factory()->create(['role_id' => $role->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

/** @param array<string, mixed> $overrides */
function ledgerSchemaRow(EmployeeProfile $employee, array $overrides = []): string
{
    $id = (string) Str::ulid();
    DB::table('leave_credit_entries')->insert(array_replace([
        'id' => $id, 'employee_id' => $employee->id, 'year' => 2026,
        'request_type' => 'SICK_LEAVE', 'kind' => 'ACCRUAL', 'days' => 0.5,
        'note' => 'Schema fixture', 'source_year' => null, 'recorded_by' => null,
        'created_at' => '2026-10-02 00:00:00.123', 'updated_at' => null,
    ], $overrides));

    return $id;
}

function ledgerSchemaHasCheck(string $name): bool
{
    $result = DB::selectOne(
        "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_credit_entries' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
        [$name],
    );

    return (int) ($result->n ?? 0) === 1;
}

it('resumes every ledger constraint and index step then runs a second time without duplicate schema', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = require base_path('database/migrations/2026_10_02_0005_create_leave_credit_entries_table.php');
    try {
        foreach (['lce_year_chk', 'lce_kind_chk', 'lce_days_chk', 'lce_source_year_chk'] as $check) {
            expect(ledgerSchemaHasCheck($check))->toBeTrue();
            DB::statement("ALTER TABLE `leave_credit_entries` DROP CONSTRAINT `{$check}`");
            $migration->up();
            $migration->up();
            expect(ledgerSchemaHasCheck($check))->toBeTrue();
        }
        DB::statement('ALTER TABLE `leave_credit_entries` DROP FOREIGN KEY `lce_employee_fk`');
        DB::statement('ALTER TABLE `leave_credit_entries` DROP INDEX `lce_employee_year_type_idx`');
        DB::statement('ALTER TABLE `leave_credit_entries` DROP INDEX `lce_recorded_by_idx`');
        DB::table('locks')->where('name', LeaveCreditWriter::LOCK)->delete();
        $migration->up();
        $migration->up();
        expect(Schema::hasForeignKey('leave_credit_entries', ['employee_id']))->toBeTrue()
            ->and(Schema::hasIndex('leave_credit_entries', 'lce_employee_year_type_idx'))->toBeTrue()
            ->and(Schema::hasIndex('leave_credit_entries', 'lce_recorded_by_idx'))->toBeTrue()
            ->and(DB::table('locks')->where('name', LeaveCreditWriter::LOCK)->count())->toBe(1);
        $indexes = array_column(Schema::getIndexes('leave_credit_entries'), 'name');
        sort($indexes);
        expect($indexes)->toBe(['lce_employee_year_type_idx', 'lce_recorded_by_idx', 'primary']);
    } finally {
        $migration->up();
    }
});

it('enforces movement kind amount year and carry-forward source on MariaDB', function () {
    $employee = ledgerSchemaEmployee();
    foreach ([
        [['year' => 1999], 'lce_year_chk'],
        [['year' => 2101], 'lce_year_chk'],
        [['kind' => 'UNKNOWN'], 'lce_kind_chk'],
        [['kind' => 'OPENING', 'days' => -0.5], 'lce_days_chk'],
        [['kind' => 'ACCRUAL', 'days' => 0], 'lce_days_chk'],
        [['kind' => 'ADJUSTMENT', 'days' => 0], 'lce_days_chk'],
        [['kind' => 'CARRY_FORWARD', 'source_year' => null], 'lce_source_year_chk'],
        [['kind' => 'CARRY_FORWARD', 'source_year' => 2024], 'lce_source_year_chk'],
        [['kind' => 'ACCRUAL', 'source_year' => 2025], 'lce_source_year_chk'],
    ] as [$attributes, $constraint]) {
        expect(fn () => ledgerSchemaRow($employee, $attributes))->toThrow(QueryException::class, $constraint);
    }
    expect(LeaveCreditEntry::query()->count())->toBe(0);
    ledgerSchemaRow($employee, ['kind' => 'BASELINE', 'days' => 7.25]);
    ledgerSchemaRow($employee, ['kind' => 'OPENING', 'days' => 0]);
    ledgerSchemaRow($employee, ['kind' => 'ADJUSTMENT', 'days' => -0.5]);
    ledgerSchemaRow($employee, ['kind' => 'CARRY_FORWARD', 'source_year' => 2025]);
    expect(LeaveCreditEntry::query()->count())->toBe(4);
});

it('preserves ledger rows across allocation deletion and cascades only with their employee', function () {
    $employee = ledgerSchemaEmployee();
    $credit = new LeaveCredit;
    $credit->forceFill(['employee_id' => $employee->id, 'year' => 2026, 'request_type' => 'SICK_LEAVE', 'credits' => 5])->save();
    $entryId = ledgerSchemaRow($employee, ['kind' => 'BASELINE', 'days' => 5]);
    $credit->delete();
    expect(LeaveCreditEntry::query()->whereKey($entryId)->exists())->toBeTrue();
    $employee->delete();
    expect(LeaveCreditEntry::query()->whereKey($entryId)->exists())->toBeFalse();
});

it('rolls a manual movement and its audit back with the real outer transaction', function () {
    $employee = ledgerSchemaEmployee();
    $actor = ledgerSchemaActor();
    DB::beginTransaction();
    try {
        app(RecordLeaveCredit::class)->handle($actor, $employee->id, 2026, 'SICK_LEAVE', 'ACCRUAL', 2.5, 'Rolled back entry', (string) Str::ulid());
        expect(LeaveCredit::query()->sole()->credits)->toBe(2.5)
            ->and(LeaveCreditEntry::query()->count())->toBe(1)
            ->and(AuditLog::query()->count())->toBe(0);
    } finally {
        DB::rollBack();
    }
    expect(LeaveCredit::query()->count())->toBe(0)
        ->and(LeaveCreditEntry::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
    app(RecordLeaveCredit::class)->handle($actor, $employee->id, 2026, 'SICK_LEAVE', 'ACCRUAL', 1, 'Committed entry', (string) Str::ulid());
    expect(LeaveCredit::query()->sole()->credits)->toBe(1.0)
        ->and(AuditLog::query()->where('action', 'LEAVE_CREDITS_SET')->count())->toBe(1);
});

it('blocks both first-allocation writers on the same independent-connection sentinel and releases it on rollback', function () {
    $employee = ledgerSchemaEmployee();
    $actor = ledgerSchemaActor();
    $default = DB::getDefaultConnection();
    $holder = DB::connection();
    config(['database.connections.leave_ledger_contender' => $holder->getConfig()]);
    DB::purge('leave_ledger_contender');
    $contender = DB::connection('leave_ledger_contender');
    $contender->statement('SET SESSION innodb_lock_wait_timeout = 1');
    expect(fn () => TransactionMutex::acquire(LeaveCreditWriter::LOCK))->toThrow(LogicException::class, 'active database transaction');
    $holder->beginTransaction();
    try {
        LeaveCreditWriter::lock();
        DB::setDefaultConnection('leave_ledger_contender');
        expect(fn () => app(RecordLeaveCredit::class)->handle($actor, $employee->id, 2026, 'SICK_LEAVE', 'ACCRUAL', 1, 'Contending entry', (string) Str::ulid()))
            ->toThrow(QueryException::class, 'Lock wait timeout');
        expect(fn () => app(SaveLeaveCredits::class)->handle($actor, $employee->id, 2026, ['SICK_LEAVE' => 5]))
            ->toThrow(QueryException::class, 'Lock wait timeout');
        expect($contender->table('leave_credits')->count())->toBe(0)
            ->and($contender->table('leave_credit_entries')->count())->toBe(0);
    } finally {
        DB::setDefaultConnection($default);
        $holder->rollBack();
        DB::purge('leave_ledger_contender');
    }
    app(SaveLeaveCredits::class)->handle($actor, $employee->id, 2026, ['SICK_LEAVE' => 5]);
    app(RecordLeaveCredit::class)->handle($actor, $employee->id, 2026, 'SICK_LEAVE', 'ACCRUAL', 1, 'After lock release', (string) Str::ulid());
    expect(LeaveCredit::query()->sole()->credits)->toBe(6.0)
        ->and((float) LeaveCreditEntry::query()->sum('days'))->toBe(6.0);
});

it('rolls aggregate writes back when MariaDB refuses the ledger insert', function () {
    $employee = ledgerSchemaEmployee();
    $actor = ledgerSchemaActor();
    app(SaveLeaveCredits::class)->handle($actor, $employee->id, 2026, ['SICK_LEAVE' => 5]);
    DB::statement("CREATE TRIGGER lce_test_refuse_insert BEFORE INSERT ON leave_credit_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger insert unavailable'");
    try {
        expect(fn () => app(RecordLeaveCredit::class)->handle($actor, $employee->id, 2026, 'SICK_LEAVE', 'ACCRUAL', 1, 'Refused by database', (string) Str::ulid()))->toThrow(QueryException::class, 'Ledger insert unavailable');
        expect(fn () => app(SaveLeaveCredits::class)->handle($actor, $employee->id, 2026, ['SICK_LEAVE' => 7]))->toThrow(QueryException::class, 'Ledger insert unavailable');
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS lce_test_refuse_insert');
    }
    expect(LeaveCredit::query()->sole()->credits)->toBe(5.0)
        ->and(LeaveCreditEntry::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'LEAVE_CREDITS_SET')->count())->toBe(1);
});
