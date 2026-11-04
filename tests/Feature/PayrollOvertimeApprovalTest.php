<?php

declare(strict_types=1);

use App\Domain\Payroll\PayslipDraft;
use App\Livewire\Payroll\RunWorkspace;
use App\Models\AttendanceLog;
use App\Models\EmployeeProfile;
use App\Models\OvertimeApproval;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollPayRule;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\PayrollReviewRequired;
use App\Modules\Timekeeping\Application\Commands\DecideOvertime;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PayrollEvidenceFixture;
use Tests\Support\PayrollReviewFixture;

/**
 * DELIBERATE DIVERGENCE 7: payroll pays overtime only as the Daily Time
 * Record approved it, day by day — min(computed, approved, the Formulas cap)
 * — and never through a run-level switch. Unapproved overtime is excluded by
 * default; an explicit rejection is optional. It no longer posts unnoticed:
 * a day still awaiting a decision is open attendance, listed before you
 * finalize, and posts unpaid only once the officer ticks "Finalize anyway"
 * (OpenAttendanceItems, PayrollOpenItemsTest). Posted payslips remain
 * immutable.
 *
 * The hourly rate is pinned at 200 (a stored override), so an overtime hour on
 * a work day is exactly 200 × 125% = 250.00.
 */
function payotActor(): User
{
    $role = new Role;
    $role->forceFill(['name' => 'PayOT '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->getKey(), 'module' => 'PAYROLL'])->save();

    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

/** @param array<string, mixed> $overrides */
function payotEmployee(array $overrides = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_merge([
        'employee_code' => 'POT-'.random_int(1000, 9999), 'first_name' => 'Ramon', 'last_name' => 'Aquino',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
        'monthly_rate' => 30000, 'hourly_rate' => 200,
    ], $overrides))->save();

    $shift = new Shift;
    $shift->forceFill([
        'employee_id' => (string) $employee->getKey(), 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 5,
        'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60, 'requires_break_punch' => false,
    ])->save();

    return $employee;
}

/** A day worked 08:00 to $out, Manila; past 17:00 is overtime. */
function payotWorked(EmployeeProfile $employee, string $date, string $out = '19:30'): void
{
    foreach (['TIME_IN' => '08:00', 'TIME_OUT' => $out] as $type => $at) {
        $punch = new AttendanceLog;
        $punch->forceFill([
            'employee_id' => (string) $employee->getKey(), 'log_type' => $type, 'source' => 'MANUAL',
            'is_geofenced' => true, 'is_anomaly' => false,
            'logged_at' => ManilaClock::instant("{$date}T{$at}:00")->utc(),
        ])->save();
    }
}

function payotDecide(EmployeeProfile $employee, string $date, string $decision, int $computed, int $approved, ?string $category = null): void
{
    $row = new OvertimeApproval;
    $row->forceFill(['employee_id' => (string) $employee->getKey(), 'date' => $date, 'decision' => $decision,
        'computed_minutes' => $computed, 'approved_minutes' => $approved, 'category' => $category])->save();
}

function payotRun(EmployeeProfile $employee): PayrollRun
{
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-OT', 'name' => 'September 2026 — overtime', 'status' => 'DRAFT',
        'period_start' => '2026-09-01', 'period_end' => '2026-09-15'])->save();
    PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->getKey(), 'position' => 0,
        'period_start' => '2026-09-01', 'period_end' => '2026-09-15']);

    return $run;
}

/** @return array{drafts: list<PayslipDraft>, issues: list<array{employee_id: string, message: string}>} */
function payotCalculate(EmployeeProfile $employee): array
{
    $result = PayrollData::calculateDrafts([(string) $employee->getKey()], '2026-09-01', '2026-09-15');

    return ['drafts' => $result['drafts'], 'issues' => $result['issues']];
}

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-09-20T12:00:00+08:00'));
});

it('pays only the minutes the Daily Time Record approved', function () {
    $employee = payotEmployee();
    payotWorked($employee, '2026-09-08');
    payotDecide($employee, '2026-09-08', 'APPROVED', 150, 60);
    payotActor();

    ['drafts' => [$draft], 'issues' => $issues] = payotCalculate($employee);

    expect($issues)->toBe([])
        ->and($draft->overtime_hours)->toBe(1.0)
        ->and($draft->overtime_pay)->toBe(250.0)
        ->and($draft->overtime_acknowledged)->toBeTrue();

    // The whole day approved pays all of it, on the same arithmetic.
    OvertimeApproval::query()->update(['approved_minutes' => 150]);
    ['drafts' => [$full]] = payotCalculate($employee);
    expect($full->overtime_hours)->toBe(2.5)
        ->and($full->overtime_pay)->toBe(625.0)
        ->and($full->gross_pay)->toBe(round($draft->gross_pay + 375.0, 2));
});

