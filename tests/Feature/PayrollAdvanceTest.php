<?php

declare(strict_types=1);

use App\Domain\Enums\PayrollRunKind;
use App\Domain\Payroll\PayrollValidation;
use App\Livewire\Payroll\RunWorkspace;
use App\Livewire\Payroll\SettingsOverview;
use App\Livewire\Payroll\Support\PayslipDocument;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Holiday;
use App\Models\LeaveTravelRequest;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipSettlement;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Payroll\Application\AdvanceSettlements;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\Commands\SavePayroll;
use App\Modules\Payroll\Application\OpenAttendanceItems;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollDraft;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\Queries\PayslipRecords;
use App\Modules\Timekeeping\Application\Commands\CorrectPunches;
use App\Modules\Timekeeping\Domain\Attendance\CorrectionWindow;
use App\Modules\Timekeeping\Domain\Attendance\PunchSlots;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PayrollEvidenceFixture;
use Tests\Support\PayrollReviewFixture;

/**
 * The advance payroll (PayrollAdvance, DELIBERATE DIVERGENCE 14): a regular
 * payroll processed before its period ends reads attendance only through
 * its "attendance counted through" date and pays the scheduled days after
 * it as full days. Those days stay open on the Daily Time Record; the
 * employee's next regular payroll prices them from their finished
 * attendance and settles the difference as one line
 * (AdvanceSettlements), after which they are frozen like every paid day.
 * The counted date must be inside the period and over; the date, the
 * presumed days and every settlement are in the evidence and the audit.
 *
 * September 2026 starts on a Tuesday: 22 scheduled Monday-to-Friday days,
 * so PHP 30,000 a month is PHP 1,363.636… a day. The advance payroll
 * covers 1 to 15 September and is processed on Tuesday the 15th, counted
 * through Monday the 14th.
 */
/** @param list<string> $modules */
function padvActor(array $modules = ['PAYROLL', 'EDTR', 'LEAVES'], bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Advance '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

/**
 * Monday to Friday, 08:00-17:00 with a 60-minute unpaid break, PHP 30,000 a month.
 *
 * @param  array<string, mixed>  $overrides
 */
function padvEmployee(string $last = 'Aquino', array $overrides = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_merge([
        'employee_code' => 'ADV-'.random_int(1000, 9999), 'first_name' => 'Ramon', 'last_name' => $last,
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true, 'monthly_rate' => 30000,
    ], $overrides))->save();
    $shift = new Shift;
    $shift->forceFill([
        'employee_id' => (string) $employee->getKey(), 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 15,
        'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60, 'requires_break_punch' => false,
    ])->save();

    return $employee;
}

function padvPunch(EmployeeProfile $employee, string $type, string $manilaStamp): void
{
    $punch = new AttendanceLog;
    $punch->forceFill([
        'employee_id' => (string) $employee->getKey(), 'log_type' => $type, 'source' => 'MANUAL',
        'is_geofenced' => true, 'is_anomaly' => false, 'logged_at' => ManilaClock::instant($manilaStamp)->utc(),
    ])->save();
}

/** A full day: 08:00 to `$out`. */
function padvWorked(EmployeeProfile $employee, string $date, string $out = '17:00'): void
{
    padvPunch($employee, 'TIME_IN', "{$date}T08:00:00");
    padvPunch($employee, 'TIME_OUT', "{$date}T{$out}:00");
}

/** The employee at work on the first ten working days of September, 1 to 14. */
function padvWorkedThroughFourteenth(EmployeeProfile $employee): void
{
    foreach (['01', '02', '03', '04', '07', '08', '09', '10', '11', '14'] as $day) {
        padvWorked($employee, "2026-09-{$day}");
    }
}

/** The eleven working days of 16 to 30 September. */
function padvWorkedSecondHalf(EmployeeProfile $employee): void
{
    foreach (['16', '17', '18', '21', '22', '23', '24', '25', '28', '29', '30'] as $day) {
        padvWorked($employee, "2026-09-{$day}");
    }
}

/** @param list<EmployeeProfile> $employees */
function padvDraft(Access $actor, string $start, string $end, ?string $through, array $employees, string $name = 'Advance'): PayrollRun
{
    $members = array_map(static fn (EmployeeProfile $e): string => (string) $e->getKey(), $employees);
    $result = app(SavePayroll::class)->handle($actor, new PayrollDraft(null, $name.' '.Str::random(4), $start, $end, $members, [], [], [], attendanceThrough: $through));

    return PayrollRun::query()->findOrFail($result['id']);
}

