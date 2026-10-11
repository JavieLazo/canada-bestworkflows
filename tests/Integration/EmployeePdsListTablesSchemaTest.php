<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * On the production engine, the family and declaration columns
 * (2026_10_07_0003, appended to `employee_pds_details`) are each added by
 * their own ALTER, which MariaDB 11.4 runs with ALGORITHM=INSTANT under
 * `innodb_strict_mode`; the six Personal Data Sheet list tables
 * (2026_10_07_0004–0009) are created with every column at its width,
 * NULLable unless required and with no default, `eedu_level_chk` and
 * `eoth_kind_chk` refuse a value off their literal lists, each named index is
 * declared before its cascading foreign key, so MariaDB reuses it and adds
 * no index of its own, and every table is ROW_FORMAT=DYNAMIC. Re-running any
 * of the seven sends no DDL but the list tables' restatement, and a run
 * killed between any two of its statements resumes with exactly the missing
 * piece. None of it touches `employee_profiles` — its definition and its
 * values, the legacy spouse, college and graduate-school text included —
 * which stays byte-for-byte as it was.
 *
 * DatabaseMigrations rolls the whole schema back after each test, and every
 * down() step is guarded, so a test that leaves a table stripped still tears
 * down cleanly; each test that strips a piece puts it back anyway.
 */

/** The migration that appends the family and declaration columns. */
const PDSLSCHEMA_COLUMNS_FILE = '2026_10_07_0003_add_family_and_declarations_to_employee_pds_details_table';

/** @var array<string, string> list table => its migration */
const PDSLSCHEMA_FILES = [
    'employee_education' => '2026_10_07_0004_create_employee_education_table',
    'employee_eligibilities' => '2026_10_07_0005_create_employee_eligibilities_table',
    'employee_work_experiences' => '2026_10_07_0006_create_employee_work_experiences_table',
    'employee_trainings' => '2026_10_07_0007_create_employee_trainings_table',
    'employee_voluntary_works' => '2026_10_07_0008_create_employee_voluntary_works_table',
    'employee_other_items' => '2026_10_07_0009_create_employee_other_items_table',
];

/** @var array<string, string> list table => the index its foreign key reuses */
const PDSLSCHEMA_INDEXES = [
    'employee_education' => 'eedu_employee_level_idx',
    'employee_eligibilities' => 'eelig_employee_idx',
    'employee_work_experiences' => 'ewexp_employee_idx',
    'employee_trainings' => 'etrain_employee_idx',
    'employee_voluntary_works' => 'evol_employee_idx',
    'employee_other_items' => 'eoth_employee_kind_position_unq',
];

/** @var array<string, list<string>> list table => its CHECKs */
const PDSLSCHEMA_CHECKS = [
    'employee_education' => ['eedu_level_chk'],
    'employee_eligibilities' => [],
    'employee_work_experiences' => [],
    'employee_trainings' => [],
    'employee_voluntary_works' => [],
    'employee_other_items' => ['eoth_kind_chk'],
];

/** @var array<string, string> column => its type */
const PDSLSCHEMA_EDUCATION_COLUMNS = [
    'id' => 'char(26)',
    'employee_id' => 'char(26)',
    'level' => 'varchar(12)',
    'school' => 'varchar(150)',
    'course' => 'varchar(150)',
    'attended_from' => 'smallint(5) unsigned',
    'attended_to' => 'smallint(5) unsigned',
    'units_earned' => 'varchar(60)',
    'year_graduated' => 'smallint(5) unsigned',
    'honors' => 'varchar(150)',
    'created_at' => 'datetime(3)',
    'updated_at' => 'datetime(3)',
];

/** @var array<string, string> column => its type */
const PDSLSCHEMA_ELIGIBILITIES_COLUMNS = [
    'id' => 'char(26)',
    'employee_id' => 'char(26)',
    'name' => 'varchar(255)',
    'rating' => 'varchar(20)',
    'exam_date' => 'date',
    'exam_place' => 'varchar(150)',
    'license_no' => 'varchar(40)',
    'license_valid_until' => 'date',
    'created_at' => 'datetime(3)',
    'updated_at' => 'datetime(3)',
];

