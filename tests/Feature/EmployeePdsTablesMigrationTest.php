<?php

declare(strict_types=1);

use App\Models\EmployeeAddress;
use App\Models\EmployeePdsDetail;
use App\Models\EmployeeProfile;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The two Personal Data Sheet tables (2026_10_07_0001 `employee_pds_details`,
 * 2026_10_07_0002 `employee_addresses`) are created once, retry-safely: a
 * repeated up() — the cPanel cron re-running a half-applied deploy — sends no
 * DDL but the row-format restatement, a run that stopped after the create
 * gets its unique and foreign key on the retry, and a repeated down() finds
 * nothing to drop. Creating or dropping them changes no existing
 * `employee_profiles` column, index or value: the legacy one-line address and
 * citizenship text stay exactly as they are. Each table holds at most one
 * details row and one address of each type per employee, and its rows go
 * with their employee.
 *
 * THIS FILE ALWAYS ENDS MIGRATED, pass or fail: each `finally` re-runs up()
 * before anything else — the details table's, then 2026_10_07_0003's, whose
 * family and declaration columns the details table's down() drops with the
 * table — then deletes the employees seeded before its DDL by key. On
 * MySQL/MariaDB DDL commits implicitly, so a down() ends RefreshDatabase's
 * transaction, which can then roll back neither the schema nor those rows
 * (the LeaveCreditTableMigrationTest pattern). This file also runs under
 * phpunit.mariadb.xml, where that cleanup is what keeps the next test clean;
 * each employee code carries a random suffix for the same reason.
 */

/** The details table migration — an anonymous class, so untyped here. */
function pdsmigDetails(): mixed
{
    return require base_path('database/migrations/2026_10_07_0001_create_employee_pds_details_table.php');
}

/** The addresses table migration. */
function pdsmigAddresses(): mixed
{
    return require base_path('database/migrations/2026_10_07_0002_create_employee_addresses_table.php');
}

/** The family and declaration columns on the details table (2026_10_07_0003). */
function pdsmigFamilyColumns(): mixed
{
    return require base_path('database/migrations/2026_10_07_0003_add_family_and_declarations_to_employee_pds_details_table.php');
}

/** An employee holding the legacy values the two tables must never touch. */
function pdsmigEmployee(string $suffix = '1'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'PDS-'.$suffix.'-'.random_int(10000, 99999),
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

    return $employee;
}

/** A details row saved through the model, so BaseModel mints its id. */
function pdsmigDetailRow(EmployeeProfile $employee, string $umid): EmployeePdsDetail
{
    $row = new EmployeePdsDetail;
    $row->fill(['employee_id' => $employee->id, 'citizenship_kind' => 'FILIPINO', 'umid_no' => $umid])->save();

    return $row;
}

/** An address row saved through the model. */
function pdsmigAddressRow(EmployeeProfile $employee, string $type, string $city): EmployeeAddress
{
    $row = new EmployeeAddress;
    $row->fill(['employee_id' => $employee->id, 'type' => $type, 'city' => $city, 'zip_code' => '4327'])->save();

    return $row;
}

/**
 * Removes the seeded employees by key where DDL has already committed them;
 * their details and address rows go with them through the cascading keys.
 *
 * @param  list<EmployeeProfile>  $employees
 */
function pdsmigCleanup(array $employees): void
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
function pdsmigDdlDuring(callable $callback): array
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
function pdsmigProfileSchema(): array
{
    return [
        'columns' => array_values(Schema::getColumns('employee_profiles')),
        'indexes' => array_values(Schema::getIndexes('employee_profiles')),
        'foreign' => array_values(Schema::getForeignKeys('employee_profiles')),
    ];
}

/*
|--------------------------------------------------------------------------
| Retry-safety
|--------------------------------------------------------------------------
*/

