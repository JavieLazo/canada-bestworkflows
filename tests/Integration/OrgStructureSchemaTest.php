<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * On the production engine, the department, unit and position structure
 * (2026_10_07_0010–0015) holds what the migrations' docblocks promise:
 * `org_nodes` refuses a kind off its list, a unit with no department and a
 * department with a parent (`orgn_kind_chk`, `orgn_parent_chk`); the three
 * new foreign keys RESTRICT deletion of a department with units, a department
 * or unit with positions, and a position someone holds; a name is kept once
 * among a department's units and a title once inside a department or unit,
 * ignoring case and accents as the collation does, while the same title may
 * repeat across them once the global unique is gone; the link backfill links
 * only exact unique matches and never writes `position` or `updated_at`; the
 * drop migration's down() refuses while a title repeats; and each migration
 * re-runs with no DDL but the row-format restatement and resumes after a
 * kill.
 *
 * It also proves the `employee_profiles` row budget the column migration
 * relies on (the database guide's row-format rule): under innodb_strict_mode,
 * on a `CREATE TABLE … LIKE` copy, the column, its index and its key go in as
 * three statements, and the column replays with ALGORITHM=INSTANT on the real
 * table. DatabaseMigrations rolls the whole schema back after each test, so
 * EVERY TEST ENDS MIGRATED, with no title held twice — the drop migration's
 * own down() would otherwise refuse that rollback.
 */

/** One of the six migrations by its sequence — an anonymous class, so untyped here. */
function orgschemaMigration(string $suffix): mixed
{
    $files = glob(base_path("database/migrations/2026_10_07_{$suffix}_*.php")) ?: [];
    if (count($files) !== 1) {
        throw new LogicException("No single migration 2026_10_07_{$suffix}");
    }

    return require $files[0];
}

/** How many CHECK constraints of this name the table carries. */
function orgschemaChecks(string $table, string $name): int
{
    $row = DB::selectOne(
        "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
        [$table, $name],
    );

    return is_object($row) && isset($row->n) ? (int) $row->n : 0;
}

/** The delete rule of the key on `$table.$column`, upper-cased. */
function orgschemaDeleteRule(string $table, string $column): string
{
    $row = DB::selectOne(
        'SELECT rc.DELETE_RULE AS rule FROM information_schema.REFERENTIAL_CONSTRAINTS rc JOIN information_schema.KEY_COLUMN_USAGE k ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND k.TABLE_NAME = rc.TABLE_NAME WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND rc.TABLE_NAME = ? AND k.COLUMN_NAME = ?',
        [$table, $column],
    );

    return strtoupper((string) ($row->rule ?? ''));
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function orgschemaDdlDuring(callable $callback): array
{
    return array_values(array_filter(
        orgschemaQueriesDuring($callback),
        static fn (string $sql): bool => preg_match('/^\s*(create|alter|drop)\b/i', $sql) === 1,
    ));
}

/**
 * Every statement the callback sends, in order.
 *
 * @return list<string>
 */
function orgschemaQueriesDuring(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $callback();
    } finally {
        DB::disableQueryLog();
    }

    return array_column(DB::getQueryLog(), 'query');
}

/** A node row straight into the table, so no model default hides a bad value. */
function orgschemaNode(string $kind, string $name, ?string $parentId = null): string
{
    $id = (string) Str::ulid();
    DB::table('org_nodes')->insert([
        'id' => $id,
        'code' => (string) random_int(100000, 999999),
        'kind' => $kind,
        'name' => $name,
        'parent_id' => $parentId,
        'created_at' => '2026-10-07 01:00:00.000',
    ]);

    return $id;
}

function orgschemaPosition(string $name, ?string $nodeId = null): string
{
    $id = (string) Str::ulid();
    DB::table('positions')->insert([
        'id' => $id,
        'code' => (string) random_int(100000, 999999),
        'name' => $name,
        'org_node_id' => $nodeId,
        'created_at' => '2026-10-07 01:00:00.000',
    ]);

    return $id;
}

function orgschemaEmployee(?string $position, ?string $positionId = null): string
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'ORGS-'.Str::upper(Str::random(8)),
        'first_name' => 'Synthetic',
        'last_name' => 'Holder',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'position' => $position,
        'position_id' => $positionId,
        'updated_at' => '2026-01-02 03:04:05.678',
    ])->save();

    return (string) $employee->getKey();
}

/*
|--------------------------------------------------------------------------
| Shape and retry safety
|--------------------------------------------------------------------------
*/

