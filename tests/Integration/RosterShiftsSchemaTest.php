<?php

declare(strict_types=1);

use App\Domain\Attendance\Shift as DomainShift;
use App\Models\EmployeeProfile;
use App\Models\RosterShift;
use App\Models\ShiftType;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** MariaDB verifies retry recovery, real CHECKs and snapshot-preserving foreign keys. */
function rshiftschemaEmployee(): EmployeeProfile
{
    return EmployeeProfile::query()->create(['employee_code' => 'RS-SCHEMA-'.Str::random(7), 'first_name' => 'Roster', 'last_name' => 'Schema', 'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true]);
}

function rshiftschemaPattern(): ShiftType
{
    return ShiftType::query()->create(['code' => Str::random(8), 'name' => 'Schema '.Str::random(7), 'mode' => 'FIXED', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'work_days' => [1], 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false]);
}

function rshiftschemaRow(EmployeeProfile $employee, int $weekday, ?ShiftType $pattern = null, bool $rest = false): RosterShift
{
    return RosterShift::query()->create(['employee_id' => $employee->id, 'weekday' => $weekday, 'shift_type_id' => $pattern?->id, 'shift_name' => $pattern->name ?? 'Rest day', 'is_rest_day' => $rest, 'shift_data' => ['start_time' => '22:00:00', 'end_time' => '06:00:00', 'work_days' => [$weekday]]]);
}

function rshiftschemaAssert(): void
{
    expect(Schema::hasIndex('roster_shifts', 'rshift_employee_weekday_unq', 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('roster_shifts', 'rshift_type_idx'))->toBeTrue()
        ->and(Schema::hasForeignKey('roster_shifts', ['employee_id']))->toBeTrue()
        ->and(Schema::hasForeignKey('roster_shifts', ['shift_type_id']))->toBeTrue();
    foreach (['rshift_weekday_chk', 'rshift_rest_chk'] as $name) {
        $row = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'roster_shifts' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'", [$name]);
        expect((int) ($row->n ?? 0))->toBe(1);
    }
}

it('retries complete and interrupted weekday shift creation without duplicate schema pieces', function () {
    $migration = require base_path('database/migrations/2026_09_28_0001_create_roster_shifts_table.php');
    // 2026_09_30_0005 later drops the unique this one adds: roll it back
    // first and re-apply it last, the order production runs them.
    $versioning = require base_path('database/migrations/2026_09_30_0005_add_effective_from_to_roster_shifts_table.php');
    try {
        $versioning->down();
        $migration->up();
        $migration->up();
        rshiftschemaAssert();
        $migration->down();
        $migration->down();
        // Simulate a cron dying immediately after CREATE, before all ALTERs.
        Schema::create('roster_shifts', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->char('id', 26)->primary();
            $t->char('employee_id', 26);
            $t->unsignedTinyInteger('weekday');
            $t->char('shift_type_id', 26)->nullable();
            $t->string('shift_name', 160);
            $t->boolean('is_rest_day')->default(false);
            $t->json('shift_data');
            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3)->nullable();
        });
        $migration->up();
        $migration->up();
        rshiftschemaAssert();
        // A second interrupted position has its first CHECK but not its last.
        DB::statement('ALTER TABLE roster_shifts DROP CONSTRAINT rshift_rest_chk');
        $migration->up();
        rshiftschemaAssert();
    } finally {
        $migration->up();
        $versioning->up();
    }
});

it('enforces one valid weekday and requires a real rest payload without a snapshot pattern link', function () {
    $employee = rshiftschemaEmployee();
    $pattern = rshiftschemaPattern();
    rshiftschemaRow($employee, 1, $pattern);
    // A later row for the same weekday is its history (2026_09_30_0005).
    rshiftschemaRow($employee, 1, $pattern);
    expect(fn () => rshiftschemaRow($employee, 7, $pattern))->toThrow(QueryException::class);
    expect(fn () => rshiftschemaRow($employee, 2, $pattern, true))->toThrow(QueryException::class);
    $rest = ['employee_id' => $employee->id, 'weekday' => 2, 'shift_name' => 'Rest', 'is_rest_day' => true];
    foreach ([[], ['work_days' => [-1]], ['work_days' => [2], 'shift_type_id' => null], ['work_days' => [-1], 'shift_type_id' => $pattern->id]] as $invalid) {
        expect(fn () => RosterShift::query()->create($rest + ['shift_data' => $invalid]))->toThrow(QueryException::class, 'rshift_rest_chk');
    }
    expect(RosterShift::query()->count())->toBe(2);
    RosterShift::query()->create($rest + ['shift_data' => DomainShift::restDay()->toArray()]);
    expect(RosterShift::query()->count())->toBe(3);

    // A weekday back on the default shift: no hours, no name, not a rest day.
    $default = ['employee_id' => $employee->id, 'weekday' => 4, 'shift_type_id' => null, 'is_rest_day' => false, 'shift_data' => null];
    expect(fn () => RosterShift::query()->create($default + ['shift_name' => 'Named']))->toThrow(QueryException::class, 'rshift_default_chk');
    expect(fn () => RosterShift::query()->create(array_replace($default, ['shift_name' => null, 'is_rest_day' => true])))->toThrow(QueryException::class);
    expect(fn () => RosterShift::query()->create(array_replace($default, ['shift_name' => 'Regular', 'shift_data' => null])))->toThrow(QueryException::class, 'rshift_default_chk');
    RosterShift::query()->create($default + ['shift_name' => null]);
    expect(RosterShift::query()->count())->toBe(4);
});

it('retains snapshot bytes when a pattern is deleted and cascades only on employee deletion', function () {
    $employee = rshiftschemaEmployee();
    $pattern = rshiftschemaPattern();
    $row = rshiftschemaRow($employee, 1, $pattern);
    $before = $row->shift_data;
    $name = $row->shift_name;
    $pattern->delete();
    expect($row->fresh()?->shift_type_id)->toBeNull()
        ->and($row->fresh()?->shift_name)->toBe($name)
        ->and($row->fresh()?->shift_data)->toBe($before);
    $employee->delete();
    expect(RosterShift::query()->count())->toBe(0);
});
