<?php

declare(strict_types=1);

use App\Domain\Payroll\PayslipDraft;
use App\Domain\Payroll\Statutory;
use App\Domain\Payroll\StatutoryLines;
use App\Livewire\Payroll\IndividualPayslip;
use App\Livewire\Payroll\RunWorkspace;
use App\Livewire\Portal\PortalPage;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\SavePayroll;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollDraft;
use App\Modules\Payroll\Domain\CutoffAdvice;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Modules\Workforce\Contracts\EmployeePayData;
use App\Support\Access;
use App\Support\Money;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ScheduleFixture;

/**
 * SSS, PhilHealth, Pag-IBIG and withholding are computed through the REAL
 * pay-context path: the statutory columns stored on `employee_profiles` (no
 * screen edits them) reach the engine through Workforce's EmployeePayData,
 * are captured in the evidence, and decide every line — a switch turns a
 * line off, a fixed figure replaces the table, a MONTHLY mode charges the
 * whole month on the month-ending cut-off. An officer can still overtype
 * any statutory line: a typed employee share wins and the tax is RE-DERIVED
 * from the shares the payslip will carry (unless the tax itself was typed);
 * the employer share stays computed; an emptied or unreadable cell returns
 * to the computed figure while a typed 0 stays an override; and finalize
 * records what was computed and what was typed beside the posted results. A
 * period that is not a standard cut-off is warned about and still finalizes.
 * The employer shares, never deducted, reach the posted payslip, the portal
 * and the CSV.
 */
/** @param  list<string>  $modules */
function statFeatActor(array $modules = ['PAYROLL'], bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Statutory '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

/**
 * @param  array<string, mixed>  $overrides  employee_profiles columns over a 30,000-a-month regular employee
 */
function statFeatEmployee(string $code, array $overrides = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_merge([
        'employee_code' => $code,
        'first_name' => 'Stat',
        'last_name' => $code,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'monthly_rate' => 30000,
        'is_active' => true,
    ], $overrides))->save();
    ScheduleFixture::standard($employee);

    return $employee->refresh();
}

/**
 * 08:00-17:00 Manila on each given August 2026 day.
 *
 * @param  list<string>  $days  two-digit day numbers
 */
function statFeatWorked(EmployeeProfile $employee, array $days): void
{
    foreach ($days as $day) {
        foreach ([['TIME_IN', '00:00:00'], ['TIME_OUT', '09:00:00']] as [$type, $at]) {
            AttendanceLog::query()->create([
                'employee_id' => (string) $employee->getKey(),
                'log_type' => $type,
                'source' => 'MANUAL',
                'is_geofenced' => true,
                'is_anomaly' => false,
                'logged_at' => "2026-08-{$day} {$at}",
            ]);
        }
    }
}

/** The ten weekdays of August 2026's first cut-off. */
const STAT_FEAT_FIRST_HALF = ['03', '04', '05', '06', '07', '10', '11', '12', '13', '14'];

/** The eleven weekdays of its second cut-off. */
const STAT_FEAT_SECOND_HALF = ['17', '18', '19', '20', '21', '24', '25', '26', '27', '28', '31'];

/**
 * One employee's draft and statutory record, through the real compute path.
 *
 * @param  array<string, mixed>  $typed  typed deductions for this employee
 * @return array{draft: PayslipDraft, statutory: array{computed: array<string, float>, overridden: list<string>, withholding_rederived: bool}}
 */
function statFeatDraft(Access $actor, EmployeeProfile $employee, string $start, string $end, array $typed = []): array
{
    $id = (string) $employee->getKey();
    $calculation = PayrollData::calculateDrafts([$id], $start, $end, $typed === [] ? [] : [$id => $typed], [], [], false, $actor);

    return ['draft' => $calculation['drafts'][0], 'statutory' => $calculation['statutory'][$id]];
}

/** @param list<EmployeeProfile> $employees */
function statFeatRun(string $code, array $employees, string $start = '2026-08-01', string $end = '2026-08-15'): PayrollRun
{
    $run = new PayrollRun;
    $run->forceFill(['code' => $code, 'name' => 'Statutory '.$code, 'period_start' => $start, 'period_end' => $end, 'status' => 'DRAFT'])->save();

    foreach ($employees as $position => $employee) {
        PayrollRunMember::create([
            'run_id' => $run->id,
            'employee_id' => $employee->getKey(),
            'position' => $position,
            'period_start' => $start,
            'period_end' => $end,
        ]);
    }

    return $run;
}

