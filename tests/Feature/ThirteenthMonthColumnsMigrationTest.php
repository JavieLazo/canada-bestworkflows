<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two 13th month columns (2026_09_26_0010 payroll_runs.kind,
 * 2026_09_26_0011 payroll_run_members.additional_basic_pay) roll back and
 * re-apply cleanly, and survive a repeated up() or down(). `kind` comes back
 * NOT NULL DEFAULT 'REGULAR', so every run reads REGULAR; the outside-EMS
 * figure comes back nullable. ROLLBACK LOSES which runs were 13th month runs
 * and every typed figure.
 *
 * THIS FILE ALWAYS ENDS MIGRATED: `finally` re-runs up(). A column drop
 * rebuilds the table on sqlite and can cascade into child rows inside
 * RefreshDatabase's transaction, so these tests seed nothing under the
 * tables they alter. The CHECK is MySQL/MariaDB only and belongs to
 * tests/Integration/ThirteenthMonthColumnsSchemaTest.
 */
function tmcolKind(): mixed
{
    return require base_path('database/migrations/2026_09_26_0010_add_kind_to_payroll_runs_table.php');
}

function tmcolOutside(): mixed
{
    return require base_path('database/migrations/2026_09_26_0011_add_additional_basic_pay_to_payroll_run_members_table.php');
}

/** @return array<string, mixed>|null */
function tmcolColumn(string $table, string $name): ?array
{
    foreach (Schema::getColumns($table) as $column) {
        if ($column['name'] === $name) {
            return $column;
        }
    }

    return null;
}

it('rolls payroll_runs.kind back and re-applies it twice, defaulting every run to REGULAR', function () {
    $migration = tmcolKind();

    try {
        $migration->down();
        $migration->down();
        expect(Schema::hasColumn('payroll_runs', 'kind'))->toBeFalse();

        $migration->up();
        $migration->up();
        $column = tmcolColumn('payroll_runs', 'kind');
        expect($column)->not->toBeNull()
            ->and($column['nullable'] ?? null)->toBeFalse()
            ->and(in_array($column['default'] ?? null, ['REGULAR', "'REGULAR'"], true))->toBeTrue();

        // A run inserted without a kind reads REGULAR.
        DB::table('payroll_runs')->insert(['id' => 'tmcol-run', 'code' => 'TMCOL', 'name' => 'Kindless', 'period_start' => '2026-08-01',
            'period_end' => '2026-08-15', 'status' => 'DRAFT', 'created_at' => '2026-09-26 01:00:00.000']);
        expect(DB::table('payroll_runs')->where('id', 'tmcol-run')->value('kind'))->toBe('REGULAR');
    } finally {
        $migration->up();
        DB::table('payroll_runs')->where('id', 'tmcol-run')->delete();
    }
});

it('rolls payroll_run_members.additional_basic_pay back and re-applies it twice, nullable', function () {
    $migration = tmcolOutside();

    try {
        $migration->down();
        $migration->down();
        expect(Schema::hasColumn('payroll_run_members', 'additional_basic_pay'))->toBeFalse();

        $migration->up();
        $migration->up();
        expect(tmcolColumn('payroll_run_members', 'additional_basic_pay')['nullable'] ?? null)->toBeTrue();
    } finally {
        $migration->up();
    }
});
