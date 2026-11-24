<?php

declare(strict_types=1);

use App\Livewire\Payroll\RunWorkspace;
use App\Models\AttendanceLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\SaveEmployeeTaxDetails;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\Queries\PayslipRecords;
use App\Modules\Payroll\Application\SourceDeductionFacts;
use App\Modules\Payroll\Domain\Canada\DeductionRates;
use App\Modules\Payroll\Domain\Canada\PayDeductions;
use App\Modules\Payroll\Domain\Canada\TaxDetails;
use App\Support\Access;
use App\Support\Money;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ScheduleFixture;

/**
 * A payroll's source deductions through the real compute path: the pension
 * contribution, the insurance premium and both income taxes are worked out
 * for every member from the facts the payroll captured — the pay date and
 * its year, the pay schedule's pays in that year, the published rates, the
 * member's tax details and what the year's posted payslips already took —
 * and join the deduction footing. The engine's own contribution and
 * withholding lines stay at zero. A finalized payroll posts them to the
 * payslip and keeps the facts in its evidence; evidence that captured none
 * deducts none; and the pay statement foots the year from posted payslips.
 */
function srcdedActor(?string $employeeId = null, bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Source '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach (['PAYROLL', 'PORTAL'] as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function srcdedEmployee(string $code): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code, 'first_name' => 'Source', 'last_name' => $code,
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'monthly_rate' => 4000, 'is_active' => true,
    ])->save();
    ScheduleFixture::standard($employee);
    // Ten working days, 08:00 to 17:00 on the suite's clock, in the two weeks from 2 August 2026.
    foreach (['03', '04', '05', '06', '07', '10', '11', '12', '13', '14'] as $day) {
        foreach ([['TIME_IN', '00:00:00'], ['TIME_OUT', '09:00:00']] as [$type, $at]) {
            AttendanceLog::query()->create([
                'employee_id' => (string) $employee->getKey(), 'log_type' => $type, 'source' => 'MANUAL',
                'is_geofenced' => true, 'is_anomaly' => false, 'logged_at' => "2026-08-{$day} {$at}",
            ]);
        }
    }

    return $employee->refresh();
}

/** @return array<string, mixed> the calculation of one employee's two weeks, paid on 21 August 2026 */
function srcdedCalculation(Access $actor, EmployeeProfile $employee): array
{
    return PayrollData::calculateDrafts([(string) $employee->getKey()], '2026-08-02', '2026-08-15', actor: $actor, payDate: '2026-08-21');
}

/** @return array{pensionable: float, pension: float, second_pension: float, insurance: float} */
function srcdedNothingYet(): array
{
    return ['pensionable' => 0.0, 'pension' => 0.0, 'second_pension' => 0.0, 'insurance' => 0.0];
}

beforeEach(function () {
    test()->travelTo(new DateTimeImmutable('2026-09-05T04:00:00Z'));
});

/* |---| The calculation |---| */

it('works out the pension contribution, the premium and both taxes for a draft, and leaves the engine lines at zero', function () {
    $employee = srcdedEmployee('SRC-001');
    $actor = srcdedActor();

    $calculation = srcdedCalculation($actor, $employee);
    $draft = $calculation['drafts'][0];
    $facts = $calculation['inputs']->sourceDeductions;

    expect($facts['pay_date'])->toBe('2026-08-21')
        ->and($facts['tax_year'])->toBe(2026)
        ->and($facts['periods'])->toBe(26)
        ->and($facts['rates_from'])->toBe('2026-01-01')
        ->and($facts['members'][(string) $employee->getKey()]['to_date'])->toBe(srcdedNothingYet());

    $expected = PayDeductions::forPay(DeductionRates::inForceOn('2026-08-21'), TaxDetails::starting(), 26, $draft->gross_pay, 0.0, srcdedNothingYet());
    expect($draft->gross_pay)->toBeGreaterThan(0.0)
        ->and($draft->cpp)->toBe($expected['cpp'])->toBeGreaterThan(0.0)
        ->and($draft->ei)->toBe($expected['ei'])->toBeGreaterThan(0.0)
        ->and($draft->federal_tax)->toBe($expected['federal_tax'])
        ->and($draft->provincial_tax)->toBe($expected['provincial_tax'])
        ->and($draft->cpp_er)->toBe($draft->cpp)
        ->and($draft->ei_er)->toBe($expected['ei_er'])
        ->and($draft->pensionable_earnings)->toBe($draft->gross_pay)
        ->and($draft->insurable_earnings)->toBe($draft->gross_pay)
        ->and($draft->sss)->toBe(0.0)
        ->and($draft->sss_er)->toBe(0.0)
        ->and($draft->philhealth)->toBe(0.0)
        ->and($draft->pagibig)->toBe(0.0)
        ->and($draft->withholding_tax)->toBe(0.0)
        ->and($draft->total_deductions)->toBe(Money::round($draft->late_deduction + $draft->undertime_deduction + PayDeductions::deducted($expected)))
        ->and($draft->net_pay)->toBe(Money::round($draft->gross_pay - $draft->total_deductions))
        ->and($calculation['statutory'][(string) $employee->getKey()]['source_deductions']['cpp'])->toBe($draft->cpp);
});

