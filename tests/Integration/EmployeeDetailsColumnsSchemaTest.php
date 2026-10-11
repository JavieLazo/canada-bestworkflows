<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * On the production engine, the Place of Birth / Citizenship / emergency
 * Address migration (2026_09_30_0001) is three plain ADD COLUMN ALTERs, one
 * per column: each is NULLable VARCHAR at the record's widths, re-running the
 * migration on a migrated schema sends no DDL, a run killed after the first
 * ALTER resumes with exactly the other two, and a rollback drops all three
 * and loses what was entered.
 *
 * The migration's docblock says MariaDB 11.4 adds these columns instantly
 * and that the table accepts them under its DYNAMIC row format, so this suite
 * replays the same statements with ALGORITHM=INSTANT, which the engine
 * refuses outright where it would have to copy the table. DatabaseMigrations
 * rolls the whole schema back after each test, so EVERY TEST ENDS MIGRATED.
 */

/** @var array<string, string> column => its type */
const EDCSCHEMA_COLUMNS = [
    'place_of_birth' => 'varchar(120)',
    'citizenship' => 'varchar(60)',
    'emergency_address' => 'varchar(300)',
];

/** The migration — an anonymous class, so untyped here. */
function edcschemaMigration(): mixed
{
    return require base_path('database/migrations/2026_09_30_0001_add_birth_place_citizenship_and_emergency_address_to_employee_profiles_table.php');
}

/**
 * The column as information_schema describes it, or null when absent.
 *
 * @return array{type: string, nullable: string, default: mixed}|null
 */
function edcschemaColumn(string $name): ?array
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

/** All three columns exist, NULLable VARCHAR at their widths, NULL by default. */
function edcschemaAssertAdded(): void
{
    foreach (EDCSCHEMA_COLUMNS as $name => $type) {
        $column = edcschemaColumn($name);
        expect($column)->not->toBeNull();
        assert($column !== null);
        expect($column['type'])->toBe($type)
            ->and($column['nullable'])->toBe('YES')
            ->and($column['default'] === null || strtoupper((string) $column['default']) === 'NULL')->toBeTrue();
    }
}

function edcschemaAssertDropped(): void
{
    foreach (array_keys(EDCSCHEMA_COLUMNS) as $name) {
        expect(edcschemaColumn($name))->toBeNull();
    }
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function edcschemaDdlDuring(callable $callback): array
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

/** An employee holding all three values; returns its id. */
function edcschemaEmployee(): string
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'EDC-'.Str::upper(Str::random(6)),
        'first_name' => 'Rosa',
        'last_name' => 'Villanueva',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-15',
        'monthly_rate' => 30000,
        'place_of_birth' => 'Iloilo City',
        'citizenship' => 'Filipino',
        'emergency_address' => '12 Luna St., Iloilo City',
    ])->save();

    return (string) $employee->getKey();
}

it('applies the migration twice on top of a migrated schema without sending any DDL', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    edcschemaAssertAdded();
    $migration = edcschemaMigration();

    expect(edcschemaDdlDuring(static function () use ($migration): void {
        $migration->up();
        $migration->up();
    }))->toBe([]);

    edcschemaAssertAdded();
});

it('adds each column in its own ALTER, which MariaDB runs instantly', function () {
    $migration = edcschemaMigration();
    $migration->down();
    edcschemaAssertDropped();

    $ddl = edcschemaDdlDuring(static fn () => $migration->up());

    expect($ddl)->toHaveCount(3)
        ->and($ddl[0])->toContain('`place_of_birth`')
        ->and($ddl[1])->toContain('`citizenship`')
        ->and($ddl[2])->toContain('`emergency_address`');
    edcschemaAssertAdded();

    // The same statements with ALGORITHM=INSTANT are refused outright where
    // the engine would have to copy the table.
    $migration->down();
    edcschemaAssertDropped();
    foreach ($ddl as $statement) {
        DB::statement($statement.', ALGORITHM=INSTANT');
    }
    edcschemaAssertAdded();
});

it('resumes a run killed after its first ALTER', function () {
    $migration = edcschemaMigration();
    $migration->down();

    DB::statement('alter table `employee_profiles` add `place_of_birth` varchar(120) null');

    $ddl = edcschemaDdlDuring(static fn () => $migration->up());

    expect($ddl)->toHaveCount(2)
        ->and($ddl[0])->toContain('`citizenship`')
        ->and($ddl[1])->toContain('`emergency_address`');
    edcschemaAssertAdded();
});

it('drops all three on rollback, tolerates a second one, and loses what was entered', function () {
    $employee = edcschemaEmployee();
    $migration = edcschemaMigration();

    $migration->down();
    edcschemaAssertDropped();
    expect(edcschemaDdlDuring(static fn () => $migration->down()))->toBe([]);

    $migration->up();
    edcschemaAssertAdded();

    $row = DB::table('employee_profiles')->where('id', $employee)->first();
    expect($row?->place_of_birth)->toBeNull()
        ->and($row?->citizenship)->toBeNull()
        ->and($row?->emergency_address)->toBeNull()
        ->and($row?->last_name)->toBe('Villanueva');
});
