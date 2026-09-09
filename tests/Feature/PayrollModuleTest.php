<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainAttendanceLog;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\Shift;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Payroll\PayrollPeriod;
use App\Domain\Payroll\PayrollReadiness;
use App\Domain\Payroll\PayslipCalculator;
use App\Livewire\Payroll\Index;
use App\Livewire\Payroll\IndividualPayslip;
use App\Livewire\Payroll\RunWorkspace;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Holiday;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Payroll module: /payroll gates on the PAYROLL grant, payslip reads are
 * scoped (EMPTY for an employee-less actor, never IS NULL rows), the draft →
 * save → finalize flow posts recomputed payslips inside one transaction, and
 * — the module's one deliberate divergence — a FINALIZED run refuses deletion.
 */

/** Helpers are payroll-prefixed: Pest loads every test file into one process. */
function payrollRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Payroll '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

function payrollActor(array $modules, bool $allData = false, ?string $employeeId = null): User
{
    $user = User::factory()->create([
        'role_id' => payrollRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);

    return $user;
}

function payrollEmployee(string $code, string $first, string $last, array $overrides = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_merge([
        'employee_code' => $code,
        'first_name' => $first,
        'last_name' => $last,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'monthly_rate' => 30000,
        'is_active' => true,
    ], $overrides))->save();

    return $employee;
}

function payrollDraftRun(string $code, string $name, array $employees = []): PayrollRun
{
    $run = new PayrollRun;
    $run->forceFill([
        'code' => $code,
        'name' => $name,
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-15',
        'status' => 'DRAFT',
    ])->save();

    foreach (array_values($employees) as $position => $employee) {
        PayrollRunMember::create([
            'run_id' => $run->id,
            'employee_id' => $employee->getKey(),
            'position' => $position,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-15',
        ]);
    }

    return $run;
}

/** A completed August 2026 first cut-off, seen from later that month in Manila. */
function payrollFreezeClock(): void
{
    test()->travelTo(new DateTimeImmutable('2026-08-20T12:00:00+08:00'));
}

/*
|--------------------------------------------------------------------------
| Route gating
|--------------------------------------------------------------------------
*/

it('sends a guest to the login page', function () {
    $this->get('/payroll')->assertRedirect(route('login'));
});

it('turns away an actor whose role lacks the PAYROLL module', function () {
    payrollActor(['PORTAL']);

    $this->get('/payroll')->assertRedirect();
});

it('renders the run list for an actor holding PAYROLL', function () {
    payrollActor(['PAYROLL'], allData: true);

    $this->get('/payroll')
        ->assertOk()
        ->assertSee('Employee Payroll')
        ->assertSee('No payroll yet. Create one to get started.');
});

it('gates the create and payslip pages on the same grant', function () {
    payrollActor(['PORTAL']);

    $this->get('/payroll/new')->assertRedirect();
});

/*
|--------------------------------------------------------------------------
| Row scoping — payslips widen on PAYROLL alone, and an employee-less
| account gets ZERO rows, never IS NULL rows.
|--------------------------------------------------------------------------
*/

it('returns zero payslips for an employee-less actor', function () {
    $employee = payrollEmployee('EMP-001', 'Liza', 'Domingo');
    $run = payrollDraftRun('PR-001', 'August 2026 — 1st cut-off', [$employee]);
    $run->forceFill(['status' => 'FINALIZED'])->save();

    $payslip = new Payslip;
    $payslip->forceFill([
        'run_id' => $run->id,
        'employee_id' => $employee->id,
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-15',
        'gross_pay' => 15000,
        'total_deductions' => 0,
        'net_pay' => 15000,
    ])->save();

    // Holds the module, no all_data, no employee link.
    $user = payrollActor(['PAYROLL']);

    $rows = Payslip::query()
        ->toEmployee(Access::forUser($user), 'employee_id', 'PAYROLL')
        ->get();

    expect($rows)->toHaveCount(0);
});

