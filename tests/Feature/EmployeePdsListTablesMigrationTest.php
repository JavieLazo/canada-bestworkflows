<?php

declare(strict_types=1);

use App\Models\EmployeeEducation;
use App\Models\EmployeeEligibility;
use App\Models\EmployeeOtherItem;
use App\Models\EmployeePdsDetail;
use App\Models\EmployeeProfile;
use App\Models\EmployeeTraining;
use App\Models\EmployeeVoluntaryWork;
use App\Models\EmployeeWorkExperience;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The family and declaration columns (2026_10_07_0003, appended to
 * `employee_pds_details`) and the six Personal Data Sheet list tables
 * (2026_10_07_0004–0009: `employee_education`, `employee_eligibilities`,
 * `employee_work_experiences`, `employee_trainings`,
 * `employee_voluntary_works`, `employee_other_items`) are added once,
 * retry-safely: a repeated up() — the cPanel cron re-running a half-applied
 * deploy — sends no DDL but the row-format restatement, a column run killed
 * part way resumes at the first missing column, and a table run that stopped
 * after the create gets its index and foreign key on the retry. None of it
 * changes an `employee_profiles` column, index or value: the legacy spouse,
 * college, graduate-school, address and citizenship values stay exactly as
 * they are, and the details row keeps its own values when the added columns
 * come and go. Every list row goes with its employee, and an other-info line
 * holds one position of its list.
 *
 * THIS FILE ALWAYS ENDS MIGRATED, pass or fail: each `finally` re-runs every
 * up() of 0003–0009 in order before anything else, then deletes the
 * employees seeded before its DDL by key. On MySQL/MariaDB DDL commits
 * implicitly, so a down() ends RefreshDatabase's transaction, which can then
 * roll back neither the schema nor those rows (the
 * EmployeePdsTablesMigrationTest pattern). This file also runs under
 * phpunit.mariadb.xml, where that cleanup is what keeps the next test clean;
 * each employee code carries a random suffix for the same reason.
 */

/** The migration that appends the family and declaration columns. */
const PDSLMIG_COLUMNS_FILE = '2026_10_07_0003_add_family_and_declarations_to_employee_pds_details_table';

/** @var array<string, array{0: string, 1: string}> list-table migration => [its table, its index] */
const PDSLMIG_LISTS = [
    '2026_10_07_0004_create_employee_education_table' => ['employee_education', 'eedu_employee_level_idx'],
    '2026_10_07_0005_create_employee_eligibilities_table' => ['employee_eligibilities', 'eelig_employee_idx'],
    '2026_10_07_0006_create_employee_work_experiences_table' => ['employee_work_experiences', 'ewexp_employee_idx'],
    '2026_10_07_0007_create_employee_trainings_table' => ['employee_trainings', 'etrain_employee_idx'],
    '2026_10_07_0008_create_employee_voluntary_works_table' => ['employee_voluntary_works', 'evol_employee_idx'],
    '2026_10_07_0009_create_employee_other_items_table' => ['employee_other_items', 'eoth_employee_kind_position_unq'],
];

/** @var list<string> the 41 columns 0003 appends, in its order */
const PDSLMIG_COLUMNS = [
    'spouse_surname', 'spouse_first_name', 'spouse_middle_name', 'spouse_name_extension',
    'spouse_occupation', 'spouse_employer', 'spouse_business_address', 'spouse_telephone',
    'father_surname', 'father_first_name', 'father_middle_name', 'father_name_extension',
    'mother_surname', 'mother_first_name', 'mother_middle_name',
    'decl_34a', 'decl_34a_details', 'decl_34b', 'decl_34b_details',
    'decl_35a', 'decl_35a_details', 'decl_35b', 'decl_35b_details', 'decl_35b_date_filed', 'decl_35b_case_status',
    'decl_36', 'decl_36_details', 'decl_37', 'decl_37_details',
    'decl_38a', 'decl_38a_details', 'decl_38b', 'decl_38b_details',
    'decl_39', 'decl_39_country',
    'decl_40a', 'decl_40a_specify', 'decl_40b', 'decl_40b_id_no', 'decl_40c', 'decl_40c_id_no',
];

