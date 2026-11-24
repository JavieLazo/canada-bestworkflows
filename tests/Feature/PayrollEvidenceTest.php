<?php

declare(strict_types=1);

use App\Domain\Payroll\PayslipDraft;
use App\Livewire\Payroll\RunWorkspace;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Holiday;
use App\Models\LeaveTravelRequest;
use App\Models\Notification;
use App\Models\OvertimeApproval;
use App\Models\PayrollEmployeeTaxDetail;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollFormula;
use App\Models\PayrollIncentiveType;
use App\Models\PayrollRunMember;
use App\Models\PayrollRunMemberIncentive;
use App\Models\Payslip;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Payroll\Application\CalculatorVersion;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\PayrollReviewRequired;
use App\Support\Access;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\PayrollEvidenceFixture;
use Tests\Support\PayrollReviewFixture;
use Tests\Support\ScheduleFixture;

/** A reviewed calculation is immutable evidence only after the entire finalization commits. */

/** A tax-exempt incentive line on the fixture's member, as SavePayroll would store it. */
function payrollEvidenceIncentive(PayrollRunMember $member, float $amount = 2500.0): PayrollIncentiveType
{
    $type = PayrollIncentiveType::create(['code' => '1', 'name' => 'Christmas Bonus', 'is_taxable' => false]);
    PayrollRunMemberIncentive::create(['member_id' => $member->id, 'type_id' => $type->id, 'amount' => $amount]);

    return $type;
}

/** A DTR overtime decision written as DecideOvertime would store it (the command itself is pinned elsewhere). */
function payrollEvidenceOvertimeDecision(string $employeeId, string $date, int $minutes): OvertimeApproval
{
    $row = new OvertimeApproval;
    $row->forceFill(['employee_id' => $employeeId, 'date' => $date, 'decision' => OvertimeApproval::APPROVED,
        'computed_minutes' => $minutes, 'approved_minutes' => $minutes]);
    $row->save();

    return $row;
}

it('replays exact saved facts without reading changed payroll source tables', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run, 'member' => $member] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $member->update(['allowance' => 120.5, 'deduction_sss' => 0]);
    payrollEvidenceOvertimeDecision($employee->id, '2026-09-04', 45);
    payrollEvidenceIncentive($member);
    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    $snapshot = PayrollFinalizationSnapshot::query()->sole();
    $payload = json_decode($snapshot->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($snapshot->calculator_version)->toBe(CalculatorVersion::current())
        ->and($snapshot->payload_hash)->toBe(hash('sha256', $snapshot->payload))
        ->and($snapshot->toArray())->not->toHaveKey('payload')
        ->and($payload['actor']['id'])->toBe($actor->userId)
        ->and($payload['inputs']['attendance'][$employee->id]['logs'][0]['logged_at'])->toContain('.123')
        ->and($payload['inputs']['attendance'][$employee->id]['leaves'][0]['is_paid'])->toBeNull()
        // The Daily Time Record's overtime decisions are captured per day;
        // the evidence carries no run-level acknowledgement.
        ->and($payload['inputs']['attendance'][$employee->id]['overtime_approvals'])->toBe(['2026-09-04' => 45])
        ->and($payload['inputs']['earnings'][$employee->id])->not->toHaveKey('overtime_acknowledged')
        ->and($payload['inputs']['deductions'][$employee->id]['sss'])->toBe(0)
        // Schema 2: the statutory settings the engine reads are captured, and
        // finalize records which lines were typed over the computed figure.
        ->and($payload['inputs']['schema_version'])->toBe(PayrollInputs::SCHEMA_VERSION)
        ->and($payload['inputs']['employees'][0])->toHaveKeys([
            'allow_sss', 'allow_philhealth', 'allow_pagibig', 'allow_withholding_tax', 'annualize_tax',
            'fixed_tax', 'percent_tax', 'fixed_sss_ee', 'fixed_sss_er', 'fixed_philhealth_ee',
            'fixed_philhealth_er', 'fixed_pagibig_ee', 'fixed_pagibig_er', 'sss_mode', 'philhealth_mode', 'pagibig_mode',
        ])
        ->and($payload['statutory'][$employee->id]['overridden'])->toBe(['sss'])
        // The incentive lines as the catalog described them, and the ledger
        // figure the PHP 90,000 split started from.
        ->and($payload['inputs']['incentives'][$employee->id][0])->toMatchArray(['code' => '1', 'name' => 'Christmas Bonus', 'is_taxable' => false, 'amount' => 2500])
        ->and($payload['inputs']['exempt_benefits_ytd'])->toBe([$employee->id => 0])
        ->and($payload['statutory'][$employee->id]['benefits'])->toEqual(['ytd_before' => 0, 'exempt' => 2500, 'ceiling' => 2500, 'taxable_excess' => 0])
        ->and($snapshot->payload)->not->toContain('Private leave reason', 'Private punch metadata', 'latitude', 'longitude', 'accuracy', 'password');
    $posted = Payslip::query()->sole()->toArray();
    $employee->update(['monthly_rate' => 45000, 'first_name' => 'Changed']);
    AttendanceLog::query()->delete();
    OvertimeApproval::query()->delete();
    PayrollRunMemberIncentive::query()->delete();
    PayrollIncentiveType::query()->update(['is_taxable' => true, 'name' => 'Renamed']);
    Shift::query()->update(['break_minutes' => 30]);
    LeaveTravelRequest::query()->update(['is_paid' => false, 'status' => 'REJECTED']);
    Holiday::query()->update(['percent' => 200]);
    PayrollFormula::query()->update(['params' => json_encode([])]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $restored = PayrollInputs::restore(PayrollInputs::json($payload['inputs']));
    $replay = PayrollData::calculateInputs($restored, strict: true);
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    expect(array_map(fn (PayslipDraft $draft) => $draft->toArray(), $replay['drafts']))->toEqual($payload['results'])
        ->and($restored->fingerprint())->toBe($snapshot->input_hash)
        ->and(Payslip::query()->sole()->toArray())->toBe($posted)
        ->and($snapshot->fresh()->payload)->toBe($snapshot->payload);
});

