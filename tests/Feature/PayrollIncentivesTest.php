<?php

declare(strict_types=1);

use App\Domain\Payroll\PayslipDraft;
use App\Domain\Payroll\Statutory;
use App\Livewire\Payroll\IndividualPayslip;
use App\Livewire\Payroll\RunWorkspace;
use App\Livewire\Portal\PortalPage;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollIncentiveType;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\PayrollRunMemberIncentive;
use App\Models\Payslip;
use App\Models\PayslipIncentive;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\SavePayroll;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollDraft;
use App\Support\Access;
use App\Support\Money;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ScheduleFixture;

/**
 * Incentives and bonuses on a payroll (DELIBERATE DIVERGENCE: the reference
 * pays bonus = 0). A TAXABLE line is taxed with the pay — it
 * reaches the engine as `bonus`. A TAX-EXEMPT line is split against the PHP
 * 90,000 yearly ceiling (ExemptBenefitCeiling, from the finalized payslips
 * of the same year) and added to gross and net AFTER the tax; whatever goes
 * over the ceiling is taxed like a taxable line. Lines are saved with the
 * draft under the payroll lock and audit, captured in the evidence with the
 * type's name and flag as they were, and posted itemised with the payslip.
 */

/** @param list<string> $modules */
function incentActor(array $modules = ['PAYROLL'], bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Incentive '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

/** An employee who worked every weekday of August 2026's first cut-off, 08:00 to 17:00 Manila. */
function incentEmployee(string $code): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code, 'first_name' => 'Inc', 'last_name' => $code, 'employment_status' => 'Regular',
        'date_hired' => '2024-01-01', 'monthly_rate' => 30000, 'is_active' => true,
    ])->save();
    ScheduleFixture::standard($employee);

    foreach (['03', '04', '05', '06', '07', '10', '11', '12', '13', '14', '17', '18', '19', '20', '21', '24', '25', '26', '27', '28', '31'] as $day) {
        foreach ([['TIME_IN', '00:00:00'], ['TIME_OUT', '09:00:00']] as [$type, $at]) {
            AttendanceLog::query()->create([
                'employee_id' => (string) $employee->getKey(), 'log_type' => $type, 'source' => 'MANUAL',
                'is_geofenced' => true, 'is_anomaly' => false, 'logged_at' => "2026-08-{$day} {$at}",
            ]);
        }
    }

    return $employee->refresh();
}

function incentType(string $code, string $name, bool $taxable): PayrollIncentiveType
{
    $type = new PayrollIncentiveType;
    $type->forceFill(['code' => $code, 'name' => $name, 'is_taxable' => $taxable])->save();

    return $type;
}

/** A finalized payslip that already spent some of the employee's yearly exemption. */
function incentPosted(EmployeeProfile $employee, string $start, string $end, float $exempt): void
{
    $payslip = new Payslip;
    $payslip->forceFill(['employee_id' => (string) $employee->getKey(), 'period_start' => $start, 'period_end' => $end,
        'gross_pay' => 15000, 'total_deductions' => 0, 'net_pay' => 15000, 'exempt_benefits' => $exempt])->save();
}

/**
 * One employee's first-cut-off draft and records.
 *
 * @param  array<string, float>  $lines  incentive type id => amount
 * @return array{draft: PayslipDraft, statutory: array<string, mixed>, lines: list<array<string, mixed>>}
 */
function incentDraft(Access $actor, EmployeeProfile $employee, array $lines = [], string $start = '2026-08-01', string $end = '2026-08-15'): array
{
    $id = (string) $employee->getKey();
    $calculation = PayrollData::calculateDrafts([$id], $start, $end, [], [], [], false, $actor, $lines === [] ? [] : [$id => $lines]);

    return ['draft' => $calculation['drafts'][0], 'statutory' => $calculation['statutory'][$id], 'lines' => $calculation['incentiveLines'][$id] ?? []];
}

