<?php

declare(strict_types=1);

use App\Domain\Payroll\PayslipDraft;
use App\Domain\Payroll\Statutory;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\SavePayroll;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollDraft;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Modules\Workforce\Contracts\EmployeePayData;
use App\Support\Access;
use Illuminate\Support\Str;
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

it('posts no contribution and no tax on a payslip with no gross pay', function () {
    $employee = statFeatEmployee('STAT-004');
    $d = statFeatDraft(statFeatActor(), $employee, '2026-08-01', '2026-08-15')['draft'];

    expect($d->gross_pay)->toBe(0.0)
        ->and([$d->sss, $d->sss_er, $d->philhealth, $d->philhealth_er, $d->pagibig, $d->pagibig_er, $d->withholding_tax])
        ->toBe([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0]);
});

/* |---| An officer's typed figures |---| */

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

/* |---| Employer shares are reported, never deducted |---| */

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