it('never pays more than the day computed, whatever was approved', function () {
    $employee = payotEmployee();
    payotWorked($employee, '2026-09-08', '18:00');
    // Approved when the day computed more; a corrected punch shortened it.
    payotDecide($employee, '2026-09-08', 'APPROVED', 150, 150);
    payotActor();

    ['drafts' => [$draft]] = payotCalculate($employee);

    expect($draft->overtime_hours)->toBe(1.0)
        ->and($draft->overtime_pay)->toBe(250.0);
});

it('pays nothing for a rejected day, and a rejection does not block', function () {
    $employee = payotEmployee();
    payotWorked($employee, '2026-09-08');
    payotDecide($employee, '2026-09-08', 'REJECTED', 150, 0);
    payotActor();

    ['drafts' => [$draft], 'issues' => $issues] = payotCalculate($employee);

    expect($issues)->toBe([])
        ->and($draft->overtime_hours)->toBe(0.0)
        ->and($draft->overtime_pay)->toBe(0.0)
        ->and($draft->overtime_acknowledged)->toBeFalse();
});

it('finalizes with unapproved overtime excluded and approved overtime paid', function () {
    $employee = payotEmployee();
    payotWorked($employee, '2026-09-08');
    payotWorked($employee, '2026-09-09');
    payotDecide($employee, '2026-09-09', 'APPROVED', 150, 150);
    payotActor();

    ['drafts' => [$draft], 'issues' => $issues] = payotCalculate($employee);

    expect($issues)->toBe([])
        ->and($draft->overtime_hours)->toBe(2.5)
        ->and($draft->overtime_pay)->toBe(625.0);

    $run = payotRun($employee);
    // The undecided 8 September is listed, and posts unpaid once accepted.
    Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->assertSee('Overtime awaiting a decision for Ramon Aquino:')
        ->call('toggleOpenItems')
        ->call('finalize');

    $posted = Payslip::query()->sole();
    expect($run->refresh()->status)->toBe('FINALIZED')
        ->and($posted->overtime_hours)->toBe(2.5)
        ->and($posted->overtime_pay)->toBe(625.0)
        ->and(OvertimeApproval::query()->count())->toBe(1);
});

it('finalizes without any overtime decisions and preserves zero overtime in its evidence', function () {
    $employee = payotEmployee();
    payotWorked($employee, '2026-09-08');
    $user = payotActor();
    (new RoleModuleGrant)->forceFill(['role_id' => $user->role_id, 'module' => 'EDTR'])->save();
    $run = payotRun($employee);
    $actor = app(Access::class);

    ['drafts' => [$draft], 'issues' => $issues] = payotCalculate($employee);
    expect($issues)->toBe([])
        ->and($draft->overtime_hours)->toBe(0.0)
        ->and($draft->overtime_pay)->toBe(0.0)
        ->and($draft->overtime_acknowledged)->toBeFalse();

    // Accepted as it stands: the day posts with no overtime pay.
    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id), acceptOpenItems: true);
    $posted = Payslip::query()->sole();
    $snapshot = PayrollFinalizationSnapshot::query()->sole();
    $payload = json_decode($snapshot->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($run->refresh()->status)->toBe('FINALIZED')
        ->and($posted->overtime_pay)->toBe(0.0)
        ->and($posted->overtime_acknowledged)->toBeFalse()
        ->and($payload['inputs']['attendance'][$employee->id]['overtime_approvals'])->toBe([])
        ->and($payload['results'][0]['overtime_pay'])->toEqual(0)
        ->and(OvertimeApproval::query()->count())->toBe(0);

    // A later approval cannot rewrite the posted zero-pay decision.
    expect(fn () => app(DecideOvertime::class)->handle($actor, $employee->id, '2026-09-08', 'APPROVED', '150'))
        ->toThrow(RuntimeException::class, 'That day is already on a posted payslip');
    expect(OvertimeApproval::query()->count())->toBe(0)
        ->and($posted->fresh()->toArray())->toBe($posted->toArray())
        ->and($snapshot->fresh()->payload)->toBe($snapshot->payload);
});

it('requires a new payroll review when excluded overtime is subsequently approved', function () {
    $employee = payotEmployee();
    payotWorked($employee, '2026-09-08');
    $user = payotActor();
    (new RoleModuleGrant)->forceFill(['role_id' => $user->role_id, 'module' => 'EDTR'])->save();
    app()->forgetInstance(Access::class);
    $actor = app(Access::class);
    $run = payotRun($employee);
    $receipt = PayrollReviewFixture::token($actor, $run->id);

    app(DecideOvertime::class)->handle($actor, $employee->id, '2026-09-08', 'APPROVED', '60');

    expect(fn () => app(FinalizePayroll::class)->handle($actor, $run->id, $receipt))
        ->toThrow(PayrollReviewRequired::class);
    expect($run->refresh()->status)->toBe('DRAFT')
        ->and(Payslip::query()->count())->toBe(0)
        ->and(PayrollFinalizationSnapshot::query()->count())->toBe(0);

    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    expect($run->refresh()->status)->toBe('FINALIZED')
        ->and(Payslip::query()->sole()->overtime_pay)->toBe(250.0);
});