it('follows the tax details saved for the employee', function () {
    $employee = srcdedEmployee('SRC-002');
    $actor = srcdedActor();
    app(SaveEmployeeTaxDetails::class)->handle($actor, (string) $employee->getKey(), 'AB', null, null, '25', false, true);

    $draft = srcdedCalculation($actor, $employee)['drafts'][0];
    $expected = PayDeductions::forPay(DeductionRates::inForceOn('2026-08-21'), new TaxDetails('AB', null, null, 25.0, false, true), 26, $draft->gross_pay, 0.0, srcdedNothingYet());

    expect($draft->cpp)->toBe(0.0)
        ->and($draft->cpp_er)->toBe(0.0)
        ->and($draft->pensionable_earnings)->toBe(0.0)
        ->and($draft->ei)->toBeGreaterThan(0.0)
        ->and($draft->federal_tax)->toBe($expected['federal_tax']);
});

it('stops a contribution at what the year has left after the posted payslips', function () {
    $employee = srcdedEmployee('SRC-003');
    $actor = srcdedActor();
    (new Payslip)->forceFill([
        'employee_id' => $employee->getKey(), 'period_start' => '2026-07-05', 'period_end' => '2026-07-18',
        'gross_pay' => 70000, 'total_deductions' => 5320, 'net_pay' => 64680, 'tax_year' => 2026,
        'cpp' => 4200.00, 'ei' => 1120.00, 'pensionable_earnings' => 70000, 'insurable_earnings' => 70000,
    ])->save();
    // A payslip of another year never counts.
    (new Payslip)->forceFill([
        'employee_id' => $employee->getKey(), 'period_start' => '2025-12-07', 'period_end' => '2025-12-20',
        'gross_pay' => 1000, 'total_deductions' => 30, 'net_pay' => 970, 'tax_year' => 2025, 'cpp' => 20.00, 'ei' => 10.00,
    ])->save();

    $calculation = srcdedCalculation($actor, $employee);
    $draft = $calculation['drafts'][0];

    // 4,230.45 and 1,123.07 are the most an employee pays in 2026.
    expect($calculation['inputs']->sourceDeductions['members'][(string) $employee->getKey()]['to_date'])
        ->toBe(['pensionable' => 70000.0, 'pension' => 4200.0, 'second_pension' => 0.0, 'insurance' => 1120.0])
        ->and($draft->cpp)->toBe(30.45)
        ->and($draft->ei)->toBe(3.07);
});

/* |---| Evidence and posting |---| */

it('posts the source deductions to the payslip and keeps their facts in the evidence', function () {
    $employee = srcdedEmployee('SRC-004');
    srcdedActor();
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-SRC-4', 'name' => 'Source 4', 'period_start' => '2026-08-02', 'period_end' => '2026-08-15', 'status' => 'DRAFT', 'pay_date' => '2026-08-21'])->save();
    PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->getKey(), 'position' => 0, 'period_start' => '2026-08-02', 'period_end' => '2026-08-15']);

    Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('finalize');
    expect($run->refresh()->status)->toBe('FINALIZED');

    $payslip = Payslip::query()->where('run_id', $run->id)->sole();
    $payload = json_decode(PayrollFinalizationSnapshot::query()->sole()->payload, true, 512, JSON_THROW_ON_ERROR);
    $id = (string) $employee->getKey();

    expect($payslip->cpp)->toBeGreaterThan(0.0)
        ->and($payslip->ei)->toBeGreaterThan(0.0)
        ->and($payslip->federal_tax)->toBeGreaterThan(0.0)
        ->and($payslip->cpp_er)->toBe($payslip->cpp)
        ->and($payslip->sss)->toBe(0.0)
        ->and($payslip->withholding_tax)->toBe(0.0)
        ->and($payslip->tax_year)->toBe(2026)
        ->and($payslip->net_pay)->toBe(Money::round($payslip->gross_pay - $payslip->total_deductions))
        ->and($payload['inputs']['schema_version'])->toBe(PayrollInputs::SCHEMA_VERSION)
        ->and($payload['inputs']['statutory_tables'])->toBe([])
        ->and($payload['inputs']['source_deductions']['pay_date'])->toBe('2026-08-21')
        ->and($payload['inputs']['source_deductions']['periods'])->toBe(26)
        ->and($payload['inputs']['source_deductions']['members'][$id]['details']['province'])->toBe('AB')
        ->and((float) $payload['statutory'][$id]['source_deductions']['cpp'])->toBe($payslip->cpp);
});

