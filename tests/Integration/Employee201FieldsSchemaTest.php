<?php

declare(strict_types=1);

use App\Models\EmployeeChild;
use App\Models\EmployeeProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * On the production engine, the 201 sheet's columns migration
 * (2026_10_01_0001) is fourteen plain ADD COLUMN ALTERs, one per column, each
 * NULLable at the record's widths and types; re-running it on a migrated
 * schema sends no DDL, a run killed after the first ALTER resumes with
 * exactly the other thirteen, and a rollback drops all fourteen and loses
 * what was entered. The children table migration (2026_10_01_0002) creates
 * `employee_children` with its unique and cascading foreign key, under
 * ROW_FORMAT=DYNAMIC, and a rollback drops it.
 *
 * The columns migration's docblock says MariaDB 11.4 adds these columns
 * instantly, that the table accepts them under its DYNAMIC row format, and
 * that nothing narrower than VARCHAR(64) was added so the table keeps its
 * row-size budget: this suite replays the same statements with
 * ALGORITHM=INSTANT, which the engine refuses outright where it would have
 * to copy the table, runs the whole instant down() sequence (which fails
 * with 1118 at the seventh drop if six of the columns are narrower), and
 * pins the budget left afterwards. DatabaseMigrations rolls the whole schema
 * back after each test, so EVERY TEST ENDS MIGRATED.
 */

/** @var array<string, string> column => its type */
const E201SCHEMA_COLUMNS = [
    'telephone' => 'varchar(64)',
    'religion' => 'varchar(64)',
    'height_cm' => 'decimal(5,2)',
    'weight_kg' => 'decimal(5,2)',
    'blood_type' => 'varchar(5)',
    'spouse_name' => 'varchar(120)',
    'spouse_employment' => 'varchar(120)',
    'college' => 'varchar(150)',
    'college_course' => 'varchar(120)',
    'college_year_graduated' => 'varchar(64)',
    'graduate_school' => 'varchar(150)',
    'graduate_course' => 'varchar(120)',
    'graduate_year_graduated' => 'varchar(64)',
    'bank_name' => 'varchar(150)',
];

/** The columns migration — an anonymous class, so untyped here. */
function e201schemaColumns(): mixed
{
    return require base_path('database/migrations/2026_10_01_0001_add_201_file_fields_to_employee_profiles_table.php');
}

/** The children table migration. */
function e201schemaChildren(): mixed
{
    return require base_path('database/migrations/2026_10_01_0002_create_employee_children_table.php');
}

/**
 * The column as information_schema describes it, or null when absent.
 *
 * @return array{type: string, nullable: string, default: mixed}|null
 */
function e201schemaColumn(string $table, string $name): ?array
{
    $row = DB::selectOne(
        'SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $name],
    );
    if (! is_object($row) || ! isset($row->type, $row->nullable) || ! property_exists($row, 'dflt')) {
        return null;
    }

    return ['type' => strtolower((string) $row->type), 'nullable' => (string) $row->nullable, 'default' => $row->dflt];
}

/** All fourteen columns exist, NULLable at their types, NULL by default. */
function e201schemaAssertAdded(): void
{
    foreach (E201SCHEMA_COLUMNS as $name => $type) {
        $column = e201schemaColumn('employee_profiles', $name);
        expect($column)->not->toBeNull();
        assert($column !== null);
        expect($column['type'])->toBe($type)
            ->and($column['nullable'])->toBe('YES')
            ->and($column['default'] === null || strtoupper((string) $column['default']) === 'NULL')->toBeTrue();
    }
}

function e201schemaAssertDropped(): void
{
    foreach (array_keys(E201SCHEMA_COLUMNS) as $name) {
        expect(e201schemaColumn('employee_profiles', $name))->toBeNull();
    }
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function e201schemaDdlDuring(callable $callback): array
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

/** An employee holding some of the values, with one child; returns its id. */
function e201schemaEmployee(): string
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'E201-'.Str::upper(Str::random(6)),
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

    $child = new EmployeeChild;
    $child->fill(['employee_id' => $employee->getKey(), 'position' => 0, 'name' => 'Ana', 'birth_date' => '2019-05-04'])->save();

    return (string) $employee->getKey();
}

/** @return array{row_format: string} */
function e201schemaTable(string $table): array
{
    $row = DB::selectOne('SELECT ROW_FORMAT AS row_format FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);

    return ['row_format' => strtoupper((string) ($row->row_format ?? ''))];
}

it('applies both migrations twice on top of a migrated schema without sending any DDL', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    e201schemaAssertAdded();
    expect(e201schemaTable('employee_children')['row_format'])->toBe('DYNAMIC');
    $columns = e201schemaColumns();
    $children = e201schemaChildren();

    // The children migration restates ROW_FORMAT=DYNAMIC on every run (its
    // one unguarded, idempotent statement); nothing else is sent.
    $ddl = e201schemaDdlDuring(static function () use ($columns, $children): void {
        $columns->up();
        $columns->up();
        $children->up();
        $children->up();
    });

    expect(array_values(array_filter($ddl, static fn (string $sql): bool => ! str_contains($sql, 'ROW_FORMAT=DYNAMIC'))))->toBe([]);
    e201schemaAssertAdded();
});