/** @var array<string, string> column => its type */
const PDSLSCHEMA_WORK_EXPERIENCES_COLUMNS = [
    'id' => 'char(26)',
    'employee_id' => 'char(26)',
    'position_title' => 'varchar(150)',
    'company' => 'varchar(150)',
    'date_from' => 'date',
    'date_to' => 'date',
    'monthly_salary' => 'decimal(12,2)',
    'pay_grade' => 'varchar(20)',
    'appointment_status' => 'varchar(60)',
    'government_service' => 'tinyint(1)',
    'created_at' => 'datetime(3)',
    'updated_at' => 'datetime(3)',
];

/** @var array<string, string> column => its type */
const PDSLSCHEMA_TRAININGS_COLUMNS = [
    'id' => 'char(26)',
    'employee_id' => 'char(26)',
    'title' => 'varchar(255)',
    'date_from' => 'date',
    'date_to' => 'date',
    'hours' => 'decimal(6,2)',
    'ld_type' => 'varchar(60)',
    'sponsor' => 'varchar(255)',
    'created_at' => 'datetime(3)',
    'updated_at' => 'datetime(3)',
];

/** @var array<string, string> column => its type */
const PDSLSCHEMA_VOLUNTARY_WORKS_COLUMNS = [
    'id' => 'char(26)',
    'employee_id' => 'char(26)',
    'organization' => 'varchar(150)',
    'organization_address' => 'varchar(255)',
    'date_from' => 'date',
    'date_to' => 'date',
    'hours' => 'decimal(6,2)',
    'position' => 'varchar(150)',
    'created_at' => 'datetime(3)',
    'updated_at' => 'datetime(3)',
];

/** @var array<string, string> column => its type */
const PDSLSCHEMA_OTHER_ITEMS_COLUMNS = [
    'id' => 'char(26)',
    'employee_id' => 'char(26)',
    'kind' => 'varchar(12)',
    'position' => 'tinyint(3) unsigned',
    'text' => 'varchar(255)',
    'created_at' => 'datetime(3)',
    'updated_at' => 'datetime(3)',
];

/** @var array<string, list<string>> list table => the columns that are NOT NULL; every other one is NULLable */
const PDSLSCHEMA_REQUIRED = [
    'employee_education' => ['id', 'employee_id', 'level', 'school', 'created_at'],
    'employee_eligibilities' => ['id', 'employee_id', 'name', 'created_at'],
    'employee_work_experiences' => ['id', 'employee_id', 'position_title', 'company', 'date_from', 'date_to', 'created_at'],
    'employee_trainings' => ['id', 'employee_id', 'title', 'date_from', 'date_to', 'created_at'],
    'employee_voluntary_works' => ['id', 'employee_id', 'organization', 'created_at'],
    'employee_other_items' => ['id', 'employee_id', 'kind', 'position', 'text', 'created_at'],
];

/** @var array<string, string> the 41 columns 0003 appends, in its order => their type; every one NULLable */
const PDSLSCHEMA_DETAIL_ADDED = [
    'spouse_surname' => 'varchar(64)',
    'spouse_first_name' => 'varchar(64)',
    'spouse_middle_name' => 'varchar(64)',
    'spouse_name_extension' => 'varchar(10)',
    'spouse_occupation' => 'varchar(120)',
    'spouse_employer' => 'varchar(150)',
    'spouse_business_address' => 'varchar(255)',
    'spouse_telephone' => 'varchar(40)',
    'father_surname' => 'varchar(64)',
    'father_first_name' => 'varchar(64)',
    'father_middle_name' => 'varchar(64)',
    'father_name_extension' => 'varchar(10)',
    'mother_surname' => 'varchar(64)',
    'mother_first_name' => 'varchar(64)',
    'mother_middle_name' => 'varchar(64)',
    'decl_34a' => 'tinyint(1)',
    'decl_34a_details' => 'varchar(255)',
    'decl_34b' => 'tinyint(1)',
    'decl_34b_details' => 'varchar(255)',
    'decl_35a' => 'tinyint(1)',
    'decl_35a_details' => 'varchar(255)',
    'decl_35b' => 'tinyint(1)',
    'decl_35b_details' => 'varchar(255)',
    'decl_35b_date_filed' => 'date',
    'decl_35b_case_status' => 'varchar(255)',
    'decl_36' => 'tinyint(1)',
    'decl_36_details' => 'varchar(255)',
    'decl_37' => 'tinyint(1)',
    'decl_37_details' => 'varchar(255)',
    'decl_38a' => 'tinyint(1)',
    'decl_38a_details' => 'varchar(255)',
    'decl_38b' => 'tinyint(1)',
    'decl_38b_details' => 'varchar(255)',
    'decl_39' => 'tinyint(1)',
    'decl_39_country' => 'varchar(60)',
    'decl_40a' => 'tinyint(1)',
    'decl_40a_specify' => 'varchar(120)',
    'decl_40b' => 'tinyint(1)',
    'decl_40b_id_no' => 'varchar(40)',
    'decl_40c' => 'tinyint(1)',
    'decl_40c_id_no' => 'varchar(40)',
];

