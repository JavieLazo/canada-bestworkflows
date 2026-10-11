<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * On the production engine, the leave-credits table (2026_09_26_0003
 * leave_credits) is retry-safe step by step, its two CHECK constraints exist
 * exactly once and refuse bad values, its unique holds one row per
 * (employee, year, type), a full rollback removes every piece and a re-run
 * restores it, and the employee foreign key cascades — an entitlement is
 * configuration, not history, like a weekly roster.
 *
 * sqlite never creates a CHECK, so only this suite sees the real ALTERs.
 * DatabaseMigrations rolls the whole schema back after each test, so EVERY
 * TEST ENDS MIGRATED: the rollback then runs down() against the state it
 * expects.
 */

/** The migration — an anonymous class, so untyped here. */
function lctschemaMigration(): mixed
{
    return require base_path('database/migrations/2026_09_26_0003_create_leave_credits_table.php');
}

/** How many CHECK constraints of this name the table carries. */
function lctschemaChecks(string $table, string $name): int
{
    $row = DB::selectOne(
        "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
        [$table, $name],
    );

    return is_object($row) && isset($row->n) ? (int) $row->n : 0;
}

/**
 * The table's index names, sorted — lower-cased by the schema builder.
 *
 * @return list<string>
 */
function lctschemaIndexNames(string $table): array
{
    $names = array_map(static fn (array $index): string => $index['name'], Schema::getIndexes($table));
    sort($names);

    return $names;
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function lctschemaDdlDuring(callable $callback): array
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

/** Every piece the migration adds, present or absent together. */
function lctschemaAssertApplied(bool $applied): void
{
    expect(Schema::hasTable('leave_credits'))->toBe($applied);
    foreach (['lcr_credits_chk', 'lcr_year_chk'] as $check) {
        expect(lctschemaChecks('leave_credits', $check))->toBe($applied ? 1 : 0);
    }

    if (! $applied) {
        return;
    }

    expect(Schema::hasIndex('leave_credits', 'lcr_employee_year_type_unq', 'unique'))->toBeTrue();
    expect(Schema::hasForeignKey('leave_credits', ['employee_id']))->toBeTrue();
}

/** The one index the migration declares by name, the primary key, and nothing MariaDB added for the foreign key. */
function lctschemaAssertDeclaredIndexes(): void
{
    expect(lctschemaIndexNames('leave_credits'))->toBe(['lcr_employee_year_type_unq', 'primary']);
}

function lctschemaEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'LCS-'.Str::upper(Str::random(5)),
        'first_name' => 'Credit',
        'last_name' => 'Schema '.Str::random(4),
        'position' => 'Field Engineer',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

/**
 * A credit row straight into the table, so no model cast or validation hides
 * a bad value. `$credits` is passed as the literal the SQL sees.
 */
function lctschemaRow(EmployeeProfile $employee, int $year, string $requestType, string $credits): string
{
    $id = (string) Str::ulid();
    DB::table('leave_credits')->insert([
        'id' => $id,
        'employee_id' => (string) $employee->getKey(),
        'year' => $year,
        'request_type' => $requestType,
        'credits' => $credits,
        'created_at' => '2026-09-26 01:00:00.000',
        'updated_at' => null,
    ]);

    return $id;
}

it('applies the migration twice on top of a migrated schema, with each CHECK exactly once', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    // migrate:fresh has already run it through the migrator.
    lctschemaAssertApplied(true);
    lctschemaAssertDeclaredIndexes();
    $migration = lctschemaMigration();

    // The migration restates ROW_FORMAT on every run — the one unguarded,
    // idempotent statement — and nothing else: neither CHECK is added twice.
    $restated = 'ALTER TABLE `leave_credits` ROW_FORMAT=DYNAMIC';
    expect(lctschemaDdlDuring(static function () use ($migration): void {
        $migration->up();
        $migration->up();
    }))->toBe([$restated, $restated]);

    lctschemaAssertApplied(true);
    lctschemaAssertDeclaredIndexes();
});