/** @param list<EmployeeProfile> $employees */
function incentRun(string $code, array $employees, string $start = '2026-08-01', string $end = '2026-08-15'): PayrollRun
{
    $run = new PayrollRun;
    $run->forceFill(['code' => $code, 'name' => 'Incentives '.$code, 'period_start' => $start, 'period_end' => $end, 'status' => 'DRAFT'])->save();
    foreach ($employees as $position => $employee) {
        PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->getKey(), 'position' => $position,
            'period_start' => $start, 'period_end' => $end]);
    }

    return $run;
}

function incentLine(PayrollRun $run, EmployeeProfile $employee, PayrollIncentiveType $type, float $amount): void
{
    $member = PayrollRunMember::query()->where('run_id', $run->id)->where('employee_id', $employee->getKey())->sole();
    PayrollRunMemberIncentive::create(['member_id' => $member->id, 'type_id' => $type->id, 'amount' => $amount]);
}

beforeEach(function () {
    test()->travelTo(new DateTimeImmutable('2026-09-05T12:00:00+08:00'));
});

/* |---| The arithmetic |---| */

it('adds a tax-exempt incentive after the tax', function () {
    $employee = incentEmployee('INC-002');
    $christmas = incentType('1', 'Christmas Bonus', false);
    $actor = incentActor();

    $without = incentDraft($actor, $employee)['draft'];
    ['draft' => $with, 'statutory' => $record] = incentDraft($actor, $employee, [$christmas->id => 10000.0]);

    expect($with->bonus)->toBe(0.0)
        ->and($with->exempt_benefits)->toBe(10000.0)
        ->and($with->withholding_tax)->toBe($without->withholding_tax)
        ->and($with->taxable_compensation)->toBe($without->taxable_compensation)
        ->and($with->gross_pay)->toBe(Money::round($without->gross_pay + 10000))
        ->and($with->net_pay)->toBe(Money::round($without->net_pay + 10000))
        ->and($record['benefits'])->toBe(['ytd_before' => 0.0, 'exempt' => 10000.0, 'ceiling' => 10000.0, 'taxable_excess' => 0.0]);
});

it('spends what is left of the ceiling on the exempt lines in the catalog\'s id order', function () {
    $employee = incentEmployee('INC-004');
    // Created out of order: the ceiling follows the ids, not creation.
    $second = incentType('2', 'Productivity Incentive', false);
    $first = incentType('1', 'Christmas Bonus', false);
    $taxable = incentType('3', 'Commission', true);
    incentPosted($employee, '2026-06-01', '2026-06-15', 85000.0);
    $actor = incentActor();

    ['draft' => $d, 'lines' => $lines] = incentDraft($actor, $employee, [$second->id => 4000.0, $taxable->id => 1000.0, $first->id => 3000.0]);

    expect(array_map(static fn (array $l): array => [$l['name'], $l['amount'], $l['over_ceiling']], $lines))->toBe([
        ['Christmas Bonus', 3000.0, 0.0],
        ['Productivity Incentive', 4000.0, 2000.0],
        ['Commission', 1000.0, 0.0],
    ])
        ->and($d->exempt_benefits)->toBe(5000.0)
        ->and($d->taxable_benefits_excess)->toBe(2000.0)
        ->and($d->bonus)->toBe(3000.0);
});

it('counts only the payslips of the same year against the ceiling', function () {
    $employee = incentEmployee('INC-005');
    $christmas = incentType('1', 'Christmas Bonus', false);
    incentPosted($employee, '2025-12-16', '2025-12-31', 90000.0);
    $actor = incentActor();

    ['draft' => $d, 'statutory' => $record] = incentDraft($actor, $employee, [$christmas->id => 10000.0]);

    expect($d->exempt_benefits)->toBe(10000.0)
        ->and($record['benefits']['ytd_before'])->toBe(0.0);
});

it('leaves out a type that no longer exists and an amount that is not positive', function () {
    $employee = incentEmployee('INC-006');
    $commission = incentType('1', 'Commission', true);
    $actor = incentActor();

    ['draft' => $d, 'lines' => $lines] = incentDraft($actor, $employee, [$commission->id => 0.0, 'missing-type' => 900.0]);

    expect($lines)->toBe([])
        ->and($d->bonus)->toBe(0.0)
        ->and($d->exempt_benefits)->toBe(0.0);
});

