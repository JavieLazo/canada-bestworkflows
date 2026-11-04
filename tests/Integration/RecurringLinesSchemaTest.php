<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\PayrollDeduction;
use App\Models\PayrollIncentiveType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Recurring lines on MariaDB (2026_10_30_0002 to _0004): each line charges
 * exactly the catalog its kind names, a positive amount under a total no
 * smaller, a known frequency and dates in order; a catalog row a line
 * charges cannot be deleted, and the employee's lines go with the employee.
 * The migrations resume from any step.
 */

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function recurringSchemaRow(string $employeeId, array $changes = []): array
{
    return [...[
        'id' => (string) Str::ulid(), 'employee_id' => $employeeId, 'kind' => 'DEDUCTION', 'deduction_id' => null, 'incentive_type_id' => null,
        'amount' => 500, 'total' => 1500, 'frequency' => 'EVERY', 'starts_on' => '2027-01-01', 'ends_on' => null, 'note' => null,
        'created_by' => null, 'created_by_name' => null, 'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null,
    ], ...$changes];
}

it('holds each line to its kind, its amounts, its frequency and its dates, and keeps its catalog rows', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $employee = EmployeeProfile::create(['employee_code' => 'RLS-1', 'first_name' => 'Line', 'last_name' => 'Schema', 'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true]);
    $loan = PayrollDeduction::create(['code' => '97', 'name' => 'Schema Loan']);
    $allowance = PayrollIncentiveType::create(['code' => '97', 'name' => 'Schema Allowance', 'is_taxable' => true]);
    $insert = static fn (array $changes) => DB::table('payroll_recurring_lines')->insert(recurringSchemaRow($employee->id, $changes));

    $insert(['deduction_id' => $loan->id]);
    $insert(['kind' => 'INCENTIVE', 'incentive_type_id' => $allowance->id, 'total' => null]);
    foreach ([
        'a deduction with no row' => [],
        'a deduction naming an incentive too' => ['deduction_id' => $loan->id, 'incentive_type_id' => $allowance->id],
        'an incentive with a total' => ['kind' => 'INCENTIVE', 'incentive_type_id' => $allowance->id],
        'no amount' => ['deduction_id' => $loan->id, 'amount' => 0],
        'a total below the amount' => ['deduction_id' => $loan->id, 'total' => 100],
        'an unknown frequency' => ['deduction_id' => $loan->id, 'frequency' => 'YEARLY'],
        'an end before the start' => ['deduction_id' => $loan->id, 'ends_on' => '2026-12-31'],
    ] as $case => $changes) {
        expect(fn () => $insert($changes))->toThrow(QueryException::class, null, $case);
    }
    expect(fn () => DB::table('payroll_deductions')->where('id', $loan->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_incentive_types')->where('id', $allowance->id)->delete())->toThrow(QueryException::class);

    DB::table('employee_profiles')->where('id', $employee->id)->delete();
    expect(DB::table('payroll_recurring_lines')->count())->toBe(0);
});

it('resumes each recurring-line migration from a partial run', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $lines = require base_path('database/migrations/2026_10_30_0002_create_payroll_recurring_lines_table.php');
    $ledger = require base_path('database/migrations/2026_10_30_0003_add_recurring_line_id_to_payslip_lines.php');
    $skips = require base_path('database/migrations/2026_10_30_0004_add_skipped_recurring_lines_to_payroll_run_members_table.php');

    $skips->down();
    $ledger->down();
    expect(Schema::hasColumn('payslip_deductions', 'recurring_line_id'))->toBeFalse()
        ->and(Schema::hasColumn('payroll_run_members', 'skipped_recurring_lines'))->toBeFalse();

    // A run stopped after the payslip_deductions column, before its index and foreign key.
    Schema::table('payslip_deductions', static fn ($t) => $t->char('recurring_line_id', 26)->nullable());
    $lines->up();
    $ledger->up();
    $ledger->up();
    $skips->up();
    $skips->up();
    expect(Schema::hasForeignKey('payslip_deductions', ['recurring_line_id']))->toBeTrue()
        ->and(Schema::hasForeignKey('payslip_incentives', ['recurring_line_id']))->toBeTrue()
        ->and(Schema::hasIndex('payroll_recurring_lines', 'prl_employee_idx'))->toBeTrue()
        ->and(Schema::hasColumn('payroll_run_members', 'skipped_recurring_lines'))->toBeTrue();
});