/** @var list<string> the legacy `employee_profiles` columns no PDS form writes */
const PDSLMIG_LEGACY = [
    'address', 'citizenship', 'spouse_name', 'spouse_employment',
    'college', 'college_course', 'college_year_graduated',
    'graduate_school', 'graduate_course', 'graduate_year_graduated',
];

/** One of the seven migrations — an anonymous class, so untyped here. */
function pdslmigMigration(string $file): mixed
{
    return require base_path("database/migrations/{$file}.php");
}

/** Every up() of 0003–0009, in order: what each `finally` puts back. */
function pdslmigRestore(): void
{
    pdslmigMigration(PDSLMIG_COLUMNS_FILE)->up();
    foreach (array_keys(PDSLMIG_LISTS) as $file) {
        pdslmigMigration($file)->up();
    }
}

/** Every down() of 0009–0003, newest first, as a rollback runs them. */
function pdslmigRollback(): void
{
    foreach (array_reverse(array_keys(PDSLMIG_LISTS)) as $file) {
        pdslmigMigration($file)->down();
    }
    pdslmigMigration(PDSLMIG_COLUMNS_FILE)->down();
}

/** An employee holding every legacy value the seven migrations must never touch. */
function pdslmigEmployee(string $suffix = '1'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'PDL-'.$suffix.'-'.random_int(10000, 99999),
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

    return $employee;
}

/** One row in each list table and one line of each other-info kind, saved through the models. */
function pdslmigListRows(EmployeeProfile $employee): void
{
    (new EmployeeEducation)->fill([
        'employee_id' => $employee->id, 'level' => EmployeeEducation::COLLEGE, 'school' => 'Southern Luzon State University',
        'course' => 'BS Accountancy', 'attended_from' => 2008, 'attended_to' => 2012, 'year_graduated' => 2012,
    ])->save();
    (new EmployeeEligibility)->fill([
        'employee_id' => $employee->id, 'name' => 'Career Service Professional', 'rating' => '84.52',
        'exam_date' => '2018-08-12', 'exam_place' => 'Lucena City',
    ])->save();
    (new EmployeeWorkExperience)->fill([
        'employee_id' => $employee->id, 'position_title' => 'Accounting Clerk', 'company' => 'Quezon Rural Bank',
        'date_from' => '2012-06-01', 'date_to' => '2015-05-31', 'monthly_salary' => 15000.5, 'government_service' => false,
    ])->save();
    (new EmployeeTraining)->fill([
        'employee_id' => $employee->id, 'title' => 'Basic Bookkeeping', 'date_from' => '2016-02-01',
        'date_to' => '2016-02-03', 'hours' => 24.5,
    ])->save();
    (new EmployeeVoluntaryWork)->fill([
        'employee_id' => $employee->id, 'organization' => 'Red Cross Quezon Chapter', 'hours' => 40,
    ])->save();
    foreach ([EmployeeOtherItem::SKILL, EmployeeOtherItem::DISTINCTION, EmployeeOtherItem::MEMBERSHIP] as $kind) {
        (new EmployeeOtherItem)->fill(['employee_id' => $employee->id, 'kind' => $kind, 'position' => 0, 'text' => $kind.' line'])->save();
    }
}

/**
 * How many rows each list table holds for the employee.
 *
 * @return array<string, int>
 */
function pdslmigRowCounts(string $employeeId): array
{
    $counts = [];
    foreach (PDSLMIG_LISTS as [$table]) {
        $counts[$table] = DB::table($table)->where('employee_id', $employeeId)->count();
    }

    return $counts;
}

/**
 * What the first statement of a list table's up() leaves: its columns and
 * primary key, no index, no foreign key.
 */