beforeEach(function () {
    // Both August 2026 cut-offs are in the past.
    test()->travelTo(new DateTimeImmutable('2026-09-05T12:00:00+08:00'));
});

/* |---| Computed through the real path |---| */

it('computes every statutory line from the employee record through the real pay-context path', function () {
    $employee = statFeatEmployee('STAT-001');
    statFeatWorked($employee, STAT_FEAT_FIRST_HALF);
    $actor = statFeatActor();

    ['draft' => $d, 'statutory' => $record] = statFeatDraft($actor, $employee, '2026-08-01', '2026-08-15');

    expect($d->gross_pay)->toBeGreaterThan(0.0)
        ->and($d->sss)->toBe(Money::round(Statutory::sss(30000)['employee'] / 2))
        ->and($d->sss)->toBe(750.0)
        ->and($d->sss_er)->toBe(1515.0)
        ->and($d->philhealth)->toBe(375.0)
        ->and($d->philhealth_er)->toBe(375.0)
        ->and($d->pagibig)->toBe(100.0)
        ->and($d->pagibig_er)->toBe(100.0);

    $taxable = StatutoryLines::taxable($d->gross_pay, 750.0, 375.0, 100.0, $d->late_deduction, $d->undertime_deduction);
    expect($d->withholding_tax)->toBe(Statutory::semiMonthlyWithholdingTax($taxable))
        ->and($d->withholding_tax)->toBeGreaterThan(0.0)
        ->and($d->taxable_compensation)->toBe(Money::round($taxable))
        ->and($d->total_deductions)->toBe(Money::round($d->late_deduction + $d->undertime_deduction + 750.0 + 375.0 + 100.0 + $d->withholding_tax))
        ->and($d->net_pay)->toBe(Money::round($d->gross_pay - $d->total_deductions));

    expect($record)->toBe([
        'computed' => [
            'sss' => 750.0, 'sss_er' => 1515.0, 'philhealth' => 375.0, 'philhealth_er' => 375.0,
            'pagibig' => 100.0, 'pagibig_er' => 100.0, 'withholding_tax' => $d->withholding_tax,
        ],
        'overridden' => [],
        // No computed deduction switched off: every row is on the list.
        'off' => [],
        'withholding_rederived' => false,
        // No incentives: nothing against the PHP 90,000 ceiling.
        'benefits' => ['ytd_before' => 0.0, 'exempt' => 0.0, 'ceiling' => 0.0, 'taxable_excess' => 0.0],
    ]);
});

it('charges a MONTHLY-mode line the whole month on the second cut-off and nothing on the first', function () {
    $employee = statFeatEmployee('STAT-002', ['sss_mode' => 'MONTHLY', 'philhealth_mode' => 'SEMI_MONTHLY', 'pagibig_mode' => 'MONTHLY']);
    statFeatWorked($employee, [...STAT_FEAT_FIRST_HALF, ...STAT_FEAT_SECOND_HALF]);
    $actor = statFeatActor();

    $first = statFeatDraft($actor, $employee, '2026-08-01', '2026-08-15')['draft'];
    $second = statFeatDraft($actor, $employee, '2026-08-16', '2026-08-31')['draft'];

    expect([$first->sss, $first->sss_er, $first->philhealth, $first->pagibig, $first->pagibig_er])->toBe([0.0, 0.0, 375.0, 0.0, 0.0])
        ->and([$second->sss, $second->sss_er, $second->philhealth, $second->pagibig, $second->pagibig_er])->toBe([1500.0, 3030.0, 375.0, 200.0, 200.0]);
});

it('posts nothing for a line whose switch is off, and a fixed monthly figure where one is set', function () {
    $employee = statFeatEmployee('STAT-003', [
        'allow_philhealth' => false,
        'allow_withholding_tax' => false,
        'fixed_sss_ee' => 800,
        'fixed_pagibig_er' => 250,
    ]);
    statFeatWorked($employee, STAT_FEAT_FIRST_HALF);
    $d = statFeatDraft(statFeatActor(), $employee, '2026-08-01', '2026-08-15')['draft'];

    expect($d->philhealth)->toBe(0.0)
        ->and($d->philhealth_er)->toBe(0.0)
        ->and($d->withholding_tax)->toBe(0.0)
        // Half of the fixed 800; the employer share keeps the table.
        ->and($d->sss)->toBe(400.0)
        ->and($d->sss_er)->toBe(1515.0)
        ->and($d->pagibig)->toBe(100.0)
        ->and($d->pagibig_er)->toBe(125.0);
});