it('creates both tables once, and a repeated run sends no DDL but the row-format restatement', function () {
    $details = pdsmigDetails();
    $addresses = pdsmigAddresses();

    try {
        // migrate:fresh already ran both through the migrator.
        expect(Schema::hasTable('employee_pds_details'))->toBeTrue()
            ->and(Schema::hasTable('employee_addresses'))->toBeTrue()
            ->and(Schema::hasIndex('employee_pds_details', 'epds_employee_unq'))->toBeTrue()
            ->and(Schema::hasIndex('employee_addresses', 'eaddr_employee_type_unq'))->toBeTrue()
            ->and(Schema::hasForeignKey('employee_pds_details', ['employee_id']))->toBeTrue()
            ->and(Schema::hasForeignKey('employee_addresses', ['employee_id']))->toBeTrue();

        // sqlite: nothing at all. MySQL/MariaDB: only each table's unguarded,
        // idempotent ROW_FORMAT restatement (the docblocks say it needs no
        // guard); the create, the unique, the foreign key and every CHECK
        // stay behind their own checks.
        $allowed = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? ['ALTER TABLE `employee_pds_details` ROW_FORMAT=DYNAMIC', 'ALTER TABLE `employee_addresses` ROW_FORMAT=DYNAMIC']
            : [];
        expect(pdsmigDdlDuring(static function () use ($details, $addresses): void {
            $details->up();
            $addresses->up();
        }))->toBe($allowed);
    } finally {
        $details->up();
        pdsmigFamilyColumns()->up();
        $addresses->up();
    }
});

it('finishes a run that stopped after the create: the unique and the foreign key arrive on the retry', function () {
    $details = pdsmigDetails();
    $addresses = pdsmigAddresses();

    try {
        $addresses->down();
        $details->down();

        // What the first statement of each up() leaves: columns and primary
        // key, no unique, no foreign key.
        Schema::create('employee_pds_details', function (Blueprint $t): void {
            $t->char('id', 26)->primary();
            $t->char('employee_id', 26);
            $t->string('civil_status_other', 60)->nullable();
            $t->string('citizenship_kind', 10)->nullable();
            $t->string('dual_citizenship_by', 15)->nullable();
            $t->string('dual_citizenship_country', 60)->nullable();
            $t->boolean('permanent_address_same')->nullable();
            $t->string('umid_no', 40)->nullable();
            $t->string('philsys_pcn', 40)->nullable();
            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3)->nullable();
        });
        Schema::create('employee_addresses', function (Blueprint $t): void {
            $t->char('id', 26)->primary();
            $t->char('employee_id', 26);
            $t->string('type', 12);
            $t->string('house_no', 60)->nullable();
            $t->string('street', 120)->nullable();
            $t->string('subdivision', 120)->nullable();
            $t->string('barangay', 120)->nullable();
            $t->string('city', 120)->nullable();
            $t->string('province', 120)->nullable();
            $t->string('zip_code', 10)->nullable();
            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3)->nullable();
        });
        expect(Schema::hasIndex('employee_pds_details', 'epds_employee_unq'))->toBeFalse()
            ->and(Schema::hasForeignKey('employee_pds_details', ['employee_id']))->toBeFalse()
            ->and(Schema::hasIndex('employee_addresses', 'eaddr_employee_type_unq'))->toBeFalse()
            ->and(Schema::hasForeignKey('employee_addresses', ['employee_id']))->toBeFalse();

        foreach ([[$details, 'employee_pds_details'], [$addresses, 'employee_addresses']] as [$migration, $table]) {
            $ddl = pdsmigDdlDuring(static fn () => $migration->up());

            // The retry never re-creates the table: on sqlite the foreign key
            // is a rebuild through a temporary table, never a create of this one.
            expect($ddl)->not->toBe([]);
            foreach ($ddl as $statement) {
                expect(strtolower($statement))->not->toStartWith('create table "'.$table.'"')
                    ->not->toStartWith('create table `'.$table.'`');
            }
        }

        expect(Schema::hasIndex('employee_pds_details', 'epds_employee_unq'))->toBeTrue()
            ->and(Schema::hasForeignKey('employee_pds_details', ['employee_id']))->toBeTrue()
            ->and(Schema::hasIndex('employee_addresses', 'eaddr_employee_type_unq'))->toBeTrue()
            ->and(Schema::hasForeignKey('employee_addresses', ['employee_id']))->toBeTrue();
    } finally {
        $details->up();
        pdsmigFamilyColumns()->up();
        $addresses->up();
    }
});

