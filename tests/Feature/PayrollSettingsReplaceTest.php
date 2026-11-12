<?php

declare(strict_types=1);

use App\Domain\Payroll\PayRules;
use App\Domain\Payroll\PayslipDraft;
use App\Domain\Payroll\StatutorySchedule;
use App\Models\AuditLog;
use App\Models\EmployeeSalaryRate;
use App\Models\PayrollBenefitCeiling;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollGovDeductionPolicy;
use App\Models\PayrollPayRule;
use App\Models\PayrollStatutoryTable;
use App\Models\Payslip;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\Commands\ReplaceBenefitCeiling;
use App\Modules\Payroll\Application\Commands\ReplaceGovDeductionPolicy;
use App\Modules\Payroll\Application\Commands\ReplacePayRules;
use App\Modules\Payroll\Application\Commands\ReplaceStatutoryTable;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Modules\Workforce\Application\Commands\ReplaceSalary;
use Tests\Support\PayrollEvidenceFixture;
use Tests\Support\PayrollReviewFixture;

/**
 * Payroll Settings hold ONE set of rules each, replaced in place: a save
 * takes effect at once for every draft payroll, whatever its period, with no
 * date to choose and no list of versions — and a FINALIZED payroll is never
 * touched, because it reads the evidence captured when it was posted. Each
 * replacement is one row in the audit trail.
 */
it('replaces a contribution table at once for every draft, and leaves a finalized payroll as it was paid', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);

    // Finalized with the figures in use: PhilHealth at 2.5% of 30,000, halved.
    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    $posted = Payslip::query()->sole()->toArray();
    $evidence = PayrollFinalizationSnapshot::query()->sole()->toArray();
    expect($posted['philhealth'])->toBe(375.0);

    // The same dates as a draft would price them, and an EARLIER period, before the change.
    $before = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    $earlier = PayrollData::calculateDrafts([$employee->id], '2026-08-16', '2026-08-31', actor: $actor);
    expect($before['drafts'][0]->philhealth)->toBe(375.0)
        ->and($earlier['inputs']->statutoryTables[StatutorySchedule::PHILHEALTH]['figures']['ee_rate'])->toBe(0.025);

    $message = app(ReplaceStatutoryTable::class)->handle($actor, StatutorySchedule::PHILHEALTH, '', ['floor' => 10000.0, 'cap' => 100000.0, 'ee_rate' => 0.03, 'er_rate' => 0.025]);
    expect($message)->toBe('PhilHealth figures saved. Draft payrolls now use them.');

    // One row, the same one, from the date it always covered.
    $row = PayrollStatutoryTable::query()->where('kind', StatutorySchedule::PHILHEALTH)->sole();
    expect($row->effective_from->format('Y-m-d'))->toBe('2025-01-01')
        ->and($row->params['ee_rate'])->toBe(0.03);

    // Every draft reads the new figures at once — an earlier period and a later one alike — and its preview goes stale.
    $after = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    $earlierAgain = PayrollData::calculateDrafts([$employee->id], '2026-08-16', '2026-08-31', actor: $actor);
    $later = PayrollData::calculateDrafts([$employee->id], '2026-09-16', '2026-09-30', actor: $actor);
    expect($after['drafts'][0]->philhealth)->toBe(450.0)
        ->and($after['inputs']->fingerprint())->not->toBe($before['inputs']->fingerprint())
        ->and($earlierAgain['inputs']->statutoryTables[StatutorySchedule::PHILHEALTH]['figures']['ee_rate'])->toBe(0.03)
        ->and($later['inputs']->statutoryTables[StatutorySchedule::PHILHEALTH]['figures']['ee_rate'])->toBe(0.03);

    // The finalized payroll: the payslip and its evidence are byte for byte what was posted.
    expect(Payslip::query()->sole()->toArray())->toBe($posted)
        ->and(PayrollFinalizationSnapshot::query()->sole()->toArray())->toBe($evidence);

    expect(AuditLog::query()->where('action', 'STATUTORY_TABLE_UPDATED')->where('entity_id', $row->id)->count())->toBe(1);
});

it('replaces the tax-free ceiling at once, in one row, without touching what a finalized payroll captured', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    $evidence = PayrollFinalizationSnapshot::query()->sole()->toArray();

    expect(app(ReplaceBenefitCeiling::class)->handle($actor, '120,000'))->toBe('Tax-exempt ceiling set to 120,000.00.');

    $row = PayrollBenefitCeiling::query()->sole();
    $draft = PayrollData::calculateDrafts([$employee->id], '2026-08-16', '2026-08-31', actor: $actor);
    expect($row->ceiling)->toBe(120000.0)
        ->and($row->effective_from->format('Y-m-d'))->toBe('2025-01-01')
        ->and($draft['inputs']->ceiling())->toBe(120000.0)
        ->and(PayrollFinalizationSnapshot::query()->sole()->toArray())->toBe($evidence);
});

it('replaces the company pay rules, an employee\'s contributions and their salary at once, each in one row, and never moves a finalized payslip', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    $posted = Payslip::query()->sole()->toArray();
    $evidence = PayrollFinalizationSnapshot::query()->sole()->toArray();
    $draft = static fn (): PayslipDraft => PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor)['drafts'][0];
    $before = $draft();
    expect($before->sss)->toBe(750.0);

    // Pay Basis & Rates: a fixed divisor of 20 in place of the month's working days.
    $rules = PayrollPayRule::query()->where('scope', PayrollPayRule::COMPANY)->sole();
    expect(app(ReplacePayRules::class)->handle($actor, PayrollPayRule::COMPANY, null, ['daily_rate' => ['method' => PayRules::METHOD_DIVISOR, 'value' => 20.0]] + $rules->params))
        ->toBe('Pay Basis & Rates saved. Draft payrolls now use them.');
    $afterRules = $draft();
    expect(PayrollPayRule::query()->where('scope', PayrollPayRule::COMPANY)->sole()->id)->toBe($rules->id)
        ->and($afterRules->daily_rate)->toBe(1500.0)
        ->and($afterRules->daily_rate)->not->toBe($before->daily_rate);

    // Contributions & tax: this employee no longer has SSS deducted.
    app(ReplaceGovDeductionPolicy::class)->handle($actor, $employee->id, ['allow_sss' => false] + GovDeductionSettings::standard()->toArray());
    $policy = PayrollGovDeductionPolicy::query()->sole();
    expect($policy->status)->toBe(PayrollGovDeductionPolicy::APPROVED)
        ->and($policy->effective_from->format('Y-m-d'))->toBe('2025-01-01')
        ->and($draft()->sss)->toBe(0.0);

    // Salary: 30,000 becomes 33,000 for the whole draft period.
    app(ReplaceSalary::class)->handle($actor, $employee->id, '33,000', null);
    $salary = EmployeeSalaryRate::query()->where('employee_id', $employee->id)->sole();
    expect($salary->monthly_rate)->toBe(33000.0)
        ->and($salary->effective_from->format('Y-m-d'))->toBe('2024-01-01')
        ->and($employee->refresh()->monthly_rate)->toBe(33000.0)
        ->and($draft()->daily_rate)->toBe(1650.0);

    // The finalized payroll: the payslip and its evidence are byte for byte what was posted.
    expect(Payslip::query()->sole()->toArray())->toBe($posted)
        ->and(PayrollFinalizationSnapshot::query()->sole()->toArray())->toBe($evidence);

    // Every replacement is in the audit trail.
    expect(AuditLog::query()->where('action', 'PAY_RULES_SAVED')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'GOV_POLICY_SAVED')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'SALARY_CHANGE_RECORDED')->count())->toBe(1);
});