/** One of the seven migrations — an anonymous class, so untyped here. */
function pdslschemaMigration(string $file): mixed
{
    return require base_path("database/migrations/{$file}.php");
}

/** Every up() of 0003–0009, in order. */
function pdslschemaRestore(): void
{
    pdslschemaMigration(PDSLSCHEMA_COLUMNS_FILE)->up();
    foreach (PDSLSCHEMA_FILES as $file) {
        pdslschemaMigration($file)->up();
    }
}

/** Every down() of 0009–0003, newest first, as a rollback runs them. */
function pdslschemaRollback(): void
{
    foreach (array_reverse(PDSLSCHEMA_FILES) as $file) {
        pdslschemaMigration($file)->down();
    }
    pdslschemaMigration(PDSLSCHEMA_COLUMNS_FILE)->down();
}

/** An employee holding the legacy values; returns its id. */
function pdslschemaEmployee(): string
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'PDL-'.Str::upper(Str::random(6)),
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-15',
        'monthly_rate' => 30000,
        'address' => 'Blk 3 Lot 7, Poblacion, Tayabas City',
        'citizenship' => 'Filipino',
        'spouse_name' => 'Maria S. Dela Cruz',
        'spouse_employment' => 'Teacher, DepEd',
        'college' => 'Southern Luzon State University',
        'college_course' => 'BS Accountancy',
        'college_year_graduated' => '2012',
        'graduate_school' => 'University of the Philippines',
        'graduate_course' => 'Master in Public Administration',
        'graduate_year_graduated' => '2018',
    ])->save();

    return (string) $employee->getKey();
}

/**
 * A row written straight to a list table, so only the database judges it.
 *
 * @param  array<string, string|int|float|bool|null>  $values
 */
function pdslschemaRow(string $table, string $employee, array $values): string
{
    $id = (string) Str::ulid();
    DB::table($table)->insert(['id' => $id, 'employee_id' => $employee, 'created_at' => '2026-10-07 01:00:00.000'] + $values);

    return $id;
}

/** One row in every list table for the employee, written straight to the tables. */
function pdslschemaRows(string $employee): void
{
    pdslschemaRow('employee_education', $employee, ['level' => 'COLLEGE', 'school' => 'Southern Luzon State University', 'year_graduated' => 2012]);
    pdslschemaRow('employee_eligibilities', $employee, ['name' => 'Career Service Professional', 'exam_date' => '2018-08-12']);
    pdslschemaRow('employee_work_experiences', $employee, ['position_title' => 'Accounting Clerk', 'company' => 'Quezon Rural Bank', 'date_from' => '2012-06-01', 'date_to' => '2015-05-31', 'monthly_salary' => 15000.5]);
    pdslschemaRow('employee_trainings', $employee, ['title' => 'Basic Bookkeeping', 'date_from' => '2016-02-01', 'date_to' => '2016-02-03', 'hours' => 24.5]);
    pdslschemaRow('employee_voluntary_works', $employee, ['organization' => 'Red Cross Quezon Chapter']);
    pdslschemaRow('employee_other_items', $employee, ['kind' => 'SKILL', 'position' => 0, 'text' => 'Bookkeeping']);
}

function pdslschemaChecks(string $table, string $name): int
{
    $row = DB::selectOne(
        "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
        [$table, $name],
    );

    return is_object($row) && isset($row->n) ? (int) $row->n : 0;
}

/**
 * Every index name SHOW INDEX lists for the table, once each, sorted.
 *
 * @return list<string>
 */