it('refuses stale reviews even when a changed input leaves the total unchanged', function (string $change) {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run, 'member' => $member] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $incentive = payrollEvidenceIncentive($member);
    $token = PayrollReviewFixture::token($actor, $run->id);
    match ($change) {
        'salary' => $employee->update(['monthly_rate' => 45000]),
        'employee-name' => $employee->update(['first_name' => 'Updated']),
        'punch' => AttendanceLog::query()->where('log_type', 'TIME_IN')->update(['logged_at' => '2026-09-01 00:01:00.123']),
        'shift' => Shift::query()->update(['grace_minutes' => 6]),
        'leave' => LeaveTravelRequest::query()->update(['is_paid' => false]),
        'holiday' => Holiday::query()->update(['percent' => 150]),
        'formula' => PayrollFormula::query()->update(['expression' => 'Changed display only']),
        'allowance' => $member->update(['allowance' => 0]),
        'deduction' => $member->update(['deduction_sss' => 0]),
        // An overtime decision on the DTR changes what the payroll pays.
        'overtime-approval' => payrollEvidenceOvertimeDecision($employee->id, '2026-09-04', 30),
        // An employee's tax details are calculation inputs.
        'tax-details' => (new PayrollEmployeeTaxDetail)->forceFill(['employee_id' => $employee->id, 'province' => 'AB', 'additional_tax' => 10, 'deduct_pension' => true, 'deduct_insurance' => true])->save(),
        // Rate Per Day prices every day once entered.
        'day-rate' => $employee->update(['daily_rate' => 1363.64]),
        'fixed-share' => $employee->update(['fixed_sss_ee' => 250.05]),
        'mode' => $employee->update(['sss_mode' => 'MONTHLY']),
        'annualize' => $employee->update(['annualize_tax' => true]),
        'tax-override' => $member->update(['deduction_withholding_tax' => 10]),
        'name' => $run->update(['name' => 'Changed draft']),
        'period' => $run->update(['period_end' => '2026-09-14']),
        // Incentives: the amount, the type as the catalog now describes it,
        // and the exemption another payroll has since spent.
        'incentive' => PayrollRunMemberIncentive::query()->update(['amount' => 2600]),
        'incentive-flag' => $incentive->update(['is_taxable' => true]),
        'incentive-name' => $incentive->update(['name' => 'Year-end Bonus']),
        'ledger' => (new Payslip)->forceFill(['employee_id' => $employee->id, 'period_start' => '2026-06-01', 'period_end' => '2026-06-15',
            'gross_pay' => 1000, 'total_deductions' => 0, 'net_pay' => 1000, 'exempt_benefits' => 100])->save(),
        default => throw new LogicException('Unknown payroll input change'),
    };
    expect(fn () => app(FinalizePayroll::class)->handle($actor, $run->id, $token))->toThrow(PayrollReviewRequired::class);
    expect($run->fresh()->status)->toBe('DRAFT')
        ->and(PayrollFinalizationSnapshot::query()->count())->toBe(0)
        ->and(Payslip::query()->where('run_id', $run->id)->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
})->with(['salary', 'employee-name', 'punch', 'shift', 'leave', 'holiday', 'formula', 'allowance', 'deduction', 'overtime-approval', 'tax-details', 'day-rate', 'fixed-share', 'mode', 'annualize', 'tax-override', 'name', 'period', 'incentive', 'incentive-flag', 'incentive-name', 'ledger']);

