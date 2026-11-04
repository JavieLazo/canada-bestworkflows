<?php

declare(strict_types=1);

use App\Domain\Enums\PayrollRunKind;
use App\Domain\Payroll\PayslipDraft;
use App\Livewire\Records\ReferenceListPage;
use App\Models\AuditLog;
use App\Models\PayrollDeduction;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\PayrollRunMemberDeduction;
use App\Models\Payslip;
use App\Models\PayslipDeduction;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Catalogs\Deductions;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\Commands\SavePayroll;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollDraft;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\PayrollReview;
use App\Modules\Payroll\Application\PayrollReviewRequired;
use App\Modules\Payroll\Application\Queries\PayrollRecords;
use App\Support\Access;
use App\Support\Money;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PayrollEvidenceFixture;

/**
 * Records Management's named deductions are payroll charges: a cash advance
 * or loan reduces net pay without changing statutory tax or the generic Other
 * line, survives a draft save, and is copied into immutable posted evidence.
 */
function pcdType(string $code, string $name): PayrollDeduction
{
    return PayrollDeduction::create(['code' => $code, 'name' => $name]);
}

/** A row payroll computes itself: the seeded SSS row the migration put in place. */
function pcdAutomatic(string $id = 'ded-1'): PayrollDeduction
{
    return PayrollDeduction::query()->findOrFail($id);
}

