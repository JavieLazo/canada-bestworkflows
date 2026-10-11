<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * On the production engine, `payroll_runs.kind` (2026_09_26_0010) carries
 * `runs_kind_chk` exactly once, refuses anything but REGULAR and
 * THIRTEENTH_MONTH, and resumes from a run killed between its column and its
 * CHECK. DatabaseMigrations rolls the schema back after each test, so EVERY
 * TEST ENDS MIGRATED.
 */
function tmschemaChecks(): int
{
    $row = DB::selectOne(
        "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_runs' AND CONSTRAINT_NAME = 'runs_kind_chk' AND CONSTRAINT_TYPE = 'CHECK'",
    );

    return is_object($row) && isset($row->n) ? (int) $row->n : 0;
}

function tmschemaRun(string $kind): void
{
    DB::table('payroll_runs')->insert(['id' => (string) Str::ulid(), 'code' => 'TMS-'.Str::random(4), 'name' => 'Kind '.Str::random(6),
        'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'status' => 'DRAFT', 'kind' => $kind,
        'created_at' => '2026-09-26 01:00:00.000']);
}

it('pins the two kinds with one CHECK and resumes a run killed before it', function () {
    expect(tmschemaChecks())->toBe(1);

    tmschemaRun('REGULAR');
    tmschemaRun('THIRTEENTH_MONTH');
    expect(fn () => tmschemaRun('WEEKLY'))->toThrow(QueryException::class, 'runs_kind_chk');
    expect(DB::table('payroll_runs')->count())->toBe(2);

    $migration = require base_path('database/migrations/2026_09_26_0010_add_kind_to_payroll_runs_table.php');
    $migration->up();
    expect(tmschemaChecks())->toBe(1);

    DB::statement('ALTER TABLE `payroll_runs` DROP CONSTRAINT `runs_kind_chk`');
    $migration->up();
    expect(tmschemaChecks())->toBe(1);
});