function pdslschemaShowIndex(string $table): array
{
    $names = [];
    foreach (DB::select("SHOW INDEX FROM `{$table}`") as $row) {
        $fields = is_object($row) ? (array) $row : [];
        $names[] = (string) ($fields['Key_name'] ?? '');
    }
    $names = array_values(array_unique($names));
    sort($names);

    return $names;
}

function pdslschemaRowFormat(string $table): string
{
    $row = DB::selectOne('SELECT ROW_FORMAT AS row_format FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);

    return is_object($row) && isset($row->row_format) ? strtoupper((string) $row->row_format) : '';
}

/** The ON DELETE rule of the table's one foreign key, and the table it names. */
function pdslschemaForeignKey(string $table): string
{
    $row = DB::selectOne(
        'SELECT DELETE_RULE AS rule, REFERENCED_TABLE_NAME AS target FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table],
    );

    return is_object($row) && isset($row->rule, $row->target) ? strtoupper((string) $row->rule).' '.$row->target : '';
}

/**
 * Each column as information_schema describes it, in table order.
 *
 * @return array<string, array{type: string, nullable: string, default: mixed}>
 */
function pdslschemaColumns(string $table): array
{
    $columns = [];
    $rows = DB::select(
        'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
        [$table],
    );
    foreach ($rows as $row) {
        $fields = is_object($row) ? (array) $row : [];
        $columns[(string) ($fields['name'] ?? '')] = [
            'type' => strtolower((string) ($fields['type'] ?? '')),
            'nullable' => (string) ($fields['nullable'] ?? ''),
            'default' => $fields['dflt'] ?? null,
        ];
    }

    return $columns;
}

/** SHOW CREATE TABLE, exactly as the server prints it. */
function pdslschemaCreateTable(string $table): string
{
    $row = DB::selectOne("SHOW CREATE TABLE `{$table}`");
    $fields = is_object($row) ? (array) $row : [];

    return (string) ($fields['Create Table'] ?? '');
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function pdslschemaDdlDuring(callable $callback): array
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

/** The idempotent ROW_FORMAT restatement a list table's up() always sends. */
function pdslschemaRestated(string $table): string
{
    return "ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC";
}

/** Every list table and the 41 details columns carry every piece their migrations declare. */
function pdslschemaAssertApplied(): void
{
    foreach (PDSLSCHEMA_INDEXES as $table => $index) {
        $expected = ['PRIMARY', $index];
        sort($expected);
        expect(pdslschemaShowIndex($table))->toBe($expected)
            ->and(pdslschemaForeignKey($table))->toBe('CASCADE employee_profiles')
            ->and(pdslschemaRowFormat($table))->toBe('DYNAMIC');
        foreach (PDSLSCHEMA_CHECKS[$table] as $check) {
            expect(pdslschemaChecks($table, $check))->toBe(1);
        }
    }
    expect(array_slice(array_keys(pdslschemaColumns('employee_pds_details')), -41))->toBe(array_keys(PDSLSCHEMA_DETAIL_ADDED));
}

/*
|--------------------------------------------------------------------------
| Shape
|--------------------------------------------------------------------------
*/

it('creates the CHECKs on MariaDB and they refuse values off their lists', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $employee = pdslschemaEmployee();
    $other = pdslschemaEmployee();

    expect(fn () => pdslschemaRow('employee_education', $employee, ['level' => 'HIGHSCHOOL', 'school' => 'Tayabas Central School']))
        ->toThrow(QueryException::class, 'eedu_level_chk');
    expect(fn () => pdslschemaRow('employee_other_items', $employee, ['kind' => 'HOBBY', 'position' => 0, 'text' => 'Chess']))
        ->toThrow(QueryException::class, 'eoth_kind_chk');
    expect(DB::table('employee_education')->count())->toBe(0)
        ->and(DB::table('employee_other_items')->count())->toBe(0);

    // Every listed value lands, and a level may hold any number of schools.
    foreach (['ELEMENTARY', 'SECONDARY', 'VOCATIONAL', 'COLLEGE', 'GRADUATE', 'COLLEGE'] as $level) {
        pdslschemaRow('employee_education', $employee, ['level' => $level, 'school' => 'School '.$level]);
    }
    foreach (['SKILL', 'DISTINCTION', 'MEMBERSHIP'] as $kind) {
        pdslschemaRow('employee_other_items', $employee, ['kind' => $kind, 'position' => 0, 'text' => $kind.' line']);
    }
    $school = pdslschemaRow('employee_education', $other, ['level' => 'COLLEGE', 'school' => 'Manuel S. Enverga University']);
    $line = pdslschemaRow('employee_other_items', $other, ['kind' => 'SKILL', 'position' => 0, 'text' => 'Bookkeeping']);

    // An UPDATE is held to the same lists.
    expect(fn () => DB::table('employee_education')->where('id', $school)->update(['level' => 'HIGHSCHOOL']))
        ->toThrow(QueryException::class, 'eedu_level_chk');
    expect(fn () => DB::table('employee_other_items')->where('id', $line)->update(['kind' => 'HOBBY']))
        ->toThrow(QueryException::class, 'eoth_kind_chk');

    expect(DB::table('employee_education')->where('employee_id', $employee)->count())->toBe(6)
        ->and(DB::table('employee_education')->where('id', $school)->value('level'))->toBe('COLLEGE')
        ->and(DB::table('employee_other_items')->where('id', $line)->value('kind'))->toBe('SKILL');

    // One other-info line per position of each list, per employee.
    expect(fn () => pdslschemaRow('employee_other_items', $employee, ['kind' => 'SKILL', 'position' => 0, 'text' => 'Singing']))
        ->toThrow(QueryException::class, 'eoth_employee_kind_position_unq');
});

it('declares each column at its width, NULLable unless required, with no default', function () {
    $tables = [
        'employee_education' => PDSLSCHEMA_EDUCATION_COLUMNS,
        'employee_eligibilities' => PDSLSCHEMA_ELIGIBILITIES_COLUMNS,
        'employee_work_experiences' => PDSLSCHEMA_WORK_EXPERIENCES_COLUMNS,
        'employee_trainings' => PDSLSCHEMA_TRAININGS_COLUMNS,
        'employee_voluntary_works' => PDSLSCHEMA_VOLUNTARY_WORKS_COLUMNS,
        'employee_other_items' => PDSLSCHEMA_OTHER_ITEMS_COLUMNS,
    ];
    foreach ($tables as $table => $expected) {
        $columns = pdslschemaColumns($table);
        expect(array_keys($columns))->toBe(array_keys($expected));
        foreach ($expected as $name => $type) {
            expect($columns[$name]['type'])->toBe($type)
                ->and($columns[$name]['nullable'])->toBe(in_array($name, PDSLSCHEMA_REQUIRED[$table], true) ? 'NO' : 'YES')
                // MariaDB prints a NULLable column's missing default as 'NULL'.
                ->and($columns[$name]['default'] === null || strtoupper((string) $columns[$name]['default']) === 'NULL')->toBeTrue();
        }
    }

    // The details table ends with the 41 added columns, in the migration's
    // order, every one NULLable with no default: a missing answer is NULL,
    // never a default No.
    $details = pdslschemaColumns('employee_pds_details');
    expect(array_slice(array_keys($details), -41))->toBe(array_keys(PDSLSCHEMA_DETAIL_ADDED));
    foreach (PDSLSCHEMA_DETAIL_ADDED as $name => $type) {
        expect($details[$name]['type'])->toBe($type)
            ->and($details[$name]['nullable'])->toBe('YES')
            ->and($details[$name]['default'] === null || strtoupper((string) $details[$name]['default']) === 'NULL')->toBeTrue();
    }
});

it('adds the 41 details columns with ALGORITHM=INSTANT', function () {
    $columns = pdslschemaMigration(PDSLSCHEMA_COLUMNS_FILE);
    $copy = 'pdslschema_instant_copy';
    $strict = DB::selectOne('SELECT @@SESSION.innodb_strict_mode AS mode');
    $wasStrict = is_object($strict) && isset($strict->mode) ? (int) $strict->mode : 1;

    try {
        // Each column is its own ALTER, in the migration's order.
        $columns->down();
        $ddl = pdslschemaDdlDuring(static fn () => $columns->up());
        expect($ddl)->toHaveCount(41);
        foreach (array_keys(PDSLSCHEMA_DETAIL_ADDED) as $i => $name) {
            expect(strtolower($ddl[$i]))->toStartWith('alter table `employee_pds_details` add ')
                ->and($ddl[$i])->toContain("`{$name}`");
        }

        // The same statements with ALGORITHM=INSTANT, replayed on a copy of
        // the table as it stands before the migration, are refused outright
        // where the engine would have to copy or rebuild it — strict mode on,
        // so a row-size overflow is an error, not a warning.
        $columns->down();
        DB::statement('SET SESSION innodb_strict_mode = ON');
        DB::statement("DROP TABLE IF EXISTS `{$copy}`");
        DB::statement("CREATE TABLE `{$copy}` LIKE `employee_pds_details`");
        expect(pdslschemaRowFormat($copy))->toBe('DYNAMIC');
        foreach ($ddl as $statement) {
            DB::statement(str_replace('`employee_pds_details`', "`{$copy}`", $statement).', ALGORITHM=INSTANT');
        }
        expect(array_slice(array_keys(pdslschemaColumns($copy)), -41))->toBe(array_keys(PDSLSCHEMA_DETAIL_ADDED));
    } finally {
        DB::statement('SET SESSION innodb_strict_mode = '.($wasStrict === 1 ? 'ON' : 'OFF'));
        DB::statement("DROP TABLE IF EXISTS `{$copy}`");
        $columns->up();
    }
});

it('reuses each index for its foreign key and sets ROW_FORMAT=DYNAMIC', function () {
    // SHOW INDEX lists the primary key and the declared index, and no
    // `…_employee_id_foreign` index: the cascading key uses the declared one.
    pdslschemaAssertApplied();

    // The keys cascade on the real engine: the rows go with their employee.
    $employee = pdslschemaEmployee();
    $other = pdslschemaEmployee();
    pdslschemaRows($employee);
    pdslschemaRows($other);

    DB::table('employee_profiles')->where('id', $employee)->delete();

    foreach (array_keys(PDSLSCHEMA_FILES) as $table) {
        expect(DB::table($table)->where('employee_id', $employee)->count())->toBe(0)
            ->and(DB::table($table)->where('employee_id', $other)->count())->toBe(1);
    }
});

/*
|--------------------------------------------------------------------------
| Retry-safety
|--------------------------------------------------------------------------
*/

it('runs up() twice and resumes after a stop between steps', function () {
    $columns = pdslschemaMigration(PDSLSCHEMA_COLUMNS_FILE);

    try {
        // Twice on top of the migrated schema: the list tables' restatement,
        // nothing else; the column run sends nothing at all.
        $expected = [];
        foreach (array_keys(PDSLSCHEMA_FILES) as $table) {
            $expected[] = pdslschemaRestated($table);
            $expected[] = pdslschemaRestated($table);
        }
        expect(pdslschemaDdlDuring(static function () use ($columns): void {
            $columns->up();
            $columns->up();
            foreach (PDSLSCHEMA_FILES as $file) {
                $migration = pdslschemaMigration($file);
                $migration->up();
                $migration->up();
            }
        }))->toBe($expected);
        pdslschemaAssertApplied();

        // Killed before the last two columns: only those two are added, in
        // the migration's order, so the table ends as a whole run leaves it.
        $missing = ['decl_40c', 'decl_40c_id_no'];
        foreach (array_reverse($missing) as $name) {
            DB::statement("ALTER TABLE `employee_pds_details` DROP COLUMN `{$name}`");
            expect(Schema::hasColumn('employee_pds_details', $name))->toBeFalse();
        }

        $ddl = pdslschemaDdlDuring(static fn () => $columns->up());

        expect($ddl)->toHaveCount(2);
        foreach ($missing as $i => $name) {
            expect(strtolower($ddl[$i]))->toStartWith('alter table `employee_pds_details` add ')
                ->and($ddl[$i])->toContain("`{$name}`");
        }

        // Killed before a CHECK: only that CHECK is re-added.
        foreach (PDSLSCHEMA_CHECKS as $table => $checks) {
            foreach ($checks as $check) {
                DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$check}`");
                expect(pdslschemaChecks($table, $check))->toBe(0);

                $migration = pdslschemaMigration(PDSLSCHEMA_FILES[$table]);
                $ddl = pdslschemaDdlDuring(static fn () => $migration->up());

                expect($ddl)->toHaveCount(2)
                    ->and($ddl[0])->toStartWith("ALTER TABLE `{$table}` ADD CONSTRAINT `{$check}` CHECK")
                    ->and($ddl[1])->toBe(pdslschemaRestated($table));
            }
        }
        pdslschemaAssertApplied();

        // Killed before the foreign key: only the key is re-added, on the
        // index that is already there.
        foreach (PDSLSCHEMA_FILES as $table => $file) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$table}_employee_id_foreign`");
            expect(Schema::hasForeignKey($table, ['employee_id']))->toBeFalse();

            $migration = pdslschemaMigration($file);
            $ddl = pdslschemaDdlDuring(static fn () => $migration->up());

            expect($ddl)->toHaveCount(2)
                ->and(strtolower($ddl[0]))->toContain('foreign key (`employee_id`) references `employee_profiles`')
                ->and(strtolower($ddl[0]))->toContain('on delete cascade')
                ->and($ddl[1])->toBe(pdslschemaRestated($table));
        }
        pdslschemaAssertApplied();

        // Killed after the CREATE TABLE: strip each table back to its columns
        // and primary key (the key first — MariaDB will not drop an index a
        // constraint uses); the retry adds the index, then the key, then any
        // CHECK, and never re-creates the table.
        foreach (PDSLSCHEMA_FILES as $table => $file) {
            $index = PDSLSCHEMA_INDEXES[$table];
            $checks = PDSLSCHEMA_CHECKS[$table];
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$table}_employee_id_foreign`");
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
            foreach ($checks as $check) {
                DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$check}`");
            }
            expect(pdslschemaShowIndex($table))->toBe(['PRIMARY']);

            $migration = pdslschemaMigration($file);
            $ddl = pdslschemaDdlDuring(static fn () => $migration->up());

            expect($ddl)->toHaveCount(3 + count($checks))
                ->and(strtolower($ddl[0]))->toContain("`{$index}`")
                ->and(strtolower($ddl[1]))->toContain('foreign key (`employee_id`) references `employee_profiles`');
            foreach ($checks as $i => $check) {
                expect($ddl[2 + $i])->toStartWith("ALTER TABLE `{$table}` ADD CONSTRAINT `{$check}` CHECK");
            }
            expect($ddl[2 + count($checks)])->toBe(pdslschemaRestated($table));
        }
        pdslschemaAssertApplied();
    } finally {
        pdslschemaRestore();
    }
});