it('refuses credits or a year outside the constraints and a second row for one employee, year and type', function () {
    $employee = lctschemaEmployee();

    // Below zero: the CHECK is what says no. Above DECIMAL(6,2)'s ceiling,
    // STRICT_TRANS_TABLES refuses the value as out of range for the column
    // (error 1264) before the CHECK is ever evaluated, so only the refusal
    // itself is pinned there.
    expect(fn () => lctschemaRow($employee, 2026, 'VACATION_LEAVE', '-1'))->toThrow(QueryException::class, 'lcr_credits_chk');
    expect(fn () => lctschemaRow($employee, 2026, 'VACATION_LEAVE', '10000'))->toThrow(QueryException::class);
    expect(fn () => lctschemaRow($employee, 1999, 'VACATION_LEAVE', '15'))->toThrow(QueryException::class, 'lcr_year_chk');
    expect(fn () => lctschemaRow($employee, 2101, 'VACATION_LEAVE', '15'))->toThrow(QueryException::class, 'lcr_year_chk');
    expect(DB::table('leave_credits')->count())->toBe(0);

    // The edges land: an explicit zero, the ceiling, and both bounding years.
    $zero = lctschemaRow($employee, 2026, 'VACATION_LEAVE', '0');
    $ceiling = lctschemaRow($employee, 2026, 'SICK_LEAVE', '9999.99');
    $first = lctschemaRow($employee, 2000, 'VACATION_LEAVE', '15');
    $last = lctschemaRow($employee, 2100, 'VACATION_LEAVE', '7.5');
    expect(DB::table('leave_credits')->whereIn('id', [$zero, $ceiling, $first, $last])->count())->toBe(4)
        ->and((string) DB::table('leave_credits')->where('id', $zero)->value('credits'))->toBe('0.00')
        ->and((string) DB::table('leave_credits')->where('id', $ceiling)->value('credits'))->toBe('9999.99')
        ->and((string) DB::table('leave_credits')->where('id', $last)->value('credits'))->toBe('7.50');

    // One row per (employee, year, type): the same type in the same year is
    // refused by the unique, in another year it is a different row.
    expect(fn () => lctschemaRow($employee, 2026, 'SICK_LEAVE', '5'))->toThrow(QueryException::class, 'lcr_employee_year_type_unq');
    $nextYear = lctschemaRow($employee, 2027, 'SICK_LEAVE', '5');
    expect(DB::table('leave_credits')->where('id', $nextYear)->exists())->toBeTrue()
        ->and(DB::table('leave_credits')->where('employee_id', (string) $employee->getKey())->count())->toBe(5);

    // An UPDATE meets the same CHECKs as an INSERT.
    expect(fn () => DB::table('leave_credits')->where('id', $zero)->update(['credits' => '-0.5']))
        ->toThrow(QueryException::class, 'lcr_credits_chk');
    expect(fn () => DB::table('leave_credits')->where('id', $zero)->update(['year' => 1999]))
        ->toThrow(QueryException::class, 'lcr_year_chk');
    expect((string) DB::table('leave_credits')->where('id', $zero)->value('credits'))->toBe('0.00')
        ->and((int) DB::table('leave_credits')->where('id', $zero)->value('year'))->toBe(2026);
});

it('cascades the credit rows with their employee', function () {
    $employee = lctschemaEmployee();
    $other = lctschemaEmployee();
    $vacation = lctschemaRow($employee, 2026, 'VACATION_LEAVE', '15');
    $sick = lctschemaRow($employee, 2026, 'SICK_LEAVE', '15');
    $theirs = lctschemaRow($other, 2026, 'VACATION_LEAVE', '15');

    // An entitlement is configuration, not history: it goes with its employee.
    DB::table('employee_profiles')->where('id', $employee->getKey())->delete();

    expect(DB::table('leave_credits')->whereIn('id', [$vacation, $sick])->count())->toBe(0)
        ->and(DB::table('leave_credits')->where('id', $theirs)->exists())->toBeTrue()
        ->and(DB::table('employee_profiles')->where('id', $other->getKey())->exists())->toBeTrue();
});