function padvFinalize(Access $actor, PayrollRun $run): void
{
    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
}

/** The advance payroll of the docblock, posted on 15 September; the clock is left on that day. */
function padvPostedAdvance(Access $actor, EmployeeProfile $employee): PayrollRun
{
    test()->travelTo(new DateTimeImmutable('2026-09-15T10:00:00+08:00'));
    padvWorkedThroughFourteenth($employee);
    $run = padvDraft($actor, '2026-09-01', '2026-09-15', '2026-09-14', [$employee]);
    padvFinalize($actor, $run);

    return $run->refresh();
}

/** @return array<string, mixed> */
function padvEvidence(PayrollRun $run): array
{
    return json_decode((string) PayrollFinalizationSnapshot::query()->where('run_id', $run->id)->sole()->payload, true, 512, JSON_THROW_ON_ERROR);
}

/*
|--------------------------------------------------------------------------
| The counted date
|--------------------------------------------------------------------------
*/

it('starts a draft opened while the period runs counted through yesterday, and an ended one through nothing', function () {
    padvActor();

    $this->travelTo(new DateTimeImmutable('2026-09-12T10:00:00+08:00'));
    Livewire::test(RunWorkspace::class)->assertSet('attendanceThrough', '2026-09-11')->assertSeeHtml('data-attendance-through');

    $this->travelTo(new DateTimeImmutable('2026-09-20T10:00:00+08:00'));
    Livewire::test(RunWorkspace::class)->assertSet('attendanceThrough', '');
});

it('refuses a counted date that is today, outside the period, or on a 13th month run', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-15T10:00:00+08:00'));
    $actor = padvActor();
    $employee = padvEmployee();

    // The period's last day is no advance at all, so "today" needs a period still running.
    expect(fn () => padvDraft($actor, '2026-09-01', '2026-09-30', '2026-09-15', [$employee]))
        ->toThrow(RuntimeException::class, 'yesterday at the latest');
    expect(fn () => padvDraft($actor, '2026-09-01', '2026-09-15', '2026-08-31', [$employee]))
        ->toThrow(RuntimeException::class, 'inside the payroll period');
    expect(fn () => app(SavePayroll::class)->handle($actor, new PayrollDraft(null, 'Yearly', '2026-01-01', '2026-12-31', [], [], [], [], kind: PayrollRunKind::THIRTEENTH_MONTH, attendanceThrough: '2026-06-30')))
        ->toThrow(RuntimeException::class, 'no attendance cut-off');
    expect(PayrollRun::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'PAYROLL_RUN_CREATED')->count())->toBe(0);
});

it('saves the counted date, pays the days after it as scheduled and finalizes without the open-period tick', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-15T10:00:00+08:00'));
    padvActor();
    $employee = padvEmployee();
    padvWorkedThroughFourteenth($employee);
    // At work right now: an open Time In the payroll would otherwise refuse as unpaired.
    padvPunch($employee, 'TIME_IN', '2026-09-15T08:00:00');

    Livewire::test(RunWorkspace::class)
        ->set('name', 'September 2026 — first cut-off')
        ->set('start', '2026-09-01')
        ->set('end', '2026-09-15')
        ->set('attendanceThrough', '2026-09-14')
        ->call('openPicker')
        ->call('togglePick', $employee->id)
        ->call('applyPicked')
        ->call('save')
        ->assertRedirect();
    $run = PayrollRun::query()->sole();

    expect($run->attendance_through?->format('Y-m-d'))->toBe('2026-09-14')
        ->and(AuditLog::query()->where('action', 'PAYROLL_RUN_CREATED')->value('details'))->toContain('attendance counted through 14 Sep 2026');

    // The preview: paid in advance, the period not "open", the open Time In no issue.
    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->assertSeeHtml('data-advance')
        ->assertSee('attendance is counted through')
        ->assertDontSeeHtml('data-open-period')
        ->assertDontSeeHtml('data-advance-error')
        ->assertSeeHtml('data-presumed-days="'.$employee->id.'"');
    $page->call('finalize');

    expect($run->refresh()->status)->toBe('FINALIZED');
    $payslip = Payslip::query()->where('run_id', $run->id)->sole();
    // Ten worked days and the presumed 15th; the 12th and 13th are rest days.
    expect($payslip->work_days)->toBe(11.0)
        ->and($payslip->presumed_days)->toBe(1.0)
        ->and($payslip->absence_days)->toBe(0.0)
        ->and($payslip->work_days_pay)->toBe(15000.0)
        ->and($payslip->settlement)->toBe(0.0)
        ->and($payslip->attendance_through?->format('Y-m-d'))->toBe('2026-09-14')
        ->and(AuditLog::query()->where('action', 'PAYROLL_FINALIZED')->value('details'))->toContain('attendance counted through 14 Sep 2026, 1 day paid in advance');

    $evidence = padvEvidence($run);
    expect($evidence['inputs']['schema_version'])->toBe(PayrollInputs::SCHEMA_VERSION)
        ->and($evidence['inputs']['attendance_through'])->toBe('2026-09-14')
        ->and($evidence['inputs']['settlements'])->toBe([])
        ->and($evidence['open_items']['period_open'])->toBeFalse()
        // The 15th's punch is not in the facts: the days after the counted date are decided, not read.
        ->and(array_values(array_filter(array_column($evidence['inputs']['attendance'][$employee->id]['logs'], 'logged_at'), static fn (string $at): bool => str_starts_with($at, '2026-09-15'))))->toBe([]);
});

