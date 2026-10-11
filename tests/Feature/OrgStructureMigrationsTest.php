<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\OrgNode;
use App\Models\Position;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The department, unit and position structure arrives in six migrations
 * (2026_10_07_0010–0015) that each survive a second run: the mutex sentinel
 * is inserted once, `org_nodes` and the placement columns on `positions` and
 * `employee_profiles` are added once with their uniques, indexes and keys,
 * and a rollback of the chain, newest first, removes every piece and a re-run
 * restores it. The link backfill fills `employee_profiles.position_id` only
 * for a text that is exactly one position's name — never a case variant, an
 * off-list or blank text, or a title two positions share — never touches
 * `position` or `updated_at`, and names what it left unlinked in one
 * `Log::error` line. The global unique on `positions.name` is dropped only
 * once the per-placement unique exists, and comes back on rollback only while
 * no title repeats.
 *
 * THIS FILE ALWAYS ENDS MIGRATED, pass or fail: each test that sends DDL
 * re-runs every up() in order in its `finally`, then deletes the rows it
 * seeded by key. On MySQL/MariaDB DDL commits implicitly, so a down() ends
 * RefreshDatabase's transaction (the EmployeePdsListTablesMigrationTest
 * pattern); this file also runs under phpunit.mariadb.xml. The CHECKs and the
 * collation's case and accent rules are proven in
 * tests/Integration/OrgStructureSchemaTest, because sqlite has neither.
 */

/** @var list<string> the six migrations, oldest first */
const ORGMIG_FILES = [
    '2026_10_07_0010_add_org_structure_lock_to_locks_table',
    '2026_10_07_0011_create_org_nodes_table',
    '2026_10_07_0012_add_org_node_to_positions_table',
    '2026_10_07_0013_add_position_id_to_employee_profiles_table',
    '2026_10_07_0014_link_employee_profiles_to_positions_table',
    '2026_10_07_0015_drop_global_name_unique_from_positions_table',
];

/** One of the six migrations — an anonymous class, so untyped here. */
function orgmigMigration(string $suffix): mixed
{
    foreach (ORGMIG_FILES as $file) {
        if (str_starts_with($file, '2026_10_07_'.$suffix)) {
            return require base_path("database/migrations/{$file}.php");
        }
    }

    throw new LogicException("No migration 2026_10_07_{$suffix}");
}

/** Every up(), oldest first: what each `finally` puts back. */
function orgmigRestore(): void
{
    foreach (ORGMIG_FILES as $file) {
        (require base_path("database/migrations/{$file}.php"))->up();
    }
}

/** Every piece of the chain, present or absent together. */
function orgmigAssertApplied(bool $applied): void
{
    expect(DB::table('locks')->where('name', 'copems:org-structure')->count())->toBe($applied ? 1 : 0)
        ->and(Schema::hasTable('org_nodes'))->toBe($applied)
        ->and(Schema::hasColumn('positions', 'org_node_id'))->toBe($applied)
        ->and(Schema::hasColumn('positions', 'sort_order'))->toBe($applied)
        ->and(Schema::hasIndex('positions', 'positions_org_node_name_unq'))->toBe($applied)
        ->and(Schema::hasIndex('positions', 'positions_org_node_order_idx'))->toBe($applied)
        ->and(Schema::hasForeignKey('positions', ['org_node_id']))->toBe($applied)
        ->and(Schema::hasColumn('employee_profiles', 'position_id'))->toBe($applied)
        ->and(Schema::hasIndex('employee_profiles', 'ep_position_idx'))->toBe($applied)
        ->and(Schema::hasForeignKey('employee_profiles', ['position_id']))->toBe($applied)
        // The global unique on the title is the one piece that goes the other way.
        ->and(Schema::hasIndex('positions', ['name'], 'unique'))->toBe(! $applied);

    if ($applied) {
        foreach (['orgn_code_unq', 'orgn_parent_name_unq', 'orgn_parent_order_idx'] as $index) {
            expect(Schema::hasIndex('org_nodes', $index))->toBeTrue("org_nodes.{$index} is missing");
        }
        expect(Schema::hasForeignKey('org_nodes', ['parent_id']))->toBeTrue();
    }
}