it('adds each column in its own ALTER, which MariaDB runs instantly', function () {
    $columns = e201schemaColumns();
    $columns->down();
    e201schemaAssertDropped();

    $ddl = e201schemaDdlDuring(static fn () => $columns->up());

    expect($ddl)->toHaveCount(count(E201SCHEMA_COLUMNS));
    foreach (array_keys(E201SCHEMA_COLUMNS) as $i => $name) {
        expect($ddl[$i])->toContain("`{$name}`");
    }
    e201schemaAssertAdded();

    // The same statements with ALGORITHM=INSTANT are refused outright where
    // the engine would have to copy the table.
    $columns->down();
    e201schemaAssertDropped();
    foreach ($ddl as $statement) {
        DB::statement($statement.', ALGORITHM=INSTANT');
    }
    e201schemaAssertAdded();
});

it('leaves the table room for a dozen more short columns', function () {
    // As the migration's docblock records: thirteen instant VARCHAR(63)
    // columns fit before this migration, twelve after it. A drop below ten
    // means a column was added at a width InnoDB counts in full; widen it to
    // VARCHAR(64) or more.
    $added = 0;
    try {
        for ($i = 1; $i <= 20; $i++) {
            DB::statement("ALTER TABLE `employee_profiles` ADD COLUMN `e201_pad{$i}` varchar(63) NULL, ALGORITHM=INSTANT");
            $added = $i;
        }
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('Row size too large');
    } finally {
        // The padding leaves through a rebuild, not instant drops: with it
        // in the row, the instant DROPs of the schema rollback would meet
        // the same 1118 (the quirk the migration's docblock describes).
        $drops = [];
        for ($i = 1; $i <= $added; $i++) {
            $drops[] = "DROP COLUMN `e201_pad{$i}`";
        }
        if ($drops !== []) {
            DB::statement('ALTER TABLE `employee_profiles` '.implode(', ', $drops).', ALGORITHM=COPY');
        }
    }

    expect($added)->toBeGreaterThanOrEqual(10);
});

it('resumes a run killed after its first ALTER', function () {
    $columns = e201schemaColumns();
    $columns->down();

    DB::statement('alter table `employee_profiles` add `telephone` varchar(64) null');

    $ddl = e201schemaDdlDuring(static fn () => $columns->up());

    expect($ddl)->toHaveCount(count(E201SCHEMA_COLUMNS) - 1)
        ->and($ddl[0])->toContain('`religion`');
    foreach ($ddl as $statement) {
        expect($statement)->not->toContain('`telephone`');
    }
    e201schemaAssertAdded();
});

it('creates the children table with its unique before the cascading key, and resumes after a kill', function () {
    $children = e201schemaChildren();
    $children->down();
    expect(e201schemaColumn('employee_children', 'id'))->toBeNull();

    $ddl = e201schemaDdlDuring(static fn () => $children->up());

    expect(count($ddl))->toBeGreaterThanOrEqual(3)
        ->and(strtolower($ddl[0]))->toStartWith('create table `employee_children`')
        ->and($ddl[1])->toContain('echild_employee_position_unq')
        ->and($ddl[2])->toContain('foreign key')
        ->and($ddl[2])->toContain('on delete cascade');

    $key = DB::selectOne("SELECT DELETE_RULE AS rule FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_children'");
    expect(strtoupper((string) ($key->rule ?? '')))->toBe('CASCADE')
        ->and(e201schemaTable('employee_children')['row_format'])->toBe('DYNAMIC');

    // Killed after the create: the resume adds only the unique and the key.
    $children->down();
    DB::statement('create table `employee_children` (`id` char(26) not null, `employee_id` char(26) not null, `position` tinyint unsigned not null, `name` varchar(120) not null, `birth_date` date null, `created_at` datetime(3) not null, primary key (`id`)) default character set utf8mb4 collate utf8mb4_unicode_ci engine = InnoDB');

    $ddl = e201schemaDdlDuring(static fn () => $children->up());

    foreach ($ddl as $statement) {
        expect(strtolower($statement))->not->toStartWith('create table');
    }
    expect(array_filter($ddl, static fn (string $sql): bool => str_contains($sql, 'echild_employee_position_unq')))->toHaveCount(1)
        ->and(array_filter($ddl, static fn (string $sql): bool => str_contains(strtolower($sql), 'foreign key')))->toHaveCount(1);
});

it('drops every column and the table on rollback, tolerates a second one, and loses what was entered', function () {
    $employee = e201schemaEmployee();
    $columns = e201schemaColumns();
    $children = e201schemaChildren();

    // A cascading key: the child goes with its employee, on the real engine.
    expect(EmployeeChild::query()->where('employee_id', $employee)->count())->toBe(1);

    $children->down();
    $columns->down();
    e201schemaAssertDropped();
    expect(e201schemaColumn('employee_children', 'id'))->toBeNull();
    expect(e201schemaDdlDuring(static function () use ($columns, $children): void {
        $children->down();
        $columns->down();
    }))->toBe([]);

    $columns->up();
    $children->up();
    e201schemaAssertAdded();

    $row = DB::table('employee_profiles')->where('id', $employee)->first();
    expect($row?->telephone)->toBeNull()
        ->and($row?->height_cm)->toBeNull()
        ->and($row?->bank_name)->toBeNull()
        ->and($row?->last_name)->toBe('Villanueva')
        ->and(EmployeeChild::query()->count())->toBe(0);

    // Back in place, the key cascades.
    $child = new EmployeeChild;
    $child->fill(['employee_id' => $employee, 'position' => 0, 'name' => 'Ana', 'birth_date' => null])->save();
    EmployeeProfile::query()->whereKey($employee)->delete();
    expect(EmployeeChild::query()->count())->toBe(0);
});
