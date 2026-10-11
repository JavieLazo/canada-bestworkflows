<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * On the production engine, the two Personal Data Sheet tables
 * (2026_10_07_0001 `employee_pds_details`, 2026_10_07_0002
 * `employee_addresses`) are created with their CHECKs, which refuse a value
 * off their literal lists; each unique index is declared before its
 * cascading foreign key, so MariaDB reuses it and adds no index of its own;
 * both tables are ROW_FORMAT=DYNAMIC; re-running either migration sends no
 * DDL but that restatement, and a run killed between any two of its
 * statements resumes with exactly the missing piece. Creating and dropping
 * them leaves `employee_profiles` — its definition and its values, the
 * legacy one-line address and citizenship text included — byte-for-byte as
 * it was.
 *
 * DatabaseMigrations rolls the whole schema back after each test, and both
 * down() steps are guarded, so a test that leaves a table stripped still
 * tears down cleanly; each test that strips a piece puts it back anyway.
 */

/** @var list<string> */
const PDSSCHEMA_DETAIL_CHECKS = ['epds_citizenship_kind_chk', 'epds_dual_by_chk'];

/** @var array<string, string> column => its type */
const PDSSCHEMA_DETAIL_COLUMNS = [
    'id' => 'char(26)',
    'employee_id' => 'char(26)',
    'civil_status_other' => 'varchar(60)',
    'citizenship_kind' => 'varchar(10)',
    'dual_citizenship_by' => 'varchar(15)',
    'dual_citizenship_country' => 'varchar(60)',
    'permanent_address_same' => 'tinyint(1)',
    'umid_no' => 'varchar(40)',
    'philsys_pcn' => 'varchar(40)',
    'created_at' => 'datetime(3)',
    'updated_at' => 'datetime(3)',
];

/** @var array<string, string> column => its type */
const PDSSCHEMA_ADDRESS_COLUMNS = [
    'id' => 'char(26)',
    'employee_id' => 'char(26)',
    'type' => 'varchar(12)',
    'house_no' => 'varchar(60)',
    'street' => 'varchar(120)',
    'subdivision' => 'varchar(120)',
    'barangay' => 'varchar(120)',
    'city' => 'varchar(120)',
    'province' => 'varchar(120)',
    'zip_code' => 'varchar(10)',
    'created_at' => 'datetime(3)',
    'updated_at' => 'datetime(3)',
];

/** @var list<string> the columns that are NOT NULL; every other one is NULLable */
const PDSSCHEMA_REQUIRED = ['id', 'employee_id', 'type', 'created_at'];

/**
 * The family and declaration columns 2026_10_07_0003 appends to the details
 * table, in its order — the keys of EmployeePdsListTablesSchemaTest's
 * PDSLSCHEMA_DETAIL_ADDED, repeated because test files share one namespace.
 * That file checks their types.
 *
 * @var list<string>
 */
const PDSSCHEMA_DETAIL_ADDED_BY_0003 = [
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

/** The details table migration — an anonymous class, so untyped here. */
function pdsschemaDetails(): mixed
{
    return require base_path('database/migrations/2026_10_07_0001_create_employee_pds_details_table.php');
}

/** The addresses table migration. */
function pdsschemaAddresses(): mixed
{
    return require base_path('database/migrations/2026_10_07_0002_create_employee_addresses_table.php');
}

/** The family and declaration columns on the details table (2026_10_07_0003). */
function pdsschemaFamilyColumns(): mixed
{
    return require base_path('database/migrations/2026_10_07_0003_add_family_and_declarations_to_employee_pds_details_table.php');
}

/** An employee holding the legacy values; returns its id. */
function pdsschemaEmployee(): string
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'PDS-'.Str::upper(Str::random(6)),
        'first_name' => 'Rosa',
        'last_name' => 'Villanueva',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-15',
        'monthly_rate' => 30000,
        'address' => 'Blk 3 Lot 7, Poblacion, Tayabas City',
        'citizenship' => 'Filipino',
        'civil_status' => 'Married',
        'height_cm' => 162.5,
    ])->save();

    return (string) $employee->getKey();
}

/**
 * A details row written straight to the table, so only the database judges it.
 *
 * @param  array<string, string|bool|null>  $values
 */