/* |---| Saving the draft |---| */

it('saves the lines under their members and names them in the audit', function () {
    $ana = incentEmployee('INC-011');
    $ben = incentEmployee('INC-012');
    $commission = incentType('1', 'Commission', true);
    $christmas = incentType('2', 'Christmas Bonus', false);
    $actor = incentActor();
    $a = (string) $ana->getKey();
    $b = (string) $ben->getKey();

    $created = app(SavePayroll::class)->handle($actor, new PayrollDraft(
        null, 'Incentive draft', '2026-08-01', '2026-08-15', [$a, $b], [], [], [],
        [$a => [$commission->id => '1500.50', $christmas->id => 2000], 'not-a-member' => [$commission->id => 1]],
    ));

    $rows = PayrollRunMemberIncentive::query()->with('member')->get()
        ->map(static fn (PayrollRunMemberIncentive $row): array => [$row->member?->employee_id, $row->type_id, $row->amount])
        ->sortBy(static fn (array $row): string => $row[1])->values()->all();
    expect($rows)->toEqualCanonicalizing([[$a, $commission->id, 1500.5], [$a, $christmas->id, 2000.0]])
        ->and(AuditLog::query()->where('action', 'PAYROLL_RUN_CREATED')->sole()->details)
        ->toBe('Incentive draft created as a draft with 2 employees; 2 incentive lines');

    // A re-save replaces the lines with the member rows it re-creates.
    $this->travel(1)->seconds();
    app(SavePayroll::class)->handle($actor, new PayrollDraft(
        $created['id'], 'Incentive draft', '2026-08-01', '2026-08-15', [$a, $b], [], [], [],
        [$b => [$christmas->id => 750]],
    ));
    expect(PayrollRunMemberIncentive::query()->count())->toBe(1)
        ->and(PayrollRunMemberIncentive::query()->sole()->member?->employee_id)->toBe($b)
        ->and(AuditLog::query()->where('action', 'PAYROLL_RUN_UPDATED')->sole()->details)->toBe('Incentive draft saved; 1 incentive line');
});