it('applies every migration twice on top of a migrated schema, sending no DDL but the row format', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    expect(orgschemaChecks('org_nodes', 'orgn_kind_chk'))->toBe(1)
        ->and(orgschemaChecks('org_nodes', 'orgn_parent_chk'))->toBe(1)
        ->and(DB::table('locks')->where('name', 'copems:org-structure')->count())->toBe(1)
        ->and(Schema::hasIndex('positions', ['name'], 'unique'))->toBeFalse();

    $ddl = orgschemaDdlDuring(static function (): void {
        foreach (['0010', '0011', '0012', '0013', '0014', '0015'] as $suffix) {
            orgschemaMigration($suffix)->up();
            orgschemaMigration($suffix)->up();
        }
    });

    expect(array_values(array_filter($ddl, static fn (string $sql): bool => ! str_contains($sql, 'ROW_FORMAT=DYNAMIC'))))->toBe([])
        ->and(orgschemaChecks('org_nodes', 'orgn_kind_chk'))->toBe(1)
        ->and(orgschemaChecks('org_nodes', 'orgn_parent_chk'))->toBe(1)
        ->and(DB::table('locks')->where('name', 'copems:org-structure')->count())->toBe(1);

    $format = DB::selectOne("SELECT ROW_FORMAT AS f FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'org_nodes'");
    expect(strtoupper((string) ($format->f ?? '')))->toBe('DYNAMIC');
});

it('resumes a structure create killed after its CREATE TABLE with every index, key and CHECK', function () {
    $placement = orgschemaMigration('0012');
    $nodes = orgschemaMigration('0011');

    $placement->down();
    $nodes->down();
    expect(Schema::hasTable('org_nodes'))->toBeFalse();

    DB::statement('create table `org_nodes` (`id` char(26) not null, `code` varchar(40) not null, `kind` varchar(12) not null, `name` varchar(160) not null, `description` text null, `parent_id` char(26) null, `sort_order` int unsigned not null default 0, `created_at` datetime(3) not null, `updated_at` datetime(3) null, primary key (`id`)) default character set utf8mb4 collate utf8mb4_unicode_ci engine = InnoDB');

    $ddl = orgschemaDdlDuring(static function () use ($nodes, $placement): void {
        $nodes->up();
        $placement->up();
    });

    foreach ($ddl as $statement) {
        expect(strtolower($statement))->not->toStartWith('create table');
    }
    foreach (['orgn_code_unq', 'orgn_parent_name_unq', 'orgn_parent_order_idx'] as $index) {
        expect(Schema::hasIndex('org_nodes', $index))->toBeTrue();
    }
    expect(orgschemaChecks('org_nodes', 'orgn_kind_chk'))->toBe(1)
        ->and(orgschemaChecks('org_nodes', 'orgn_parent_chk'))->toBe(1)
        ->and(orgschemaDeleteRule('org_nodes', 'parent_id'))->toBe('RESTRICT')
        ->and(orgschemaDeleteRule('positions', 'org_node_id'))->toBe('RESTRICT')
        // The key reuses a declared index: none beyond the three, the primary key.
        ->and(count(Schema::getIndexes('org_nodes')))->toBe(4);
});

/*
|--------------------------------------------------------------------------
| What the database refuses
|--------------------------------------------------------------------------
*/

it('refuses a kind off the list, a unit without a department and a department with a parent', function () {
    $department = orgschemaNode('DEPARTMENT', 'Operations');
    orgschemaNode('UNIT', 'Dispatch', $department);

    expect(fn () => orgschemaNode('UNIT', 'Loose unit'))->toThrow(QueryException::class, 'orgn_parent_chk');
    expect(fn () => orgschemaNode('DEPARTMENT', 'Nested department', $department))->toThrow(QueryException::class, 'orgn_parent_chk');
    expect(fn () => orgschemaNode('TEAM', 'Team'))->toThrow(QueryException::class, 'orgn_kind_chk');
    expect(fn () => DB::table('org_nodes')->where('id', $department)->update(['kind' => 'UNIT']))
        ->toThrow(QueryException::class, 'orgn_parent_chk');

    expect(DB::table('org_nodes')->count())->toBe(2);
});

