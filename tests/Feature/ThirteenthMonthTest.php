<?php

declare(strict_types=1);

use App\Domain\Payroll\PayslipDraft;
use App\Livewire\Payroll\Index;
use App\Livewire\Payroll\IndividualPayslip;
use App\Livewire\Payroll\ThirteenthMonthWorkspace;
use App\Livewire\Portal\PortalPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollIncentiveType;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\Commands\SavePayroll;
use App\Modules\Payroll\Application\ExemptBenefitLedger;
use App\Modules\Payroll\Application\PayrollDraft;
use App\Modules\Payroll\Application\ThirteenthMonthData;
use App\Modules\Payroll\Application\ThirteenthMonthInputs;
use App\Modules\Payroll\Domain\Tax\SupplementaryWithholding;
use App\Modules\Workforce\Application\Queries\PayrollEmployees;
use App\Support\Access;
use App\Support\Money;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The 13th month run: a yearly run covering 1 January to 31 December that
 * pays each member 1/12 of the basic pay they earned that year —
 * `work_days_pay + leave_pay` on EMS's own finalized REGULAR payslips, plus
 * a typed figure for the months paid outside EMS — tax-free up to what is
 * left of the PHP 90,000 yearly exemption, the excess taxed at the marginal
 * rate (overtypable). It lives in its own sidebar entry and screens, is
 * saved, reviewed and finalized like a regular run, and its payslip joins
 * the exemption ledger.
 */

/** @param list<string> $modules */
function thirtActor(array $modules = ['PAYROLL'], bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Thirteenth '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

/** @param array<string, mixed> $overrides */
function thirtEmployee(string $code, array $overrides = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_merge([
        'employee_code' => $code, 'first_name' => 'Thirteen', 'last_name' => $code, 'employment_status' => 'Regular',
        'date_hired' => '2024-01-01', 'monthly_rate' => 30000, 'is_active' => true,
    ], $overrides))->save();

    return $employee->refresh();
}

/** A finalized run of the given kind, for posting payslips under. */
function thirtRun(string $code, string $kind = 'REGULAR', string $start = '2026-08-01', string $end = '2026-08-15', string $status = 'FINALIZED'): PayrollRun
{
    $run = new PayrollRun;
    $run->forceFill(['code' => $code, 'name' => 'Run '.$code, 'period_start' => $start, 'period_end' => $end, 'status' => $status, 'kind' => $kind])->save();

    return $run;
}

/** A posted payslip with the basic pay figures the 13th month reads. */
function thirtPayslip(EmployeeProfile $employee, ?PayrollRun $run, string $start, string $end, float $workDaysPay, float $leavePay = 0.0, ?float $taxable = null, float $exempt = 0.0): Payslip
{
    $payslip = new Payslip;
    $payslip->forceFill(['run_id' => $run?->id, 'employee_id' => (string) $employee->getKey(), 'period_start' => $start, 'period_end' => $end,
        'work_days_pay' => $workDaysPay, 'leave_pay' => $leavePay, 'gross_pay' => $workDaysPay + $leavePay, 'total_deductions' => 0,
        'net_pay' => $workDaysPay + $leavePay, 'taxable_compensation' => $taxable, 'exempt_benefits' => $exempt])->save();

    return $payslip;
}

/**
 * A DRAFT 13th month run with these members.
 *
 * @param  list<EmployeeProfile>  $employees
 */
function thirtDraft(string $code, array $employees, int $year = 2026): PayrollRun
{
    $run = thirtRun($code, 'THIRTEENTH_MONTH', "{$year}-01-01", "{$year}-12-31", 'DRAFT');
    foreach ($employees as $position => $employee) {
        PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->getKey(), 'position' => $position,
            'period_start' => "{$year}-01-01", 'period_end' => "{$year}-12-31"]);
    }

    return $run;
}

beforeEach(function () {
    test()->travelTo(new DateTimeImmutable('2026-12-20T12:00:00+08:00'));
});