it('refuses an unknown type or an amount that is not positive, and writes nothing', function (array $lines, string $message) {
    $ana = incentEmployee('INC-013');
    $actor = incentActor();
    $a = (string) $ana->getKey();
    incentType('1', 'Commission', true);

    expect(fn () => app(SavePayroll::class)->handle($actor, new PayrollDraft(null, 'Refused', '2026-08-01', '2026-08-15', [$a], [], [], [], [$a => $lines])))
        ->toThrow(RuntimeException::class, $message);
    expect(PayrollRun::query()->count())->toBe(0)
        ->and(PayrollRunMemberIncentive::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'unknown type' => [['missing-type' => 100], 'An incentive type on this payroll no longer exists.'],
    'zero' => [['missing-type' => 0], 'Every incentive amount must be more than zero.'],
    'negative' => [['missing-type' => -5], 'Every incentive amount must be more than zero.'],
    'not a number' => [['missing-type' => 'lots'], 'Every incentive amount must be more than zero.'],
]);

/* |---| Finalizing |---| */

it('posts every line itemised, and the next payroll spends only what is left of the ceiling', function () {
    $employee = incentEmployee('INC-021');
    $christmas = incentType('1', 'Christmas Bonus', false);
    $commission = incentType('2', 'Commission', true);
    incentActor();

    $first = incentRun('PR-INC-1', [$employee]);
    incentLine($first, $employee, $christmas, 60000.0);
    incentLine($first, $employee, $commission, 1000.0);
    Livewire::test(RunWorkspace::class, ['id' => $first->id])->call('finalize');
    expect($first->refresh()->status)->toBe('FINALIZED');

    $posted = Payslip::query()->where('run_id', $first->id)->sole();
    expect($posted->exempt_benefits)->toBe(60000.0)
        ->and($posted->bonus)->toBe(1000.0)
        ->and(PayslipIncentive::query()->where('payslip_id', $posted->id)->orderBy('position')->get()
            ->map(static fn (PayslipIncentive $l): array => [$l->position, $l->type_code, $l->name, $l->is_taxable, $l->amount, $l->over_ceiling])->all())
        ->toBe([[0, '1', 'Christmas Bonus', false, 60000.0, 0.0], [1, '2', 'Commission', true, 1000.0, 0.0]]);
    $payload = json_decode(PayrollFinalizationSnapshot::query()->where('run_id', $first->id)->sole()->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['inputs']['incentives'][(string) $employee->getKey()][0]['name'])->toBe('Christmas Bonus')
        ->and($payload['inputs']['exempt_benefits_ytd'])->toBe([(string) $employee->getKey() => 0])
        ->and($payload['incentives'][(string) $employee->getKey()][0]['over_ceiling'])->toBe(0);

    // The second cut-off: 30,000 of the ceiling is left.
    $second = incentRun('PR-INC-2', [$employee], '2026-08-16', '2026-08-31');
    incentLine($second, $employee, $christmas, 40000.0);
    Livewire::test(RunWorkspace::class, ['id' => $second->id])->call('finalize');

    $next = Payslip::query()->where('run_id', $second->id)->sole();
    expect($next->exempt_benefits)->toBe(30000.0)
        ->and($next->taxable_benefits_excess)->toBe(10000.0)
        ->and($next->bonus)->toBe(10000.0)
        ->and(PayslipIncentive::query()->where('payslip_id', $next->id)->sole()->over_ceiling)->toBe(10000.0);

    // A renamed or re-flagged type never rewrites what was posted.
    $christmas->forceFill(['name' => 'Year-end Bonus', 'is_taxable' => true])->save();
    expect(PayslipIncentive::query()->where('payslip_id', $posted->id)->where('position', 0)->sole()->name)->toBe('Christmas Bonus');
});

/* |---| The workspace, the payslip and the portal |---| */

it('edits a member\'s incentives in the dialog and saves them with the draft', function () {
    $employee = incentEmployee('INC-031');
    $commission = incentType('1', 'Commission', true);
    $christmas = incentType('2', 'Christmas Bonus', false);
    incentActor();
    $run = incentRun('PR-INC-31', [$employee]);
    $id = (string) $employee->getKey();

    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->call('openIncentives', $id);
    expect($page->get('incentiveForm'))->toBe([$commission->id => '', $christmas->id => ''])
        ->and($page->html())->toContain('data-incentives-dialog')->toContain('Tax-exempt')->toContain('Manage Incentive Types');

    $page->set("incentiveForm.{$commission->id}", '1,250.00')
        ->set("incentiveForm.{$christmas->id}", '0')
        ->call('applyIncentives');

    expect($page->get('draftIncentives'))->toBe([$id => [$commission->id => 1250.0]])
        ->and($page->get('incentivesOpen'))->toBeFalse()
        ->and($page->html())->toContain('Commission: $1,250.00 (taxable)');

    // A moment after the run was created, as a real save is (the frozen
    // clock would otherwise make MariaDB report the run row unchanged).
    $this->travel(1)->seconds();
    $page->call('save');
    expect(PayrollRunMemberIncentive::query()->sole()->amount)->toBe(1250.0);

    // Reopened, the dialog starts from what was saved.
    $again = Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('openIncentives', $id);
    expect($again->get('incentiveForm'))->toBe([$commission->id => '1250', $christmas->id => '']);
});

it('keeps a bad amount in the dialog and applies nothing', function () {
    $employee = incentEmployee('INC-032');
    $commission = incentType('1', 'Commission', true);
    incentActor();
    $run = incentRun('PR-INC-32', [$employee]);

    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->call('openIncentives', (string) $employee->getKey())
        ->set("incentiveForm.{$commission->id}", '-50')
        ->call('applyIncentives');

    expect($page->get('incentiveError'))->toBe('Enter each amount as a dollar amount, or leave it empty.')
        ->and($page->get('incentivesOpen'))->toBeTrue()
        ->and($page->get('draftIncentives'))->toBe([]);

    // A stranger's id opens nothing.
    Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('openIncentives', 'not-a-member')
        ->assertSet('incentivesOpen', false);
});

it('shows the incentives in the grid, the CSV, the payslip and My Portal', function () {
    $employee = incentEmployee('INC-033');
    $christmas = incentType('1', 'Christmas Bonus', false);
    $commission = incentType('2', 'Commission', true);
    incentPosted($employee, '2026-06-01', '2026-06-15', 88000.0);
    incentActor();
    $run = incentRun('PR-INC-33', [$employee]);
    incentLine($run, $employee, $christmas, 5000.0);
    incentLine($run, $employee, $commission, 1000.0);

    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id]);
    expect($page->html())->toContain('>Incentives</th>')->toContain('$6,000.00')
        ->toContain('Christmas Bonus: $5,000.00 (tax-exempt, $3,000.00 over the yearly ceiling)');

    $export = Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->call('exportCsv')
        ->assertFileDownloaded('payroll-pr-inc-33.csv');
    $bytes = (string) base64_decode((string) data_get($export->effects, 'download.content'), true);
    $lines = explode("\r\n", (string) preg_replace('/^\x{FEFF}/u', '', $bytes));
    expect($lines[0])->toContain('"Incentives taxed","Incentives not taxed"')
        ->and($lines[1] ?? '')->toContain('"4000","2000"');

    Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('finalize');

    Livewire::test(IndividualPayslip::class, ['id' => $run->id, 'employeeId' => (string) $employee->getKey()])
        ->assertSeeHtml('data-pay-statement')
        ->assertSee('Christmas Bonus')
        ->assertSee('Commission');

    incentActor(['PORTAL'], allData: false, employeeId: (string) $employee->getKey());
    $payslip = Payslip::query()->where('run_id', $run->id)->sole();
    Livewire::test(PortalPage::class)
        ->set('tab', 'payslips')
        ->call('viewPayslip', $payslip->id)
        ->assertSee('Christmas Bonus')
        ->assertSee('Commission')
        // The tax treatment sits in the additional details, which My Portal
        // does not show; Payroll above does.
        ->assertDontSee('taxed over the yearly ceiling')
        ->assertDontSee('Incentive tax treatment')
        ->assertDontSee('>Bonus<', false);
});

