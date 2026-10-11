<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\WorkSite;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * On the production engine, the weekly-location storage (2026_09_26_0001
 * roster_locations, 2026_09_26_0002 attendance_logs.location_kind) is
 * retry-safe step by step, its four CHECK constraints exist exactly once and
 * refuse bad values, a full rollback removes every piece and a re-run
 * restores it, the employee foreign key cascades and the site foreign key
 * RESTRICTS (the first domain FK here that does — a schedule must be edited
 * before its site can go), and the one ADD COLUMN is accepted with
 * ALGORITHM=INSTANT, which is what its docblock promises for MariaDB 11.4.
 *
 * sqlite never creates a CHECK and rebuilds a table for a column drop, so
 * only this suite sees the real ALTERs. DatabaseMigrations rolls the whole
 * schema back after each test, so EVERY TEST ENDS MIGRATED: the rollback
 * then runs each down() against the state it expects.
 */

/**
 * 2026_09_30_0006 later versions this table — dated rows, no unique — so
 * these tests exercise the 2026_09_26 migrations as they stood: it is rolled
 * back before each and re-applied after, the order production runs them.
 */
function rlocschemaVersioning(): mixed
{
    return require base_path('database/migrations/2026_09_30_0006_add_effective_from_to_roster_locations_table.php');
}

beforeEach(function () {
    rlocschemaVersioning()->down();
});

afterEach(function () {
    rlocschemaVersioning()->up();
});

/**
 * The two migrations, oldest first — anonymous classes, so untyped here.
 *
 * @return array{0: mixed, 1: mixed}
 */
function rlocschemaMigrations(): array
{
    return [
        require base_path('database/migrations/2026_09_26_0001_create_roster_locations_table.php'),
        require base_path('database/migrations/2026_09_26_0002_add_location_kind_to_attendance_logs_table.php'),
    ];
}

/** How many CHECK constraints of this name the table carries. */
function rlocschemaChecks(string $table, string $name): int
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
function rlocschemaIndexNames(string $table): array
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
function rlocschemaDdlDuring(callable $callback): array
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
 * The location_kind column as information_schema describes it, or null when absent.
 *
 * @return array{type: string, nullable: string, default: mixed}|null
 */
function rlocschemaColumn(): ?array
{
    $row = DB::selectOne(
        'SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['attendance_logs', 'location_kind'],
    );
    if (! is_object($row) || ! isset($row->type, $row->nullable) || ! property_exists($row, 'dflt')) {
        return null;
    }

    return ['type' => strtolower((string) $row->type), 'nullable' => (string) $row->nullable, 'default' => $row->dflt];
}

/** Every piece both migrations add, present or absent together. */
function rlocschemaAssertApplied(bool $applied): void
{
    expect(Schema::hasTable('roster_locations'))->toBe($applied);
    foreach (['rloc_weekday_chk', 'rloc_kind_chk', 'rloc_site_chk'] as $check) {
        expect(rlocschemaChecks('roster_locations', $check))->toBe($applied ? 1 : 0);
    }

    expect(Schema::hasColumn('attendance_logs', 'location_kind'))->toBe($applied);
    expect(rlocschemaChecks('attendance_logs', 'attlog_location_kind_chk'))->toBe($applied ? 1 : 0);

    if (! $applied) {
        expect(rlocschemaColumn())->toBeNull();

        return;
    }

    expect(Schema::hasIndex('roster_locations', 'rloc_employee_weekday_unq', 'unique'))->toBeTrue();
    expect(Schema::hasIndex('roster_locations', 'rloc_site_idx'))->toBeTrue();
    expect(Schema::hasForeignKey('roster_locations', ['employee_id']))->toBeTrue();
    expect(Schema::hasForeignKey('roster_locations', ['site_id']))->toBeTrue();

    $column = rlocschemaColumn();
    expect($column)->not->toBeNull();
    assert($column !== null);
    expect($column['type'])->toBe('varchar(10)')
        ->and($column['nullable'])->toBe('NO');
    // MariaDB reports a string default quoted ('ONSITE'), MySQL bare.
    expect(in_array($column['default'], ['ONSITE', "'ONSITE'"], true))->toBeTrue();
}