function pdslmigBareCreate(string $table): void
{
    Schema::create($table, function (Blueprint $t) use ($table): void {
        $t->char('id', 26)->primary();
        $t->char('employee_id', 26);
        match ($table) {
            'employee_education' => (function () use ($t): void {
                $t->string('level', 12);
                $t->string('school', 150);
                $t->string('course', 150)->nullable();
                $t->unsignedSmallInteger('attended_from')->nullable();
                $t->unsignedSmallInteger('attended_to')->nullable();
                $t->string('units_earned', 60)->nullable();
                $t->unsignedSmallInteger('year_graduated')->nullable();
                $t->string('honors', 150)->nullable();
            })(),
            'employee_eligibilities' => (function () use ($t): void {
                $t->string('name', 255);
                $t->string('rating', 20)->nullable();
                $t->date('exam_date')->nullable();
                $t->string('exam_place', 150)->nullable();
                $t->string('license_no', 40)->nullable();
                $t->date('license_valid_until')->nullable();
            })(),
            'employee_work_experiences' => (function () use ($t): void {
                $t->string('position_title', 150);
                $t->string('company', 150);
                $t->date('date_from');
                $t->date('date_to');
                $t->decimal('monthly_salary', 12, 2)->nullable();
                $t->string('pay_grade', 20)->nullable();
                $t->string('appointment_status', 60)->nullable();
                $t->boolean('government_service')->nullable();
            })(),
            'employee_trainings' => (function () use ($t): void {
                $t->string('title', 255);
                $t->date('date_from');
                $t->date('date_to');
                $t->decimal('hours', 6, 2)->nullable();
                $t->string('ld_type', 60)->nullable();
                $t->string('sponsor', 255)->nullable();
            })(),
            'employee_voluntary_works' => (function () use ($t): void {
                $t->string('organization', 150);
                $t->string('organization_address', 255)->nullable();
                $t->date('date_from')->nullable();
                $t->date('date_to')->nullable();
                $t->decimal('hours', 6, 2)->nullable();
                $t->string('position', 150)->nullable();
            })(),
            'employee_other_items' => (function () use ($t): void {
                $t->string('kind', 12);
                $t->unsignedTinyInteger('position');
                $t->string('text', 255);
            })(),
            default => throw new InvalidArgumentException("No list table is called {$table}."),
        };
        $t->dateTime('created_at', 3);
        $t->dateTime('updated_at', 3)->nullable();
    });
}

/**
 * Removes the seeded employees by key where DDL has already committed them;
 * their details and list rows go with them through the cascading keys.
 *
 * @param  list<EmployeeProfile>  $employees
 */
function pdslmigCleanup(array $employees): void
{
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        return;
    }
    foreach ($employees as $employee) {
        EmployeeProfile::query()->whereKey($employee->getKey())->delete();
    }
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function pdslmigDdlDuring(callable $callback): array
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

/**
 * What the schema says about `employee_profiles`: every column with its type,
 * nullability and default, every index and every foreign key.
 *
 * @return array{columns: list<array<string, mixed>>, indexes: list<array<string, mixed>>, foreign: list<array<string, mixed>>}
 */
function pdslmigProfileSchema(): array
{
    return [
        'columns' => array_values(Schema::getColumns('employee_profiles')),
        'indexes' => array_values(Schema::getIndexes('employee_profiles')),
        'foreign' => array_values(Schema::getForeignKeys('employee_profiles')),
    ];
}

/**
 * The named index of the table as the schema lists it, or null.
 *
 * @return array<string, mixed>|null
 */