it('drops both tables on rollback and tolerates a second rollback', function () {
    $details = pdsmigDetails();
    $addresses = pdsmigAddresses();
    $employee = pdsmigEmployee();
    pdsmigDetailRow($employee, '0111-2222333-4');
    pdsmigAddressRow($employee, EmployeeAddress::RESIDENTIAL, 'Tayabas City');

    try {
        $addresses->down();
        $details->down();
        expect(Schema::hasTable('employee_pds_details'))->toBeFalse()
            ->and(Schema::hasTable('employee_addresses'))->toBeFalse();

        // A rollback killed after its drops and re-run finds nothing to drop.
        expect(pdsmigDdlDuring(static function () use ($details, $addresses): void {
            $addresses->down();
            $details->down();
        }))->toBe([]);

        // Back empty: ROLLBACK LOSES what was typed into the two tables, and
        // the employee is still there.
        $details->up();
        $addresses->up();
        expect(EmployeePdsDetail::query()->count())->toBe(0)
            ->and(EmployeeAddress::query()->count())->toBe(0)
            ->and(EmployeeProfile::query()->whereKey($employee->id)->exists())->toBeTrue();
    } finally {
        $details->up();
        pdsmigFamilyColumns()->up();
        $addresses->up();
        pdsmigCleanup([$employee]);
    }
});

/*
|--------------------------------------------------------------------------
| Existing data
|--------------------------------------------------------------------------
*/

it('changes no existing employee_profiles column or value', function () {
    $details = pdsmigDetails();
    $addresses = pdsmigAddresses();
    $employee = pdsmigEmployee();
    $schema = pdsmigProfileSchema();
    $row = DB::table('employee_profiles')->where('id', $employee->id)->first();
    $count = DB::table('employee_profiles')->count();

    try {
        $ddl = pdsmigDdlDuring(static function () use ($details, $addresses): void {
            $addresses->down();
            $details->down();
            $details->up();
            $addresses->up();
        });

        // Not one statement creates, alters or drops employee_profiles itself;
        // it is only the target the two foreign keys name.
        foreach ($ddl as $statement) {
            expect(preg_match('/^\s*(create|alter|drop)\s+table\s+[`"]?employee_profiles\b/i', $statement))->toBe(0);
        }

        expect(pdsmigProfileSchema())->toEqual($schema)
            ->and(DB::table('employee_profiles')->where('id', $employee->id)->first())->toEqual($row)
            ->and(DB::table('employee_profiles')->count())->toBe($count);

        $after = DB::table('employee_profiles')->where('id', $employee->id)->first();
        expect($after?->address)->toBe('Blk 3 Lot 7, Poblacion, Tayabas City')
            ->and($after?->citizenship)->toBe('Filipino')
            ->and($after?->civil_status)->toBe('Married')
            ->and((float) $after?->height_cm)->toBe(162.5);
    } finally {
        $details->up();
        pdsmigFamilyColumns()->up();
        $addresses->up();
        pdsmigCleanup([$employee]);
    }
});

/*
|--------------------------------------------------------------------------
| Keys
|--------------------------------------------------------------------------
*/