/** The indexes the migration declares by name, and nothing MariaDB added for a foreign key. */
function rlocschemaAssertDeclaredIndexes(): void
{
    expect(rlocschemaIndexNames('roster_locations'))->toBe(['primary', 'rloc_employee_weekday_unq', 'rloc_site_idx']);
}

function rlocschemaEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'RLS-'.Str::upper(Str::random(5)),
        'first_name' => 'Roster',
        'last_name' => 'Schema '.Str::random(4),
        'position' => 'Field Engineer',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

function rlocschemaSite(): WorkSite
{
    return WorkSite::query()->create([
        'name' => 'Schema site '.Str::random(4),
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'radius_meters' => 150,
        'geofence_exempt' => false,
    ]);
}

/** A weekly-location row straight into the table, so no model default hides a bad value. */
function rlocschemaRow(EmployeeProfile $employee, int $weekday, string $kind, ?string $siteId): string
{
    $id = (string) Str::ulid();
    DB::table('roster_locations')->insert([
        'id' => $id,
        'employee_id' => (string) $employee->getKey(),
        'weekday' => $weekday,
        'kind' => $kind,
        'site_id' => $siteId,
        'created_at' => '2026-09-26 01:00:00.000',
        'updated_at' => null,
    ]);

    return $id;
}

/**
 * A punch straight into the table. A null `$kind` leaves the column out of
 * the INSERT altogether, which also works while the column does not exist.
 */
function rlocschemaPunch(EmployeeProfile $employee, ?string $kind): string
{
    $id = (string) Str::ulid();
    $row = [
        'id' => $id,
        'employee_id' => (string) $employee->getKey(),
        'site_id' => null,
        'log_type' => 'TIME_IN',
        'source' => 'WEB_GPS',
        'latitude' => 14.6500,
        'longitude' => 121.0500,
        'distance_meters' => null,
        'accuracy_meters' => 9.00,
        'is_geofenced' => 1,
        'is_anomaly' => 0,
        'anomaly_note' => null,
        'logged_at' => '2026-09-02 00:00:31.000',
    ];
    if ($kind !== null) {
        $row['location_kind'] = $kind;
    }
    DB::table('attendance_logs')->insert($row);

    return $id;
}

it('applies both migrations twice on top of a migrated schema, with each CHECK exactly once', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    // migrate:fresh has already run both through the migrator.
    rlocschemaAssertApplied(true);
    rlocschemaAssertDeclaredIndexes();
    [$table, $column] = rlocschemaMigrations();

    // The table migration restates ROW_FORMAT on every run — the one
    // unguarded, idempotent statement — and nothing else.
    $restated = 'ALTER TABLE `roster_locations` ROW_FORMAT=DYNAMIC';
    expect(rlocschemaDdlDuring(static function () use ($table): void {
        $table->up();
        $table->up();
    }))->toBe([$restated, $restated]);

    // The column migration finds its column and its CHECK and sends no DDL.
    expect(rlocschemaDdlDuring(static function () use ($column): void {
        $column->up();
        $column->up();
    }))->toBe([]);

    rlocschemaAssertApplied(true);
    rlocschemaAssertDeclaredIndexes();
});

