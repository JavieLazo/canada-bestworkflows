<?php

declare(strict_types=1);

use App\Models\PayrollPayRule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The employee pay-policy approval migrations on MariaDB
 * (2026_11_02_0002, 2026_11_02_0003): every existing pay-rule row is
 * backfilled approved with its stamp, the CHECKs keep a draft an
 * employee's and a status in step with its stamp, the policy table
 * refuses a deletion of its employee and an approved row without a
 * stamp, and both migrations roll back and resume from any step.
 */
function govPolicyApprovalMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0002_add_approval_to_payroll_pay_rules_table.php');
}

function govPolicyTableMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0003_create_payroll_gov_deduction_policies_table.php');
}

/** @return array<string, mixed> */
function govPolicyPayRuleRow(string $scope, string $type, string $status, ?string $approvedAt, string $from = '2027-01-01'): array
{
    return [
        'id' => (string) Str::ulid(), 'scope' => $scope, 'scope_type' => $type, 'effective_from' => $from, 'reason' => 'Synthetic',
        'params' => '{}', 'status' => $status, 'created_by' => null, 'created_by_name' => null,
        'approved_by' => null, 'approved_by_name' => null, 'approved_at' => $approvedAt,
        'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null,
    ];
}

/** @return array<string, mixed> */
function govPolicyRow(string $employeeId, string $status, ?string $approvedAt, string $from = '2027-01-01'): array
{
    return [
        'id' => (string) Str::ulid(), 'employee_id' => $employeeId, 'effective_from' => $from, 'params' => '{}', 'reason' => 'Synthetic',
        'status' => $status, 'prepared_by' => null, 'prepared_by_name' => null, 'approved_by' => null, 'approved_by_name' => null,
        'approved_at' => $approvedAt, 'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null,
    ];
}

it('backfills every pay-rule row approved and keeps drafts to employee rows with the stamp agreeing', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = govPolicyApprovalMigration();
    $migration->up();
    $company = PayrollPayRule::query()->where('scope', 'COMPANY')->sole();
    expect($company->status)->toBe('APPROVED')->and($company->approved_at)->not->toBeNull();

    $stamp = now('UTC')->format('Y-m-d H:i:s.v');
    $employee = (string) Str::ulid();
    DB::table('payroll_pay_rules')->insert(govPolicyPayRuleRow($employee, 'EMPLOYEE', 'DRAFT', null));
    DB::table('payroll_pay_rules')->insert(govPolicyPayRuleRow((string) Str::ulid(), 'STATUS', 'APPROVED', $stamp));
    expect(fn () => DB::table('payroll_pay_rules')->insert(govPolicyPayRuleRow((string) Str::ulid(), 'STATUS', 'DRAFT', null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_pay_rules')->insert(govPolicyPayRuleRow($employee, 'EMPLOYEE', 'DRAFT', $stamp, '2027-02-01')))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_pay_rules')->insert(govPolicyPayRuleRow($employee, 'EMPLOYEE', 'APPROVED', null, '2027-03-01')))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_pay_rules')->insert(govPolicyPayRuleRow($employee, 'EMPLOYEE', 'PENDING', null, '2027-04-01')))->toThrow(QueryException::class)
        ->and(PayrollPayRule::query()->count())->toBe(3);
});

it('rolls back the drafts and the columns, and resumes from a partial run', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = govPolicyApprovalMigration();
    $migration->up();
    DB::table('payroll_pay_rules')->insert(govPolicyPayRuleRow((string) Str::ulid(), 'EMPLOYEE', 'DRAFT', null));

    $migration->down();
    expect(Schema::hasColumn('payroll_pay_rules', 'status'))->toBeFalse()
        ->and(Schema::hasColumn('payroll_pay_rules', 'approved_at'))->toBeFalse()
        ->and(PayrollPayRule::query()->count())->toBe(1);

    // A run stopped after the status column, before the rest, resumes.
    Schema::table('payroll_pay_rules', static fn ($t) => $t->string('status', 10)->default('APPROVED')->after('params'));
    $migration->up();
    $migration->up();
    expect(Schema::hasColumn('payroll_pay_rules', 'approved_at'))->toBeTrue()
        ->and(Schema::hasIndex('payroll_pay_rules', 'ppr_status_idx'))->toBeTrue()
        ->and(PayrollPayRule::query()->sole()->approved_at)->not->toBeNull();
});

it('creates the policy table with its keys and checks, and resumes from a partial run', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = govPolicyTableMigration();
    $migration->down();
    expect(Schema::hasTable('payroll_gov_deduction_policies'))->toBeFalse();
    $migration->up();
    $migration->up();

    // A run stopped after the create, before the indexes, resumes.
    Schema::table('payroll_gov_deduction_policies', static fn ($t) => $t->dropForeign('pgdp_employee_fk'));
    Schema::table('payroll_gov_deduction_policies', static fn ($t) => $t->dropUnique('pgdp_employee_from_unq'));
    $migration->up();
    expect(Schema::hasIndex('payroll_gov_deduction_policies', 'pgdp_employee_from_unq'))->toBeTrue()
        ->and(Schema::hasForeignKey('payroll_gov_deduction_policies', ['employee_id']))->toBeTrue();

    $employeeId = (string) Str::ulid();
    DB::table('employee_profiles')->insert([
        'id' => $employeeId, 'employee_code' => 'GDP-INT', 'first_name' => 'Synthetic', 'last_name' => 'Policy', 'date_hired' => '2024-01-01',
        'is_active' => true, 'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null,
    ]);
    $stamp = now('UTC')->format('Y-m-d H:i:s.v');
    DB::table('payroll_gov_deduction_policies')->insert(govPolicyRow($employeeId, 'DRAFT', null));
    DB::table('payroll_gov_deduction_policies')->insert(govPolicyRow($employeeId, 'APPROVED', $stamp, '2027-02-01'));
    expect(fn () => DB::table('payroll_gov_deduction_policies')->insert(govPolicyRow($employeeId, 'DRAFT', null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_gov_deduction_policies')->insert(govPolicyRow($employeeId, 'APPROVED', null, '2027-03-01')))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_gov_deduction_policies')->insert(govPolicyRow($employeeId, 'DRAFT', $stamp, '2027-04-01')))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_gov_deduction_policies')->insert(govPolicyRow((string) Str::ulid(), 'DRAFT', null)))->toThrow(QueryException::class)
        // The employee's history keeps the employee.
        ->and(fn () => DB::table('employee_profiles')->where('id', $employeeId)->delete())->toThrow(QueryException::class)
        ->and(DB::table('payroll_gov_deduction_policies')->count())->toBe(2);
});
