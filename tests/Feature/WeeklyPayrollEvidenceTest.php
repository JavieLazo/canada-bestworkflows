<?php

declare(strict_types=1);

use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Payroll\PayslipDraft;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\Payslip;
use App\Models\RosterDayOverride;
use App\Models\RosterShift;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\PayrollReviewRequired;
use Illuminate\Support\Facades\DB;
use Tests\Support\PayrollEvidenceFixture;
use Tests\Support\PayrollReviewFixture;

/** A weekday roster is captured in payroll evidence and any edit invalidates an outstanding review. */
function weeklyPayrollRoster(string $employeeId): RosterShift
{
    return RosterShift::create([
        'employee_id' => $employeeId, 'weekday' => 1, 'shift_name' => 'Monday night',
        'is_rest_day' => false, 'shift_data' => (new DomainShift(
            startTime: '22:00:00', endTime: '06:00:00', workDays: [1], breakMinutes: 0,
        ))->toArray(),
    ]);
}

it('captures weekday shift facts and replays them after the live roster changes', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $roster = weeklyPayrollRoster($employee->id);
    $day = RosterDayOverride::create([
        'employee_id' => $employee->id, 'date' => '2026-09-08', 'shift_name' => 'Tuesday only',
        'is_rest_day' => false, 'shift_data' => (new DomainShift(
            startTime: '23:00:00', endTime: '07:00:00', workDays: [2],
        ))->toArray(),
    ]);
    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    $snapshot = PayrollFinalizationSnapshot::query()->sole();
    $payload = json_decode($snapshot->payload, true, flags: JSON_THROW_ON_ERROR);
    $captured = $payload['inputs']['attendance'][$employee->id]['shift']['weekday_shifts'][1];
    expect($payload['inputs']['schema_version'])->toBe(PayrollInputs::SCHEMA_VERSION)
        ->and($captured['start_time'])->toBe('22:00:00')
        ->and($captured['end_time'])->toBe('06:00:00')
        ->and($payload['inputs']['attendance'][$employee->id]['shift']['date_shifts']['2026-09-08']['start_time'])->toBe('23:00:00');

    $posted = Payslip::query()->sole()->toArray();
    $roster->update(['shift_data' => (new DomainShift(workDays: [1]))->toArray()]);
    $day->update(['shift_data' => (new DomainShift(workDays: [2]))->toArray()]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $inputs = PayrollInputs::restore(PayrollInputs::json($payload['inputs']));
    $replay = PayrollData::calculateInputs($inputs, strict: true);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toBe([])
        ->and($inputs->fingerprint())->toBe($snapshot->input_hash)
        ->and(array_map(static fn (PayslipDraft $draft): array => $draft->toArray(), $replay['drafts']))->toEqual($payload['results'])
        ->and(Payslip::query()->sole()->toArray())->toBe($posted)
        ->and($snapshot->fresh()->payload)->toBe($snapshot->payload);
});

it('requires another payroll review after a weekday shift changes', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    weeklyPayrollRoster($employee->id);
    $review = PayrollReviewFixture::token($actor, $run->id);
    RosterShift::query()->sole()->update(['shift_data' => (new DomainShift(workDays: [1]))->toArray()]);
    expect(fn () => app(FinalizePayroll::class)->handle($actor, $run->id, $review))
        ->toThrow(PayrollReviewRequired::class)
        ->and($run->fresh()->status)->toBe('DRAFT')
        ->and(PayrollFinalizationSnapshot::query()->count())->toBe(0)
        ->and(Payslip::query()->count())->toBe(0);
});

it('invalidates a cutoff review when a dated rest exception changes its months divisor', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    $review = PayrollReviewFixture::token($actor, $run->id);
    RosterDayOverride::create([
        'employee_id' => $employee->id, 'date' => '2026-09-30', 'shift_name' => 'Rest day',
        'is_rest_day' => true, 'shift_data' => DomainShift::restDay()->toArray(),
    ]);
    expect(fn () => app(FinalizePayroll::class)->handle($actor, $run->id, $review))
        ->toThrow(PayrollReviewRequired::class)
        ->and($run->fresh()->status)->toBe('DRAFT')
        ->and(PayrollFinalizationSnapshot::query()->count())->toBe(0);
});