it('refuses a kind, a weekday, a site pairing or a punch kind outside the constraints', function () {
    $employee = rlocschemaEmployee();
    $site = rlocschemaSite();
    $siteId = (string) $site->getKey();

    expect(fn () => rlocschemaRow($employee, 1, 'REMOTE', null))->toThrow(QueryException::class, 'rloc_kind_chk');
    expect(fn () => rlocschemaRow($employee, 7, 'WFH', null))->toThrow(QueryException::class, 'rloc_weekday_chk');
    // ONSITE nowhere, and WFH somewhere: the pairing CHECK refuses both.
    expect(fn () => rlocschemaRow($employee, 1, 'ONSITE', null))->toThrow(QueryException::class, 'rloc_site_chk');
    expect(fn () => rlocschemaRow($employee, 1, 'WFH', $siteId))->toThrow(QueryException::class, 'rloc_site_chk');
    expect(DB::table('roster_locations')->count())->toBe(0);

    // The two legal shapes land, and a weekday holds one row per employee.
    $home = rlocschemaRow($employee, 1, 'WFH', null);
    $office = rlocschemaRow($employee, 2, 'ONSITE', $siteId);
    expect(DB::table('roster_locations')->whereIn('id', [$home, $office])->count())->toBe(2);
    expect(fn () => rlocschemaRow($employee, 2, 'WFH', null))->toThrow(QueryException::class, 'rloc_employee_weekday_unq');
    expect(DB::table('roster_locations')->where('employee_id', (string) $employee->getKey())->count())->toBe(2);

    // The punch column: the default is ONSITE, WFH is stored, anything else refused.
    $default = rlocschemaPunch($employee, null);
    $wfh = rlocschemaPunch($employee, 'WFH');
    expect(DB::table('attendance_logs')->where('id', $default)->value('location_kind'))->toBe('ONSITE')
        ->and(DB::table('attendance_logs')->where('id', $wfh)->value('location_kind'))->toBe('WFH');
    expect(fn () => rlocschemaPunch($employee, 'HOME'))->toThrow(QueryException::class, 'attlog_location_kind_chk');
    expect(fn () => DB::table('attendance_logs')->where('id', $wfh)->update(['location_kind' => 'REMOTE']))
        ->toThrow(QueryException::class, 'attlog_location_kind_chk');
    expect(DB::table('attendance_logs')->where('employee_id', (string) $employee->getKey())->count())->toBe(2);
});

it('refuses to delete a work site a weekly roster still names, and cascades the roster with its employee', function () {
    $employee = rlocschemaEmployee();
    $site = rlocschemaSite();
    $row = rlocschemaRow($employee, 3, 'ONSITE', (string) $site->getKey());

    // RESTRICT, not SET NULL or CASCADE: MySQL error 1451.
    try {
        DB::table('work_sites')->where('id', $site->getKey())->delete();
        test()->fail('Deleting a work site a roster row names must be refused by the foreign key.');
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(1451)
            ->and($e->getMessage())->toContain('foreign key constraint fails');
    }
    expect(DB::table('work_sites')->where('id', $site->getKey())->exists())->toBeTrue()
        ->and(DB::table('roster_locations')->where('id', $row)->value('site_id'))->toBe((string) $site->getKey());

    // A schedule is configuration, not history: it goes with its employee.
    DB::table('employee_profiles')->where('id', $employee->getKey())->delete();
    expect(DB::table('roster_locations')->where('id', $row)->exists())->toBeFalse();

    // And with no row naming it, the site can go.
    DB::table('work_sites')->where('id', $site->getKey())->delete();
    expect(DB::table('work_sites')->where('id', $site->getKey())->exists())->toBeFalse();
});

it('removes every piece on a full rollback, tolerates a second one, and restores them all with every punch intact', function () {
    $employee = rlocschemaEmployee();
    $site = rlocschemaSite();
    rlocschemaRow($employee, 3, 'ONSITE', (string) $site->getKey());
    $wfh = rlocschemaPunch($employee, 'WFH');
    [$table, $column] = rlocschemaMigrations();

    $column->down();
    $table->down();
    rlocschemaAssertApplied(false);

    // A rollback killed and re-run finds nothing left to drop.
    expect(rlocschemaDdlDuring(static function () use ($table, $column): void {
        $column->down();
        $table->down();
    }))->toBe([]);
    rlocschemaAssertApplied(false);

    // The punch, its coordinates and its instant are untouched.
    expect(DB::table('attendance_logs')->where('id', $wfh)->value('log_type'))->toBe('TIME_IN');
    expect((string) DB::table('attendance_logs')->where('id', $wfh)->value('logged_at'))->toBe('2026-09-02 00:00:31.000');
    expect((string) DB::table('attendance_logs')->where('id', $wfh)->value('latitude'))->toStartWith('14.65');

    $table->up();
    $column->up();
    rlocschemaAssertApplied(true);
    rlocschemaAssertDeclaredIndexes();

    // ROLLBACK LOSES every weekly schedule and which punches were from home:
    // the table is empty and the punch re-reads ONSITE with its null site.
    expect(DB::table('roster_locations')->count())->toBe(0);
    expect(DB::table('attendance_logs')->where('id', $wfh)->value('location_kind'))->toBe('ONSITE')
        ->and(DB::table('attendance_logs')->where('id', $wfh)->value('site_id'))->toBeNull();
});

