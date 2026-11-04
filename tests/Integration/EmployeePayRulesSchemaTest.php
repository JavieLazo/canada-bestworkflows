<?php

declare(strict_types=1);

use App\Models\PayrollPayRule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One employee's Pay Basis & Rates on MariaDB (2026_10_30_0001): the scope
 * kind is backfilled and must agree with the scope, only an employee's row
 * may go without figures (a "follows" version), and the migration rolls
 * back and resumes from any step.
 */
function employeeScopeMigration(): mixed
{
    return require base_path('database/migrations/2026_10_30_0001_add_employee_scope_to_payroll_pay_rules_table.php');
}

/** @return array<string, mixed> */
function employeeScopeRow(string $scope, string $type, ?string $params, string $from = '2027-01-01'): array
{
    return [
        'id' => (string) Str::ulid(), 'scope' => $scope, 'scope_type' => $type, 'effective_from' => $from, 'reason' => 'Synthetic',
        'params' => $params, 'created_by' => null, 'created_by_name' => null,
        'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null,
    ];
}

it('backfills the company, keeps the kind agreeing with the scope and lets only an employee row go without figures', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    employeeScopeMigration()->up();
    expect(PayrollPayRule::query()->sole()->scope_type)->toBe('COMPANY');

    DB::table('payroll_pay_rules')->insert(employeeScopeRow((string) Str::ulid(), 'EMPLOYEE', null));
    DB::table('payroll_pay_rules')->insert(employeeScopeRow((string) Str::ulid(), 'STATUS', '{}'));
    expect(fn () => DB::table('payroll_pay_rules')->insert(employeeScopeRow((string) Str::ulid(), 'STATUS', null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_pay_rules')->insert(employeeScopeRow('COMPANY', 'STATUS', '{}', '2028-01-01')))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_pay_rules')->insert(employeeScopeRow((string) Str::ulid(), 'COMPANY', '{}')))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_pay_rules')->insert(employeeScopeRow((string) Str::ulid(), 'PERSON', '{}')))->toThrow(QueryException::class)
        ->and(PayrollPayRule::query()->count())->toBe(3);
});

it('rolls back the employee versions and resumes from a partial run', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = employeeScopeMigration();
    DB::table('payroll_pay_rules')->insert(employeeScopeRow((string) Str::ulid(), 'EMPLOYEE', null));

    // Rolled back: every employee version goes, then the column.
    $migration->down();
    expect(Schema::hasColumn('payroll_pay_rules', 'scope_type'))->toBeFalse()
        ->and(PayrollPayRule::query()->count())->toBe(1);

    // A run stopped after the column, before the backfill and the rest, resumes.
    Schema::table('payroll_pay_rules', static fn ($t) => $t->string('scope_type', 10)->default('STATUS')->after('scope'));
    $migration->up();
    $migration->up();
    expect(PayrollPayRule::query()->sole()->scope_type)->toBe('COMPANY')
        ->and(Schema::hasIndex('payroll_pay_rules', 'ppr_scope_type_idx'))->toBeTrue();
    DB::table('payroll_pay_rules')->insert(employeeScopeRow((string) Str::ulid(), 'EMPLOYEE', null));
    expect(fn () => DB::table('payroll_pay_rules')->insert(employeeScopeRow((string) Str::ulid(), 'STATUS', null)))->toThrow(QueryException::class);
});
