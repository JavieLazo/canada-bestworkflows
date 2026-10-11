<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\ExemptBenefitLedger;
use App\Support\Access;
use Illuminate\Support\Str;

/**
 * The PHP 90,000 ledger reads what each employee's posted payslips already
 * kept exempt in one calendar year: the sum of `payslips.exempt_benefits`,
 * per employee, over the payslips whose PERIOD ENDS in that year. Every
 * requested employee gets a figure (0.0 when nothing was posted), other
 * years and other employees never leak in, and the read is scoped through
 * the actor like every payroll read — a non-all-data actor sees only their
 * own payslips, an unlinked one none.
 */
function exledgerActor(bool $allData, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Ledger '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->getKey(), 'module' => 'PAYROLL'])->save();

    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function exledgerEmployee(string $code): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => 'Ledger',
        'last_name' => $code,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'monthly_rate' => 30000,
        'is_active' => true,
    ])->save();

    return $employee;
}

function exledgerPayslip(EmployeeProfile $employee, string $start, string $end, float $exempt): Payslip
{
    $payslip = new Payslip;
    $payslip->forceFill([
        'employee_id' => $employee->getKey(),
        'period_start' => $start,
        'period_end' => $end,
        'gross_pay' => 20_000,
        'total_deductions' => 0,
        'net_pay' => 20_000,
        'exempt_benefits' => $exempt,
    ])->save();

    return $payslip;
}

it('sums each employee\'s exempt benefits over the payslips that end in the year', function () {
    $ana = exledgerEmployee('LED-001');
    $ben = exledgerEmployee('LED-002');
    $actor = exledgerActor(allData: true);

    exledgerPayslip($ana, '2026-06-01', '2026-06-15', 10_000.50);
    exledgerPayslip($ana, '2026-12-16', '2026-12-31', 20_000.25);
    exledgerPayslip($ana, '2026-01-01', '2026-12-31', 45_000.00);
    // Another year on either side: never counted.
    exledgerPayslip($ana, '2025-12-16', '2025-12-31', 30_000.00);
    exledgerPayslip($ana, '2027-01-01', '2027-01-15', 5_000.00);
    // A period that STARTS in 2025 and ends in 2026 belongs to 2026.
    exledgerPayslip($ben, '2025-12-26', '2026-01-10', 1_234.56);

    expect(app(ExemptBenefitLedger::class)->usedBefore($actor, [$ana->id, $ben->id], 2026))
        ->toBe([$ana->id => 75_000.75, $ben->id => 1_234.56]);
});

it('reports 0.0 for an employee with no posted exempt benefits, and nothing for no employees', function () {
    $ana = exledgerEmployee('LED-011');
    $ben = exledgerEmployee('LED-012');
    $actor = exledgerActor(allData: true);
    exledgerPayslip($ana, '2026-08-01', '2026-08-15', 0.0);

    expect(app(ExemptBenefitLedger::class)->usedBefore($actor, [$ana->id, $ben->id], 2026))
        ->toBe([$ana->id => 0.0, $ben->id => 0.0])
        ->and(app(ExemptBenefitLedger::class)->usedBefore($actor, [], 2026))->toBe([]);
});

it('never counts an employee who was not asked for', function () {
    $ana = exledgerEmployee('LED-021');
    $ben = exledgerEmployee('LED-022');
    $actor = exledgerActor(allData: true);
    exledgerPayslip($ana, '2026-08-01', '2026-08-15', 1_000.0);
    exledgerPayslip($ben, '2026-08-01', '2026-08-15', 9_000.0);

    expect(app(ExemptBenefitLedger::class)->usedBefore($actor, [$ana->id], 2026))->toBe([$ana->id => 1_000.0]);
});

it('scopes the read through the actor: own payslips only without all-data, none when unlinked', function () {
    $ana = exledgerEmployee('LED-031');
    $ben = exledgerEmployee('LED-032');
    exledgerPayslip($ana, '2026-08-01', '2026-08-15', 1_000.0);
    exledgerPayslip($ben, '2026-08-01', '2026-08-15', 9_000.0);

    $own = exledgerActor(allData: false, employeeId: $ana->id);
    expect(app(ExemptBenefitLedger::class)->usedBefore($own, [$ana->id, $ben->id], 2026))
        ->toBe([$ana->id => 1_000.0, $ben->id => 0.0]);

    $unlinked = exledgerActor(allData: false);
    expect(app(ExemptBenefitLedger::class)->usedBefore($unlinked, [$ana->id, $ben->id], 2026))
        ->toBe([$ana->id => 0.0, $ben->id => 0.0]);
});