/* |---| The basis and the arithmetic |---| */

it('bases the amount on the year\'s finalized regular pay plus the typed outside figure', function () {
    $employee = thirtEmployee('TM-001');
    $regular = thirtRun('PR-A');
    thirtPayslip($employee, $regular, '2026-08-01', '2026-08-15', 12_000.0, 1_500.0, 15_000.0);
    // A legacy payslip with no run is regular pay too.
    thirtPayslip($employee, null, '2026-07-16', '2026-07-31', 13_000.0, 0.0, 14_000.0);
    // Never counted: another year, and an earlier 13th month payslip.
    thirtPayslip($employee, thirtRun('PR-B'), '2025-12-16', '2025-12-31', 50_000.0);
    thirtPayslip($employee, thirtRun('PR-C', 'THIRTEENTH_MONTH', '2026-01-01', '2026-12-31'), '2026-01-01', '2026-12-31', 99_000.0);
    $actor = thirtActor();
    $id = (string) $employee->getKey();

    $calculation = ThirteenthMonthData::calculateDrafts([$id], 2026, [$id => '9500'], actor: $actor);
    /** @var PayslipDraft $d */
    $d = $calculation['drafts'][0];

    // (12,000 + 1,500 + 13,000 + 9,500) / 12 = 3,000.
    expect($calculation['statutory'][$id]['basis'])->toBe(['ems' => 26_500.0, 'outside' => 9_500.0, 'total' => 36_000.0, 'regular_taxable' => 15_000.0])
        ->and($d->thirteenth_month)->toBe(3_000.0)
        ->and($d->gross_pay)->toBe(3_000.0)
        ->and($d->period_start)->toBe('2026-01-01')
        ->and($d->period_end)->toBe('2026-12-31')
        // Tax-free under the ceiling, and no contributions on a 13th month.
        ->and($d->exempt_benefits)->toBe(3_000.0)
        ->and($d->withholding_tax)->toBe(0.0)
        ->and([$d->sss, $d->philhealth, $d->pagibig, $d->sss_er])->toBe([0.0, 0.0, 0.0, 0.0])
        ->and($d->net_pay)->toBe(3_000.0)
        ->and($calculation['combined'])->toBe([]);
});

it('taxes only the part over what is left of PHP 90,000, at the marginal rate, unless typed over', function () {
    $employee = thirtEmployee('TM-002');
    // 30,000 of basic pay a month for the year; 85,000 of the exemption already spent.
    thirtPayslip($employee, thirtRun('PR-D'), '2026-11-16', '2026-11-30', 360_000.0, 0.0, 15_000.0, 85_000.0);
    $actor = thirtActor();
    $id = (string) $employee->getKey();

    $calculation = ThirteenthMonthData::calculateDrafts([$id], 2026, actor: $actor);
    $d = $calculation['drafts'][0];
    $expectedTax = SupplementaryWithholding::tax($calculation['employees']->first(), 15_000.0, 25_000.0);

    expect($d->thirteenth_month)->toBe(30_000.0)
        ->and($d->exempt_benefits)->toBe(5_000.0)
        ->and($d->taxable_benefits_excess)->toBe(25_000.0)
        ->and($d->taxable_compensation)->toBe(25_000.0)
        ->and($d->withholding_tax)->toBe($expectedTax)
        ->and($expectedTax)->toBeGreaterThan(0.0)
        ->and($d->net_pay)->toBe(Money::round(30_000.0 - $expectedTax))
        ->and($calculation['statutory'][$id]['overridden'])->toBe([]);

    // Typed over: the typed figure wins and is recorded as an override.
    $typed = ThirteenthMonthData::calculateDrafts([$id], 2026, [], [$id => '1000'], actor: $actor);
    expect($typed['drafts'][0]->withholding_tax)->toBe(1_000.0)
        ->and($typed['statutory'][$id]['overridden'])->toBe(['withholding_tax'])
        ->and($typed['statutory'][$id]['computed']['withholding_tax'])->toBe($expectedTax);
});