it('posts no contribution and no tax on a payslip with no gross pay', function () {
    $employee = statFeatEmployee('STAT-004');
    $d = statFeatDraft(statFeatActor(), $employee, '2026-08-01', '2026-08-15')['draft'];

    expect($d->gross_pay)->toBe(0.0)
        ->and([$d->sss, $d->sss_er, $d->philhealth, $d->philhealth_er, $d->pagibig, $d->pagibig_er, $d->withholding_tax])
        ->toBe([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0]);
});

/* |---| An officer's typed figures |---| */

it('lets a typed share win, keeps the employer share computed, and re-derives the tax from the shares posted', function () {
    $employee = statFeatEmployee('STAT-005');
    statFeatWorked($employee, STAT_FEAT_FIRST_HALF);
    $actor = statFeatActor();

    $computed = statFeatDraft($actor, $employee, '2026-08-01', '2026-08-15')['draft'];
    ['draft' => $d, 'statutory' => $record] = statFeatDraft($actor, $employee, '2026-08-01', '2026-08-15', ['sss' => 1350.5]);

    $taxable = StatutoryLines::taxable($d->gross_pay, 1350.5, 375.0, 100.0, $d->late_deduction, $d->undertime_deduction);
    expect($d->sss)->toBe(1350.5)
        ->and($d->sss_er)->toBe(1515.0)
        ->and($d->withholding_tax)->toBe(Statutory::semiMonthlyWithholdingTax($taxable))
        ->and($d->withholding_tax)->toBeLessThan($computed->withholding_tax)
        ->and($d->taxable_compensation)->toBe(Money::round($taxable))
        ->and($d->total_deductions)->toBe(Money::round($d->late_deduction + $d->undertime_deduction + 1350.5 + 375.0 + 100.0 + $d->withholding_tax))
        ->and($record['overridden'])->toBe(['sss'])
        ->and($record['withholding_rederived'])->toBeTrue()
        ->and($record['computed']['sss'])->toBe(750.0)
        ->and($record['computed']['withholding_tax'])->toBe($computed->withholding_tax);
});

it('keeps a typed withholding figure over the re-derived one', function () {
    $employee = statFeatEmployee('STAT-006');
    statFeatWorked($employee, STAT_FEAT_FIRST_HALF);
    ['draft' => $d, 'statutory' => $record] = statFeatDraft(statFeatActor(), $employee, '2026-08-01', '2026-08-15', ['sss' => 1000, 'withholding_tax' => 123.45]);

    expect($d->sss)->toBe(1000.0)
        ->and($d->withholding_tax)->toBe(123.45)
        ->and($record['overridden'])->toBe(['sss', 'withholding_tax'])
        ->and($record['withholding_rederived'])->toBeFalse();
});

it('returns an emptied or unreadable cell to the computed figure, and keeps a typed zero as an override', function () {
    $employee = statFeatEmployee('STAT-007');
    statFeatWorked($employee, STAT_FEAT_FIRST_HALF);
    statFeatActor();
    $run = statFeatRun('PR-STAT-7', [$employee]);
    $id = (string) $employee->getKey();

    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->call('updateDeduction', $id, 'philhealth', '0');
    expect($page->get('draftDeductions')[$id]['philhealth'])->toBe(0.0);
    $page->assertSeeHtml('data-overridden="philhealth"');

    $page->call('updateDeduction', $id, 'philhealth', '');
    expect(array_key_exists($id, $page->get('draftDeductions')))->toBeFalse();
    $page->assertDontSeeHtml('data-overridden="philhealth"');

    $page->call('updateDeduction', $id, 'sss', '900')
        ->call('updateDeduction', $id, 'sss', 'not a number');
    expect(array_key_exists($id, $page->get('draftDeductions')))->toBeFalse();
});