/** @param list<string> $modules */
function pcdActor(array $modules, bool $allData = true): Access
{
    $role = Role::create(['name' => 'Custom deductions '.Str::random(6), 'all_data' => $allData, 'is_system' => false]);
    foreach ($modules as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }
    $user = User::factory()->create(['role_id' => $role->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

/** Issue a server review of exactly the saved draft, including named lines. */
function pcdReceipt(Access $actor, PayrollRun $run): string
{
    $saved = PayrollRun::query()->with('members.incentives', 'members.deductions')->findOrFail($run->id);
    $overrides = PayrollData::overridesFromMembers($saved->members);
    $calculation = PayrollData::calculateDrafts(
        PayrollData::memberIds($saved->members),
        $saved->period_start->format('Y-m-d'),
        $saved->period_end->format('Y-m-d'),
        $overrides['deductions'], $overrides['allowances'], $overrides['earnings'],
        actor: $actor, draftIncentives: $overrides['incentives'],
        draftCustomDeductions: $overrides['customDeductions'],
    );
    $inputs = $calculation['inputs'];
    if ($inputs === null) {
        throw new LogicException('The saved custom-deduction run has no review inputs.');
    }

    return PayrollReview::issue($actor, $saved->id, $saved->name, $inputs);
}

it('subtracts cash advances and loans from net without changing tax or the generic Other line', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    $cash = pcdType('6', 'Cash Advance');
    $loan = pcdType('7', 'Loan');
    $id = $employee->id;
    $deductions = [$id => ['other_deductions' => 45]];

    $base = PayrollData::calculateDrafts([$id], '2026-09-01', '2026-09-15',
        draftDeductions: $deductions, actor: $actor)['drafts'][0];
    $calculation = PayrollData::calculateDrafts([$id], '2026-09-01', '2026-09-15',
        draftDeductions: $deductions, actor: $actor,
        draftCustomDeductions: [$id => [$cash->id => 1200.25, $loan->id => 350.75]]);
    $with = $calculation['drafts'][0];

    expect($calculation['customDeductionLines'][$id])->toBe([
        ['type_id' => $cash->id, 'code' => '6', 'name' => 'Cash Advance', 'amount' => 1200.25, 'employer_amount' => null, 'automatic' => false, 'recurring_line_id' => null],
        ['type_id' => $loan->id, 'code' => '7', 'name' => 'Loan', 'amount' => 350.75, 'employer_amount' => null, 'automatic' => false, 'recurring_line_id' => null],
    ])
        ->and($with->custom_deductions)->toBe(1551.0)
        ->and($with->other_deductions)->toBe(45.0)
        ->and($with->gross_pay)->toBe($base->gross_pay)
        ->and($with->sss)->toBe($base->sss)
        ->and($with->philhealth)->toBe($base->philhealth)
        ->and($with->pagibig)->toBe($base->pagibig)
        ->and($with->taxable_compensation)->toBe($base->taxable_compensation)
        ->and($with->withholding_tax)->toBe($base->withholding_tax)
        ->and($with->total_deductions)->toBe(Money::round($base->total_deductions + 1551))
        ->and($with->net_pay)->toBe(Money::round($base->net_pay - 1551));
});

it('saves and replaces named lines with members inside the audited draft', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    $cash = pcdType('6', 'Cash Advance');
    $loan = pcdType('7', 'Loan');

    app(SavePayroll::class)->handle($actor, new PayrollDraft($run->id, 'With deductions',
        '2026-09-01', '2026-09-15', [$employee->id], [], [], [],
        customDeductions: [$employee->id => [$cash->id => '1200.25', $loan->id => 350.75]]));

    $lines = PayrollRunMemberDeduction::query()->with('member')->orderBy('deduction_id')->get()
        ->map(static fn (PayrollRunMemberDeduction $line): array => [$line->member?->employee_id, $line->deduction_id, $line->amount])->all();
    expect($lines)->toBe([[$employee->id, $cash->id, 1200.25], [$employee->id, $loan->id, 350.75]])
        ->and(AuditLog::query()->where('action', 'PAYROLL_RUN_UPDATED')->sole()->details)
        ->toContain('2 custom deduction lines')
        ->and(app(PayrollRecords::class)->find($actor, $run->id, true)?->members->first()?->deductions)
        ->toBe([$cash->id => 1200.25, $loan->id => 350.75]);

    app(SavePayroll::class)->handle($actor, new PayrollDraft($run->id, 'Revised deductions',
        '2026-09-01', '2026-09-15', [$employee->id], [], [], [],
        customDeductions: [$employee->id => [$loan->id => 50]]));
    expect(PayrollRunMemberDeduction::query()->count())->toBe(1)
        ->and(PayrollRunMemberDeduction::query()->sole()->deduction_id)->toBe($loan->id)
        ->and(PayrollRunMemberDeduction::query()->sole()->amount)->toBe(50.0)
        ->and(AuditLog::query()->where('action', 'PAYROLL_RUN_UPDATED')->latest('id')->first()?->details)
        ->toContain('1 custom deduction line');
});

it('rejects invalid or computed catalog ids directly in the save command without changing its draft', function (string $id, mixed $amount, string $message) {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run, 'member' => $member] = PayrollEvidenceFixture::make();
    if ($id === 'ded-1') {
        expect(pcdAutomatic()->calculation)->toBe('SSS'); // the engine's own SSS line
    }
    $before = $run->name;
    expect(fn () => app(SavePayroll::class)->handle($actor, new PayrollDraft($run->id, 'Should roll back',
        '2026-09-01', '2026-09-15', [$employee->id], [], [], [],
        customDeductions: [$employee->id => [$id => $amount]])))
        ->toThrow(RuntimeException::class, $message);
    expect($run->fresh()?->name)->toBe($before)
        ->and(PayrollRunMember::query()->sole()->id)->toBe($member->id)
        ->and(PayrollRunMemberDeduction::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'missing type' => ['removed-type', 100, 'A deduction type on this payroll no longer exists.'],
    'computed statutory id' => ['ded-1', 100, 'Computed payroll deductions cannot be added twice.'],
    'zero amount' => ['removed-type', 0, 'Every custom deduction amount must be more than zero.'],
    'negative amount' => ['removed-type', -5, 'Every custom deduction amount must be more than zero.'],
    'not a number' => ['removed-type', 'lots', 'Every custom deduction amount must be more than zero.'],
]);

it('refuses a custom-deduction draft write by an actor without organization-wide Payroll access', function () {
    ['employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    $cash = pcdType('6', 'Cash Advance');
    $actor = pcdActor(['PAYROLL'], false);

    expect(fn () => app(SavePayroll::class)->handle($actor, new PayrollDraft($run->id, 'Unauthorized',
        '2026-09-01', '2026-09-15', [$employee->id], [], [], [],
        customDeductions: [$employee->id => [$cash->id => 100]])))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect($run->fresh()?->name)->toBe('Evidence payroll')
        ->and(PayrollRunMemberDeduction::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('rolls back named lines and the run update if required audit storage fails', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run, 'member' => $member] = PayrollEvidenceFixture::make();
    $cash = pcdType('6', 'Cash Advance');
    AuditLog::creating(static function (): void {
        throw new RuntimeException('Synthetic audit failure');
    });

    expect(fn () => app(SavePayroll::class)->handle($actor, new PayrollDraft($run->id, 'Rolled back',
        '2026-09-01', '2026-09-15', [$employee->id], [], [], [],
        customDeductions: [$employee->id => [$cash->id => 100]])))
        ->toThrow(RuntimeException::class, 'audit record could not be recorded');
    expect($run->fresh()?->name)->toBe('Evidence payroll')
        ->and(PayrollRunMember::query()->sole()->id)->toBe($member->id)
        ->and(PayrollRunMemberDeduction::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('posts copied deduction names and replays the same payslip after the catalog changes', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run, 'member' => $member] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $cash = pcdType('6', 'Cash Advance');
    $loan = pcdType('7', 'Loan');
    $member->update(['deduction_other' => 40]);
    PayrollRunMemberDeduction::create(['member_id' => $member->id, 'deduction_id' => $cash->id, 'amount' => 1000.25]);
    PayrollRunMemberDeduction::create(['member_id' => $member->id, 'deduction_id' => $loan->id, 'amount' => 250]);

    $receipt = pcdReceipt($actor, $run);
    expect(app(FinalizePayroll::class)->handle($actor, $run->id, $receipt))->toBe(1);

    $posted = Payslip::query()->where('run_id', $run->id)->sole();
    $lines = PayslipDeduction::query()->where('payslip_id', $posted->id)->orderBy('position')->get()
        ->map(static fn (PayslipDeduction $line): array => [$line->position, $line->deduction_code, $line->name, $line->amount])->all();
    $payload = json_decode(PayrollFinalizationSnapshot::query()->where('run_id', $run->id)->sole()->payload, true, 512, JSON_THROW_ON_ERROR);
    $restored = PayrollInputs::restore(PayrollInputs::json($payload['inputs']));
    $replay = PayrollData::calculateInputs($restored, strict: true);

    expect($lines)->toBe([[0, '6', 'Cash Advance', 1000.25], [1, '7', 'Loan', 250.0]])
        ->and($posted->custom_deductions)->toBe(1250.25)
        ->and($posted->other_deductions)->toBe(40.0)
        ->and($posted->net_pay)->toBe(Money::round($posted->gross_pay - $posted->total_deductions))
        ->and($payload['inputs']['custom_deductions'][$employee->id][0]['name'])->toBe('Cash Advance')
        ->and($payload['custom_deductions'][$employee->id][1]['name'])->toBe('Loan')
        ->and(array_map(static fn (PayslipDraft $draft): array => $draft->toArray(), $replay['drafts']))->toEqual($payload['results'])
        ->and($replay['customDeductionLines'])->toEqual($payload['custom_deductions']);

    $cash->update(['name' => 'Renamed Advance']);
    $loan->update(['name' => 'Renamed Loan']);
    $records = pcdActor(['PAYROLL']);
    expect(PayslipDeduction::query()->where('payslip_id', $posted->id)->orderBy('position')->pluck('name')->all())
        ->toBe(['Cash Advance', 'Loan'])
        ->and(PayrollInputs::restore(PayrollInputs::json($payload['inputs']))->fingerprint())->toBe($restored->fingerprint())
        ->and($posted->fresh()?->custom_deductions)->toBe(1250.25)
        ->and(app(PayrollRecords::class)->deductionUsage($records, [$cash->id]))->toBe([$cash->id => ['drafts' => 0, 'finalized' => 1]])
        ->and(fn () => app(Deductions::class)->delete($records, $cash->id))
        ->toThrow(RuntimeException::class, 'on a finalized payroll');
});

it('requires a fresh review when a draft deduction label changes', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run, 'member' => $member] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $cash = pcdType('6', 'Cash Advance');
    PayrollRunMemberDeduction::create(['member_id' => $member->id, 'deduction_id' => $cash->id, 'amount' => 100]);
    $oldReceipt = pcdReceipt($actor, $run);
    $cash->update(['name' => 'Advance Repayment']);

    expect(fn () => app(FinalizePayroll::class)->handle($actor, $run->id, $oldReceipt))
        ->toThrow(PayrollReviewRequired::class);
    expect($run->fresh()?->status)->toBe('DRAFT')
        ->and(Payslip::query()->count())->toBe(0)
        ->and(PayrollFinalizationSnapshot::query()->count())->toBe(0);

    expect(app(FinalizePayroll::class)->handle($actor, $run->id, pcdReceipt($actor, $run)))->toBe(1);
    expect(PayslipDeduction::query()->sole()->name)->toBe('Advance Repayment');
});

it('deletes a typed row off drafts with a required audit, and never off a finalized payroll', function () {
    ['employee' => $employee, 'run' => $run, 'member' => $member] = PayrollEvidenceFixture::make();
    $cash = pcdType('6', 'Cash Advance');
    PayrollRunMemberDeduction::create(['member_id' => $member->id, 'deduction_id' => $cash->id, 'amount' => 100]);
    // Without all data nobody manages it, nor sees what charges it.
    $reader = pcdActor(['PAYROLL', 'RECORDS'], allData: false);
    expect(app(PayrollRecords::class)->deductionUsage($reader, [$cash->id]))->toBe([])
        ->and(fn () => app(Deductions::class)->delete($reader, $cash->id))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect(PayrollRunMemberDeduction::query()->count())->toBe(1);

    // Only an all-data PAYROLL holder manages the Payroll Settings
    // (PayrollSettingsAccess); Records all-data no longer does.
    expect(app(PayrollRecords::class)->deductionUsage(pcdActor(['RECORDS']), [$cash->id]))->toBe([]);

    // On a DRAFT the row only warns; deleting it takes the typed line off
    // the draft in the same transaction and audits that draft like a save.
    $records = pcdActor(['PAYROLL']);
    expect(app(PayrollRecords::class)->deductionUsage($records, [$cash->id]))->toBe([$cash->id => ['drafts' => 1, 'finalized' => 0]]);
    $page = Livewire::test(ReferenceListPage::class, ['slug' => 'payroll-deductions']);
    expect($page->html())->toContain('Delete · on 1 draft')->not->toContain('in use by');
    $page->call('confirmDelete', $cash->id);
    expect($page->html())->toContain('amounts typed under it on 1 draft');
    expect(app(Deductions::class)->delete($records, $cash->id))->toBe('Cash Advance deleted.')
        ->and(PayrollDeduction::query()->whereKey($cash->id)->exists())->toBeFalse()
        ->and(PayrollRunMemberDeduction::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'REFERENCE_DELETED')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'PAYROLL_RUN_UPDATED')->where('entity_id', $run->id)->value('details'))
        ->toContain('1 Cash Advance line removed because the deduction was deleted');

    // On a FINALIZED payroll the row is history: the delete is blocked.
    $loan = pcdType('7', 'SSS Loan');
    PayrollRunMemberDeduction::create(['member_id' => $member->id, 'deduction_id' => $loan->id, 'amount' => 50]);
    PayrollRun::query()->whereKey($run->id)->update(['status' => 'FINALIZED']);
    expect(app(PayrollRecords::class)->deductionUsage($records, [$loan->id]))->toBe([$loan->id => ['drafts' => 0, 'finalized' => 1]])
        ->and(Livewire::test(ReferenceListPage::class, ['slug' => 'payroll-deductions'])->html())
        ->toContain('Delete · on a finalized payroll')
        ->and(fn () => app(Deductions::class)->delete($records, $loan->id))
        ->toThrow(RuntimeException::class, 'on a finalized payroll');
    expect(PayrollDeduction::query()->whereKey($loan->id)->exists())->toBeTrue()
        ->and(PayrollRunMemberDeduction::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'REFERENCE_DELETED')->count())->toBe(1);
});