it('keeps the preview still while the processing day is punched', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-15T10:00:00+08:00'));
    $actor = padvActor();
    $employee = padvEmployee();
    padvWorkedThroughFourteenth($employee);
    padvPunch($employee, 'TIME_IN', '2026-09-15T08:00:00');

    $before = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor, attendanceThrough: '2026-09-14')['inputs'];
    padvPunch($employee, 'TIME_OUT', '2026-09-15T12:00:00');
    $after = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor, attendanceThrough: '2026-09-14')['inputs'];
    $whole = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor)['inputs'];

    expect($before?->fingerprint())->toBe($after?->fingerprint())
        ->and($whole?->fingerprint())->not->toBe($after?->fingerprint());
});

it('lists open attendance only among the counted days', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-15T10:00:00+08:00'));
    $actor = padvActor();
    $employee = padvEmployee();
    padvWorkedThroughFourteenth($employee);
    // Overtime awaiting a decision on the 10th (counted) and the 14th (counted), punches on the 15th (presumed).
    AttendanceLog::query()->where('employee_id', $employee->id)->whereDate('logged_at', '2026-09-10')->where('log_type', 'TIME_OUT')->update(['logged_at' => ManilaClock::instant('2026-09-10T19:30:00')->utc()]);
    padvWorked($employee, '2026-09-15', '19:30');
    foreach ([['LR-C', '2026-09-11', '2026-09-11'], ['LR-P', '2026-09-15', '2026-09-15']] as [$code, $start, $end]) {
        $request = new LeaveTravelRequest;
        $request->forceFill(['code' => $code, 'employee_id' => $employee->id, 'request_type' => 'SICK', 'day_type' => 'WHOLE_DAY',
            'start_date' => $start, 'end_date' => $end, 'reason' => 'Flu', 'status' => 'PENDING', 'is_paid' => null])->save();
    }

    $inputs = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor, attendanceThrough: '2026-09-14')['inputs'];
    $open = OpenAttendanceItems::forInputs($inputs, '2026-09-15');

    expect($open->periodOpen)->toBeFalse()
        ->and($open->overtime)->toBe([$employee->id => ['2026-09-10']])
        ->and($open->requests)->toBe([$employee->id => [['type' => 'SICK', 'start' => '2026-09-11', 'end' => '2026-09-11']]]);
});

/*
|--------------------------------------------------------------------------
| What is frozen, and the settlement
|--------------------------------------------------------------------------
*/

it('freezes only the counted days until the presumed ones are settled', function () {
    $actor = padvActor();
    $employee = padvEmployee();
    padvPostedAdvance($actor, $employee);
    $this->travelTo(new DateTimeImmutable('2026-09-20T10:00:00+08:00'));

    expect(fn () => CorrectPunches::apply($actor, $employee->id, '2026-09-14', ['time_in' => '08:00', 'break_out' => null, 'break_in' => null, 'time_out' => '18:00'], PunchSlots::SAME_DAY, 'Stayed'))
        ->toThrow(RuntimeException::class, CorrectionWindow::PAID_DAY);
    // The presumed 15th is still open: its attendance is what the settlement will read.
    CorrectPunches::apply($actor, $employee->id, '2026-09-15', ['time_in' => '08:00', 'break_out' => null, 'break_in' => null, 'time_out' => '17:00'], PunchSlots::SAME_DAY, 'Forgot to punch');
    expect(AttendanceLog::query()->where('employee_id', $employee->id)->where('source', AttendanceLog::SOURCE_CORRECTION)->count())->toBe(2);
});

