<?php

declare(strict_types=1);

use App\Models\EmployeeChild;
use App\Models\EmployeeProfile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The 201 sheet's migrations (2026_10_01_0001, the fourteen columns on
 * `employee_profiles`; 2026_10_01_0002, the `employee_children` table)
 * survive a repeated up() or down() — the cPanel cron re-running a
 * half-applied deploy — and a run killed between their ALTERs resumes with
 * only the pieces still missing. A rollback drops every column and the
 * table, and a re-run brings them back empty: ROLLBACK LOSES what was
 * entered (both docblocks say so; this pins it).
 *
 * THIS FILE ALWAYS ENDS MIGRATED, pass or fail: each `finally` re-runs up().
 * The seeded employee has no child rows across a column drop, because on
 * sqlite a column drop may rebuild the table and, inside RefreshDatabase's
 * transaction, that DROP TABLE cascades into children.
 */

/** @var list<string> */
const E201MIG_COLUMNS = [
    'telephone', 'religion', 'height_cm', 'weight_kg', 'blood_type', 'spouse_name', 'spouse_employment',
    'college', 'college_course', 'college_year_graduated', 'graduate_school', 'graduate_course', 'graduate_year_graduated',
    'bank_name',
];

/** The columns migration — an anonymous class, so untyped here. */
function e201migColumns(): mixed
{
    return require base_path('database/migrations/2026_10_01_0001_add_201_file_fields_to_employee_profiles_table.php');
}

/** The children table migration. */
function e201migChildren(): mixed
{
    return require base_path('database/migrations/2026_10_01_0002_create_employee_children_table.php');
}

function e201migEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'E201-1',
        'first_name' => 'Rosa',
        'last_name' => 'Villanueva',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-15',
        'monthly_rate' => 30000,
        'telephone' => '(042) 911 5690',
        'height_cm' => 162.5,
        'blood_type' => 'O+',
        'bank_name' => 'GoTyme Bank',
    ])->save();

    return $employee;
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function e201migDdlDuring(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $callback();
    } finally {
        DB::disableQueryLog();
    }

    return array_values(array_filter(
        array_column(DB::getQueryLog(), 'query'),
        static fn (string $sql): bool => preg_match('/^\s*(create|alter|drop)\b/i', $sql) === 1,
    ));
}

function e201migHas(string $column): bool
{
    return Schema::hasColumn('employee_profiles', $column);
}

it('adds the fourteen columns and the children table once, and a repeated run sends no DDL but the row-format restatement', function () {
    $columns = e201migColumns();
    $children = e201migChildren();

    try {
        // migrate:fresh already ran both through the migrator.
        foreach (E201MIG_COLUMNS as $column) {
            expect(e201migHas($column))->toBeTrue();
        }
        expect(Schema::hasTable('employee_children'))->toBeTrue()
            ->and(Schema::hasIndex('employee_children', 'echild_employee_position_unq'))->toBeTrue();

        // sqlite: nothing at all. MySQL/MariaDB: only the children table's
        // unguarded, idempotent ROW_FORMAT restatement (its docblock says
        // it needs no guard); every column ADD, the index and the foreign
        // key stay behind their own checks.
        $allowed = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? ['ALTER TABLE `employee_children` ROW_FORMAT=DYNAMIC']
            : [];
        expect(e201migDdlDuring(static function () use ($columns, $children): void {
            $columns->up();
            $children->up();
        }))->toBe($allowed);
    } finally {
        $columns->up();
        $children->up();
    }
});

it('drops every column and the table on rollback, tolerates a second one, and loses what was entered', function () {
    $columns = e201migColumns();
    $children = e201migChildren();
    $employee = e201migEmployee();

    $child = new EmployeeChild;
    $child->fill(['employee_id' => $employee->id, 'position' => 0, 'name' => 'Ana', 'birth_date' => '2019-05-04'])->save();

    try {
        $children->down();
        $columns->down();
        foreach (E201MIG_COLUMNS as $column) {
            expect(e201migHas($column))->toBeFalse();
        }
        expect(Schema::hasTable('employee_children'))->toBeFalse();

        // A rollback killed after its drops and re-run finds nothing to drop.
        expect(e201migDdlDuring(static function () use ($columns, $children): void {
            $children->down();
            $columns->down();
        }))->toBe([]);

        $columns->up();
        $children->up();

        // Back as NULL and empty: ROLLBACK LOSES the entered values, and the
        // rest of the employee is untouched.
        $row = DB::table('employee_profiles')->where('id', $employee->id)->first();
        expect($row?->telephone)->toBeNull()
            ->and($row?->height_cm)->toBeNull()
            ->and($row?->blood_type)->toBeNull()
            ->and($row?->bank_name)->toBeNull()
            ->and($row?->last_name)->toBe('Villanueva')
            ->and((float) $row?->monthly_rate)->toBe(30000.0)
            ->and(EmployeeChild::query()->count())->toBe(0);
    } finally {
        $columns->up();
        $children->up();
    }
});

it('resumes a run killed between its ALTERs with only the pieces still missing', function () {
    $columns = e201migColumns();
    $children = e201migChildren();

    try {
        $children->down();
        $columns->down();

        // Killed after the first ADD: only telephone exists, and the
        // children table was created without its unique or foreign key.
        Schema::table('employee_profiles', function (Blueprint $t): void {
            $t->string('telephone', 64)->nullable();
        });
        Schema::create('employee_children', function (Blueprint $t): void {
            $t->char('id', 26)->primary();
            $t->char('employee_id', 26);
            $t->unsignedTinyInteger('position');
            $t->string('name', 120);
            $t->date('birth_date')->nullable();
            $t->dateTime('created_at', 3);
        });

        $ddl = e201migDdlDuring(static fn () => $columns->up());

        expect($ddl)->toHaveCount(count(E201MIG_COLUMNS) - 1);
        foreach ($ddl as $statement) {
            expect($statement)->not->toContain('telephone');
        }
        foreach (E201MIG_COLUMNS as $column) {
            expect(e201migHas($column))->toBeTrue();
        }

        $ddl = e201migDdlDuring(static fn () => $children->up());

        expect($ddl)->not->toBe([]);
        foreach ($ddl as $statement) {
            expect(strtolower($statement))->not->toStartWith('create table "employee_children"');
        }
        expect(Schema::hasIndex('employee_children', 'echild_employee_position_unq'))->toBeTrue()
            ->and(Schema::hasForeignKey('employee_children', ['employee_id']))->toBeTrue();
    } finally {
        $columns->up();
        $children->up();
    }
});