it('removes every piece on a full rollback, tolerates a second one, and restores them all with the employee intact', function () {
    $employee = lctschemaEmployee();
    lctschemaRow($employee, 2026, 'VACATION_LEAVE', '15');
    $migration = lctschemaMigration();

    $migration->down();
    lctschemaAssertApplied(false);

    // A rollback killed and re-run finds nothing left to drop.
    expect(lctschemaDdlDuring(static fn () => $migration->down()))->toBe([]);
    lctschemaAssertApplied(false);

    // The employee is untouched by the drop.
    expect(DB::table('employee_profiles')->where('id', $employee->getKey())->exists())->toBeTrue();

    $migration->up();
    lctschemaAssertApplied(true);
    lctschemaAssertDeclaredIndexes();

    // ROLLBACK LOSES every entered credit: the table comes back empty.
    expect(DB::table('leave_credits')->count())->toBe(0);
});

it('resumes the migration from a run killed between any two of its statements', function () {
    $migration = lctschemaMigration();
    $restated = 'ALTER TABLE `leave_credits` ROW_FORMAT=DYNAMIC';

    // Killed before a CHECK: only that CHECK is re-added (plus the restatement).
    foreach (['lcr_credits_chk', 'lcr_year_chk'] as $check) {
        DB::statement("ALTER TABLE `leave_credits` DROP CONSTRAINT `{$check}`");
        expect(lctschemaChecks('leave_credits', $check))->toBe(0);

        $ddl = lctschemaDdlDuring(static fn () => $migration->up());

        expect($ddl)->toHaveCount(2)
            ->and($ddl[0])->toBe($restated)
            ->and($ddl[1])->toStartWith("ALTER TABLE `leave_credits` ADD CONSTRAINT `{$check}` CHECK");
        expect(lctschemaChecks('leave_credits', $check))->toBe(1);
    }
    lctschemaAssertApplied(true);

    // Killed before the unique: the unique is re-added, then the foreign key
    // that uses it, ON DELETE CASCADE. The key has to go first and comes back
    // with the index.
    DB::statement('ALTER TABLE `leave_credits` DROP FOREIGN KEY `leave_credits_employee_id_foreign`');
    DB::statement('ALTER TABLE `leave_credits` DROP INDEX `lcr_employee_year_type_unq`');
    expect(Schema::hasIndex('leave_credits', 'lcr_employee_year_type_unq'))->toBeFalse()
        ->and(Schema::hasForeignKey('leave_credits', ['employee_id']))->toBeFalse();

    $ddl = lctschemaDdlDuring(static fn () => $migration->up());

    expect($ddl)->toHaveCount(3)
        ->and(strtolower($ddl[0]))->toContain('add unique `lcr_employee_year_type_unq`')
        ->and(strtolower($ddl[1]))->toContain('foreign key (`employee_id`) references `employee_profiles`')
        ->and(strtolower($ddl[1]))->toContain('on delete cascade')
        ->and($ddl[2])->toBe($restated);
    lctschemaAssertApplied(true);
    lctschemaAssertDeclaredIndexes();
});

it('resumes the migration from a create killed after its CREATE TABLE', function () {
    $migration = lctschemaMigration();

    // Strip the migrated table back to what that run leaves: the columns and
    // the primary key. The foreign key goes first, because MariaDB will not
    // drop an index a constraint still uses.
    DB::statement('ALTER TABLE `leave_credits` DROP FOREIGN KEY `leave_credits_employee_id_foreign`');
    DB::statement('ALTER TABLE `leave_credits` DROP INDEX `lcr_employee_year_type_unq`');
    foreach (['lcr_credits_chk', 'lcr_year_chk'] as $check) {
        DB::statement("ALTER TABLE `leave_credits` DROP CONSTRAINT `{$check}`");
    }

    expect(lctschemaIndexNames('leave_credits'))->toBe(['primary']);
    expect(Schema::getForeignKeys('leave_credits'))->toBe([]);
    expect(lctschemaChecks('leave_credits', 'lcr_credits_chk'))->toBe(0)
        ->and(lctschemaChecks('leave_credits', 'lcr_year_chk'))->toBe(0);

    $migration->up();

    lctschemaAssertApplied(true);
    // The unique landed before the constraint, so MariaDB reused it and
    // added no index of its own for the foreign key.
    lctschemaAssertDeclaredIndexes();

    // A second run finds every piece in place: the only DDL it sends is the
    // idempotent ROW_FORMAT restatement.
    expect(lctschemaDdlDuring(static fn () => $migration->up()))->toBe(['ALTER TABLE `leave_credits` ROW_FORMAT=DYNAMIC']);
    lctschemaAssertApplied(true);
});
