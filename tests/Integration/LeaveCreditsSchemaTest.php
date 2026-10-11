<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * On the production engine, the leave-credit drop (2026_09_25_0002) is two
 * plain DROP COLUMN ALTERs, one per column: re-running the migration on a
 * migrated schema sends no DDL, a run killed between the two ALTERs resumes
 * with exactly the one that is left, a full rollback restores both columns as
 * DECIMAL(6,2) NOT NULL DEFAULT 0.00 (a second rollback finds nothing to add),
 * and the employees survive every step — only the balances are lost.
 *
 * The migration's docblock says MariaDB 11.4 drops these columns instantly,
 * so this suite sends the same statements with ALGORITHM=INSTANT, which the
 * engine refuses outright where it would have to copy the table. sqlite may
 * rebuild a table for a column drop, so only this suite sees the real ALTERs.
 * DatabaseMigrations rolls the whole schema back after each test, so EVERY
 * TEST ENDS MIGRATED.
 */

/** @var list<string> */
const LCSCHEMA_COLUMNS = ['vacation_credits', 'sick_credits'];

/** The migration — an anonymous class, so untyped here. */
function lcschemaMigration(): mixed
{
    return require base_path('database/migrations/2026_09_25_0002_drop_leave_credits_from_employee_profiles_table.php');
}

/**
 * The column as information_schema describes it, or null when absent.
 *
 * @return array{type: string, nullable: string, default: mixed}|null
 */
function lcschemaColumn(string $name): ?array
{
    $row = DB::selectOne(
        'SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['employee_profiles', $name],
    );
    if (! is_object($row) || ! isset($row->type, $row->nullable) || ! property_exists($row, 'dflt')) {
        return null;
    }

    return ['type' => strtolower((string) $row->type), 'nullable' => (string) $row->nullable, 'default' => $row->dflt];
}

/** Neither column exists any more. */
function lcschemaAssertDropped(): void
{
    foreach (LCSCHEMA_COLUMNS as $name) {
        expect(lcschemaColumn($name))->toBeNull();
        expect(Schema::hasColumn('employee_profiles', $name))->toBeFalse();
    }
}

/** Both columns are back exactly as the create migration declared them. */
function lcschemaAssertRestored(): void
{
    foreach (LCSCHEMA_COLUMNS as $name) {
        $column = lcschemaColumn($name);
        expect($column)->not->toBeNull();
        assert($column !== null);
        expect($column['type'])->toBe('decimal(6,2)')
            ->and($column['nullable'])->toBe('NO')
            ->and((string) $column['default'])->toBe('0.00');
    }
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function lcschemaDdlDuring(callable $callback): array
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

/** An employee straight through the model; returns its id. */
function lcschemaEmployee(): string
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'LC-'.Str::upper(Str::random(6)),
        'first_name' => 'Credit',
        'last_name' => 'Holder',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-15',
        'monthly_rate' => 30000,
    ])->save();

    return (string) $employee->getKey();
}

function lcschemaCredit(string $employeeId, string $column): string
{
    return (string) DB::table('employee_profiles')->where('id', $employeeId)->value($column);
}

it('applies the migration twice on top of a migrated schema without sending any DDL', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    // migrate:fresh has already run it through the migrator.
    lcschemaAssertDropped();
    $migration = lcschemaMigration();

    expect(lcschemaDdlDuring(static function () use ($migration): void {
        $migration->up();
        $migration->up();
    }))->toBe([]);

    lcschemaAssertDropped();
});

it('drops each column in its own ALTER, which MariaDB runs instantly', function () {
    $migration = lcschemaMigration();
    $migration->down();
    lcschemaAssertRestored();

    $ddl = lcschemaDdlDuring(static fn () => $migration->up());

    expect($ddl)->toBe([
        'alter table `employee_profiles` drop `vacation_credits`',
        'alter table `employee_profiles` drop `sick_credits`',
    ]);
    lcschemaAssertDropped();

    // The same statements with ALGORITHM=INSTANT are refused outright where
    // the engine would have to copy the table, so this proves the drops are
    // instant on the production engine.
    $migration->down();
    lcschemaAssertRestored();
    foreach ($ddl as $statement) {
        DB::statement($statement.', ALGORITHM=INSTANT');
    }
    lcschemaAssertDropped();
});

it('restores both columns at zero on rollback, tolerates a second one, and loses the balances', function () {
    $employee = lcschemaEmployee();
    $migration = lcschemaMigration();

    $migration->down();
    lcschemaAssertRestored();

    // A rollback killed after its ADDs and re-run finds nothing left to add.
    expect(lcschemaDdlDuring(static fn () => $migration->down()))->toBe([]);
    lcschemaAssertRestored();

    // The row that predates the rollback reads the default, and takes the old
    // fractional day counts again.
    expect(lcschemaCredit($employee, 'vacation_credits'))->toBe('0.00');
    DB::table('employee_profiles')->where('id', $employee)->update(['vacation_credits' => 15, 'sick_credits' => 7.5]);
    expect(lcschemaCredit($employee, 'vacation_credits'))->toBe('15.00')
        ->and(lcschemaCredit($employee, 'sick_credits'))->toBe('7.50');

    $migration->up();
    lcschemaAssertDropped();

    // The employee is untouched by the drop.
    $fresh = EmployeeProfile::query()->findOrFail($employee);
    expect($fresh->monthly_rate)->toBe(30000.0)
        ->and($fresh->date_hired->format('Y-m-d'))->toBe('2024-01-15')
        ->and($fresh->employment_status)->toBe('Regular');

    // ROLLBACK LOSES the balances: the restored columns read 0.00, not 15 and 7.5.
    $migration->down();
    expect(lcschemaCredit($employee, 'vacation_credits'))->toBe('0.00')
        ->and(lcschemaCredit($employee, 'sick_credits'))->toBe('0.00');
});

it('resumes a run killed between its two ALTERs', function () {
    $employee = lcschemaEmployee();
    $migration = lcschemaMigration();

    $migration->down();
    lcschemaAssertRestored();

    // Killed after the first drop: only the second column is left, and the
    // re-run sends exactly the one ALTER for it.
    DB::statement('alter table `employee_profiles` drop `vacation_credits`');
    expect(Schema::hasColumn('employee_profiles', 'vacation_credits'))->toBeFalse()
        ->and(Schema::hasColumn('employee_profiles', 'sick_credits'))->toBeTrue();

    expect(lcschemaDdlDuring(static fn () => $migration->up()))->toBe([
        'alter table `employee_profiles` drop `sick_credits`',
    ]);
    lcschemaAssertDropped();
    expect(EmployeeProfile::query()->whereKey($employee)->exists())->toBeTrue();
});