it('resumes the table migration from a run killed between any two of its statements', function () {
    [$table] = rlocschemaMigrations();
    $restated = 'ALTER TABLE `roster_locations` ROW_FORMAT=DYNAMIC';

    // Killed before a CHECK: only that CHECK is re-added (plus the restatement).
    foreach (['rloc_weekday_chk', 'rloc_kind_chk', 'rloc_site_chk'] as $check) {
        DB::statement("ALTER TABLE `roster_locations` DROP CONSTRAINT `{$check}`");
        expect(rlocschemaChecks('roster_locations', $check))->toBe(0);

        $ddl = rlocschemaDdlDuring(static fn () => $table->up());

        expect($ddl)->toHaveCount(2)
            ->and($ddl[0])->toBe($restated)
            ->and($ddl[1])->toStartWith("ALTER TABLE `roster_locations` ADD CONSTRAINT `{$check}` CHECK");
        expect(rlocschemaChecks('roster_locations', $check))->toBe(1);
    }

    // Killed before the site index: the index is re-added, and only it. The
    // foreign key uses that index, so it has to go first and comes back with it.
    DB::statement('ALTER TABLE `roster_locations` DROP FOREIGN KEY `roster_locations_site_id_foreign`');
    DB::statement('ALTER TABLE `roster_locations` DROP INDEX `rloc_site_idx`');
    expect(Schema::hasIndex('roster_locations', 'rloc_site_idx'))->toBeFalse()
        ->and(Schema::hasForeignKey('roster_locations', ['site_id']))->toBeFalse();

    $ddl = rlocschemaDdlDuring(static fn () => $table->up());

    expect($ddl)->toHaveCount(3)
        ->and(strtolower($ddl[0]))->toContain('add index `rloc_site_idx`')
        ->and(strtolower($ddl[1]))->toContain('foreign key (`site_id`) references `work_sites`')
        ->and(strtolower($ddl[1]))->toContain('on delete restrict')
        ->and($ddl[2])->toBe($restated);
    rlocschemaAssertApplied(true);
    rlocschemaAssertDeclaredIndexes();

    // Killed before the unique: the same for the employee side, ON DELETE CASCADE.
    DB::statement('ALTER TABLE `roster_locations` DROP FOREIGN KEY `roster_locations_employee_id_foreign`');
    DB::statement('ALTER TABLE `roster_locations` DROP INDEX `rloc_employee_weekday_unq`');
    expect(Schema::hasIndex('roster_locations', 'rloc_employee_weekday_unq'))->toBeFalse();

    $ddl = rlocschemaDdlDuring(static fn () => $table->up());

    expect($ddl)->toHaveCount(3)
        ->and(strtolower($ddl[0]))->toContain('add unique `rloc_employee_weekday_unq`')
        ->and(strtolower($ddl[1]))->toContain('foreign key (`employee_id`) references `employee_profiles`')
        ->and(strtolower($ddl[1]))->toContain('on delete cascade')
        ->and($ddl[2])->toBe($restated);
    rlocschemaAssertApplied(true);
    rlocschemaAssertDeclaredIndexes();
});