it('settles the presumed days on the next payroll from their finished attendance, and then freezes them', function () {
    $actor = padvActor();
    $absent = padvEmployee('Absent');
    $worked = padvEmployee('Worked');
    $this->travelTo(new DateTimeImmutable('2026-09-15T10:00:00+08:00'));
    padvWorkedThroughFourteenth($absent);
    padvWorkedThroughFourteenth($worked);
    $advance = padvDraft($actor, '2026-09-01', '2026-09-15', '2026-09-14', [$absent, $worked]);
    padvFinalize($actor, $advance);
    $advanceSlips = Payslip::query()->where('run_id', $advance->id)->get()->keyBy('employee_id');

    // The 15th turned out: Absent never came in; Worked worked the day as scheduled.
    padvWorked($worked, '2026-09-15');
    $this->travelTo(new DateTimeImmutable('2026-10-01T10:00:00+08:00'));
    padvWorkedSecondHalf($absent);
    padvWorkedSecondHalf($worked);

    // On the 15th itself nothing was settled: the presumed day was not over.
    $this->travelTo(new DateTimeImmutable('2026-09-15T18:00:00+08:00'));
    expect(PayrollData::calculateDrafts([$absent->id], '2026-09-16', '2026-09-30', actor: $actor)['inputs']?->settlements)->toBe([]);
    expect(app(PayslipRecords::class)->unsettledAdvances($actor))->toHaveCount(2);

    $this->travelTo(new DateTimeImmutable('2026-10-01T10:00:00+08:00'));
    $next = padvDraft($actor, '2026-09-16', '2026-09-30', null, [$absent, $worked], 'Second');
    $page = Livewire::test(RunWorkspace::class, ['id' => $next->id])
        ->assertSeeHtml('data-advance-settled')
        ->assertSeeHtml('data-settlement="'.$absent->id.'"')
        ->call('openWhy', $absent->id, 'settlement')
        ->assertSee('Settlement for 15 Sep 2026 · payroll '.$advance->code)
        ->assertSee('1 absence');
    $page->call('finalize');
    expect($next->refresh()->status)->toBe('FINALIZED');

    $slips = Payslip::query()->where('run_id', $next->id)->get()->keyBy('employee_id');
    // Absent: the presumed day (PHP 1,363.64) was worth nothing, so it is charged back.
    expect($slips[$absent->id]->settlement)->toBe(-1363.64)
        ->and($slips[$absent->id]->work_days)->toBe(11.0)
        ->and($slips[$absent->id]->gross_pay)->toBe(round(15000.0 - 1363.64, 2))
        // Worked: the day was worth exactly what was presumed, so the settlement is zero — and still recorded.
        ->and($slips[$worked->id]->settlement)->toBe(0.0)
        ->and($slips[$worked->id]->gross_pay)->toBe(15000.0);

    $rows = PayslipSettlement::query()->get()->keyBy('employee_id');
    expect($rows)->toHaveCount(2)
        ->and($rows[$absent->id]->payslip_id)->toBe($slips[$absent->id]->id)
        ->and($rows[$absent->id]->settled_payslip_id)->toBe($advanceSlips[$absent->id]->id)
        ->and($rows[$absent->id]->settled_code)->toBe($advance->code)
        ->and($rows[$absent->id]->from_date->format('Y-m-d'))->toBe('2026-09-15')
        ->and($rows[$absent->id]->to_date->format('Y-m-d'))->toBe('2026-09-15')
        ->and($rows[$absent->id]->presumed_amount)->toBe(1363.64)
        ->and($rows[$absent->id]->actual_amount)->toBe(0.0)
        ->and($rows[$absent->id]->amount)->toBe(-1363.64)
        ->and((float) $rows[$absent->id]->details['actual']['absence_days'])->toBe(1.0)
        ->and($rows[$worked->id]->amount)->toBe(0.0)
        ->and(AuditLog::query()->where('action', 'PAYROLL_FINALIZED')->where('entity_id', $next->id)->value('details'))->toContain('settles earlier presumed days for 2 employees');

    // The evidence carries the settlement's own facts and names the payslip it settled.
    $evidence = padvEvidence($next);
    expect($evidence['inputs']['settlements'][$absent->id][0]['payslip_id'])->toBe($advanceSlips[$absent->id]->id)
        ->and($evidence['inputs']['settlements'][$absent->id][0]['run_code'])->toBe($advance->code)
        ->and($evidence['inputs']['settlements'][$absent->id][0]['from'])->toBe('2026-09-15')
        ->and(PayrollInputs::restore(PayrollInputs::json($evidence['inputs']))->fingerprint())->toBe($evidence['inputs'] === null ? '' : PayrollInputs::restore(PayrollInputs::json($evidence['inputs']))->fingerprint());

    // Settled: nothing is pending, the settled day is frozen, and the settlement is on the payslip.
    expect(app(PayslipRecords::class)->unsettledAdvances($actor))->toBe([]);
    expect(fn () => CorrectPunches::apply($actor, $absent->id, '2026-09-15', ['time_in' => '08:00', 'break_out' => null, 'break_in' => null, 'time_out' => '17:00'], PunchSlots::SAME_DAY, 'Late filing'))
        ->toThrow(RuntimeException::class, CorrectionWindow::PAID_DAY);
    $settlements = app(PayslipRecords::class)->settlements($actor, [$slips[$absent->id]->id])[$slips[$absent->id]->id];
    $document = PayslipDocument::issued(app(PayslipRecords::class)->find($actor, $next->id, $absent->id), $absent, false, [], [], collect(), settlements: $settlements);
    expect(array_column($document['earnings'], 'label'))->toContain('Settlement for 15 Sep 2026 · '.$advance->code);
    $advanceDocument = PayslipDocument::issued(app(PayslipRecords::class)->find($actor, $advance->id, $absent->id), $absent, false, [], [], collect());
    expect(array_column($advanceDocument['basis'], 'label'))->toContain('Paid in advance · days');
});