it('stamps a payslip with the year of its pay date, and records no contribution month, base or withholding method', function () {
    expect(SourceDeductionFacts::taxYearOf('2027-01-08', '2027-01-02'))->toBe(2027)
        ->and(SourceDeductionFacts::taxYearOf('2027-01-08', '2026-12-19'))->toBe(2027)
        // A payroll with no pay date counts in the year its period ends.
        ->and(SourceDeductionFacts::taxYearOf(null, '2026-12-19'))->toBe(2026);

    // A period that ends in December and is paid in January counts in the new year.
    $this->travelTo(new DateTimeImmutable('2027-01-05T04:00:00Z'));
    $employee = srcdedEmployee('SRC-006');
    srcdedActor();
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-SRC-6', 'name' => 'Source 6', 'period_start' => '2026-12-06', 'period_end' => '2026-12-19', 'status' => 'DRAFT', 'pay_date' => '2027-01-08', 'contribution_month' => '2026-12'])->save();
    PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->getKey(), 'position' => 0, 'period_start' => '2026-12-06', 'period_end' => '2026-12-19']);

    Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('finalize');
    expect($run->refresh()->status)->toBe('FINALIZED');

    $payslip = Payslip::query()->where('run_id', $run->id)->sole();
    $inputs = json_decode(PayrollFinalizationSnapshot::query()->sole()->payload, true, 512, JSON_THROW_ON_ERROR)['inputs'];
    expect($payslip->tax_year)->toBe(2027)
        ->and($payslip->contribution_month)->toBeNull()
        ->and($payslip->contribution_base)->toBeNull()
        ->and($payslip->tax_method)->toBeNull()
        ->and($payslip->tax_refund)->toBe(0.0)
        ->and($inputs['source_deductions']['tax_year'])->toBe(2027)
        // The evidence keeps its schema-25 keys, each captured empty.
        ->and($inputs['schema_version'])->toBe(25)
        ->and($inputs['contribution_month'])->toBeNull()
        ->and($inputs['contribution_posted'])->toBe([])
        ->and($inputs['tax_year'])->toBeNull()
        ->and($inputs['statutory_tables'])->toBe([]);
});

it('restores captured facts exactly, and deducts none from evidence that captured none', function () {
    $employee = srcdedEmployee('SRC-005');
    $actor = srcdedActor();
    $calculation = srcdedCalculation($actor, $employee);
    $captured = $calculation['inputs']->toArray();

    $restored = PayrollInputs::restore(PayrollInputs::json($captured));
    expect($restored->sourceDeductions)->toEqual($calculation['inputs']->sourceDeductions)
        ->and(PayrollData::calculateInputs($restored)['drafts'][0]->cpp)->toBe($calculation['drafts'][0]->cpp);

    $none = PayrollData::calculateInputs(PayrollInputs::restore(PayrollInputs::json(['source_deductions' => null] + $captured)))['drafts'][0];
    expect($none->cpp)->toBe(0.0)
        ->and($none->ei)->toBe(0.0)
        ->and($none->federal_tax)->toBe(0.0)
        ->and($none->net_pay)->toBe(Money::round($none->gross_pay - $none->total_deductions));

    $tampered = $captured;
    $tampered['source_deductions']['periods'] = 0;
    expect(static fn () => PayrollInputs::restore(PayrollInputs::json($tampered)))->toThrow(RuntimeException::class, 'Invalid payroll source deduction evidence.');
});