it('blocks a member with nothing to base the 13th month on', function () {
    $employee = thirtEmployee('TM-003');
    $actor = thirtActor();
    $id = (string) $employee->getKey();

    $calculation = ThirteenthMonthData::calculateDrafts([$id], 2026, actor: $actor);

    expect($calculation['drafts'][0]->thirteenth_month)->toBe(0.0)
        ->and($calculation['combined'][0]['message'])->toContain('earned no basic pay on a finalized 2026 payroll');

    // Typing what they earned outside EMS clears it.
    expect(ThirteenthMonthData::calculateDrafts([$id], 2026, [$id => 24_000], actor: $actor)['combined'])->toBe([]);
});

/* |---| Saving |---| */

it('saves a 13th month run for exactly one calendar year, and keeps its kind and year', function () {
    $employee = thirtEmployee('TM-011');
    $actor = thirtActor();
    $id = (string) $employee->getKey();
    $save = app(SavePayroll::class);

    $created = $save->handle($actor, new PayrollDraft(null, '13th Month Pay 2026', '2026-01-01', '2026-12-31', [$id],
        [$id => ['withholding_tax' => 250, 'sss' => 900]], [$id => 500.0], [$id => ['night_differential' => 50]], [], 'THIRTEENTH_MONTH', [$id => 12000]));

    $run = PayrollRun::query()->findOrFail($created['id']);
    $member = PayrollRunMember::query()->where('run_id', $run->id)->sole();
    expect($run->kind)->toBe('THIRTEENTH_MONTH')
        ->and($member->additional_basic_pay)->toBe(12_000.0)
        // Only a typed withholding applies on a 13th month run.
        ->and($member->deduction_withholding_tax)->toBe(250.0)
        ->and($member->deduction_sss)->toBeNull()
        ->and($member->allowance)->toBeNull()
        ->and($member->earning_night_differential)->toBeNull()
        ->and(AuditLog::query()->where('action', 'PAYROLL_RUN_CREATED')->sole()->details)
        ->toBe('13th Month Pay 2026 created as a draft with 1 employees; 13th month 2026; 1 statutory line typed over the computed figure');

    $this->travel(1)->seconds();
    expect(fn () => $save->handle($actor, new PayrollDraft($run->id, 'Now regular', '2026-01-01', '2026-12-31', [$id], [], [], [])))
        ->toThrow(RuntimeException::class, 'A payroll cannot change between a regular and a 13th month run.')
        ->and(fn () => $save->handle($actor, new PayrollDraft($run->id, '13th Month Pay 2026', '2025-01-01', '2025-12-31', [$id], [], [], [], [], 'THIRTEENTH_MONTH')))
        ->toThrow(RuntimeException::class, 'A 13th month run keeps the year it was created for.');

    // Neither refusal wrote anything.
    $run->refresh();
    expect([$run->name, $run->kind, $run->period_start->format('Y-m-d')])->toBe(['13th Month Pay 2026', 'THIRTEENTH_MONTH', '2026-01-01'])
        ->and(AuditLog::query()->where('action', 'PAYROLL_RUN_UPDATED')->count())->toBe(0);
});

