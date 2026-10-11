<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * On the production engine, the Rate Per Day migration (2026_09_30_0002) is
 * one plain ADD COLUMN: a NULLable DECIMAL(12,2) with no default, re-running
 * the migration on a migrated schema sends no DDL, and a rollback drops it and
 * loses what was entered.
 *
 * The migration's docblock says MariaDB 11.4 adds the column instantly under
 * the table's DYNAMIC row format, so this suite replays the same statement
 * with ALGORITHM=INSTANT, which the engine refuses outright where it would
 * have to copy the table. DatabaseMigrations rolls the whole schema back
 * after each test, so EVERY TEST ENDS MIGRATED.
 */

/** The migration — an anonymous class, so untyped here. */
function drschemaMigration(): mixed
{
    return require base_path('database/migrations/2026_09_30_0002_add_daily_rate_to_employee_profiles_table.php');
}

/**
 * The column as information_schema describes it, or null when absent.
 *
 * @return array{type: string, nullable: string, default: mixed}|null
 */
function drschemaColumn(): ?array
{
    $row = DB::selectOne(
        'SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['employee_profiles', 'daily_rate'],
    );
    if (! is_object($row) || ! isset($row->type, $row->nullable) || ! property_exists($row, 'dflt')) {
        return null;
    }

    return ['type' => strtolower((string) $row->type), 'nullable' => (string) $row->nullable, 'default' => $row->dflt];
}

/** The column exists as a NULLable DECIMAL(12,2), NULL by default. */
function drschemaAssertAdded(): void
{
    $column = drschemaColumn();
    expect($column)->not->toBeNull();
    assert($column !== null);
    expect($column['type'])->toBe('decimal(12,2)')
        ->and($column['nullable'])->toBe('YES')
        ->and($column['default'] === null || strtoupper((string) $column['default']) === 'NULL')->toBeTrue();
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function drschemaDdlDuring(callable $callback): array
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

/** An employee holding a Rate Per Day; returns its id. */
function drschemaEmployee(): string
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'DRS-'.Str::upper(Str::random(6)),
        'first_name' => 'Rosa',
        'last_name' => 'Villanueva',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-15',
        'monthly_rate' => 30000,
        'daily_rate' => 1250.5,
    ])->save();

    return (string) $employee->getKey();
}

it('applies the migration twice on top of a migrated schema without sending any DDL', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    drschemaAssertAdded();
    $migration = drschemaMigration();

    expect(drschemaDdlDuring(static function () use ($migration): void {
        $migration->up();
        $migration->up();
    }))->toBe([]);

    drschemaAssertAdded();
});

it('adds the column in one ALTER, which MariaDB runs instantly', function () {
    $migration = drschemaMigration();
    $migration->down();
    expect(drschemaColumn())->toBeNull();

    $ddl = drschemaDdlDuring(static fn () => $migration->up());

    expect($ddl)->toHaveCount(1)
        ->and($ddl[0])->toContain('`daily_rate`');
    drschemaAssertAdded();

    // The same statement with ALGORITHM=INSTANT is refused outright where the
    // engine would have to copy the table.
    $migration->down();
    expect(drschemaColumn())->toBeNull();
    DB::statement($ddl[0].', ALGORITHM=INSTANT');
    drschemaAssertAdded();
});

it('keeps two decimals and drops the column on rollback, tolerating a second one', function () {
    $employee = drschemaEmployee();
    expect((string) DB::table('employee_profiles')->where('id', $employee)->value('daily_rate'))->toBe('1250.50');

    $migration = drschemaMigration();
    $migration->down();
    expect(drschemaColumn())->toBeNull()
        ->and(drschemaDdlDuring(static fn () => $migration->down()))->toBe([]);

    $migration->up();
    drschemaAssertAdded();

    $row = DB::table('employee_profiles')->where('id', $employee)->first();
    expect($row?->daily_rate)->toBeNull()
        ->and($row?->last_name)->toBe('Villanueva');
});
