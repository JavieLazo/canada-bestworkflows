<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The leave form's settings on MariaDB (2026_11_11_0001 to 0003 and 0005):
 * the officers table is created once, resumes from a partial run, keeps one
 * row per slot and pins its slots; the head of office and the approval note
 * are added once and roll back; a slot's employee is added step by step, and
 * deleting the employee empties the slot without removing it.
 */
function lfschemaMigration(string $file): mixed
{
    return require base_path("database/migrations/{$file}");
}

/** Whether the named CHECK exists. */
function lfschemaHasCheck(string $table, string $name): bool
{
    return (int) (DB::selectOne(
        "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
        [$table, $name],
    )->n ?? 0) > 0;
}

/** @return array<string, mixed> */
function lfschemaRow(string $slot): array
{
    return ['id' => (string) Str::ulid(), 'slot' => $slot, 'name' => 'Ana', 'created_at' => now()->format('Y-m-d H:i:s.v')];
}

it('creates the officers table once, resumes a partial run and enforces its slots', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $employeeColumn = lfschemaMigration('2026_11_11_0005_add_employee_to_leave_signatories_table.php');
    $migration = lfschemaMigration('2026_11_11_0001_create_leave_signatories_table.php');
    $employeeColumn->down();
    $migration->down();
    expect(Schema::hasTable('leave_signatories'))->toBeFalse();

    // A cron killed after the create, before the index, the key and the CHECK.
    Schema::create('leave_signatories', function ($t): void {
        $t->engine = 'InnoDB';
        $t->char('id', 26)->primary();
        $t->string('slot', 20);
        $t->string('name', 200)->nullable();
        $t->string('designation', 200)->nullable();
        $t->unsignedBigInteger('updated_by')->nullable();
        $t->string('updated_by_name', 200)->nullable();
        $t->dateTime('created_at', 3);
        $t->dateTime('updated_at', 3)->nullable();
    });
    $migration->up();
    $migration->up();

    expect(Schema::hasIndex('leave_signatories', 'lsig_slot_unq'))->toBeTrue()
        ->and(Schema::hasForeignKey('leave_signatories', ['updated_by']))->toBeTrue()
        ->and(lfschemaHasCheck('leave_signatories', 'lsig_slot_chk'))->toBeTrue();

    DB::table('leave_signatories')->insert(lfschemaRow('CERTIFIED'));
    expect(fn () => DB::table('leave_signatories')->insert(lfschemaRow('CERTIFIED')))->toThrow(QueryException::class)
        ->and(fn () => DB::table('leave_signatories')->insert(lfschemaRow('MAYOR')))->toThrow(QueryException::class);
    DB::table('leave_signatories')->delete();

    $migration->down();
    expect(Schema::hasTable('leave_signatories'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('leave_signatories'))->toBeTrue();
    $employeeColumn->up();
});

it('adds a slot\'s employee step by step, resumes, empties on delete and rolls back', function () {
    $migration = lfschemaMigration('2026_11_11_0005_add_employee_to_leave_signatories_table.php');
    $migration->down();
    expect(Schema::hasColumn('leave_signatories', 'employee_id'))->toBeFalse();

    // A cron killed after the column, before the index and the key.
    Schema::table('leave_signatories', static fn ($t) => $t->char('employee_id', 26)->nullable());
    $migration->up();
    $migration->up();
    expect(Schema::hasIndex('leave_signatories', 'lsig_employee_idx'))->toBeTrue()
        ->and(Schema::hasForeignKey('leave_signatories', ['employee_id']))->toBeTrue();

    $employee = (string) Str::ulid();
    DB::table('employee_profiles')->insert([
        'id' => $employee, 'employee_code' => 'LSIG-1', 'first_name' => 'Ana', 'last_name' => 'Reyes', 'position' => 'HR Head',
        'monthly_rate' => 0, 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
        'created_at' => now()->format('Y-m-d H:i:s.v'),
    ]);
    DB::table('leave_signatories')->insert(['employee_id' => $employee] + lfschemaRow('APPROVED'));
    expect(fn () => DB::table('leave_signatories')->where('slot', 'APPROVED')->update(['employee_id' => (string) Str::ulid()]))->toThrow(QueryException::class);

    DB::table('employee_profiles')->where('id', $employee)->delete();
    expect(DB::table('leave_signatories')->where('slot', 'APPROVED')->value('employee_id'))->toBeNull();
    DB::table('leave_signatories')->delete();

    $migration->down();
    expect(Schema::hasColumn('leave_signatories', 'employee_id'))->toBeFalse();
    $migration->up();
    expect(Schema::hasColumn('leave_signatories', 'employee_id'))->toBeTrue();
});

it('adds the approved form once and rolls back', function () {
    $migration = lfschemaMigration('2026_11_11_0006_add_approved_form_to_leave_travel_requests_table.php');
    $migration->up();
    $migration->up();
    expect(Schema::hasColumn('leave_travel_requests', 'approved_form'))->toBeTrue();

    $migration->down();
    expect(Schema::hasColumn('leave_travel_requests', 'approved_form'))->toBeFalse();
    $migration->up();
    expect(Schema::hasColumn('leave_travel_requests', 'approved_form'))->toBeTrue();
});

it('adds the head of office column by column, resumes and rolls back', function () {
    $migration = lfschemaMigration('2026_11_11_0002_add_head_of_office_to_org_nodes_table.php');
    $migration->down();
    expect(Schema::hasColumn('org_nodes', 'head_name'))->toBeFalse();

    // A cron killed between the two columns.
    Schema::table('org_nodes', static fn ($t) => $t->string('head_name', 200)->nullable());
    $migration->up();
    $migration->up();
    expect(Schema::hasColumns('org_nodes', ['head_name', 'head_designation']))->toBeTrue();

    $migration->down();
    expect(Schema::hasColumn('org_nodes', 'head_designation'))->toBeFalse();
    $migration->up();
    expect(Schema::hasColumns('org_nodes', ['head_name', 'head_designation']))->toBeTrue();
});

it('adds the approval note once and rolls back', function () {
    $migration = lfschemaMigration('2026_11_11_0003_add_approval_note_to_leave_travel_requests_table.php');
    $migration->up();
    $migration->up();
    expect(Schema::hasColumn('leave_travel_requests', 'approval_note'))->toBeTrue();

    $migration->down();
    expect(Schema::hasColumn('leave_travel_requests', 'approval_note'))->toBeFalse();
    $migration->up();
    expect(Schema::hasColumn('leave_travel_requests', 'approval_note'))->toBeTrue();
});