it('warns, without blocking, when an earlier open payroll this year also pays a tax-exempt incentive', function () {
    $employee = incentEmployee('INC-041');
    $christmas = incentType('1', 'Christmas Bonus', false);
    $commission = incentType('2', 'Commission', true);
    incentActor();

    $earlier = incentRun('PR-INC-41', [$employee]);
    $later = incentRun('PR-INC-42', [$employee], '2026-08-16', '2026-08-31');
    incentLine($later, $employee, $christmas, 1000.0);

    // Nothing earlier pays an exempt line yet: no warning.
    expect(Livewire::test(RunWorkspace::class, ['id' => $later->id])->html())->not->toContain('data-exempt-order-warning');

    // A TAXABLE line on the earlier run changes nothing either.
    incentLine($earlier, $employee, $commission, 500.0);
    expect(Livewire::test(RunWorkspace::class, ['id' => $later->id])->html())->not->toContain('data-exempt-order-warning');

    // An exempt one does — on the LATER run only, and Finalize still works.
    PayrollRunMemberIncentive::query()->delete();
    incentLine($earlier, $employee, $christmas, 500.0);
    incentLine($later, $employee, $christmas, 1000.0);
    expect(Livewire::test(RunWorkspace::class, ['id' => $later->id])->html())->toContain('data-exempt-order-warning')
        ->and(Livewire::test(RunWorkspace::class, ['id' => $earlier->id])->html())->not->toContain('data-exempt-order-warning');

    Livewire::test(RunWorkspace::class, ['id' => $later->id])->call('finalize');
    expect($later->refresh()->status)->toBe('FINALIZED');
});