it('freezes the presumed days\' figures in the advance payroll\'s evidence and settles from them, not from a new computation', function () {
    $actor = padvActor();
    $employee = padvEmployee();
    $advance = padvPostedAdvance($actor, $employee);

    // The advance payroll wrote what it presumed the 15th was worth beside its
    // results (JSON carries a whole peso figure as an integer; read as floats).
    $frozen = array_map(static fn (int|float $v): float => (float) $v, padvEvidence($advance)['presumed'][$employee->id]);
    expect($frozen['work_days'])->toBe(1.0)
        ->and($frozen['basic_pay'])->toBe(1363.64)
        ->and($frozen['late_deduction'])->toBe(0.0)
        ->and(array_keys($frozen))->toEqualCanonicalizing(AdvanceSettlements::LINE_KEYS);

    // The 15th turned out absent. The settlement's presumed side is the frozen figure.
    $this->travelTo(new DateTimeImmutable('2026-10-01T10:00:00+08:00'));
    padvWorkedSecondHalf($employee);
    $calculation = PayrollData::calculateDrafts([$employee->id], '2026-09-16', '2026-09-30', actor: $actor);
    $line = $calculation['settlementLines'][$employee->id][0];
    $inputs = $calculation['inputs'];
    // (toEqual: the frozen copy is read in key order, the evidence stores keys sorted.)
    expect($line['presumed'])->toEqual($frozen)
        ->and($line['presumed_amount'])->toBe(1363.64)
        ->and($line['amount'])->toBe(-1363.64)
        ->and($inputs?->settlements[$employee->id][0]->presumedLines)->toEqual($frozen)
        ->and($inputs?->settlements[$employee->id][0]->resigned)->toBeNull();

    // The evidence carries both new facts, round-trips exactly, and refuses them malformed or missing.
    assert($inputs !== null);
    $shape = $inputs->toArray();
    expect($shape['schema_version'])->toBe(PayrollInputs::SCHEMA_VERSION)
        ->and($shape['settlements'][$employee->id][0]['presumed_lines'])->toEqual($frozen)
        ->and($shape['settlements'][$employee->id][0]['resigned'])->toBeNull()
        ->and(PayrollInputs::restore(PayrollInputs::json($shape))->fingerprint())->toBe($inputs->fingerprint());
    $older = $shape;
    $older['schema_version'] = 12;
    $older = PayrollEvidenceFixture::beforeSchema17($older);
    unset($older['settlements'][$employee->id][0]['presumed_lines'], $older['settlements'][$employee->id][0]['resigned'], $older['leave_cash_outs'], $older['cash_out_type'], $older['gov_policies'], $older['adjustments']);
    $restored = PayrollInputs::restore(PayrollInputs::json($older));
    expect($restored->settlements[$employee->id][0]->presumedLines)->toBeNull()
        ->and($restored->settlements[$employee->id][0]->resigned)->toBeNull()
        ->and($restored->fingerprint())->toBe(hash('sha256', PayrollInputs::json($older)));
    $missing = $shape;
    unset($missing['settlements'][$employee->id][0]['presumed_lines']);
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($missing)))->toThrow(RuntimeException::class, 'settlement evidence');
    $malformed = $shape;
    unset($malformed['settlements'][$employee->id][0]['presumed_lines']['basic_pay']);
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($malformed)))->toThrow(RuntimeException::class, 'settlement evidence');
    $badDate = $shape;
    $badDate['settlements'][$employee->id][0]['resigned'] = '14 Sep 2026';
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($badDate)))->toThrow(RuntimeException::class, 'settlement evidence');

    // What was frozen is what is settled, whatever a new computation would
    // give (the evidence model refuses edits, so the test tampers through
    // the query builder, as nothing in the application can)...
    $snapshot = PayrollFinalizationSnapshot::query()->where('run_id', $advance->id)->sole();
    $payload = json_decode((string) $snapshot->payload, true, 512, JSON_THROW_ON_ERROR);
    $payload['presumed'][$employee->id]['basic_pay'] = 2000.0;
    DB::table('payroll_finalization_snapshots')->where('id', $snapshot->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    expect(PayrollData::calculateDrafts([$employee->id], '2026-09-16', '2026-09-30', actor: $actor)['settlementLines'][$employee->id][0]['amount'])->toBe(-2000.0);

    // ...and a payslip posted before the figure was recorded is priced again from what it presumed from.
    unset($payload['presumed']);
    DB::table('payroll_finalization_snapshots')->where('id', $snapshot->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    $again = PayrollData::calculateDrafts([$employee->id], '2026-09-16', '2026-09-30', actor: $actor);
    expect($again['settlementLines'][$employee->id][0]['amount'])->toBe(-1363.64)
        ->and($again['inputs']?->settlements[$employee->id][0]->presumedLines)->toBeNull();
});