it('removes the details and address rows with their employee', function () {
    $employee = pdsmigEmployee();
    $other = pdsmigEmployee('2');
    pdsmigDetailRow($employee, '0111-2222333-4');
    pdsmigAddressRow($employee, EmployeeAddress::RESIDENTIAL, 'Tayabas City');
    pdsmigAddressRow($employee, EmployeeAddress::PERMANENT, 'Lucena City');
    $kept = pdsmigDetailRow($other, '0555-6666777-8');
    $keptAddress = pdsmigAddressRow($other, EmployeeAddress::RESIDENTIAL, 'Sariaya');

    EmployeeProfile::query()->whereKey($employee->id)->delete();

    expect(EmployeePdsDetail::query()->where('employee_id', $employee->id)->count())->toBe(0)
        ->and(EmployeeAddress::query()->where('employee_id', $employee->id)->count())->toBe(0);

    // Another employee's rows stay.
    expect(EmployeePdsDetail::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(EmployeeAddress::query()->whereKey($keptAddress->id)->exists())->toBeTrue();
});

it('refuses a second details row or a second address of one type for the same employee', function () {
    $employee = pdsmigEmployee();
    $other = pdsmigEmployee('2');
    pdsmigDetailRow($employee, '0111-2222333-4');
    pdsmigAddressRow($employee, EmployeeAddress::RESIDENTIAL, 'Tayabas City');

    expect(fn () => pdsmigDetailRow($employee, '0999-0000000-0'))->toThrow(QueryException::class);
    expect(fn () => pdsmigAddressRow($employee, EmployeeAddress::RESIDENTIAL, 'Lucena City'))->toThrow(QueryException::class);

    // One of each type, and one details row, per employee — not per table.
    pdsmigAddressRow($employee, EmployeeAddress::PERMANENT, 'Lucena City');
    pdsmigDetailRow($other, '0555-6666777-8');
    pdsmigAddressRow($other, EmployeeAddress::RESIDENTIAL, 'Sariaya');

    expect(EmployeePdsDetail::query()->where('employee_id', $employee->id)->pluck('umid_no')->all())->toBe(['0111-2222333-4'])
        ->and(EmployeeAddress::query()->where('employee_id', $employee->id)->orderBy('type')->pluck('type')->all())
        ->toBe([EmployeeAddress::PERMANENT, EmployeeAddress::RESIDENTIAL])
        ->and(EmployeePdsDetail::query()->count())->toBe(2)
        ->and(EmployeeAddress::query()->count())->toBe(3);
});

it('reads permanent_address_same back as NULL, true or false', function () {
    $first = pdsmigEmployee();
    $second = pdsmigEmployee('2');
    $third = pdsmigEmployee('3');

    foreach ([[$first, null], [$second, true], [$third, false]] as [$employee, $answer]) {
        $row = new EmployeePdsDetail;
        $row->fill(['employee_id' => $employee->id, 'permanent_address_same' => $answer])->save();
    }

    // NULL is "not answered" and stays NULL; it never reads as true.
    expect(EmployeePdsDetail::query()->where('employee_id', $first->id)->value('permanent_address_same'))->toBeNull()
        ->and(EmployeePdsDetail::query()->where('employee_id', $first->id)->firstOrFail()->permanent_address_same)->toBeNull()
        ->and(EmployeePdsDetail::query()->where('employee_id', $second->id)->firstOrFail()->permanent_address_same)->toBeTrue()
        ->and(EmployeePdsDetail::query()->where('employee_id', $third->id)->firstOrFail()->permanent_address_same)->toBeFalse();

    // The relations read the same rows.
    $loaded = EmployeeProfile::query()->with(['pdsDetail', 'addresses'])->findOrFail($second->id);
    expect($loaded->pdsDetail?->permanent_address_same)->toBeTrue()
        ->and($loaded->addresses)->toHaveCount(0);
    pdsmigAddressRow($second, EmployeeAddress::RESIDENTIAL, 'Tayabas City');
    expect($loaded->load('addresses')->addresses->pluck('city')->all())->toBe(['Tayabas City'])
        ->and(Str::isUlid((string) $loaded->addresses->first()?->id))->toBeTrue();
});