it('refuses a 13th month run that is not one calendar year, pays incentives, or reaches 2027', function () {
    $employee = thirtEmployee('TM-012');
    $actor = thirtActor();
    $id = (string) $employee->getKey();
    $type = PayrollIncentiveType::create(['code' => '1', 'name' => 'Commission', 'is_taxable' => true]);
    $save = app(SavePayroll::class);

    expect(fn () => $save->handle($actor, new PayrollDraft(null, 'Half year', '2026-01-01', '2026-06-30', [$id], [], [], [], [], 'THIRTEENTH_MONTH')))
        ->toThrow(RuntimeException::class, 'A 13th month payroll covers 1 January to 31 December of one year.')
        ->and(fn () => $save->handle($actor, new PayrollDraft(null, 'Two years', '2026-01-01', '2027-12-31', [$id], [], [], [], [], 'THIRTEENTH_MONTH')))
        ->toThrow(RuntimeException::class, 'A 13th month payroll covers 1 January to 31 December of one year.')
        ->and(fn () => $save->handle($actor, new PayrollDraft(null, 'With incentives', '2026-01-01', '2026-12-31', [$id], [], [], [], [$id => [$type->id => 100]], 'THIRTEENTH_MONTH')))
        ->toThrow(RuntimeException::class, 'Incentives are paid on a regular payroll, not on the 13th month run.')
        ->and(fn () => $save->handle($actor, new PayrollDraft(null, 'Bad outside', '2026-01-01', '2026-12-31', [$id], [], [], [], [], 'THIRTEENTH_MONTH', [$id => '-5'])))
        ->toThrow(RuntimeException::class, 'Type the basic pay earned outside EMS as a peso figure, or leave it empty.')
        ->and(fn () => $save->handle($actor, new PayrollDraft(null, 'Unknown kind', '2026-01-01', '2026-12-31', [$id], [], [], [], [], 'WEEKLY')))
        ->toThrow(RuntimeException::class, 'Choose which payroll this is.')
        // No withholding table is in force before the first version.
        ->and(fn () => $save->handle($actor, new PayrollDraft(null, '2024', '2024-01-01', '2024-12-31', [$id], [], [], [], [], 'THIRTEENTH_MONTH')))
        ->toThrow(RuntimeException::class, 'No Withholding Tax table is in force on 31 Dec 2024. Add a version that starts on or before it in Contribution & Tax Tables.');

    expect(PayrollRun::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

/* |---| Finalizing |---| */

it('posts the 13th month payslips with their evidence, and the next payroll sees the exemption spent', function () {
    $employee = thirtEmployee('TM-021');
    thirtPayslip($employee, thirtRun('PR-E'), '2026-11-16', '2026-11-30', 240_000.0, 0.0, 15_000.0);
    User::factory()->create(['employee_id' => (string) $employee->getKey(), 'is_active' => true]);
    thirtActor();
    $run = thirtDraft('PR-13', [$employee]);
    PayrollRunMember::query()->where('run_id', $run->id)->update(['additional_basic_pay' => 60_000]);

    Livewire::test(ThirteenthMonthWorkspace::class, ['id' => $run->id])->call('finalize');

    $payslip = Payslip::query()->where('run_id', $run->id)->sole();
    expect($run->refresh()->status)->toBe('FINALIZED')
        ->and($payslip->thirteenth_month)->toBe(25_000.0)
        ->and($payslip->exempt_benefits)->toBe(25_000.0)
        ->and($payslip->withholding_tax)->toBe(0.0)
        ->and($payslip->net_pay)->toBe(25_000.0)
        ->and(AuditLog::query()->where('action', 'PAYROLL_FINALIZED')->sole()->details)->toBe('Run PR-13 finalized — 1 13th month payslips')
        ->and(Notification::query()->sole()->title)->toBe('13th month pay PR-13 posted');

    // The evidence replays byte for byte from what was captured.
    $snapshot = PayrollFinalizationSnapshot::query()->where('run_id', $run->id)->sole();
    $payload = json_decode($snapshot->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($snapshot->schema_version)->toBe(ThirteenthMonthInputs::SCHEMA_VERSION)
        ->and($payload['kind'])->toBe('THIRTEENTH_MONTH')
        ->and($payload['inputs']['outside_basic_pay'])->toBe([(string) $employee->getKey() => 60000]);
    $restored = ThirteenthMonthInputs::restore(json_encode($payload['inputs'], JSON_THROW_ON_ERROR));
    $replay = ThirteenthMonthData::calculateInputs($restored, strict: true);
    expect(array_map(static fn (PayslipDraft $d): array => $d->toArray(), $replay['drafts']))->toEqual($payload['results'])
        ->and($restored->fingerprint())->toBe($snapshot->input_hash);

    // The PHP 90,000 ledger now holds the 25,000.
    expect(app(ExemptBenefitLedger::class)->usedBefore(app(Access::class), [(string) $employee->getKey()], 2026))
        ->toBe([(string) $employee->getKey() => 25_000.0]);
});

it('refuses to finalize a member with nothing to base it on, and a stale preview', function () {
    $ana = thirtEmployee('TM-022');
    $actor = thirtActor();
    $run = thirtDraft('PR-13B', [$ana]);

    expect(fn () => app(FinalizePayroll::class)->handle($actor, $run->id, null))->toThrow(RuntimeException::class);
    Livewire::test(ThirteenthMonthWorkspace::class, ['id' => $run->id])->call('finalize');
    expect($run->refresh()->status)->toBe('DRAFT')
        ->and(Payslip::query()->where('run_id', $run->id)->count())->toBe(0)
        ->and(PayrollFinalizationSnapshot::query()->where('run_id', $run->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'PAYROLL_FINALIZED')->count())->toBe(0);

    // With a basis, a preview goes stale when a regular payroll is finalized meanwhile.
    thirtPayslip($ana, thirtRun('PR-F'), '2026-11-16', '2026-11-30', 120_000.0);
    $screen = Livewire::test(ThirteenthMonthWorkspace::class, ['id' => $run->id]);
    thirtPayslip($ana, thirtRun('PR-G'), '2026-12-01', '2026-12-15', 12_000.0);
    $screen->call('finalize')->assertSet('reviewRequired', true);
    expect($run->refresh()->status)->toBe('DRAFT')
        ->and(PayrollFinalizationSnapshot::query()->where('run_id', $run->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'PAYROLL_FINALIZED')->count())->toBe(0);
});

/* |---| The screens |---| */

it('creates a run for the year with a resigned employee, and refuses someone who left before it', function () {
    $stayed = thirtEmployee('TM-031');
    $left = thirtEmployee('TM-032', ['is_active' => false, 'date_resigned' => '2026-06-30']);
    $gone = thirtEmployee('TM-033', ['is_active' => false, 'date_resigned' => '2025-11-30']);
    thirtActor();

    $page = Livewire::test(ThirteenthMonthWorkspace::class)
        ->assertSet('year', 2026)
        ->assertSet('name', '13th Month Pay 2026')
        ->call('openPicker');
    expect($page->html())->toContain('Everyone employed at some point of 2026');

    $page->call('togglePick', (string) $stayed->getKey())
        ->call('togglePick', (string) $left->getKey())
        ->call('togglePick', (string) $gone->getKey())
        ->call('applyPicked');
    expect($page->get('members'))->toBe([(string) $stayed->getKey(), (string) $left->getKey()]);

    $page->call('updateOutside', (string) $left->getKey(), '36,000.00')->call('save');
    $run = PayrollRun::query()->sole();
    $page->assertRedirect(route('payroll.thirteenth.show', ['id' => $run->id]));
    expect($run->kind)->toBe('THIRTEENTH_MONTH')
        ->and(PayrollRunMember::query()->where('employee_id', (string) $left->getKey())->sole()->additional_basic_pay)->toBe(36_000.0);
});

it('shows the figures, marks a typed withholding and clears it back to the computed one', function () {
    $employee = thirtEmployee('TM-034');
    thirtPayslip($employee, thirtRun('PR-H'), '2026-11-16', '2026-11-30', 120_000.0);
    thirtActor();
    $run = thirtDraft('PR-13C', [$employee]);
    $id = (string) $employee->getKey();

    $page = Livewire::test(ThirteenthMonthWorkspace::class, ['id' => $run->id]);
    expect($page->html())->toContain('₱120,000.00')->toContain('₱10,000.00')->not->toContain('data-overridden');

    $page->call('updateWithholding', $id, '300');
    expect($page->html())->toContain('data-overridden="withholding_tax"')->toContain('₱9,700.00');

    $page->call('updateWithholding', $id, '');
    expect($page->html())->not->toContain('data-overridden')
        ->and($page->get('withholding'))->toBe([]);

    $export = Livewire::test(ThirteenthMonthWorkspace::class, ['id' => $run->id])
        ->call('exportCsv')
        ->assertFileDownloaded('cyberonions-13th-month-pr-13c.csv');
    $bytes = (string) base64_decode((string) data_get($export->effects, 'download.content'), true);
    $lines = explode("\r\n", (string) preg_replace('/^\x{FEFF}/u', '', $bytes));
    expect($lines[0])->toContain('"13th month pay","Tax-exempt","Over PHP 90,000","Withholding tax","Net pay"')
        ->and($lines[1] ?? '')->toContain('"120000","0","120000","10000","10000","0","0","10000"');
});

it('lists each kind under its own sidebar entry and opens each run in its own workspace', function () {
    $regular = thirtRun('PR-R', 'REGULAR', '2026-08-01', '2026-08-15', 'DRAFT');
    $yearly = thirtDraft('PR-Y', [thirtEmployee('TM-041')]);
    thirtActor();

    Livewire::test(Index::class)->assertSee('Run PR-R')->assertDontSee('Run PR-Y')->assertSee('Basic Payroll')->assertDontSee('Monthly');
    Livewire::test(Index::class, ['kind' => 'THIRTEENTH_MONTH'])->assertSee('Run PR-Y')->assertDontSee('Run PR-R')
        ->assertSee('Create 13th Month Payroll')->assertDontSee('Yearly');

    $this->get('/payroll/thirteenth-month')->assertOk()->assertSee('Run PR-Y')->assertDontSee('Run PR-R');
    $this->get('/payroll/'.$yearly->id)->assertRedirect(route('payroll.thirteenth.show', ['id' => $yearly->id]));
    $this->get('/payroll/thirteenth-month/'.$regular->id)->assertRedirect(route('payroll.show', ['id' => $regular->id]));
    $this->get('/payroll/thirteenth-month/new')->assertOk()->assertSee('Create 13th month payroll')->assertDontSee('13th Month Payroll - Yearly');
    $this->get('/payroll/thirteenth-month/missing')->assertOk()->assertSee('That payroll no longer exists.')
        ->assertSee('Back to 13th Month Payroll')->assertDontSee('13th Month Payroll - Yearly');
});

it('lights exactly one Payroll entry in the sidebar, the longest that holds the page', function () {
    thirtActor(['PAYROLL', 'RECORDS']);

    $basic = (string) $this->get('/payroll')->assertOk()->getContent();
    $yearly = (string) $this->get('/payroll/thirteenth-month')->assertOk()->getContent();

    $current = static fn (string $html): array => preg_match_all('/<a href="[^"]*(\/payroll[^"]*)" class="[^"]*"\s+aria-current="page"\s*>/', (string) $html, $m) ? $m[1] : [];
    // A 13th month payslip opens under the yearly tree, so the yearly entry is lit.
    $run = thirtRun('PR-13S', 'THIRTEENTH_MONTH', '2026-01-01', '2026-12-31');
    $employee = thirtEmployee('TM-042');
    thirtPayslip($employee, $run, '2026-01-01', '2026-12-31', 0.0);
    $payslipPage = (string) $this->get(route('payroll.thirteenth.payslip', ['id' => $run->id, 'employeeId' => (string) $employee->getKey()]))->assertOk()->getContent();

    expect($current($basic))->toBe(['/payroll'])
        ->and($current($yearly))->toBe(['/payroll/thirteenth-month'])
        ->and($current($payslipPage))->toBe(['/payroll/thirteenth-month'])
        // Neither the sidebar nor a page's heading or title carries a
        // " - Monthly" / " - Yearly" suffix.
        ->and($basic)->toContain('>Basic Payroll</span>')->toContain('>13th Month Payroll</span>')
        ->and($basic)->not->toContain('Basic Payroll - Monthly')->not->toContain('13th Month Payroll - Yearly')
        ->and($yearly)->not->toContain('Basic Payroll - Monthly')->not->toContain('13th Month Payroll - Yearly');
});

it('prints a 13th month payslip in its own layout and shows it in My Portal', function () {
    $employee = thirtEmployee('TM-051');
    $run = thirtRun('PR-13P', 'THIRTEENTH_MONTH', '2026-01-01', '2026-12-31');
    $payslip = new Payslip;
    $payslip->forceFill(['run_id' => $run->id, 'employee_id' => (string) $employee->getKey(), 'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
        'thirteenth_month' => 30_000, 'gross_pay' => 30_000, 'exempt_benefits' => 5_000, 'taxable_benefits_excess' => 25_000,
        'withholding_tax' => 4_000, 'total_deductions' => 4_000, 'net_pay' => 26_000])->save();
    thirtActor();

    $html = Livewire::test(IndividualPayslip::class, ['id' => $run->id, 'employeeId' => (string) $employee->getKey()])->html();
    expect($html)->toContain('data-thirteenth-month')
        ->toContain('Taxed (over PHP 90,000 a year)')
        ->toContain('25,000.00')
        ->not->toContain('Work Days:');

    thirtActor(['PORTAL'], allData: false, employeeId: (string) $employee->getKey());
    Livewire::test(PortalPage::class)
        ->set('tab', 'payslips')
        ->call('viewPayslip', $payslip->id)
        ->assertSee('13th month pay')
        ->assertSee('30,000.00');
});

/* |---| Refusals |---| */

it('refuses every 13th month write and read to a payroll holder without all-data, and to an unlinked account', function (bool $linked) {
    $other = thirtEmployee('TM-061');
    $own = thirtEmployee('TM-062');
    $run = thirtDraft('PR-13X', [$other]);
    $before = [PayrollRun::query()->count(), PayrollRunMember::query()->count(), AuditLog::query()->count()];
    $actor = thirtActor(['PAYROLL'], allData: false, employeeId: $linked ? (string) $own->getKey() : null);

    // The workspace: no picker rows, no admission, no save, no finalize, no export.
    $page = Livewire::test(ThirteenthMonthWorkspace::class)
        ->call('openPicker')
        ->call('togglePick', (string) $other->getKey())
        ->call('togglePickShown')
        ->assertSet('picked', []);
    expect($page->html())->not->toContain('TM-061');
    $page->call('save');

    Livewire::test(ThirteenthMonthWorkspace::class, ['id' => $run->id])->assertSee('That payroll no longer exists.');

    // The commands and queries, called directly.
    expect(fn () => app(SavePayroll::class)->handle($actor, new PayrollDraft(null, 'Refused', '2026-01-01', '2026-12-31', [(string) $other->getKey()], [], [], [], [], 'THIRTEENTH_MONTH')))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.')
        ->and(fn () => app(FinalizePayroll::class)->handle($actor, $run->id))->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.')
        ->and(app(PayrollEmployees::class)->employedInYear($actor, 2026))->toHaveCount(0)
        ->and(app(PayrollEmployees::class)->admissibleInYear($actor, (string) $other->getKey(), 2026))->toBeFalse();

    expect([PayrollRun::query()->count(), PayrollRunMember::query()->count(), AuditLog::query()->count()])->toBe($before)
        ->and($run->refresh()->status)->toBe('DRAFT');
})->with(['linked to an employee' => true, 'unlinked' => false]);

it('shows a payroll holder without all-data only their own posted 13th month payslip, and no export', function () {
    $other = thirtEmployee('TM-063');
    $own = thirtEmployee('TM-064');
    $run = thirtRun('PR-13Y', 'THIRTEENTH_MONTH', '2026-01-01', '2026-12-31');
    foreach ([$other, $own] as $position => $employee) {
        PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->getKey(), 'position' => $position,
            'period_start' => '2026-01-01', 'period_end' => '2026-12-31']);
        $payslip = new Payslip;
        $payslip->forceFill(['run_id' => $run->id, 'employee_id' => (string) $employee->getKey(), 'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
            'thirteenth_month' => 1000 + $position, 'gross_pay' => 1000 + $position, 'total_deductions' => 0, 'net_pay' => 1000 + $position])->save();
    }
    thirtActor(['PAYROLL'], allData: false, employeeId: (string) $own->getKey());

    $page = Livewire::test(ThirteenthMonthWorkspace::class, ['id' => $run->id]);
    expect($page->html())->toContain('₱1,001.00')->not->toContain('₱1,000.00');

    $page->call('exportCsv')->assertNoFileDownloaded();
});

/* |---| The evidence refuses what it did not capture |---| */

it('refuses tampered or foreign 13th month evidence', function (string $change) {
    $employee = thirtEmployee('TM-071');
    thirtPayslip($employee, thirtRun('PR-J'), '2026-11-16', '2026-11-30', 120_000.0, 0.0, 15_000.0);
    $actor = thirtActor();
    $id = (string) $employee->getKey();
    $inputs = ThirteenthMonthData::calculateDrafts([$id], 2026, [$id => 1000], actor: $actor)['inputs'];
    assert($inputs !== null);
    $data = $inputs->toArray();
    // The untouched capture restores.
    expect(ThirteenthMonthInputs::restore(json_encode($data, JSON_THROW_ON_ERROR))->fingerprint())->toBe($inputs->fingerprint());

    match ($change) {
        'extra' => $data['unexpected'] = 'discard me',
        'stranger' => $data['outside_basic_pay']['someone-else'] = 5,
        'negative' => $data['basic_pay'][$id] = -1,
        'kind' => $data['kind'] = 'REGULAR',
        'schema' => $data['schema_version'] = ThirteenthMonthInputs::SCHEMA_VERSION + 1,
        'text' => $data['exempt_benefits_ytd'][$id] = '0',
        default => throw new LogicException('Unknown change'),
    };

    expect(fn () => ThirteenthMonthInputs::restore(json_encode($data, JSON_THROW_ON_ERROR)))->toThrow(RuntimeException::class);
})->with(['extra', 'stranger', 'negative', 'kind', 'schema', 'text']);

it('refuses to replay 13th month evidence under another calculation version', function () {
    $employee = thirtEmployee('TM-072');
    $actor = thirtActor();
    $inputs = ThirteenthMonthData::calculateDrafts([(string) $employee->getKey()], 2026, actor: $actor)['inputs'];
    assert($inputs !== null);
    $data = $inputs->toArray();
    $data['calculator_version'] = 'payroll-v1:obsolete';

    expect(fn () => ThirteenthMonthData::calculateInputs(ThirteenthMonthInputs::restore(json_encode($data, JSON_THROW_ON_ERROR))))
        ->toThrow(RuntimeException::class, 'This payroll evidence requires its recorded calculation version.');
});

it('works out the tax base of a payslip posted before it was recorded', function () {
    $employee = thirtEmployee('TM-073');
    // Posted before 2026_09_26_0004: no taxable_compensation stored.
    $payslip = thirtPayslip($employee, thirtRun('PR-K'), '2026-09-01', '2026-09-15', 400_000.0);
    $payslip->forceFill(['gross_pay' => 20_000, 'sss' => 750, 'philhealth' => 375, 'pagibig' => 100, 'late_deduction' => 25, 'undertime_deduction' => 0])->save();
    $actor = thirtActor();
    $id = (string) $employee->getKey();

    $calculation = ThirteenthMonthData::calculateDrafts([$id], 2026, actor: $actor);

    // 20,000 − (750 + 375 + 100) − 25 = 18,750, exactly as PayrollData records it.
    expect($calculation['statutory'][$id]['basis']['regular_taxable'])->toBe(18_750.0)
        ->and($calculation['drafts'][0]->withholding_tax)
        ->toBe(SupplementaryWithholding::tax($calculation['employees']->first(), 18_750.0, $calculation['drafts'][0]->taxable_benefits_excess));
});