it('resumes the table migration from a create killed after its CREATE TABLE', function () {
    [$table] = rlocschemaMigrations();

    // Strip the migrated table back to what that run leaves: the columns and
    // the primary key. The foreign keys go first, because MariaDB will not
    // drop an index a constraint still uses.
    foreach (['employee_id', 'site_id'] as $column) {
        DB::statement("ALTER TABLE `roster_locations` DROP FOREIGN KEY `roster_locations_{$column}_foreign`");
    }
    foreach (['rloc_employee_weekday_unq', 'rloc_site_idx'] as $index) {
        DB::statement("ALTER TABLE `roster_locations` DROP INDEX `{$index}`");
    }
    foreach (['rloc_weekday_chk', 'rloc_kind_chk', 'rloc_site_chk'] as $check) {
        DB::statement("ALTER TABLE `roster_locations` DROP CONSTRAINT `{$check}`");
    }

    expect(rlocschemaIndexNames('roster_locations'))->toBe(['primary']);
    expect(Schema::getForeignKeys('roster_locations'))->toBe([]);
    expect(rlocschemaChecks('roster_locations', 'rloc_kind_chk'))->toBe(0);

    $table->up();

    rlocschemaAssertApplied(true);
    // The indexes landed before the constraints, so MariaDB reused them and
    // added no index of its own for either foreign key.
    rlocschemaAssertDeclaredIndexes();

    // A second run finds every piece in place: the only DDL it sends is the
    // idempotent ROW_FORMAT restatement.
    expect(rlocschemaDdlDuring(static fn () => $table->up()))->toBe(['ALTER TABLE `roster_locations` ROW_FORMAT=DYNAMIC']);
    rlocschemaAssertApplied(true);
});

it('resumes the column migration from a run killed on either side of its two ALTERs, and adds the column instantly', function () {
    $employee = rlocschemaEmployee();
    $punch = rlocschemaPunch($employee, null);
    [, $column] = rlocschemaMigrations();

    // Killed BEFORE the column: both statements are sent, the column first.
    $column->down();
    expect(Schema::hasColumn('attendance_logs', 'location_kind'))->toBeFalse();

    $ddl = rlocschemaDdlDuring(static fn () => $column->up());

    expect($ddl)->toHaveCount(2);
    // Appended, never placed with AFTER: that is what keeps the ADD instant
    // on a DYNAMIC-row table.
    expect(strtolower($ddl[0]))->toStartWith('alter table `attendance_logs` add `location_kind` varchar(10)')
        ->toContain('not null')
        ->toContain("default 'onsite'")
        ->not->toContain(' after ');
    expect($ddl[1])->toBe("ALTER TABLE `attendance_logs` ADD CONSTRAINT `attlog_location_kind_chk` CHECK (`location_kind` IN ('ONSITE','WFH'))");
    rlocschemaAssertApplied(true);

    // Killed BETWEEN the two: the column is there, the CHECK is not, and the
    // re-run adds only the CHECK.
    DB::statement('ALTER TABLE `attendance_logs` DROP CONSTRAINT `attlog_location_kind_chk`');
    expect(rlocschemaChecks('attendance_logs', 'attlog_location_kind_chk'))->toBe(0);
    expect(rlocschemaDdlDuring(static fn () => $column->up()))->toBe([$ddl[1]]);
    rlocschemaAssertApplied(true);

    // Killed AFTER both, before the migrator recorded it: a value may already
    // have landed, and the re-run sends no DDL and leaves it alone.
    DB::table('attendance_logs')->where('id', $punch)->update(['location_kind' => 'WFH']);
    expect(rlocschemaDdlDuring(static fn () => $column->up()))->toBe([]);
    expect(DB::table('attendance_logs')->where('id', $punch)->value('location_kind'))->toBe('WFH');

    // The same ADD with ALGORITHM=INSTANT is refused outright where the
    // engine would have to copy the table, so this proves the ADD is instant.
    $column->down();
    expect(Schema::hasColumn('attendance_logs', 'location_kind'))->toBeFalse();
    DB::statement($ddl[0].', ALGORITHM=INSTANT');
    expect(Schema::hasColumn('attendance_logs', 'location_kind'))->toBeTrue();
    // The re-run then adds only the CHECK, and the punch reads the default.
    expect(rlocschemaDdlDuring(static fn () => $column->up()))->toBe([$ddl[1]]);
    rlocschemaAssertApplied(true);
    expect(DB::table('attendance_logs')->where('id', $punch)->value('location_kind'))->toBe('ONSITE');
});