it('ignores a decision outside the run period', function () {
    $employee = payotEmployee();
    payotWorked($employee, '2026-09-08');
    payotDecide($employee, '2026-09-08', 'APPROVED', 150, 150);
    payotDecide($employee, '2026-09-16', 'APPROVED', 150, 150);
    payotActor();

    $result = PayrollData::calculateDrafts([(string) $employee->getKey()], '2026-09-01', '2026-09-15');

    expect($result['inputs']?->attendance[(string) $employee->getKey()]->overtimeApprovals)->toBe(['2026-09-08' => 150])
        ->and($result['drafts'][0]->overtime_hours)->toBe(2.5);
});

it('no longer offers the run-level overtime switch', function () {
    $employee = payotEmployee();
    payotActor();
    $run = payotRun($employee);

    expect(fn () => Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('acknowledgeOvertime', (string) $employee->getKey(), true))
        ->toThrow(Exception::class, 'Public method [acknowledgeOvertime] not found');

    // A stored run-level acknowledgement is ignored: nothing was approved.
    PayrollRunMember::query()->update(['overtime_acknowledged' => true]);
    payotWorked($employee, '2026-09-08');
    ['drafts' => [$draft]] = payotCalculate($employee);
    expect($draft->overtime_pay)->toBe(0.0);
});

it('finalizes with the approved overtime pay', function () {
    $employee = payotEmployee();
    payotWorked($employee, '2026-09-08');
    payotDecide($employee, '2026-09-08', 'APPROVED', 150, 90);
    payotActor();
    $run = payotRun($employee);

    Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('finalize');

    $payslip = Payslip::query()->sole();
    expect($run->refresh()->status)->toBe('FINALIZED')
        ->and($payslip->overtime_hours)->toBe(1.5)
        ->and($payslip->overtime_pay)->toBe(375.0)
        ->and($payslip->overtime_acknowledged)->toBeTrue();
});

it('pays a seaport day uncapped at the version\'s seaport rate, from the captured categories', function () {
    $employee = payotEmployee();
    payotWorked($employee, '2026-09-08');
    // The company rule: overtime capped at one hour, seaport overtime at 150%.
    $version = PayrollPayRule::query()->sole();
    $params = $version->params;
    $params['overtime']['max_hours'] = 1.0;
    $params['overtime']['seaport_rate'] = 1.5;
    $version->forceFill(['params' => $params])->save();
    payotDecide($employee, '2026-09-08', 'APPROVED', 150, 150);
    $user = payotActor();
    (new RoleModuleGrant)->forceFill(['role_id' => $user->role_id, 'module' => 'EDTR'])->save();

    // Ordinary overtime: the cap leaves one hour at 200 × 125%.
    ['drafts' => [$ordinary]] = payotCalculate($employee);
    expect($ordinary->overtime_hours)->toBe(1.0)
        ->and($ordinary->overtime_pay)->toBe(250.0);

    // Seaport overtime: every approved minute, at 200 × 150%.
    OvertimeApproval::query()->update(['category' => 'SEAPORT']);
    ['drafts' => [$seaport], 'issues' => $issues] = payotCalculate($employee);
    expect($issues)->toBe([])
        ->and($seaport->overtime_hours)->toBe(2.5)
        ->and($seaport->overtime_pay)->toBe(750.0);

    // The category is evidence: captured with the approvals and replayed.
    $run = payotRun($employee);
    $actor = app(Access::class);
    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    $payload = json_decode(PayrollFinalizationSnapshot::query()->sole()->payload, true, 512, JSON_THROW_ON_ERROR);
    expect(Payslip::query()->sole()->overtime_pay)->toBe(750.0)
        ->and($payload['inputs']['schema_version'])->toBe(PayrollInputs::SCHEMA_VERSION)
        ->and($payload['inputs']['attendance'][$employee->id]['overtime_approvals'])->toBe(['2026-09-08' => 150])
        ->and($payload['inputs']['attendance'][$employee->id]['overtime_categories'])->toBe(['2026-09-08' => 'SEAPORT'])
        ->and(PayrollInputs::restore(PayrollInputs::json($payload['inputs']))->attendance[$employee->id]->overtimeCategories)->toBe(['2026-09-08' => 'SEAPORT']);

    // Evidence from before the categories existed restores with none.
    $older = $payload['inputs'];
    $older['schema_version'] = 16;
    $older = PayrollEvidenceFixture::beforeSchema17($older);
    expect(PayrollInputs::restore(PayrollInputs::json($older))->attendance[$employee->id]->overtimeCategories)->toBe([]);
});