it('prices and posts the payslip from the Rate Per Day and captures it in the evidence', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $employee->update(['daily_rate' => 1250]);

    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    $payload = json_decode(PayrollFinalizationSnapshot::query()->sole()->payload, true, 512, JSON_THROW_ON_ERROR);
    $slip = Payslip::query()->sole();

    // Both rates are captured, and the pay priced from the day rate is what the pension plan counts.
    expect($payload['inputs']['employees'][0]['daily_rate'])->toEqual(1250)
        ->and($payload['inputs']['employees'][0]['monthly_rate'])->toEqual(30000)
        ->and($slip->daily_rate)->toBe(1250.0)
        ->and($slip->work_days)->toBeGreaterThan(0.0)
        ->and($slip->work_days_pay)->toBe(Money::round($slip->work_days * 1250))
        ->and($slip->cpp)->toBeGreaterThan(0.0)
        ->and($slip->pensionable_earnings)->toBe($slip->gross_pay);
});

it('refuses to restore evidence written under the previous schema', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    ScheduleFixture::standard($employee);
    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    $inputs = json_decode(PayrollFinalizationSnapshot::query()->sole()->payload, true, 512, JSON_THROW_ON_ERROR)['inputs'];

    // Schema 1 had no statutory settings: restoring it would replay with
    // defaults and diverge from what was computed, so it is refused outright.
    $inputs['schema_version'] = 1;
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($inputs)))
        ->toThrow(RuntimeException::class, 'Unsupported payroll evidence schema.');

    // And a current payload missing one setting fails the exact round-trip.
    $inputs['schema_version'] = PayrollInputs::SCHEMA_VERSION;
    $withoutDayRate = $inputs;
    unset($inputs['employees'][0]['annualize_tax']);
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($inputs)))->toThrow(RuntimeException::class);

    // So does one missing the Rate Per Day (schema 5): it is never read as
    // "none entered".
    unset($withoutDayRate['employees'][0]['daily_rate']);
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($withoutDayRate)))->toThrow(RuntimeException::class);
});

it('requires a separate review action after a stale Livewire preview', function () {
    ['employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    ScheduleFixture::standard($employee);
    $screen = Livewire::test(RunWorkspace::class, ['id' => $run->id]);
    $employee->update(['monthly_rate' => 45000]);
    $run->update(['name' => 'Another manager saved this name']);
    $screen->call('finalize')->assertSet('reviewRequired', true)
        ->assertSet('name', 'Another manager saved this name')->assertSee('Review Updated Payroll');
    $screen->call('finalize')->assertSet('reviewRequired', true);
    expect($run->fresh()->status)->toBe('DRAFT');
    $screen->call('reviewChanges')->assertSet('reviewRequired', false);
    expect($run->fresh()->status)->toBe('DRAFT');
    $screen->call('finalize');
    expect($run->fresh()->status)->toBe('FINALIZED')->and(PayrollFinalizationSnapshot::query()->count())->toBe(1);
});

it('rejects missing forged and other-actor receipts and rechecks revoked access', function (string $kind) {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run, 'role' => $role, 'user' => $user] = PayrollEvidenceFixture::make();
    ScheduleFixture::standard($employee);
    $token = PayrollReviewFixture::token($actor, $run->id);
    if ($kind === 'other-actor') {
        $other = User::factory()->create(['role_id' => $role->id]);
        $actor = Access::forUser($other);
    } elseif ($kind === 'revoked') {
        RoleModuleGrant::query()->where('role_id', $role->id)->delete();
        $actor = Access::forUser($user->fresh());
    } elseif ($kind === 'missing') {
        $token = null;
    } else {
        $token = str_repeat('0', 64);
    }
    expect(fn () => app(FinalizePayroll::class)->handle($actor, $run->id, $token))->toThrow(RuntimeException::class);
    expect($run->fresh()->status)->toBe('DRAFT')->and(PayrollFinalizationSnapshot::query()->count())->toBe(0);
})->with(['missing', 'forged', 'other-actor', 'revoked']);