function pdsschemaDetail(string $employee, array $values): string
{
    $id = (string) Str::ulid();
    DB::table('employee_pds_details')->insert(['id' => $id, 'employee_id' => $employee, 'created_at' => '2026-10-07 01:00:00.000'] + $values);

    return $id;
}

/** An address row written straight to the table. */
function pdsschemaAddress(string $employee, string $type): string
{
    $id = (string) Str::ulid();
    DB::table('employee_addresses')->insert([
        'id' => $id,
        'employee_id' => $employee,
        'type' => $type,
        'city' => 'Tayabas City',
        'zip_code' => '4327',
        'created_at' => '2026-10-07 01:00:00.000',
    ]);

    return $id;
}

function pdsschemaChecks(string $table, string $name): int
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
function pdsschemaShowIndex(string $table): array
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

function pdsschemaRowFormat(string $table): string
{
    $row = DB::selectOne('SELECT ROW_FORMAT AS row_format FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);

    return is_object($row) && isset($row->row_format) ? strtoupper((string) $row->row_format) : '';
}

/** The ON DELETE rule of the table's one foreign key, and the table it names. */
function pdsschemaForeignKey(string $table): string
{
    $row = DB::selectOne(
        'SELECT DELETE_RULE AS rule, REFERENCED_TABLE_NAME AS target FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table],
    );

    return is_object($row) && isset($row->rule, $row->target) ? strtoupper((string) $row->rule).' '.$row->target : '';
}

/**
 * Each column as information_schema describes it.
 *
 * @return array<string, array{type: string, nullable: string, default: mixed}>
 */
function pdsschemaColumns(string $table): array
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
function pdsschemaCreateTable(string $table): string
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
function pdsschemaDdlDuring(callable $callback): array
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

/** Both tables carry every piece their migrations declare. */
function pdsschemaAssertApplied(): void
{
    expect(pdsschemaShowIndex('employee_pds_details'))->toBe(['PRIMARY', 'epds_employee_unq'])
        ->and(pdsschemaShowIndex('employee_addresses'))->toBe(['PRIMARY', 'eaddr_employee_type_unq'])
        ->and(pdsschemaForeignKey('employee_pds_details'))->toBe('CASCADE employee_profiles')
        ->and(pdsschemaForeignKey('employee_addresses'))->toBe('CASCADE employee_profiles')
        ->and(pdsschemaChecks('employee_addresses', 'eaddr_type_chk'))->toBe(1)
        ->and(pdsschemaRowFormat('employee_pds_details'))->toBe('DYNAMIC')
        ->and(pdsschemaRowFormat('employee_addresses'))->toBe('DYNAMIC');
    foreach (PDSSCHEMA_DETAIL_CHECKS as $check) {
        expect(pdsschemaChecks('employee_pds_details', $check))->toBe(1);
    }
}

/*
|--------------------------------------------------------------------------
| Shape
|--------------------------------------------------------------------------
*/

it('creates the CHECKs on MariaDB and they refuse values off their lists', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $first = pdsschemaEmployee();
    $second = pdsschemaEmployee();
    $third = pdsschemaEmployee();

    expect(fn () => pdsschemaDetail($first, ['citizenship_kind' => 'X']))->toThrow(QueryException::class, 'epds_citizenship_kind_chk');
    expect(fn () => pdsschemaDetail($first, ['dual_citizenship_by' => 'X']))->toThrow(QueryException::class, 'epds_dual_by_chk');
    expect(fn () => pdsschemaAddress($first, 'HOME'))->toThrow(QueryException::class, 'eaddr_type_chk');
    expect(DB::table('employee_pds_details')->count())->toBe(0)
        ->and(DB::table('employee_addresses')->count())->toBe(0);

    // Every listed value lands, and so does NULL: every column is optional.
    $dual = pdsschemaDetail($first, ['citizenship_kind' => 'DUAL', 'dual_citizenship_by' => 'NATURALIZATION', 'dual_citizenship_country' => 'Japan']);
    pdsschemaDetail($second, ['citizenship_kind' => 'FILIPINO', 'permanent_address_same' => true]);
    pdsschemaDetail($third, []);
    DB::table('employee_pds_details')->where('id', $dual)->update(['dual_citizenship_by' => 'BIRTH']);
    pdsschemaAddress($first, 'RESIDENTIAL');
    $permanent = pdsschemaAddress($first, 'PERMANENT');

    // An UPDATE is held to the same lists.
    expect(fn () => DB::table('employee_pds_details')->where('id', $dual)->update(['citizenship_kind' => 'ALIEN']))
        ->toThrow(QueryException::class, 'epds_citizenship_kind_chk');
    expect(fn () => DB::table('employee_addresses')->where('id', $permanent)->update(['type' => 'HOME']))
        ->toThrow(QueryException::class, 'eaddr_type_chk');

    expect(DB::table('employee_pds_details')->where('id', $dual)->value('dual_citizenship_by'))->toBe('BIRTH')
        ->and(DB::table('employee_pds_details')->where('employee_id', $third)->value('permanent_address_same'))->toBeNull()
        ->and(DB::table('employee_addresses')->where('id', $permanent)->value('type'))->toBe('PERMANENT');

    // One details row and one address of each type per employee.
    expect(fn () => pdsschemaDetail($first, []))->toThrow(QueryException::class, 'epds_employee_unq');
    expect(fn () => pdsschemaAddress($first, 'PERMANENT'))->toThrow(QueryException::class, 'eaddr_employee_type_unq');
});

