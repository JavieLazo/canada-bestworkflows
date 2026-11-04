<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The tax year columns and the tax balances table on MariaDB
 * (2026_11_02_0011, 2026_11_02_0012): a payslip's year, method and
 * refund are shaped, a balance's status and approval stamp agree, its
 * kind and year are valid, its amounts are not negative, the employee key
 * keeps it, and both migrations roll back and run twice.
 */
function taxYearMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0011_add_tax_year_to_payslips_table.php');
}

function taxBalancesMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0012_create_payroll_tax_balances_table.php');
}

/** @return array<string, mixed> */
function taxBalanceRow(string $employeeId, int $year, string $kind, string $status, float $taxable, float $withheld, ?string $approvedAt): array
{
    return ['id' => (string) Str::ulid(), 'employee_id' => $employeeId, 'tax_year' => $year, 'kind' => $kind, 'taxable_income' => $taxable, 'tax_withheld' => $withheld,
        'reason' => 'Synthetic', 'reference' => null, 'status' => $status, 'prepared_by' => null, 'prepared_by_name' => null,
        'approved_by' => null, 'approved_by_name' => null, 'approved_at' => $approvedAt,
        'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null];
}

it('adds the payslip columns once, pins their shapes, and rolls back', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = taxYearMigration();
    $migration->up();
    $migration->up();
    expect(Schema::hasColumn('payslips', 'tax_year'))->toBeTrue()
        ->and(Schema::hasColumn('payslips', 'tax_method'))->toBeTrue()
        ->and(Schema::hasColumn('payslips', 'tax_refund'))->toBeTrue()
        ->and(Schema::hasIndex('payslips', 'psl_tax_year_idx'))->toBeTrue();

    $payslip = DB::table('payslips')->first(['id']);
    if ($payslip !== null) {
        DB::table('payslips')->where('id', $payslip->id)->update(['tax_year' => 2026, 'tax_method' => 'WHOLE_YEAR', 'tax_refund' => 12.5]);
        expect(fn () => DB::table('payslips')->where('id', $payslip->id)->update(['tax_year' => 1999]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('payslips')->where('id', $payslip->id)->update(['tax_method' => 'GUESS']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('payslips')->where('id', $payslip->id)->update(['tax_refund' => -1]))->toThrow(QueryException::class);
        DB::table('payslips')->where('id', $payslip->id)->update(['tax_year' => null, 'tax_method' => null, 'tax_refund' => 0]);
    }

    $migration->down();
    expect(Schema::hasColumn('payslips', 'tax_year'))->toBeFalse()->and(Schema::hasColumn('payslips', 'tax_refund'))->toBeFalse();
    $migration->up();
    expect(Schema::hasColumn('payslips', 'tax_year'))->toBeTrue();
});

it('creates the balances table once, pins its rules, keeps the employee and rolls back', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = taxBalancesMigration();
    $migration->up();
    $migration->up();
    $stamp = now('UTC')->format('Y-m-d H:i:s.v');
    $employeeId = (string) Str::ulid();
    DB::table('employee_profiles')->insert(['id' => $employeeId, 'employee_code' => 'TAX-INT', 'first_name' => 'Synthetic', 'last_name' => 'Tax', 'date_hired' => '2024-01-01',
        'monthly_rate' => 30000, 'is_active' => true, 'created_at' => $stamp, 'updated_at' => null]);

    DB::table('payroll_tax_balances')->insert(taxBalanceRow($employeeId, 2026, 'OPENING', 'DRAFT', 100000.0, 5000.0, null));
    DB::table('payroll_tax_balances')->insert(taxBalanceRow($employeeId, 2026, 'PREVIOUS_EMPLOYER', 'APPROVED', 50000.0, 0.0, $stamp));
    expect(fn () => DB::table('payroll_tax_balances')->insert(taxBalanceRow($employeeId, 2026, 'OPENING', 'DRAFT', 1.0, 0.0, $stamp)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_tax_balances')->insert(taxBalanceRow($employeeId, 2026, 'OPENING', 'APPROVED', 1.0, 0.0, null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_tax_balances')->insert(taxBalanceRow($employeeId, 2026, 'OPENING', 'POSTED', 1.0, 0.0, $stamp)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_tax_balances')->insert(taxBalanceRow($employeeId, 2026, 'BONUS', 'DRAFT', 1.0, 0.0, null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_tax_balances')->insert(taxBalanceRow($employeeId, 1999, 'OPENING', 'DRAFT', 1.0, 0.0, null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_tax_balances')->insert(taxBalanceRow($employeeId, 2026, 'OPENING', 'DRAFT', -1.0, 0.0, null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('employee_profiles')->where('id', $employeeId)->delete())->toThrow(QueryException::class)
        ->and(DB::table('payroll_tax_balances')->where('employee_id', $employeeId)->count())->toBe(2);

    $migration->down();
    expect(Schema::hasTable('payroll_tax_balances'))->toBeFalse();
    $migration->up();
    $migration->up();
    expect(Schema::hasTable('payroll_tax_balances'))->toBeTrue();
});