it('rolls back evidence and posting when an insert is vetoed', function (string $model) {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    ScheduleFixture::standard($employee);
    $model::creating(static fn (): bool => false);
    expect(fn () => app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id)))
        ->toThrow(RuntimeException::class);
    expect($run->fresh()->status)->toBe('DRAFT')
        ->and(PayrollFinalizationSnapshot::query()->count())->toBe(0)
        ->and(Payslip::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
})->with([PayrollFinalizationSnapshot::class, Payslip::class]);

it('refuses model edits deletion and destructive rollback of posted evidence', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    ScheduleFixture::standard($employee);
    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    $snapshot = PayrollFinalizationSnapshot::query()->sole();
    expect(fn () => $snapshot->forceFill(['payload' => '{}'])->save())->toThrow(RuntimeException::class)
        ->and(fn () => $snapshot->delete())->toThrow(RuntimeException::class);
    $migration = require database_path('migrations/2026_09_17_0002_create_payroll_finalization_snapshots_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(PayrollFinalizationSnapshot::query()->count())->toBe(1);
});

it('rejects corrupt unknown and altered evidence without silently coercing its values', function (string $change) {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    $type = PayrollIncentiveType::create(['code' => '1', 'name' => 'Christmas Bonus', 'is_taxable' => false]);
    $inputs = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor, draftIncentives: [$employee->id => [$type->id => 500]])['inputs'];
    $data = $inputs->toArray();
    // The untouched capture restores (the incentive lines and ledger included).
    expect(PayrollInputs::restore(PayrollInputs::json($data))->fingerprint())->toBe($inputs->fingerprint());
    if ($change === 'schema') {
        $data['schema_version'] = 99;
    } elseif ($change === 'flag') {
        $data['employees'][0]['is_active'] = 'true';
    } elseif ($change === 'extra') {
        $data['unexpected'] = 'discard me';
    } elseif ($change === 'incentive-amount') {
        $data['incentives'][$employee->id][0]['amount'] = 0;
    } elseif ($change === 'incentive-stranger') {
        $data['incentives']['someone-else'] = $data['incentives'][$employee->id];
    } elseif ($change === 'incentive-flag') {
        $data['incentives'][$employee->id][0]['is_taxable'] = 0;
    } elseif ($change === 'ledger') {
        $data['exempt_benefits_ytd'][$employee->id] = -1;
    } else {
        $data['calculator_version'] = 'payroll-v1:obsolete';
    }
    expect(fn () => PayrollData::calculateInputs(PayrollInputs::restore(PayrollInputs::json($data))))->toThrow(RuntimeException::class);
})->with(['schema', 'flag', 'extra', 'version', 'incentive-amount', 'incentive-stranger', 'incentive-flag', 'ledger']);

it('preserves absence zero false and list order in input fingerprints', function () {
    expect(PayrollInputs::json(['b' => 1, 'a' => 0]))->toBe(PayrollInputs::json(['a' => 0, 'b' => 1]));
    expect(PayrollInputs::json(['money' => 0.0]))->toBe(PayrollInputs::json(['money' => 0]));
    expect(array_unique(array_map(fn ($value) => PayrollInputs::json(['value' => $value]), [null, 0, false, [], [1, 2], [2, 1]])))->toHaveCount(6);
    expect(fn () => PayrollInputs::restore('{'))->toThrow(JsonException::class);
});