it('restricts deleting a department with units, a row with positions, and a position someone holds', function () {
    $department = orgschemaNode('DEPARTMENT', 'Finance');
    $unit = orgschemaNode('UNIT', 'Payroll', $department);
    $position = orgschemaPosition('Payroll Clerk', $unit);
    $holder = orgschemaEmployee('Payroll Clerk', $position);

    expect(orgschemaDeleteRule('org_nodes', 'parent_id'))->toBe('RESTRICT')
        ->and(orgschemaDeleteRule('positions', 'org_node_id'))->toBe('RESTRICT')
        ->and(orgschemaDeleteRule('employee_profiles', 'position_id'))->toBe('RESTRICT');

    expect(fn () => DB::table('org_nodes')->where('id', $department)->delete())->toThrow(QueryException::class, '1451');
    expect(fn () => DB::table('org_nodes')->where('id', $unit)->delete())->toThrow(QueryException::class, '1451');
    expect(fn () => DB::table('positions')->where('id', $position)->delete())->toThrow(QueryException::class, '1451');
    expect(DB::table('org_nodes')->count())->toBe(2)
        ->and(DB::table('positions')->where('id', $position)->exists())->toBeTrue();

    // Emptied from the bottom up, each delete goes through.
    DB::table('employee_profiles')->where('id', $holder)->update(['position_id' => null]);
    DB::table('positions')->where('id', $position)->delete();
    DB::table('org_nodes')->where('id', $unit)->delete();
    DB::table('org_nodes')->where('id', $department)->delete();
    expect(DB::table('org_nodes')->count())->toBe(0);
});

it('keeps a unit name once per department and a title once per placement, ignoring case and accents', function () {
    $finance = orgschemaNode('DEPARTMENT', 'Finance');
    $audit = orgschemaNode('DEPARTMENT', 'Audit');
    orgschemaNode('UNIT', 'Payroll', $finance);

    expect(fn () => orgschemaNode('UNIT', 'PAYROLL', $finance))->toThrow(QueryException::class, 'orgn_parent_name_unq');
    expect(fn () => orgschemaNode('UNIT', 'Páyroll', $finance))->toThrow(QueryException::class, 'orgn_parent_name_unq');
    // Another department may have a unit of the same name.
    orgschemaNode('UNIT', 'Payroll', $audit);
    // NULL parents are distinct, so two departments of one name pass the
    // schema: the commands refuse that under the mutex.
    $twin = orgschemaNode('DEPARTMENT', 'finance');

    orgschemaPosition('Résumé Reviewer', $finance);
    expect(fn () => orgschemaPosition('resume reviewer', $finance))->toThrow(QueryException::class, 'positions_org_node_name_unq');
    // With the global unique gone, the same title sits in another department.
    $repeat = orgschemaPosition('Résumé Reviewer', $audit);
    // Not placed titles are NULL placements: the commands keep them unique.
    $loose = [orgschemaPosition('Driver'), orgschemaPosition('Driver')];

    expect(DB::table('positions')->count())->toBe(4);

    // Leave no title twice, so the rollback's down() of 0015 can run.
    DB::table('positions')->whereIn('id', [$repeat, ...$loose])->delete();
    DB::table('org_nodes')->where('id', $twin)->delete();
});

/*
|--------------------------------------------------------------------------
| The link backfill and the title unique
|--------------------------------------------------------------------------
*/

it('links only exact unique matches and writes nothing but position_id', function () {
    $driver = orgschemaPosition('Driver');
    $security = orgschemaNode('DEPARTMENT', 'Security');
    $north = orgschemaPosition('Guard', orgschemaNode('UNIT', 'North Gate', $security));
    $south = orgschemaPosition('Guard', orgschemaNode('UNIT', 'South Gate', $security));

    $exact = orgschemaEmployee('Driver');
    $others = [
        orgschemaEmployee('driver'),   // the collation calls it equal; the backfill does not
        orgschemaEmployee('Drivér'),
        orgschemaEmployee('Messenger'),
        orgschemaEmployee('Guard'),    // two positions hold it
        orgschemaEmployee(''),
        orgschemaEmployee(null),
    ];

    $queries = orgschemaQueriesDuring(static function (): void {
        orgschemaMigration('0014')->up();
        orgschemaMigration('0014')->up();
    });

    expect(DB::table('employee_profiles')->where('id', $exact)->value('position_id'))->toBe($driver)
        ->and(DB::table('employee_profiles')->whereIn('id', $others)->whereNotNull('position_id')->count())->toBe(0);

    $updates = array_values(array_filter($queries, static fn (string $sql): bool => preg_match('/^\s*update\b/i', $sql) === 1));
    expect($updates)->toHaveCount(1);
    foreach ($updates as $sql) {
        $set = preg_match('/\bset\s+(.*?)\s+where\b/is', $sql, $match) === 1 ? $match[1] : '';
        expect(str_replace('`', '', $set))->toBe('position_id = ?');
    }

    foreach (DB::table('employee_profiles')->whereIn('id', [$exact, ...$others])->get(['updated_at']) as $row) {
        expect((string) $row->updated_at)->toStartWith('2026-01-02 03:04:05');
    }
    expect(DB::table('employee_profiles')->where('id', $others[1])->value('position'))->toBe('Drivér');

    // Leave no title twice for the rollback.
    DB::table('positions')->where('id', $south)->delete();
    expect(DB::table('positions')->where('id', $north)->exists())->toBeTrue();
});