it('names the typed statutory lines in the save audit, and keeps the plain sentence without them', function () {
    $ana = statFeatEmployee('STAT-012');
    $ben = statFeatEmployee('STAT-013');
    $actor = statFeatActor();
    $a = (string) $ana->getKey();
    $b = (string) $ben->getKey();

    // One typed SSS and a typed "other deductions": only the first is a
    // statutory line typed over a computed figure.
    $created = app(SavePayroll::class)->handle($actor, new PayrollDraft(
        null, 'Audit draft', '2026-08-01', '2026-08-15', [$a, $b],
        [$a => ['sss' => 900, 'other_deductions' => 50]], [], [],
    ));
    expect(AuditLog::query()->where('action', 'PAYROLL_RUN_CREATED')->sole()->details)
        ->toBe('Audit draft created as a draft with 2 employees; 1 statutory line typed over the computed figure');

    // Each save a moment after the last, as real saves are: under the frozen
    // clock an unchanged name and period would make MariaDB report 0 changed
    // rows, which SavePayroll's DRAFT guard reads as a lost race.
    $this->travel(1)->seconds();

    // Two lines across two employees: plural.
    app(SavePayroll::class)->handle($actor, new PayrollDraft(
        $created['id'], 'Audit draft', '2026-08-01', '2026-08-15', [$a, $b],
        [$a => ['sss' => 900], $b => ['withholding_tax' => 0]], [], [],
    ));
    // None at all: the plain sentence, with no statutory clause.
    $this->travel(1)->seconds();
    app(SavePayroll::class)->handle($actor, new PayrollDraft(
        $created['id'], 'Audit draft', '2026-08-01', '2026-08-15', [$a, $b],
        [$b => ['other_deductions' => 25]], [], [],
    ));

    expect(AuditLog::query()->where('action', 'PAYROLL_RUN_UPDATED')->orderBy('created_at')->orderBy('id')->pluck('details')->all())
        ->toBe(['Audit draft saved; 2 statutory lines typed over the computed figure', 'Audit draft saved']);
});

/* |---| Finalize records it |---| */

it('records the computed lines and the overrides with the finalized evidence and the audit', function () {
    $employee = statFeatEmployee('STAT-008');
    statFeatWorked($employee, STAT_FEAT_FIRST_HALF);
    statFeatActor();
    $run = statFeatRun('PR-STAT-8', [$employee]);
    PayrollRunMember::query()->where('run_id', $run->id)->update(['deduction_pagibig' => 50]);

    Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('finalize');
    expect($run->refresh()->status)->toBe('FINALIZED');

    $payslip = Payslip::query()->where('run_id', $run->id)->sole();
    $payload = json_decode(PayrollFinalizationSnapshot::query()->sole()->payload, true, 512, JSON_THROW_ON_ERROR);
    $id = (string) $employee->getKey();

    expect($payload['statutory'][$id]['overridden'])->toBe(['pagibig'])
        ->and($payload['statutory'][$id]['withholding_rederived'])->toBeTrue()
        ->and((float) $payload['statutory'][$id]['computed']['pagibig'])->toBe(100.0)
        ->and($payload['inputs']['employees'][0])->toMatchArray(['allow_sss' => true, 'sss_mode' => 'SEMI_MONTHLY', 'annualize_tax' => false])
        ->and($payslip->pagibig)->toBe(50.0)
        ->and($payslip->pagibig_er)->toBe(100.0)
        ->and($payslip->taxable_compensation)->toBe(Money::round(StatutoryLines::taxable(
            $payslip->gross_pay, $payslip->sss, $payslip->philhealth, 50.0, $payslip->late_deduction, $payslip->undertime_deduction,
        )));

    $audit = AuditLog::query()->where('action', 'PAYROLL_FINALIZED')->sole();
    expect($audit->details)->toContain('; 1 statutory line typed over the computed figure')
        ->not->toContain('non-standard cut-off');
});

it('warns about a non-standard period without blocking finalize', function () {
    $employee = statFeatEmployee('STAT-009');
    statFeatWorked($employee, ['03', '04', '05', '06', '07', '10', '11', '12']);
    statFeatActor();
    $run = statFeatRun('PR-STAT-9', [$employee], '2026-08-03', '2026-08-12');

    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->assertSeeHtml('data-cutoff-warning')
        ->assertSee('is not a 1st–15th or 16th–end-of-month cut-off');
    $page->call('finalize');

    expect($run->refresh()->status)->toBe('FINALIZED')
        ->and(AuditLog::query()->where('action', 'PAYROLL_FINALIZED')->sole()->details)->toContain('; non-standard cut-off');

    // A standard cut-off shows no warning.
    expect(CutoffAdvice::warning('2026-08-01', '2026-08-15'))->toBeNull();
});