it('rolls the catalog delete back when the draft audit cannot be written', function () {
    ['member' => $member] = PayrollEvidenceFixture::make();
    $cash = pcdType('6', 'Cash Advance');
    PayrollRunMemberDeduction::create(['member_id' => $member->id, 'deduction_id' => $cash->id, 'amount' => 100]);
    $records = pcdActor(['PAYROLL']);
    AuditLog::creating(static function (): void {
        throw new RuntimeException('Synthetic audit failure');
    });

    expect(fn () => app(Deductions::class)->delete($records, $cash->id))
        ->toThrow(RuntimeException::class, 'audit record could not be recorded');
    expect(PayrollDeduction::query()->whereKey($cash->id)->exists())->toBeTrue()
        ->and(PayrollRunMemberDeduction::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('refuses custom deductions on a 13th-month run', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    $cash = pcdType('6', 'Cash Advance');

    expect(fn () => app(SavePayroll::class)->handle($actor, new PayrollDraft(null, 'Yearly run',
        '2026-01-01', '2026-12-31', [$employee->id], [], [], [],
        kind: PayrollRunKind::THIRTEENTH_MONTH,
        customDeductions: [$employee->id => [$cash->id => 100]])))
        ->toThrow(RuntimeException::class, 'Custom deductions belong on a regular payroll');
    expect(PayrollRun::query()->where('name', 'Yearly run')->exists())->toBeFalse()
        ->and(PayrollRunMemberDeduction::query()->count())->toBe(0);
});
