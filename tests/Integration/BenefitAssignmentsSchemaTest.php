<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The tax-free ceiling and benefit assignment tables on MariaDB
 * (2026_11_02_0010): the ceiling is seeded once and never negative, one
 * version per effective date; an assignment's status and approval stamp
 * agree, its span runs forward, its amount is above zero or absent, the
 * employee and type keys keep it; and the migration rolls back and runs
 * twice.
 */
function benefitTablesMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0010_create_benefit_ceilings_and_assignments_tables.php');
}

/** @return array<string, mixed> */
function benefitAssignmentRow(string $employeeId, string $typeId, string $status, ?string $endsOn, ?float $amount, ?string $approvedAt): array
{
    return ['id' => (string) Str::ulid(), 'employee_id' => $employeeId, 'type_id' => $typeId, 'starts_on' => '2026-11-01', 'ends_on' => $endsOn,
        'amount' => $amount, 'reason' => 'Synthetic', 'reference' => null, 'status' => $status, 'prepared_by' => null, 'prepared_by_name' => null,
        'approved_by' => null, 'approved_by_name' => null, 'approved_at' => $approvedAt,
        'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null];
}

it('seeds the ceiling once, pins both tables\' rules and keeps the employee and type', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = benefitTablesMigration();
    $migration->up();
    $migration->up();
    expect(DB::table('payroll_benefit_ceilings')->count())->toBe(1)
        ->and((float) DB::table('payroll_benefit_ceilings')->value('ceiling'))->toBe(90000.0);
    $stamp = now('UTC')->format('Y-m-d H:i:s.v');
    $ceiling = static fn (string $from, float $pesos): array => ['id' => (string) Str::ulid(), 'effective_from' => $from, 'ceiling' => $pesos, 'reason' => 'Synthetic',
        'created_by' => null, 'created_by_name' => null, 'created_at' => $stamp, 'updated_at' => null];
    DB::table('payroll_benefit_ceilings')->insert($ceiling('2027-01-01', 95000.0));
    expect(fn () => DB::table('payroll_benefit_ceilings')->insert($ceiling('2027-01-01', 96000.0)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_benefit_ceilings')->insert($ceiling('2028-01-01', -1.0)))->toThrow(QueryException::class);

    $employeeId = (string) Str::ulid();
    DB::table('employee_profiles')->insert(['id' => $employeeId, 'employee_code' => 'BEN-INT', 'first_name' => 'Synthetic', 'last_name' => 'Benefit', 'date_hired' => '2024-01-01',
        'monthly_rate' => 30000, 'is_active' => true, 'created_at' => $stamp, 'updated_at' => null]);
    $typeId = (string) Str::ulid();
    DB::table('payroll_incentive_types')->insert(['id' => $typeId, 'code' => 'BEN-INT', 'name' => 'Synthetic benefit', 'is_taxable' => false, 'created_at' => $stamp, 'updated_at' => null]);

    DB::table('payroll_employee_benefits')->insert(benefitAssignmentRow($employeeId, $typeId, 'DRAFT', null, null, null));
    DB::table('payroll_employee_benefits')->insert(benefitAssignmentRow($employeeId, $typeId, 'APPROVED', '2026-12-31', 750.0, $stamp));
    expect(fn () => DB::table('payroll_employee_benefits')->insert(benefitAssignmentRow($employeeId, $typeId, 'DRAFT', null, null, $stamp)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_employee_benefits')->insert(benefitAssignmentRow($employeeId, $typeId, 'APPROVED', null, null, null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_employee_benefits')->insert(benefitAssignmentRow($employeeId, $typeId, 'PAID', null, null, $stamp)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_employee_benefits')->insert(benefitAssignmentRow($employeeId, $typeId, 'DRAFT', '2026-10-31', null, null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_employee_benefits')->insert(benefitAssignmentRow($employeeId, $typeId, 'DRAFT', null, 0.0, null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('employee_profiles')->where('id', $employeeId)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_incentive_types')->where('id', $typeId)->delete())->toThrow(QueryException::class)
        ->and(DB::table('payroll_employee_benefits')->where('employee_id', $employeeId)->count())->toBe(2);
});

it('rolls back both tables and runs again on bare tables', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = benefitTablesMigration();
    $migration->up();
    $migration->down();
    expect(Schema::hasTable('payroll_benefit_ceilings'))->toBeFalse()->and(Schema::hasTable('payroll_employee_benefits'))->toBeFalse();
    $migration->up();
    $migration->up();
    expect(Schema::hasTable('payroll_benefit_ceilings'))->toBeTrue()->and(Schema::hasTable('payroll_employee_benefits'))->toBeTrue()
        ->and(DB::table('payroll_benefit_ceilings')->count())->toBe(1);
});