function pdslmigIndex(string $table, string $name): ?array
{
    foreach (Schema::getIndexes($table) as $index) {
        if ($index['name'] === $name) {
            return $index;
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Retry-safety — the family and declaration columns
|--------------------------------------------------------------------------
*/

it('adds every family and declaration column once, and a repeated run sends no DDL', function () {
    $columns = pdslmigMigration(PDSLMIG_COLUMNS_FILE);

    try {
        // migrate:fresh already ran it: the 41 columns sit at the end of the
        // table, in the migration's order, after the details row's own.
        $listing = Schema::getColumnListing('employee_pds_details');
        expect(array_slice($listing, -41))->toBe(PDSLMIG_COLUMNS)
            ->and(array_slice($listing, 0, -41))->toBe([
                'id', 'employee_id', 'civil_status_other', 'citizenship_kind', 'dual_citizenship_by',
                'dual_citizenship_country', 'permanent_address_same', 'umid_no', 'philsys_pcn', 'created_at', 'updated_at',
            ]);

        // Every column is behind its own hasColumn() guard: nothing at all.
        expect(pdslmigDdlDuring(static fn () => $columns->up()))->toBe([]);
    } finally {
        pdslmigRestore();
    }
});

it('resumes a column run that stopped part way', function () {
    $columns = pdslmigMigration(PDSLMIG_COLUMNS_FILE);
    $missing = array_slice(PDSLMIG_COLUMNS, -20);

    try {
        // A run killed after the 21st column: the last 20 are not there yet.
        foreach (array_reverse($missing) as $column) {
            Schema::table('employee_pds_details', function (Blueprint $t) use ($column): void {
                $t->dropColumn($column);
            });
        }
        foreach ($missing as $column) {
            expect(Schema::hasColumn('employee_pds_details', $column))->toBeFalse();
        }
        expect(array_slice(Schema::getColumnListing('employee_pds_details'), -21))->toBe(array_slice(PDSLMIG_COLUMNS, 0, 21));

        $ddl = pdslmigDdlDuring(static fn () => $columns->up());

        // Exactly one ALTER per missing column, none for the 21 already there.
        expect($ddl)->toHaveCount(20);
        foreach ($ddl as $i => $statement) {
            expect(preg_match('/^alter table [`"]employee_pds_details[`"] add /i', $statement))->toBe(1)
                ->and($statement)->toContain($missing[$i]);
        }
        expect(array_slice(Schema::getColumnListing('employee_pds_details'), -41))->toBe(PDSLMIG_COLUMNS);
    } finally {
        pdslmigRestore();
    }
});

/*
|--------------------------------------------------------------------------
| Retry-safety — the list tables
|--------------------------------------------------------------------------
*/

it('creates each list table once with its index and foreign key, and a repeated run sends no DDL but the row-format restatement', /** @param list<string> $indexColumns */ function (string $file, string $table, string $name, array $indexColumns, bool $unique) {
    $migration = pdslmigMigration($file);

    try {
        // migrate:fresh already ran it through the migrator.
        $index = pdslmigIndex($table, $name);
        expect(Schema::hasTable($table))->toBeTrue()
            ->and($index)->not->toBeNull()
            ->and($index['columns'] ?? null)->toBe($indexColumns)
            ->and($index['unique'] ?? null)->toBe($unique)
            ->and(Schema::hasForeignKey($table, ['employee_id']))->toBeTrue();

        $foreign = array_values(Schema::getForeignKeys($table));
        expect($foreign)->toHaveCount(1)
            ->and($foreign[0]['foreign_table'] ?? null)->toBe('employee_profiles')
            ->and(strtolower((string) ($foreign[0]['on_delete'] ?? '')))->toBe('cascade');

        // sqlite: nothing at all. MySQL/MariaDB: only the unguarded,
        // idempotent ROW_FORMAT restatement; the create, the index, the
        // foreign key and any CHECK stay behind their own checks.
        $allowed = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? ["ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC"]
            : [];
        expect(pdslmigDdlDuring(static fn () => $migration->up()))->toBe($allowed);
    } finally {
        pdslmigRestore();
    }
})->with([
    'education' => ['2026_10_07_0004_create_employee_education_table', 'employee_education', 'eedu_employee_level_idx', ['employee_id', 'level'], false],
    'eligibilities' => ['2026_10_07_0005_create_employee_eligibilities_table', 'employee_eligibilities', 'eelig_employee_idx', ['employee_id'], false],
    'work experiences' => ['2026_10_07_0006_create_employee_work_experiences_table', 'employee_work_experiences', 'ewexp_employee_idx', ['employee_id'], false],
    'trainings' => ['2026_10_07_0007_create_employee_trainings_table', 'employee_trainings', 'etrain_employee_idx', ['employee_id'], false],
    'voluntary works' => ['2026_10_07_0008_create_employee_voluntary_works_table', 'employee_voluntary_works', 'evol_employee_idx', ['employee_id'], false],
    'other items' => ['2026_10_07_0009_create_employee_other_items_table', 'employee_other_items', 'eoth_employee_kind_position_unq', ['employee_id', 'kind', 'position'], true],
]);

it('finishes a list table run that stopped after the create', function (string $file) {
    [$table, $name] = PDSLMIG_LISTS[$file];
    $migration = pdslmigMigration($file);

    try {
        $migration->down();
        pdslmigBareCreate($table);
        expect(Schema::hasIndex($table, $name))->toBeFalse()
            ->and(Schema::hasForeignKey($table, ['employee_id']))->toBeFalse();

        $ddl = pdslmigDdlDuring(static fn () => $migration->up());

        // The index comes first, so MySQL/MariaDB reuse it for the key; the
        // retry never re-creates the table (on sqlite the key is a rebuild
        // through a temporary table, never a create of this one).
        expect($ddl)->not->toBe([])
            ->and($ddl[0])->toContain($name);
        foreach ($ddl as $statement) {
            expect(strtolower($statement))->not->toStartWith('create table "'.$table.'"')
                ->not->toStartWith('create table `'.$table.'`');
        }

        expect(Schema::hasIndex($table, $name))->toBeTrue()
            ->and(Schema::hasForeignKey($table, ['employee_id']))->toBeTrue();
    } finally {
        pdslmigRestore();
    }
})->with(array_keys(PDSLMIG_LISTS));

/*
|--------------------------------------------------------------------------
| Existing data
|--------------------------------------------------------------------------
*/

it('changes no existing employee_profiles column or value', function () {
    $employee = pdslmigEmployee();
    $schema = pdslmigProfileSchema();
    $listing = Schema::getColumnListing('employee_profiles');
    $row = DB::table('employee_profiles')->where('id', $employee->id)->first();
    $count = DB::table('employee_profiles')->count();

    try {
        $ddl = pdslmigDdlDuring(static function (): void {
            pdslmigRollback();
            pdslmigRestore();
        });

        // Not one statement creates, alters or drops employee_profiles itself;
        // it is only the table the six foreign keys name.
        expect($ddl)->not->toBe([]);
        foreach ($ddl as $statement) {
            expect(preg_match('/^\s*(create|alter|drop)\s+table\s+[`"]?employee_profiles\b/i', $statement))->toBe(0);
        }

        expect(pdslmigProfileSchema())->toEqual($schema)
            ->and(Schema::getColumnListing('employee_profiles'))->toBe($listing)
            ->and(DB::table('employee_profiles')->where('id', $employee->id)->first())->toEqual($row)
            ->and(DB::table('employee_profiles')->count())->toBe($count);

        $after = (array) DB::table('employee_profiles')->where('id', $employee->id)->first(PDSLMIG_LEGACY);
        expect($after)->toBe([
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
        ]);
    } finally {
        pdslmigRestore();
        pdslmigCleanup([$employee]);
    }
});

it('keeps the details row and its own values when the family and declaration columns are dropped and re-added', function () {
    $employee = pdslmigEmployee();
    $columns = pdslmigMigration(PDSLMIG_COLUMNS_FILE);
    $detail = new EmployeePdsDetail;
    $detail->fill([
        'employee_id' => $employee->id,
        'civil_status_other' => 'Separated in fact',
        'citizenship_kind' => 'DUAL',
        'dual_citizenship_by' => 'BIRTH',
        'dual_citizenship_country' => 'Japan',
        'permanent_address_same' => false,
        'umid_no' => '0111-2222333-4',
        'philsys_pcn' => '1234-5678-9012-3456',
        'spouse_surname' => 'Dela Cruz',
        'father_first_name' => 'Pedro',
        'decl_34a' => true,
        'decl_35b' => false,
        'decl_35b_date_filed' => '2020-03-04',
    ])->save();
    $own = ['id', 'employee_id', 'civil_status_other', 'citizenship_kind', 'dual_citizenship_by', 'dual_citizenship_country', 'permanent_address_same', 'umid_no', 'philsys_pcn', 'created_at'];
    $before = (array) DB::table('employee_pds_details')->where('id', $detail->id)->first($own);

    try {
        $columns->down();
        foreach (PDSLMIG_COLUMNS as $column) {
            expect(Schema::hasColumn('employee_pds_details', $column))->toBeFalse();
        }
        expect((array) DB::table('employee_pds_details')->where('id', $detail->id)->first($own))->toBe($before);

        $columns->up();

        // The same row, its own values untouched; ROLLBACK LOSES what was
        // typed into the added columns, which come back NULL.
        expect((array) DB::table('employee_pds_details')->where('id', $detail->id)->first($own))->toBe($before)
            ->and(DB::table('employee_pds_details')->count())->toBe(1);
        $added = (array) DB::table('employee_pds_details')->where('id', $detail->id)->first(PDSLMIG_COLUMNS);
        expect(array_filter($added, static fn (mixed $value): bool => $value !== null))->toBe([]);

        $reloaded = EmployeePdsDetail::query()->findOrFail($detail->id);
        expect($reloaded->decl_34a)->toBeNull()
            ->and($reloaded->decl_35b)->toBeNull()
            ->and($reloaded->spouse_surname)->toBeNull()
            ->and($reloaded->permanent_address_same)->toBeFalse()
            ->and($reloaded->umid_no)->toBe('0111-2222333-4');
    } finally {
        pdslmigRestore();
        pdslmigCleanup([$employee]);
    }
});

/*
|--------------------------------------------------------------------------
| Keys
|--------------------------------------------------------------------------
*/

it('removes every list row and other item with their employee', function () {
    $employee = pdslmigEmployee();
    $other = pdslmigEmployee('2');
    pdslmigListRows($employee);
    pdslmigListRows($other);
    $kept = pdslmigRowCounts($other->id);
    expect(pdslmigRowCounts($employee->id))->toBe([
        'employee_education' => 1,
        'employee_eligibilities' => 1,
        'employee_work_experiences' => 1,
        'employee_trainings' => 1,
        'employee_voluntary_works' => 1,
        'employee_other_items' => 3,
    ]);

    EmployeeProfile::query()->whereKey($employee->id)->delete();

    expect(array_sum(pdslmigRowCounts($employee->id)))->toBe(0)
        ->and(pdslmigRowCounts($other->id))->toBe($kept);
});

it('refuses a second other item at the same position of the same kind for the same employee', function () {
    $employee = pdslmigEmployee();
    $other = pdslmigEmployee('2');
    $line = static function (EmployeeProfile $who, string $kind, int $position, string $text): void {
        (new EmployeeOtherItem)->fill(['employee_id' => $who->id, 'kind' => $kind, 'position' => $position, 'text' => $text])->save();
    };

    $line($employee, EmployeeOtherItem::SKILL, 0, 'Bookkeeping');
    expect(fn () => $line($employee, EmployeeOtherItem::SKILL, 0, 'Singing'))->toThrow(QueryException::class);

    // One line per position of each list, per employee — not per table.
    $line($employee, EmployeeOtherItem::SKILL, 1, 'Singing');
    $line($employee, EmployeeOtherItem::DISTINCTION, 0, 'Outstanding Employee 2020');
    $line($other, EmployeeOtherItem::SKILL, 0, 'Bookkeeping');

    expect(EmployeeOtherItem::query()->where('employee_id', $employee->id)->where('kind', EmployeeOtherItem::SKILL)->orderBy('position')->pluck('text')->all())
        ->toBe(['Bookkeeping', 'Singing'])
        ->and(EmployeeOtherItem::query()->count())->toBe(4);
});

/*
|--------------------------------------------------------------------------
| Rollback
|--------------------------------------------------------------------------
*/

it('drops every table and column on rollback and tolerates a second rollback', function () {
    $employee = pdslmigEmployee();
    pdslmigListRows($employee);
    $detail = new EmployeePdsDetail;
    $detail->fill(['employee_id' => $employee->id, 'umid_no' => '0111-2222333-4', 'spouse_surname' => 'Dela Cruz', 'decl_36' => true])->save();

    try {
        pdslmigRollback();
        foreach (PDSLMIG_LISTS as [$table]) {
            expect(Schema::hasTable($table))->toBeFalse();
        }
        foreach (PDSLMIG_COLUMNS as $column) {
            expect(Schema::hasColumn('employee_pds_details', $column))->toBeFalse();
        }

        // A rollback killed after its drops and re-run finds nothing to drop.
        expect(pdslmigDdlDuring(static fn () => pdslmigRollback()))->toBe([]);

        // Back empty: ROLLBACK LOSES what was typed into the lists and the
        // added columns; the employee and the details row are still there.
        pdslmigRestore();
        expect(array_sum(pdslmigRowCounts($employee->id)))->toBe(0)
            ->and(EmployeeProfile::query()->whereKey($employee->id)->exists())->toBeTrue()
            ->and(EmployeePdsDetail::query()->whereKey($detail->id)->value('umid_no'))->toBe('0111-2222333-4')
            ->and(EmployeePdsDetail::query()->whereKey($detail->id)->value('spouse_surname'))->toBeNull()
            ->and(EmployeePdsDetail::query()->whereKey($detail->id)->value('decl_36'))->toBeNull();
    } finally {
        pdslmigRestore();
        pdslmigCleanup([$employee]);
    }
});
