<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The payslip contribution columns on MariaDB (2026_11_02_0009): a month
 * is NULL or `YYYY-MM`, the migration runs twice, and it rolls back the
 * columns with their index and CHECK.
 */
function contribMonthMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0009_add_contribution_month_to_payslips_table.php');
}

it('adds the month and base once, pins the month shape, and rolls back', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = contribMonthMigration();
    $migration->up();
    $migration->up();
    expect(Schema::hasColumn('payslips', 'contribution_month'))->toBeTrue()
        ->and(Schema::hasColumn('payslips', 'contribution_base'))->toBeTrue()
        ->and(Schema::hasIndex('payslips', 'psl_contribution_month_idx'))->toBeTrue();

    $payslip = DB::table('payslips')->first(['id']);
    if ($payslip !== null) {
        DB::table('payslips')->where('id', $payslip->id)->update(['contribution_month' => '2026-11']);
        expect(fn () => DB::table('payslips')->where('id', $payslip->id)->update(['contribution_month' => '2026-13']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('payslips')->where('id', $payslip->id)->update(['contribution_month' => 'Nov 26']))->toThrow(QueryException::class);
        DB::table('payslips')->where('id', $payslip->id)->update(['contribution_month' => null]);
    }

    $migration->down();
    expect(Schema::hasColumn('payslips', 'contribution_month'))->toBeFalse()
        ->and(Schema::hasColumn('payslips', 'contribution_base'))->toBeFalse();
    $migration->up();
    expect(Schema::hasColumn('payslips', 'contribution_month'))->toBeTrue();
});