it('prices a holiday proclaimed after the advance payroll posted on the settlement\'s actual side', function () {
    $actor = padvActor();
    $employee = padvEmployee();
    padvPostedAdvance($actor, $employee);

    // The employee worked the presumed 15th as scheduled, and the 15th was then proclaimed a regular holiday.
    padvWorked($employee, '2026-09-15');
    $holiday = new Holiday;
    $holiday->forceFill(['code' => 'HOL-T1', 'name' => 'Proclaimed later', 'date' => '2026-09-15', 'schedule' => 'ONE_TIME', 'type' => 'REGULAR', 'percent' => 200])->save();
    $this->travelTo(new DateTimeImmutable('2026-10-01T10:00:00+08:00'));
    padvWorkedSecondHalf($employee);

    // Presumed as an ordinary day (PHP 1,363.64); worked, it is a regular holiday at 200% (PHP 2,727.27): the difference is owed.
    $line = PayrollData::calculateDrafts([$employee->id], '2026-09-16', '2026-09-30', actor: $actor)['settlementLines'][$employee->id][0];
    expect($line['presumed']['basic_pay'])->toBe(1363.64)
        ->and($line['actual']['basic_pay'])->toBe(2727.27)
        ->and($line['amount'])->toBe(1363.63);
});

it('charges back the presumed days after a resignation recorded later, while no payroll after the leaving date may hold the leaver', function () {
    $actor = padvActor();
    $employee = padvEmployee();
    padvPostedAdvance($actor, $employee);

    // Recorded afterwards: the employee left on the 14th, so the presumed 15th was never owed.
    $employee->forceFill(['date_resigned' => '2026-09-14', 'is_active' => false])->save();
    $this->travelTo(new DateTimeImmutable('2026-10-01T10:00:00+08:00'));
    $settlement = AdvanceSettlements::capture($actor, [$employee->id], '2026-09-16', '2026-10-01', app(EmployeePayContexts::class)->get($actor, [$employee->id]))[$employee->id][0];
    expect($settlement->resigned)->toBe('2026-09-14')
        // The presumed side keeps the employee the advance payroll paid: no leaving date.
        ->and(PayrollInputs::employeeToArray($settlement->employee)['date_resigned'])->toBeNull();

    $line = AdvanceSettlements::price($settlement);
    expect($line['presumed']['work_days'])->toBe(1.0)
        ->and($line['actual']['work_days'])->toBe(0.0)
        ->and($line['actual']['absence_days'])->toBe(0.0)
        ->and($line['amount'])->toBe(-1363.64);

    // A regular payroll starting after the leaving date refuses the leaver, so nothing settles this on its own.
    expect(fn () => padvDraft($actor, '2026-09-16', '2026-09-30', null, [$employee]))->toThrow(RuntimeException::class, PayrollValidation::MESSAGE_OUTSIDE_EMPLOYMENT);
    expect(app(PayslipRecords::class)->unsettledAdvances($actor))->toHaveCount(1);
});

