<?php

declare(strict_types=1);

use App\Models\AttendanceLog;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\ApproveGovDeductionPolicy;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\Commands\SaveGovDeductionPolicy;
use App\Modules\Payroll\Application\ContributionMonthToDate;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Support\Access;
use Illuminate\Support\Str;
use Tests\Support\PayrollEvidenceFixture;
use Tests\Support\PayrollReviewFixture;

/**
 * DELIBERATE DIVERGENCE 19 through the application: a month's posted
 * shares are captured in the evidence (never read from the live table on
 * a replay, never from a draft), the HALF_THEN_REST schedule takes the
 * rest on the month-ending payroll, a payslip records its contribution
 * month and base, and the Contributions & tax version is picked for the
 * run's CONTRIBUTION MONTH.
 */

/** An unlinked all-data PAYROLL officer, signed in. */
function cspActor(): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Split '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->getKey(), 'module' => 'PAYROLL'])->save();
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

it('takes the rest of the month from what the first cut-off posted, captured in the evidence and recorded on the payslip', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    foreach (['TIME_IN' => '2026-09-16 00:00:00.000', 'TIME_OUT' => '2026-09-16 09:00:00.000'] as $type => $at) {
        AttendanceLog::create(['employee_id' => $employee->id, 'source' => 'CORRECTION', 'log_type' => $type, 'logged_at' => $at, 'latitude' => 14.5, 'longitude' => 121.0]);
    }
    $preparer = cspActor();
    $id = app(SaveGovDeductionPolicy::class)->handle($preparer, $employee->id, '2026-09-01', 'Half then the rest', [...GovDeductionSettings::STANDARD, 'sss_mode' => GovDeductionSettings::MODE_HALF_THEN_REST]);
    app(ApproveGovDeductionPolicy::class)->handle(cspActor(), $id);
    test()->actingAs(User::query()->findOrFail((int) $actor->userId));
    app()->forgetInstance(Access::class);
    $actor = app(Access::class);

    // The first cut-off: half, as the reference's half-each schedule gives; nothing posted yet.
    $first = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    $half = $first['drafts'][0]->sss;
    expect($half)->toBeGreaterThan(0.0)
        ->and($first['inputs']->contributionMonth)->toBeNull()
        ->and($first['inputs']->contributionMonth())->toBe('2026-09')
        ->and($first['inputs']->contributionPosted)->toBe([])
        ->and($first['drafts'][0]->contribution_base)->toBe(30000.0);
    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    $posted = Payslip::query()->where('employee_id', $employee->id)->sole();
    expect($posted->sss)->toBe($half)
        ->and($posted->contribution_month)->toBe('2026-09')
        ->and($posted->contribution_base)->toBe(30000.0);

    // A draft of the second cut-off never counts; the posted first does.
    $draft = new PayrollRun;
    $draft->forceFill(['code' => 'PR-SPL', 'name' => 'Second half', 'status' => 'DRAFT', 'period_start' => '2026-09-16', 'period_end' => '2026-09-30'])->save();
    PayrollRunMember::create(['run_id' => $draft->id, 'employee_id' => $employee->id, 'position' => 0, 'period_start' => '2026-09-16', 'period_end' => '2026-09-30']);
    expect(ContributionMonthToDate::posted([$employee->id], '2026-09')[$employee->id]['sss'])->toBe($half)
        ->and(ContributionMonthToDate::posted([$employee->id], '2026-10'))->toBe([]);

    $second = PayrollData::calculateDrafts([$employee->id], '2026-09-16', '2026-09-30', actor: $actor);
    expect($second['inputs']->contributionPosted[$employee->id]['sss'])->toBe($half)
        ->and($second['inputs']->contributionPosted[$employee->id]['pagibig'])->toBe($posted->pagibig)
        // The rest of the month: the whole month's figure less the half posted — the two halves make the month.
        ->and($second['drafts'][0]->sss + $half)->toBe($half * 2)
        ->and($second['inputs']->toArray()['schema_version'])->toBe(PayrollInputs::SCHEMA_VERSION);

    // The evidence replays the captured figure, whatever posts later.
    $restored = PayrollInputs::restore(PayrollInputs::json($second['inputs']->toArray()));
    expect($restored->fingerprint())->toBe($second['inputs']->fingerprint());
    Payslip::query()->update(['sss' => 1.0]);
    expect(PayrollData::calculateInputs($restored, strict: true)['drafts'][0]->sss)->toBe($second['drafts'][0]->sss);
    $older = $second['inputs']->toArray();
    $older['schema_version'] = 19;
    $older = PayrollEvidenceFixture::asSchema($older, 19);
    expect(PayrollInputs::restore(PayrollInputs::json($older))->contributionPosted)->toBe([]);
    $broken = $second['inputs']->toArray();
    $broken['contribution_posted'][$employee->id]['sss'] = -1;
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($broken)))->toThrow(RuntimeException::class, 'Invalid payroll contribution evidence.');
    $broken = $second['inputs']->toArray();
    $broken['contribution_month'] = '2026-13';
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($broken)))->toThrow(RuntimeException::class, 'Invalid payroll contribution evidence.');
});

it('picks the Contributions & tax version for the run\'s contribution month', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $preparer = cspActor();
    $id = app(SaveGovDeductionPolicy::class)->handle($preparer, $employee->id, '2026-10-01', 'Exempt from October', [...GovDeductionSettings::STANDARD, 'allow_sss' => false]);
    app(ApproveGovDeductionPolicy::class)->handle(cspActor(), $id);
    test()->actingAs(User::query()->findOrFail((int) $actor->userId));
    app()->forgetInstance(Access::class);
    $actor = app(Access::class);

    $september = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    $october = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor, contributionMonth: '2026-10');
    expect($september['inputs']->govPolicies)->toBe([])
        ->and($september['drafts'][0]->sss)->toBeGreaterThan(0.0)
        ->and($october['inputs']->contributionMonth)->toBe('2026-10')
        ->and($october['inputs']->govPolicies[$employee->id]['version_id'] ?? null)->toBe($id)
        ->and($october['drafts'][0]->sss)->toBe(0.0);
});