/* |---| Employer shares are reported, never deducted |---| */

it('shows the employer shares on the posted payslip and in the CSV, and keeps them out of My Portal', function () {
    $employee = statFeatEmployee('STAT-010');
    statFeatWorked($employee, STAT_FEAT_FIRST_HALF);
    statFeatActor();
    $run = statFeatRun('PR-STAT-10', [$employee]);

    // The draft's CSV appends the three shares after Net pay (and three
    // incentive columns after them).
    $export = Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->call('exportCsv')
        ->assertFileDownloaded('payroll-pr-stat-10.csv');
    $bytes = (string) base64_decode((string) data_get($export->effects, 'download.content'), true);
    $lines = explode("\r\n", (string) preg_replace('/^\x{FEFF}/u', '', $bytes));
    expect($lines[0])->toContain('"Net pay","SSS (employer)","PhilHealth (employer)","Pag-IBIG (employer)",')
        ->and($lines[1] ?? '')->toContain(',"1515","375","100",');

    Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('finalize');
    $payslip = Payslip::query()->where('run_id', $run->id)->sole();

    Livewire::test(IndividualPayslip::class, ['id' => $run->id, 'employeeId' => (string) $employee->getKey()])
        ->assertSee('Employer contributions (not deducted)')
        ->assertSee('1,515.00');

    // The employee's own portal, through the self-service pin, shows the payslip
    // alone: the employer's shares stay on Payroll and Reports.
    statFeatActor(['PORTAL'], allData: false, employeeId: (string) $employee->getKey());
    Livewire::test(PortalPage::class)
        ->set('tab', 'payslips')
        ->call('viewPayslip', $payslip->id)
        ->assertSet('payslipOpen', true)
        ->assertDontSeeHtml('data-employer-shares')
        ->assertDontSee('Employer contributions (not deducted)')
        ->assertDontSee('1,515.00');
});

/* |---| The pay contract carries every switch |---| */

it('carries all sixteen statutory settings on the Workforce pay contract', function () {
    $defaults = [
        'allow_sss' => true, 'allow_philhealth' => true, 'allow_pagibig' => true, 'allow_withholding_tax' => true,
        'annualize_tax' => false, 'fixed_tax' => 0.0, 'percent_tax' => 0.0,
        'fixed_sss_ee' => 0.0, 'fixed_sss_er' => 0.0, 'fixed_philhealth_ee' => 0.0, 'fixed_philhealth_er' => 0.0,
        'fixed_pagibig_ee' => 0.0, 'fixed_pagibig_er' => 0.0,
        'sss_mode' => null, 'philhealth_mode' => null, 'pagibig_mode' => null,
    ];

    // The engine reads these off the employee structurally and treats a
    // missing one as "switched off", so a property dropped from the contract
    // would post zero with every other test still green.
    $parameters = [];
    foreach ((new ReflectionClass(EmployeePayData::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
        $parameters[$parameter->getName()] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : 'REQUIRED';
    }
    foreach ($defaults as $name => $default) {
        expect(array_key_exists($name, $parameters))->toBeTrue("EmployeePayData lacks {$name}")
            ->and($parameters[$name])->toBe($default);
    }

    // And the Workforce query fills them from the record, not the defaults.
    $employee = statFeatEmployee('STAT-011', [
        'allow_sss' => false, 'annualize_tax' => true, 'fixed_tax' => 123.45, 'percent_tax' => 7.5,
        'fixed_philhealth_er' => 333.33, 'pagibig_mode' => 'MONTHLY',
    ]);
    $data = app(EmployeePayContexts::class)->get(statFeatActor(), [(string) $employee->getKey()])[0];
    expect($data->allow_sss)->toBeFalse()
        ->and($data->annualize_tax)->toBeTrue()
        ->and($data->fixed_tax)->toBe(123.45)
        ->and($data->percent_tax)->toBe(7.5)
        ->and($data->fixed_philhealth_er)->toBe(333.33)
        ->and($data->pagibig_mode)->toBe('MONTHLY')
        ->and($data->sss_mode)->toBe('SEMI_MONTHLY');
});