it('lists the days paid in advance that no payroll has settled on the Payroll Settings overview', function () {
    $actor = padvActor();
    $employee = padvEmployee('Pending');
    $advance = padvPostedAdvance($actor, $employee);
    $this->travelTo(new DateTimeImmutable('2026-10-05T10:00:00+08:00'));

    Livewire::test(SettingsOverview::class)
        ->assertSee('Ramon Pending\'s days paid in advance on payroll '.$advance->code.' (15 Sep 2026 to 15 Sep 2026) are not settled yet.')
        ->assertSee('Their next regular payroll settles them');

    $employee->forceFill(['is_active' => false])->save();
    Livewire::test(SettingsOverview::class)
        ->assertSee('no payroll will settle them on its own')
        ->assertSee('Unsettled days');
});

/*
|--------------------------------------------------------------------------
| Evidence and schema
|--------------------------------------------------------------------------
*/

it('restores schema 10 evidence without the advance fields and refuses malformed ones', function () {
    $actor = padvActor();
    $employee = padvEmployee();
    $advance = padvPostedAdvance($actor, $employee);
    $inputs = padvEvidence($advance)['inputs'];

    $older = $inputs;
    $older['schema_version'] = 10;
    $older = PayrollEvidenceFixture::beforeSchema17($older);
    unset($older['attendance_through'], $older['settlements'], $older['leave_charges'], $older['leave_cash_outs'], $older['cash_out_type'], $older['gov_policies'], $older['adjustments']);
    $restored = PayrollInputs::restore(PayrollInputs::json($older));
    expect($restored->attendanceThrough)->toBeNull()
        ->and($restored->settlements)->toBe([])
        ->and($restored->fingerprint())->toBe(hash('sha256', PayrollInputs::json($older)));

    $current = PayrollInputs::restore(PayrollInputs::json($inputs));
    expect($current->attendanceThrough)->toBe('2026-09-14')
        ->and($current->attendanceEnd())->toBe('2026-09-14');

    $malformed = $inputs;
    $malformed['attendance_through'] = '14 Sep 2026';
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($malformed)))->toThrow(RuntimeException::class);

    $missing = $inputs;
    unset($missing['attendance_through']);
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($missing)))->toThrow(RuntimeException::class);

    $foreign = $inputs;
    $foreign['settlements'] = ['someone-else' => []];
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($foreign)))->toThrow(RuntimeException::class, 'settlement evidence');
});

it('migrates the advance columns and the settlement ledger twice without complaint', function () {
    $columns = require base_path('database/migrations/2026_10_31_0001_add_attendance_through_to_payroll_tables.php');
    $ledger = require base_path('database/migrations/2026_10_31_0002_create_payslip_settlements_table.php');

    $ledger->down();
    $columns->down();
    $columns->up();
    $columns->up();
    $ledger->up();
    $ledger->up();

    expect(Schema::hasColumn('payroll_runs', 'attendance_through'))->toBeTrue()
        ->and(Schema::hasColumn('payslips', 'attendance_through'))->toBeTrue()
        ->and(Schema::hasColumn('payslips', 'presumed_days'))->toBeTrue()
        ->and(Schema::hasColumn('payslips', 'settlement'))->toBeTrue()
        ->and(Schema::hasTable('payslip_settlements'))->toBeTrue()
        ->and(Schema::hasIndex('payslip_settlements', 'pss_settled_unq'))->toBeTrue();
});