it('leaves employee_profiles byte-for-byte as it was', function () {
    $employee = pdslschemaEmployee();
    pdslschemaRows($employee);
    $definition = pdslschemaCreateTable('employee_profiles');
    $row = DB::table('employee_profiles')->where('id', $employee)->first();
    expect($definition)->toContain('CREATE TABLE `employee_profiles`');

    try {
        $ddl = pdslschemaDdlDuring(static function () use ($definition): void {
            pdslschemaRollback();
            expect(pdslschemaCreateTable('employee_profiles'))->toBe($definition);
            pdslschemaRestore();
        });

        // employee_profiles is only the table the six keys name; no statement
        // creates, alters or drops it.
        expect($ddl)->not->toBe([]);
        foreach ($ddl as $statement) {
            expect(preg_match('/^\s*(create|alter|drop)\s+table\s+`?employee_profiles\b/i', $statement))->toBe(0);
        }
        expect(pdslschemaCreateTable('employee_profiles'))->toBe($definition)
            ->and(DB::table('employee_profiles')->where('id', $employee)->first())->toEqual($row);

        $after = DB::table('employee_profiles')->where('id', $employee)->first();
        expect($after?->spouse_name)->toBe('Maria S. Dela Cruz')
            ->and($after?->spouse_employment)->toBe('Teacher, DepEd')
            ->and($after?->college)->toBe('Southern Luzon State University')
            ->and($after?->college_course)->toBe('BS Accountancy')
            ->and($after?->college_year_graduated)->toBe('2012')
            ->and($after?->graduate_school)->toBe('University of the Philippines')
            ->and($after?->graduate_course)->toBe('Master in Public Administration')
            ->and($after?->graduate_year_graduated)->toBe('2018')
            ->and($after?->address)->toBe('Blk 3 Lot 7, Poblacion, Tayabas City')
            ->and($after?->citizenship)->toBe('Filipino');
        pdslschemaAssertApplied();
    } finally {
        pdslschemaRestore();
    }
});