it('refuses to restore the title unique while a title repeats, then restores and drops it again', function () {
    $drop = orgschemaMigration('0015');
    $finance = orgschemaNode('DEPARTMENT', 'Finance');
    $audit = orgschemaNode('DEPARTMENT', 'Audit');
    orgschemaPosition('Analyst', $finance);
    $repeat = orgschemaPosition('ANALYST', $audit);

    expect(orgschemaDdlDuring(static function () use ($drop): void {
        expect(fn () => $drop->down())->toThrow(RuntimeException::class, 'Rename the repeated titles first: "');
    }))->toBe([]);
    expect(Schema::hasIndex('positions', ['name'], 'unique'))->toBeFalse();

    DB::table('positions')->where('id', $repeat)->update(['name' => 'Audit Analyst']);
    $drop->down();
    $drop->down();
    expect(Schema::hasIndex('positions', 'positions_name_unique', 'unique'))->toBeTrue();
    expect(fn () => orgschemaPosition('analyst', $audit))->toThrow(QueryException::class, 'positions_name_unique');

    $drop->up();
    expect(orgschemaDdlDuring(static fn () => $drop->up()))->toBe([])
        ->and(Schema::hasIndex('positions', ['name'], 'unique'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The employee_profiles row budget
|--------------------------------------------------------------------------
*/

it('fits position_id, its index and its key on employee_profiles under strict mode', function () {
    $column = orgschemaMigration('0013');
    $column->down();
    expect(Schema::hasColumn('employee_profiles', 'position_id'))->toBeFalse();

    DB::statement('SET SESSION innodb_strict_mode = ON');
    $strict = DB::selectOne('SELECT @@SESSION.innodb_strict_mode AS s');
    expect((int) ($strict->s ?? 0))->toBe(1);

    // A fresh copy: the three statements the migration sends, one by one.
    DB::statement('DROP TABLE IF EXISTS `orgschema_ep_copy`');
    DB::statement('CREATE TABLE `orgschema_ep_copy` LIKE `employee_profiles`');
    try {
        DB::statement('ALTER TABLE `orgschema_ep_copy` ADD COLUMN `position_id` char(26) NULL');
        DB::statement('ALTER TABLE `orgschema_ep_copy` ADD INDEX `orgschema_copy_position_idx` (`position_id`)');
        DB::statement('ALTER TABLE `orgschema_ep_copy` ADD CONSTRAINT `orgschema_copy_position_fk` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE RESTRICT');
        expect(Schema::hasColumn('orgschema_ep_copy', 'position_id'))->toBeTrue();

        // What the copy still takes afterwards, recorded for the docblock.
        $room = 0;
        try {
            for ($i = 1; $i <= 30; $i++) {
                DB::statement("ALTER TABLE `orgschema_ep_copy` ADD COLUMN `orgschema_pad{$i}` varchar(63) NULL");
                $room = $i;
            }
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('Row size too large');
        }
        fwrite(STDERR, "[orgschema] the copy took {$room} more VARCHAR(63) columns after position_id\n");
        expect($room)->toBeGreaterThanOrEqual(5);
    } finally {
        DB::statement('DROP TABLE IF EXISTS `orgschema_ep_copy`');
    }

    // On the real table: the column instantly, then the migration adds the
    // index and the key.
    DB::statement('ALTER TABLE `employee_profiles` ADD COLUMN `position_id` char(26) NULL, ALGORITHM=INSTANT');
    $ddl = orgschemaDdlDuring(static fn () => $column->up());

    expect($ddl)->toHaveCount(2)
        ->and($ddl[0])->toContain('ep_position_idx')
        ->and(strtolower($ddl[1]))->toContain('foreign key')
        ->and(Schema::hasForeignKey('employee_profiles', ['position_id']))->toBeTrue()
        ->and(orgschemaDeleteRule('employee_profiles', 'position_id'))->toBe('RESTRICT');
});