it('declares each column at its width, NULLable unless required, with no default', function () {
    foreach (['employee_pds_details' => PDSSCHEMA_DETAIL_COLUMNS, 'employee_addresses' => PDSSCHEMA_ADDRESS_COLUMNS] as $table => $expected) {
        $columns = pdsschemaColumns($table);
        // The details table's own columns come first; the only others are
        // the ones 2026_10_07_0003 appends after them, in its order.
        $appended = $table === 'employee_pds_details' ? PDSSCHEMA_DETAIL_ADDED_BY_0003 : [];
        expect(array_keys($columns))->toBe([...array_keys($expected), ...$appended]);
        foreach ($expected as $name => $type) {
            expect($columns[$name]['type'])->toBe($type)
                ->and($columns[$name]['nullable'])->toBe(in_array($name, PDSSCHEMA_REQUIRED, true) ? 'NO' : 'YES')
                // A missing row and a NULL read the same way, so no column
                // has a default (MariaDB prints a NULLable column's as 'NULL').
                ->and($columns[$name]['default'] === null || strtoupper((string) $columns[$name]['default']) === 'NULL')->toBeTrue();
        }
    }
});

it('reuses each unique index for its foreign key and sets ROW_FORMAT=DYNAMIC', function () {
    // SHOW INDEX lists the primary key and the declared unique, and no
    // `…_employee_id_foreign` index: the cascading key uses the unique.
    pdsschemaAssertApplied();

    // The keys cascade on the real engine: the rows go with their employee.
    $employee = pdsschemaEmployee();
    $other = pdsschemaEmployee();
    pdsschemaDetail($employee, ['umid_no' => '0111-2222333-4']);
    pdsschemaAddress($employee, 'RESIDENTIAL');
    pdsschemaAddress($employee, 'PERMANENT');
    $kept = pdsschemaDetail($other, ['umid_no' => '0555-6666777-8']);

    DB::table('employee_profiles')->where('id', $employee)->delete();

    expect(DB::table('employee_pds_details')->where('employee_id', $employee)->count())->toBe(0)
        ->and(DB::table('employee_addresses')->where('employee_id', $employee)->count())->toBe(0)
        ->and(DB::table('employee_pds_details')->where('id', $kept)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Retry-safety
|--------------------------------------------------------------------------
*/

it('runs up() twice and resumes after a stop between steps', function () {
    $details = pdsschemaDetails();
    $addresses = pdsschemaAddresses();
    $migrations = ['employee_pds_details' => $details, 'employee_addresses' => $addresses];
    $restated = static fn (string $table): string => "ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC";

    try {
        // Twice on top of the migrated schema: the restatement, nothing else.
        expect(pdsschemaDdlDuring(static function () use ($details, $addresses): void {
            $details->up();
            $details->up();
            $addresses->up();
            $addresses->up();
        }))->toBe([
            $restated('employee_pds_details'), $restated('employee_pds_details'),
            $restated('employee_addresses'), $restated('employee_addresses'),
        ]);
        pdsschemaAssertApplied();

        // Killed before a CHECK: only that CHECK is re-added.
        foreach ([['employee_pds_details', 'epds_citizenship_kind_chk'], ['employee_pds_details', 'epds_dual_by_chk'], ['employee_addresses', 'eaddr_type_chk']] as [$table, $check]) {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$check}`");
            expect(pdsschemaChecks($table, $check))->toBe(0);

            $migration = $migrations[$table];
            $ddl = pdsschemaDdlDuring(static fn () => $migration->up());

            expect($ddl)->toHaveCount(2)
                ->and($ddl[0])->toStartWith("ALTER TABLE `{$table}` ADD CONSTRAINT `{$check}` CHECK")
                ->and($ddl[1])->toBe($restated($table));
            pdsschemaAssertApplied();
        }

        // Killed before the foreign key: only the key is re-added, on the
        // unique that is already there.
        foreach ($migrations as $table => $migration) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$table}_employee_id_foreign`");
            expect(Schema::hasForeignKey($table, ['employee_id']))->toBeFalse();

            $ddl = pdsschemaDdlDuring(static fn () => $migration->up());

            expect($ddl)->toHaveCount(2)
                ->and(strtolower($ddl[0]))->toContain('foreign key (`employee_id`) references `employee_profiles`')
                ->and(strtolower($ddl[0]))->toContain('on delete cascade')
                ->and($ddl[1])->toBe($restated($table));
            pdsschemaAssertApplied();
        }

        // Killed after the CREATE TABLE: strip each table back to its columns
        // and primary key (the key first — MariaDB will not drop an index a
        // constraint uses); the retry adds the unique, then the key, then the
        // CHECKs, and never re-creates the table.
        $pieces = [
            'employee_pds_details' => ['epds_employee_unq', PDSSCHEMA_DETAIL_CHECKS],
            'employee_addresses' => ['eaddr_employee_type_unq', ['eaddr_type_chk']],
        ];
        foreach ($pieces as $table => [$unique, $checks]) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$table}_employee_id_foreign`");
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$unique}`");
            foreach ($checks as $check) {
                DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$check}`");
            }
            expect(pdsschemaShowIndex($table))->toBe(['PRIMARY']);

            $migration = $migrations[$table];
            $ddl = pdsschemaDdlDuring(static fn () => $migration->up());

            expect($ddl)->toHaveCount(3 + count($checks))
                ->and(strtolower($ddl[0]))->toContain("add unique `{$unique}`")
                ->and(strtolower($ddl[1]))->toContain('foreign key (`employee_id`) references `employee_profiles`');
            foreach ($checks as $i => $check) {
                expect($ddl[2 + $i])->toStartWith("ALTER TABLE `{$table}` ADD CONSTRAINT `{$check}` CHECK");
            }
            expect($ddl[2 + count($checks)])->toBe($restated($table));
        }
        pdsschemaAssertApplied();
    } finally {
        $details->up();
        // 2026_10_07_0003's columns go with the details table's down().
        pdsschemaFamilyColumns()->up();
        $addresses->up();
    }
});

it('leaves employee_profiles byte-for-byte as it was', function () {
    $employee = pdsschemaEmployee();
    $details = pdsschemaDetails();
    $addresses = pdsschemaAddresses();
    $definition = pdsschemaCreateTable('employee_profiles');
    $row = DB::table('employee_profiles')->where('id', $employee)->first();
    expect($definition)->toContain('CREATE TABLE `employee_profiles`');

    try {
        $ddl = pdsschemaDdlDuring(static function () use ($details, $addresses, $definition): void {
            $addresses->down();
            $details->down();
            expect(pdsschemaCreateTable('employee_profiles'))->toBe($definition);
            $details->up();
            $addresses->up();
        });

        // employee_profiles is only the table the two keys name; no statement
        // creates, alters or drops it.
        foreach ($ddl as $statement) {
            expect(preg_match('/^\s*(create|alter|drop)\s+table\s+`?employee_profiles\b/i', $statement))->toBe(0);
        }
        expect(pdsschemaCreateTable('employee_profiles'))->toBe($definition)
            ->and(DB::table('employee_profiles')->where('id', $employee)->first())->toEqual($row);

        $after = DB::table('employee_profiles')->where('id', $employee)->first();
        expect($after?->address)->toBe('Blk 3 Lot 7, Poblacion, Tayabas City')
            ->and($after?->citizenship)->toBe('Filipino');
        pdsschemaAssertApplied();
    } finally {
        $details->up();
        // 2026_10_07_0003's columns go with the details table's down().
        pdsschemaFamilyColumns()->up();
        $addresses->up();
    }
});