function orgmigNode(string $kind, string $name, ?string $parentId = null): OrgNode
{
    $node = new OrgNode;
    $node->forceFill([
        'code' => (string) random_int(100000, 999999),
        'kind' => $kind,
        'name' => $name,
        'parent_id' => $parentId,
    ])->save();

    return $node;
}

function orgmigPosition(string $name, ?string $nodeId = null): Position
{
    $position = new Position;
    $position->forceFill([
        'code' => (string) random_int(100000, 999999),
        'name' => $name,
        'org_node_id' => $nodeId,
    ])->save();

    return $position;
}

/** An employee holding `$position` as text, with a fixed `updated_at`. */
function orgmigEmployee(?string $position, ?string $positionId = null): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'ORG-'.Str::upper(Str::random(8)),
        'first_name' => 'Lea',
        'last_name' => 'Santos',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-15',
        'position' => $position,
        'position_id' => $positionId,
        'updated_at' => '2026-01-02 03:04:05.678',
    ])->save();

    return $employee;
}

/**
 * The stored row, read past Eloquent.
 *
 * @return array{position: string|null, position_id: string|null, updated_at: string}
 */
function orgmigRow(EmployeeProfile $employee): array
{
    $row = (array) DB::table('employee_profiles')->where('id', $employee->id)->first(['position', 'position_id', 'updated_at']);

    return [
        'position' => isset($row['position']) ? (string) $row['position'] : null,
        'position_id' => isset($row['position_id']) ? (string) $row['position_id'] : null,
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

/*
|--------------------------------------------------------------------------
| Retry safety
|--------------------------------------------------------------------------
*/

it('applies every migration twice on top of a migrated schema and changes nothing', function () {
    orgmigAssertApplied(true);

    try {
        orgmigRestore();
        orgmigRestore();
    } finally {
        orgmigRestore();
    }

    orgmigAssertApplied(true);
});

it('puts back a missing sentinel once, with a millisecond timestamp', function () {
    $lock = orgmigMigration('0010');
    DB::table('locks')->where('name', 'copems:org-structure')->delete();

    $lock->up();
    $lock->up();

    $row = DB::table('locks')->where('name', 'copems:org-structure')->first();
    expect(DB::table('locks')->where('name', 'copems:org-structure')->count())->toBe(1)
        ->and((string) ($row->created_at ?? ''))->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/');
});

it('rolls the chain back newest first, tolerates a second rollback, and rebuilds it', function () {
    try {
        foreach (array_reverse(ORGMIG_FILES) as $file) {
            (require base_path("database/migrations/{$file}.php"))->down();
        }
        orgmigAssertApplied(false);

        foreach (array_reverse(ORGMIG_FILES) as $file) {
            (require base_path("database/migrations/{$file}.php"))->down();
        }
        orgmigAssertApplied(false);

        orgmigRestore();
        orgmigRestore();
        orgmigAssertApplied(true);
    } finally {
        orgmigRestore();
    }
});

it('refuses to delete a position someone holds, or a department that still has a unit', function () {
    $department = orgmigNode(OrgNode::KIND_DEPARTMENT, 'Operations');
    $unit = orgmigNode(OrgNode::KIND_UNIT, 'Dispatch', $department->id);
    $position = orgmigPosition('Dispatcher', $unit->id);
    orgmigEmployee('Dispatcher', $position->id);

    expect(fn () => DB::table('positions')->where('id', $position->id)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('org_nodes')->where('id', $unit->id)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('org_nodes')->where('id', $department->id)->delete())->toThrow(QueryException::class);

    expect(Position::query()->whereKey($position->id)->exists())->toBeTrue()
        ->and(OrgNode::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| The link backfill
|--------------------------------------------------------------------------
*/

it('links only a text that is exactly one position name, and never writes the text or updated_at', function () {
    $log = Log::spy();

    $driver = orgmigPosition('Driver');
    $clerk = orgmigPosition('Clerk');
    $department = orgmigNode(OrgNode::KIND_DEPARTMENT, 'Security');
    $north = orgmigNode(OrgNode::KIND_UNIT, 'North Gate', $department->id);
    $south = orgmigNode(OrgNode::KIND_UNIT, 'South Gate', $department->id);
    orgmigPosition('Guard', $north->id);
    orgmigPosition('Guard', $south->id);

    $exact = orgmigEmployee('Driver');
    $lower = orgmigEmployee('driver');
    $spaced = orgmigEmployee('Driver ');
    $offList = orgmigEmployee('Messenger');
    $shared = orgmigEmployee('Guard');
    $blank = orgmigEmployee('');
    $none = orgmigEmployee(null);
    $chosen = orgmigEmployee('Driver', $clerk->id);

    $backfill = orgmigMigration('0014');
    $backfill->up();
    $backfill->up();

    expect(orgmigRow($exact)['position_id'])->toBe($driver->id)
        ->and(orgmigRow($chosen)['position_id'])->toBe($clerk->id);
    foreach ([$lower, $spaced, $offList, $shared, $blank, $none] as $employee) {
        expect(orgmigRow($employee)['position_id'])->toBeNull();
    }

    $texts = [[$exact, 'Driver'], [$lower, 'driver'], [$spaced, 'Driver '], [$offList, 'Messenger'],
        [$shared, 'Guard'], [$blank, ''], [$none, null], [$chosen, 'Driver']];
    foreach ($texts as [$employee, $text]) {
        $row = orgmigRow($employee);
        expect($row['position'])->toBe($text)
            ->and(substr($row['updated_at'], 0, 19))->toBe('2026-01-02 03:04:05');
    }

    // One line per run, naming what stayed unlinked and how often; never the
    // blank texts or the record HR had already linked.
    $log->shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => str_contains($message, '"driver" (1)')
            && str_contains($message, '"Driver " (1)')
            && str_contains($message, '"Messenger" (1)')
            && str_contains($message, '"Guard" (1)')
            && str_contains($message, '4 employee record(s)'))
        ->twice();
});

it('stands down before the position_id column exists', function () {
    $column = orgmigMigration('0013');
    $backfill = orgmigMigration('0014');

    try {
        $column->down();
        expect(Schema::hasColumn('employee_profiles', 'position_id'))->toBeFalse();

        $backfill->up();

        expect(Schema::hasColumn('employee_profiles', 'position_id'))->toBeFalse();
    } finally {
        orgmigRestore();
    }
});

/*
|--------------------------------------------------------------------------
| The global title unique
|--------------------------------------------------------------------------
*/

it('will not restore the title unique while two positions share a title, and names it', function () {
    $drop = orgmigMigration('0015');
    $department = orgmigNode(OrgNode::KIND_DEPARTMENT, 'Finance');
    $unit = orgmigNode(OrgNode::KIND_UNIT, 'Payroll', $department->id);
    $first = orgmigPosition('Analyst', $department->id);
    $second = orgmigPosition('Analyst', $unit->id);

    try {
        expect(fn () => $drop->down())->toThrow(RuntimeException::class, 'Rename the repeated titles first: "Analyst".');
        expect(Schema::hasIndex('positions', ['name'], 'unique'))->toBeFalse();

        $second->forceFill(['name' => 'Payroll Analyst'])->save();
        $drop->down();
        $drop->down();
        expect(Schema::hasIndex('positions', 'positions_name_unique', 'unique'))->toBeTrue();

        $drop->up();
        $drop->up();
        expect(Schema::hasIndex('positions', ['name'], 'unique'))->toBeFalse();
    } finally {
        orgmigRestore();
        Position::query()->whereKey([$first->id, $second->id])->delete();
        OrgNode::query()->whereKey($unit->id)->delete();
        OrgNode::query()->whereKey($department->id)->delete();
    }
});

it('keeps the title unique until the per-placement unique exists', function () {
    $placement = orgmigMigration('0012');
    $drop = orgmigMigration('0015');

    try {
        $drop->down();
        $placement->down();

        expect(fn () => $drop->up())->toThrow(RuntimeException::class, 'run 2026_10_07_0012_add_org_node_to_positions_table first');
        expect(Schema::hasIndex('positions', ['name'], 'unique'))->toBeTrue();
    } finally {
        orgmigRestore();
    }

    orgmigAssertApplied(true);
});