it('shows the employee-less actor the not-found card, not the payslip', function () {
    $employee = payrollEmployee('EMP-002', 'Ramon', 'Cruz');
    $run = payrollDraftRun('PR-002', 'August 2026 — scoping', [$employee]);
    $run->forceFill(['status' => 'FINALIZED'])->save();

    $payslip = new Payslip;
    $payslip->forceFill([
        'run_id' => $run->id,
        'employee_id' => $employee->id,
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-15',
        'gross_pay' => 15000,
        'total_deductions' => 0,
        'net_pay' => 15000,
    ])->save();

    payrollActor(['PAYROLL']);

    Livewire::test(IndividualPayslip::class, ['id' => $run->id, 'employeeId' => $employee->id])
        ->assertSee('No payslip here.')
        ->assertDontSee('Individual Employee Payroll');
});

it('does not widen payslips for all_data without the PAYROLL module', function () {
    $employee = payrollEmployee('EMP-003', 'Ana', 'Reyes');

    $payslip = new Payslip;
    $payslip->forceFill([
        'employee_id' => $employee->id,
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-15',
        'gross_pay' => 15000,
        'total_deductions' => 0,
        'net_pay' => 15000,
    ])->save();

    // A supervisor who sees the roster is not licensed to read salaries.
    $user = payrollActor(['ROSTER'], allData: true);

    $rows = Payslip::query()
        ->toEmployee(Access::forUser($user), 'employee_id', 'PAYROLL')
        ->get();

    expect($rows)->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| Happy path — create a draft, save it, finalize it
|--------------------------------------------------------------------------
*/

it('creates a draft with a full member snapshot, then finalizes it into posted payslips', function () {
    payrollFreezeClock();

    $employee = payrollEmployee('EMP-010', 'Bea', 'Santos');
    payrollActor(['PAYROLL'], allData: true);

    // Create: name, cut-off, pick the employee, save.
    Livewire::test(RunWorkspace::class)
        ->set('name', 'August 2026 — 1st cut-off')
        ->set('start', '2026-08-01')
        ->set('end', '2026-08-15')
        ->call('openPicker')
        ->call('togglePick', $employee->id)
        ->call('applyPicked')
        ->call('save')
        ->assertRedirect();

    $run = PayrollRun::query()->first();

    expect($run)->not->toBeNull()
        ->and($run->code)->toBe('PR-001')
        ->and($run->name)->toBe('August 2026 — 1st cut-off')
        ->and($run->status)->toBe('DRAFT');

    // Membership is a full snapshot row, not a bare attach.
    $member = PayrollRunMember::query()->where('run_id', $run->id)->first();

    expect($member)->not->toBeNull()
        ->and($member->employee_id)->toBe($employee->id)
        ->and($member->position)->toBe(0)
        ->and($member->period_start->format('Y-m-d'))->toBe('2026-08-01')
        ->and($member->period_end->format('Y-m-d'))->toBe('2026-08-15');

    // Reopen and finalize.
    Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->call('finalize');

    $run->refresh();

    expect($run->status)->toBe('FINALIZED')
        ->and($run->finalized_at)->not->toBeNull()
        ->and($run->finalized_by)->not->toBeNull();

    $payslip = Payslip::query()->where('run_id', $run->id)->first();

    expect($payslip)->not->toBeNull()
        ->and($payslip->employee_id)->toBe($employee->id)
        ->and($payslip->period_start->format('Y-m-d'))->toBe('2026-08-01')
        ->and($payslip->period_end->format('Y-m-d'))->toBe('2026-08-15');

    expect(AuditLog::query()->where('action', 'PAYROLL_FINALIZED')->exists())->toBeTrue();
});

it('finalizes a period that has not ended yet, paying only the days that have', function () {
    // Frozen mid-period: 5 Aug Manila, with a run covering the whole month.
    // The reference refused this outright. It no longer does — the days after
    // today carry no attendance, so they simply pay nothing.
    test()->travelTo(new DateTimeImmutable('2026-08-05T12:00:00+08:00'));

    $employee = payrollEmployee('EMP-011', 'Caloy', 'Dizon');
    payrollActor(['PAYROLL'], allData: true);

    $run = payrollDraftRun('PR-009', 'August 2026 — run early', [$employee]);

    Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->call('finalize');

    expect($run->refresh()->status)->toBe('FINALIZED')
        ->and(Payslip::query()->where('run_id', $run->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Any range of days is payable
|--------------------------------------------------------------------------
*/

it('pays a two-day period and refuses only what cannot be computed', function () {
    payrollFreezeClock();

    // The rule that is gone.
    expect(PayrollPeriod::cutoffError('2026-08-01', '2026-08-02'))->toBeNull()
        ->and(PayrollPeriod::cutoffError('2026-08-07', '2026-08-07'))->toBeNull()
        ->and(PayrollPeriod::cutoffError('2026-08-09', '2026-08-23'))->toBeNull();

    // Across a month boundary, too.
    expect(PayrollPeriod::cutoffError('2026-08-28', '2026-09-03'))->toBeNull()
        ->and(PayrollPeriod::cutoffError('2026-08-01', '2026-10-31'))->toBeNull();

    // The rules that are not, and each for a reason that is not policy.
    expect(PayrollPeriod::cutoffError('2026-08-15', '2026-08-01'))
        ->toBe('The end date cannot fall before the start date.')
        ->and(PayrollPeriod::cutoffError('2026-13-01', '2026-13-05'))
        ->toBe('Choose valid start and end dates.');

    // And the statutory tables still bound their own range.
    expect(PayrollPeriod::cutoffError('2027-01-01', '2027-01-15'))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Contributions and tax are typed, not computed
|--------------------------------------------------------------------------
*/

it('seeds every hand-entered deduction at zero instead of guessing one', function () {
    payrollFreezeClock();

    // An employee configured for the full statutory treatment: every switch
    // on, no fixed overrides, the mode the seeder ships. Under the reference
    // engine this payslip would have carried half a month of SSS, PhilHealth
    // and Pag-IBIG, and the tax their base implied.
    $employee = payrollEmployee('EMP-030', 'Rosa', 'Ilagan', [
        'monthly_rate' => 30000,
        'allow_sss' => true,
        'allow_philhealth' => true,
        'allow_pagibig' => true,
        'allow_withholding_tax' => true,
        'sss_mode' => 'SEMI_MONTHLY',
        'philhealth_mode' => 'SEMI_MONTHLY',
        'pagibig_mode' => 'SEMI_MONTHLY',
    ]);
    payrollActor(['PAYROLL'], allData: true);

    $run = payrollDraftRun('PR-030', 'August 2026 — typed deductions', [$employee]);

    Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('finalize');

    $payslip = Payslip::query()->where('run_id', $run->id)->firstOrFail();

    // The five the payroll manager types, and the employer shares beside them.
    expect((float) $payslip->sss)->toBe(0.0)
        ->and((float) $payslip->sss_er)->toBe(0.0)
        ->and((float) $payslip->philhealth)->toBe(0.0)
        ->and((float) $payslip->philhealth_er)->toBe(0.0)
        ->and((float) $payslip->pagibig)->toBe(0.0)
        ->and((float) $payslip->pagibig_er)->toBe(0.0)
        ->and((float) $payslip->withholding_tax)->toBe(0.0)
        ->and((float) $payslip->other_deductions)->toBe(0.0);

    // Late and undertime are NOT part of that: they come from the DTR, they
    // are read-only in the workspace, and the total is theirs alone.
    expect((float) $payslip->total_deductions)
        ->toBe((float) $payslip->late_deduction + (float) $payslip->undertime_deduction);
});

it('keeps one employee on one payroll per cut-off', function () {
    payrollFreezeClock();

    $employee = payrollEmployee('EMP-012', 'Diana', 'Lim');
    payrollActor(['PAYROLL'], allData: true);

    payrollDraftRun('PR-020', 'August 2026 — first home', [$employee]);

    // A second run for the same period must not admit the same employee.
    Livewire::test(RunWorkspace::class)
        ->set('name', 'August 2026 — second home')
        ->set('start', '2026-08-01')
        ->set('end', '2026-08-15')
        ->call('openPicker')
        ->call('togglePick', $employee->id)
        ->call('applyPicked')
        ->call('save')
        ->assertNoRedirect();

    expect(PayrollRun::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Immutability — the module's one deliberate divergence
|--------------------------------------------------------------------------
*/

it('deletes a draft run from the list', function () {
    payrollActor(['PAYROLL'], allData: true);

    $run = payrollDraftRun('PR-030', 'August 2026 — disposable');

    Livewire::test(Index::class)
        ->call('confirmDelete', $run->id)
        ->call('delete');

    expect(PayrollRun::query()->whereKey($run->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'PAYROLL_RUN_DELETED')->exists())->toBeTrue();
});

it('refuses to delete a FINALIZED run — posted payslips must stay', function () {
    payrollActor(['PAYROLL'], allData: true);

    $employee = payrollEmployee('EMP-013', 'Elsa', 'Garcia');
    $run = payrollDraftRun('PR-031', 'August 2026 — locked', [$employee]);

    // Arm the dialog while the run is still a draft, then finalize behind its
    // back — the transaction's status re-check must still refuse.
    $component = Livewire::test(Index::class)->call('confirmDelete', $run->id);

    $run->forceFill([
        'status' => 'FINALIZED',
        'finalized_at' => CarbonImmutable::now('UTC'),
    ])->save();

    $component->call('delete');

    expect(PayrollRun::query()->whereKey($run->id)->exists())->toBeTrue()
        ->and(PayrollRun::query()->whereKey($run->id)->value('status'))->toBe('FINALIZED');

    // And the front door refuses too: a finalized run never arms the dialog.
    Livewire::test(Index::class)
        ->call('confirmDelete', $run->id)
        ->assertSet('confirmingDelete', false);
});

it('refuses payroll writes from an actor without all_data', function () {
    payrollFreezeClock();
    payrollActor(['PAYROLL']); // module without the widening

    Livewire::test(RunWorkspace::class)
        ->set('name', 'Should not exist')
        ->set('start', '2026-08-01')
        ->set('end', '2026-08-15')
        ->call('save');

    expect(PayrollRun::query()->count())->toBe(0);
});

it('draws every employee on the run, with nothing behind a scrollbar', function () {
    payrollFreezeClock();
    payrollActor(['PAYROLL'], allData: true);

    $employees = [];
    foreach (range(1, 12) as $n) {
        $employees[] = payrollEmployee('EMP-2'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'Member', 'No'.$n);
    }

    $run = payrollDraftRun('PR-040', 'August 2026 — everybody', $employees);

    $html = Livewire::test(RunWorkspace::class, ['id' => $run->id])->html();

    // Members are not paginated, so all twelve rows are drawn...
    foreach ($employees as $employee) {
        expect($html)->toContain((string) $employee->last_name);
    }

    // ...and no height cap hides most of them.
    $table = Str::before(Str::after($html, 'hris-table-wrap'), '</table>');

    expect($table)->not->toContain('hris-table-scroll')
        // The wrap itself stays: 22 columns must not scroll the whole page.
        ->and($html)->toContain('hris-table-wrap');
});

it('prices a period that spans two months at each month\'s own daily rate', function () {
    // September 2026 holds 22 scheduled weekdays, October 22 as well, so a
    // rate difference needs two months that genuinely differ: August 2026 has
    // 21 and September 22. An employee on 30,000 is therefore worth
    // 1,428.571… a day in August and 1,363.636… in September.
    $week = [1, 2, 3, 4, 5];

    $august = ShiftResolver::scheduledWorkDays('2026-08-01', '2026-08-31', $week);
    $september = ShiftResolver::scheduledWorkDays('2026-09-01', '2026-09-30', $week);

    expect($august)->not->toBe($september);

    // One worked day in each month, over a period that straddles the boundary.
    $draft = PayslipCalculator::compute(
        employee: (object) ['id' => 'E1', 'monthly_rate' => 30000, 'hourly_rate' => 0],
        logs: DomainAttendanceLog::listFromArrays([
            ['log_type' => 'TIME_IN', 'logged_at' => '2026-08-31T00:00:00.000Z'],
            ['log_type' => 'TIME_OUT', 'logged_at' => '2026-08-31T09:00:00.000Z'],
            ['log_type' => 'TIME_IN', 'logged_at' => '2026-09-01T00:00:00.000Z'],
            ['log_type' => 'TIME_OUT', 'logged_at' => '2026-09-01T09:00:00.000Z'],
        ]),
        shift: Shift::fromArray([
            'mode' => 'FIXED', 'work_days' => $week, 'start_time' => '08:00:00',
            'end_time' => '17:00:00', 'grace_minutes' => 15, 'break_minutes' => 60,
        ]),
        leaves: [],
        periodStart: '2026-08-31',
        periodEnd: '2026-09-01',
        bonus: 0.0,
    )->toArray();

    // Two days worked, and each one priced by the month it fell in — NOT two
    // days at August's rate, which is what a single period-wide rate gave.
    expect($draft['work_days'])->toBe(2.0);

    $expected = Money::round(
        Money::round(1) * (30000 / $august) + Money::round(1) * (30000 / $september)
    );

    expect($draft['work_days_pay'])->toBe($expected)
        ->and($draft['work_days_pay'])->not->toBe(Money::round(2 * (30000 / $august)));

    // The reported daily_rate is the period's first month, as it always was.
    expect($draft['daily_rate'])->toBe(Money::round(30000 / $august));
});

it('reads a hand-typed amount whether or not it carries separators', function () {
    payrollFreezeClock();

    $employee = payrollEmployee('EMP-050', 'Nena', 'Cruz');
    payrollActor(['PAYROLL'], allData: true);

    $run = payrollDraftRun('PR-050', 'August 2026 — typed amounts', [$employee]);
    $id = (string) $employee->getKey();

    // What the cells actually send: the comma-free raw value.
    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->call('updateDeduction', $id, 'sss', '1350.50');

    expect($page->get('draftDeductions')[$id]['sss'])->toBe(1350.5);

    // And what must NOT quietly become zero if a formatted string ever
    // reaches the server — is_numeric('1,350.50') is false.
    $page->call('updateDeduction', $id, 'sss', '1,350.50');

    expect($page->get('draftDeductions')[$id]['sss'])->toBe(1350.5);

    // Still refused: a value that is not an amount once separators are gone.
    $page->call('updateDeduction', $id, 'sss', '1,3x0.50');

    expect($page->get('draftDeductions')[$id]['sss'])->toBe(0.0);
});

/*
|--------------------------------------------------------------------------
| A holiday worked on a rest day
|--------------------------------------------------------------------------
*/

/** One worked day, on $date, for an employee whose week is Mon-Fri. */
function holidayDayPay(string $date, array $holiday, float $restDayPercentage): array
{
    return PayslipCalculator::compute(
        // 22 scheduled days in Aug 2026 would give an awkward rate; the shift
        // below is Mon-Fri, so a 11,000 monthly rate over 21 August weekdays
        // is not round either. The assertions read the daily_rate back rather
        // than assuming one.
        employee: (object) ['id' => 'E1', 'monthly_rate' => 30000, 'hourly_rate' => 0],
        logs: DomainAttendanceLog::listFromArrays([
            ['log_type' => 'TIME_IN', 'logged_at' => $date.'T00:00:00.000Z'],
            ['log_type' => 'TIME_OUT', 'logged_at' => $date.'T09:00:00.000Z'],
        ]),
        shift: Shift::fromArray([
            'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5], 'start_time' => '08:00:00',
            'end_time' => '17:00:00', 'grace_minutes' => 15, 'break_minutes' => 60,
        ]),
        leaves: [],
        periodStart: $date,
        periodEnd: $date,
        bonus: 0.0,
        settings: [
            'formulas' => [
                ['key' => 'REST_DAY', 'params' => [
                    ['key' => 'percentage', 'value' => $restDayPercentage],
                    ['key' => 'max_hours', 'value' => 8],
                ]],
            ],
            'holidays' => HolidaySetting::listFromArrays([$holiday]),
        ],
    )->toArray();
}

it('compounds a holiday worked on a rest day by the saved Rest Day percentage', function () {
    // 2026-08-15 is a Saturday — a rest day on a Mon-Fri week.
    // 2026-08-14 is the Friday before it — an ordinary working day.
    $holidaySat = ['date' => '2026-08-15', 'schedule' => 'ANNUAL', 'type' => 'REGULAR', 'percent' => 200];
    $holidayFri = ['date' => '2026-08-14', 'schedule' => 'ANNUAL', 'type' => 'REGULAR', 'percent' => 200];

    $onRestDay = holidayDayPay('2026-08-15', $holidaySat, 1.3);
    $onWorkDay = holidayDayPay('2026-08-14', $holidayFri, 1.3);

    // Compared to the centavo, not to the peso: `daily_rate` on the payslip is
    // rounded for display and the engine prices from the unrounded figure, so
    // rebuilding the expectation from it drifts by a centavo. The claim being
    // made is about the RATIO, and that is what is asserted.
    $daily = $onWorkDay['daily_rate'];

    // A holiday on a working day is unchanged: 200% of the daily rate.
    expect(abs($onWorkDay['holiday_pay'] - $daily * 2))->toBeLessThanOrEqual(0.01);

    // The same holiday on a rest day compounds by Formulas -> Rest Day:
    // 200% x 1.3 = 260%. Both factors are saved settings, neither is literal.
    expect(abs($onRestDay['holiday_pay'] - $onWorkDay['holiday_pay'] * 1.3))
        ->toBeLessThanOrEqual(0.01);

    // It is still ONE bucket — the rest-day bucket is not also credited, which
    // would pay 330% rather than 260%.
    expect($onRestDay['rest_day_pay'])->toBe(0.0)
        ->and($onRestDay['holiday_days'])->toBe(1.0)
        ->and($onRestDay['rest_days'])->toBe(0.0);
});

it('changes the compounded rate when the Rest Day percentage is saved differently', function () {
    $holiday = ['date' => '2026-08-15', 'schedule' => 'ANNUAL', 'type' => 'REGULAR', 'percent' => 200];

    $at130 = holidayDayPay('2026-08-15', $holiday, 1.3);
    $at150 = holidayDayPay('2026-08-15', $holiday, 1.5);

    expect($at150['holiday_pay'])->toBeGreaterThan($at130['holiday_pay']);

    // And a Rest Day percentage of 0 does not ANNIHILATE the holiday premium:
    // it means no uplift, so the day pays its own 200% and not nothing.
    $atZero = holidayDayPay('2026-08-15', $holiday, 0.0);
    $onWorkDay = holidayDayPay('2026-08-14',
        ['date' => '2026-08-14', 'schedule' => 'ANNUAL', 'type' => 'REGULAR', 'percent' => 200], 0.0);

    expect($atZero['holiday_pay'])->toBe($onWorkDay['holiday_pay'])
        ->and($atZero['holiday_pay'])->toBeGreaterThan(0.0);
});

it('blocks finalization only on what nobody has configured a rate for', function () {
    // The set is small and explicit on purpose: everything else the readiness
    // pass detects is now priced from Formulas or Holidays.
    expect(PayrollReadiness::BLOCKING_CODES)->toBe([
        PayrollReadiness::CODE_UNPAIRED_ATTENDANCE,
        PayrollReadiness::CODE_HOLIDAY_CONFIGURATION,
        PayrollReadiness::CODE_COMPOUND_HOLIDAY,
    ]);

    foreach ([
        PayrollReadiness::CODE_NIGHT_WORK,
        PayrollReadiness::CODE_REST_DAY_WORK,
        PayrollReadiness::CODE_HOLIDAY_OVERTIME,
        PayrollReadiness::CODE_HOLIDAY_REST_DAY,
    ] as $lifted) {
        expect(PayrollReadiness::BLOCKING_CODES)->not->toContain($lifted);
    }
});

it('finalizes a run containing holiday overtime on a rest day', function () {
    payrollFreezeClock();

    $employee = payrollEmployee('EMP-060', 'Ramon', 'Aquino');
    payrollActor(['PAYROLL'], allData: true);

    // 2026-08-15 is a Saturday. A holiday on it, worked, with overtime — every
    // one of the four lifted conditions at once.
    Holiday::query()->create([
        'code' => 'TEST-HOL', 'name' => 'Test Holiday', 'date' => '2026-08-15',
        'schedule' => 'ANNUAL', 'type' => 'REGULAR', 'percent' => 200,
    ]);

    foreach ([['TIME_IN', '00:00:00'], ['TIME_OUT', '12:00:00']] as [$type, $at]) {
        AttendanceLog::query()->create([
            'employee_id' => (string) $employee->getKey(),
            'log_type' => $type,
            'source' => 'MANUAL',
            'is_geofenced' => true,
            'is_anomaly' => false,
            'logged_at' => '2026-08-15 '.$at,
        ]);
    }

    $run = payrollDraftRun('PR-060', 'August 2026 — holiday OT', [$employee]);

    Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('finalize');

    expect($run->refresh()->status)->toBe('FINALIZED');
});
