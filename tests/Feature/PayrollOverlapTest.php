<?php

declare(strict_types=1);

use App\Domain\Enums\PayrollRunKind;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\Commands\SavePayroll;
use App\Modules\Payroll\Application\PayrollDraft;
use Tests\Support\PayrollEvidenceFixture;
use Tests\Support\PayrollReviewFixture;

/**
 * One employee, one payroll per day: a regular run whose dates merely
 * OVERLAP another regular run's (1–15 beside 10–25, or beside 1–30) would
 * pay the shared days twice, so it is refused like a run with the same
 * dates. Back-to-back cut-offs, runs without a shared employee and the 13th
 * month run (which pays no attendance) are not conflicts.
 *
 * The fixture's PR-EVID covers 1 to 15 September 2026.
 */
function overlapDraft(string $start, string $end, string ...$members): PayrollDraft
{
    return new PayrollDraft(null, "Run {$start} to {$end}", $start, $end, array_values($members), [], [], []);
}

function overlapRun(string $code, string $start, string $end, string $employeeId, string $kind = PayrollRunKind::REGULAR, string $status = 'DRAFT'): PayrollRun
{
    $run = PayrollRun::create(['code' => $code, 'name' => "Payroll {$code}", 'status' => $status,
        'period_start' => $start, 'period_end' => $end, 'kind' => $kind]);
    PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employeeId, 'position' => 0,
        'period_start' => $start, 'period_end' => $end]);

    return $run;
}

it('refuses a draft whose dates overlap another run of a shared employee', function (string $start, string $end) {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();

    expect(fn () => app(SavePayroll::class)->handle($actor, overlapDraft($start, $end, $employee->id)))
        ->toThrow(RuntimeException::class, 'Synthetic Evidence is already on PR-EVID (01 Sep 2026 to 15 Sep 2026), which overlaps these dates.');
    expect(PayrollRun::query()->count())->toBe(1);
})->with([
    'straddling its end' => ['2026-09-10', '2026-09-25'],
    'containing it' => ['2026-09-01', '2026-09-30'],
    'inside it' => ['2026-09-05', '2026-09-10'],
    'sharing one day' => ['2026-08-16', '2026-09-01'],
]);

it('refuses it against a finalized run too', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    $run->forceFill(['status' => 'FINALIZED'])->save();

    expect(fn () => app(SavePayroll::class)->handle($actor, overlapDraft('2026-09-10', '2026-09-25', $employee->id)))
        ->toThrow(RuntimeException::class, 'which overlaps these dates.');
});

it('keeps the cut-off sentence for a run with the same dates', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();

    expect(fn () => app(SavePayroll::class)->handle($actor, overlapDraft('2026-09-01', '2026-09-15', $employee->id)))
        ->toThrow(RuntimeException::class, 'Synthetic Evidence is already included in PR-EVID for this cut-off.');
});

it('allows back-to-back cut-offs and overlapping runs that share nobody', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    $other = EmployeeProfile::create(['employee_code' => 'OVL-002', 'first_name' => 'Other', 'last_name' => 'Person',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'monthly_rate' => 30000, 'is_active' => true]);

    $next = app(SavePayroll::class)->handle($actor, overlapDraft('2026-09-16', '2026-09-30', $employee->id));
    $apart = app(SavePayroll::class)->handle($actor, overlapDraft('2026-09-10', '2026-09-25', $other->id));

    expect($next['created'])->toBeTrue()
        ->and($apart['created'])->toBeTrue()
        ->and(PayrollRun::query()->count())->toBe(3);
});

it('never compares a regular run with the 13th month run', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    overlapRun('PR-13TH', '2026-01-01', '2026-12-31', $employee->id, PayrollRunKind::THIRTEENTH_MONTH);

    $saved = app(SavePayroll::class)->handle($actor, overlapDraft('2026-09-16', '2026-09-30', $employee->id));

    expect($saved['created'])->toBeTrue();
});

it('lets a run be saved again over its own dates', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();

    $saved = app(SavePayroll::class)->handle($actor, new PayrollDraft($run->id, 'Evidence payroll, renamed',
        '2026-09-01', '2026-09-14', [$employee->id], [], [], []));

    expect($saved)->toBe(['id' => $run->id, 'created' => false])
        ->and($run->refresh()->period_end->format('Y-m-d'))->toBe('2026-09-14');
});

it('refuses to finalize a draft that overlaps a run saved before the rule', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    // Written directly, as an older installation could hold it.
    overlapRun('PR-OLD', '2026-09-10', '2026-09-25', $employee->id, status: 'FINALIZED');

    expect(fn () => app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id)))
        ->toThrow(RuntimeException::class, 'Synthetic Evidence is already on PR-OLD (10 Sep 2026 to 25 Sep 2026), which overlaps these dates.');
    expect($run->refresh()->status)->toBe('DRAFT')
        ->and(Payslip::query()->count())->toBe(0);
});