/* |---| The pay statement |---| */

it('reads a posted payslip as a pay statement with the year so far, and keeps what the employer pays off the employee\'s copy', function () {
    $employee = srcdedEmployee('SRC-006');
    $actor = srcdedActor((string) $employee->getKey());
    $earlier = new Payslip;
    $earlier->forceFill([
        'employee_id' => $employee->getKey(), 'period_start' => '2026-07-19', 'period_end' => '2026-08-01',
        'work_days_pay' => 1800, 'basic_pay' => 1800, 'gross_pay' => 1800, 'tax_year' => 2026,
        'cpp' => 99.09, 'ei' => 29.34, 'federal_tax' => 120.00, 'provincial_tax' => 60.00, 'cpp_er' => 99.09, 'ei_er' => 41.08,
        'total_deductions' => 308.43, 'net_pay' => 1491.57,
    ])->save();
    $payslip = new Payslip;
    $payslip->forceFill([
        'employee_id' => $employee->getKey(), 'period_start' => '2026-08-02', 'period_end' => '2026-08-15',
        'work_days' => 10, 'daily_rate' => 200, 'work_days_pay' => 2000, 'basic_pay' => 2000, 'gross_pay' => 2000, 'tax_year' => 2026,
        'cpp' => 110.99, 'ei' => 32.60, 'federal_tax' => 150.00, 'provincial_tax' => 70.00, 'cpp_er' => 110.99, 'ei_er' => 45.64,
        'total_deductions' => 363.59, 'net_pay' => 1636.41,
    ])->save();

    $statement = app(PayslipRecords::class)->statements($actor, [$payslip->id])[$payslip->id];
    $pension = array_values(array_filter($statement['statutory'], static fn (array $row): bool => $row['label'] === 'Canada Pension Plan (CPP)'))[0];

    expect($statement['year'])->toBe(2026)
        ->and($statement['pay_date'])->toBe('2026-08-15')
        ->and($statement['earnings'][0])->toMatchArray(['label' => 'Regular Pay', 'quantity' => 10.0, 'unit' => 'days', 'rate' => 200.0, 'amount' => 2000.0, 'ytd' => 3800.0])
        ->and($statement['gross'])->toBe(['amount' => 2000.0, 'ytd' => 3800.0])
        ->and($pension)->toMatchArray(['amount' => 110.99, 'ytd' => 210.08])
        ->and($statement['statutory_total'])->toBe(['amount' => 363.59, 'ytd' => 672.02])
        ->and($statement['other'])->toBe([])
        ->and($statement['net'])->toBe(1636.41)
        ->and($statement['employer'])->toBe(['pension' => 110.99, 'insurance' => 45.64]);

    // The earlier payslip's statement stops at its own pay.
    expect(app(PayslipRecords::class)->statements($actor, [$earlier->id])[$earlier->id]['gross'])->toBe(['amount' => 1800.0, 'ytd' => 1800.0]);

    // The employee's own copy carries no employer figures; someone else's payslip is not theirs to read that way.
    $other = srcdedEmployee('SRC-007');
    $theirs = new Payslip;
    $theirs->forceFill(['employee_id' => $other->getKey(), 'period_start' => '2026-08-02', 'period_end' => '2026-08-15', 'gross_pay' => 900, 'total_deductions' => 0, 'net_pay' => 900, 'tax_year' => 2026])->save();
    $own = app(PayslipRecords::class)->statements($actor, [$payslip->id, $theirs->id], own: true);
    expect(array_keys($own))->toBe([$payslip->id])
        ->and($own[$payslip->id]['employer'])->toBeNull();
});

it('gives no statement to a reader the payslip is not visible to', function () {
    $employee = srcdedEmployee('SRC-008');
    $payslip = new Payslip;
    $payslip->forceFill(['employee_id' => $employee->getKey(), 'period_start' => '2026-08-02', 'period_end' => '2026-08-15', 'gross_pay' => 900, 'total_deductions' => 0, 'net_pay' => 900, 'tax_year' => 2026])->save();

    // A payroll holder without all-data access and with no employee link sees no one's payslip.
    $actor = srcdedActor(null, allData: false);

    expect(app(PayslipRecords::class)->statements($actor, [$payslip->id]))->toBe([])
        ->and(app(PayslipRecords::class)->statements($actor, [$payslip->id], own: true))->toBe([]);
});
