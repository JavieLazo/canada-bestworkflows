<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Domain\Payroll\PayRules;
use App\Livewire\Records\EmployeePaySettingsShow;
use App\Livewire\Records\PayRulesPage;
use App\Models\AuditLog;
use App\Models\PayrollDeduction;
use App\Models\PayrollPayRule;
use App\Models\PayrollRecurringLine;
use App\Models\PayrollRecurringLineEntry;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\ApproveRecurringLineEntry;
use App\Modules\Payroll\Application\Commands\DiscardRecurringLineEntry;
use App\Modules\Payroll\Application\Commands\SaveRecurringLine;
use App\Modules\Payroll\Application\Commands\SaveRecurringLineEntry;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\Queries\RecurringLineRecords;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PayrollEvidenceFixture;

/**
 * Loans (the PPMC plan's Phase 12, reduced): a loan's ledger beside the
 * payslips — an outside payment lowers what is left, a balance
 * adjustment raises or lowers it — is a DRAFT until approved (by its
 * recorder included), and only then does every draft payroll re-read what is
 * left (captured in the evidence as `paid_before`); and the Pay Basis &
 * Rates' minimum take-home pay flags a draft whose net pay falls under
 * it, cutting nothing on its own.
 */

/** @param list<string> $modules */
function lneActor(array $modules = ['PAYROLL'], bool $allData = true, ?string $employeeId = null, string $name = 'Pay Officer'): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Loan '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['name' => $name, 'role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function lneActAs(Access $actor): Access
{
    test()->actingAs(User::query()->findOrFail((int) $actor->userId));
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

/** @param array<string, mixed> $changes */
function lneLoan(Access $actor, string $employeeId, string $typeId, array $changes = []): PayrollRecurringLine
{
    app(SaveRecurringLine::class)->handle($actor, null, $employeeId, $typeId,
        [...['kind' => 'DEDUCTION', 'amount' => 500.0, 'total' => 2000.0, 'frequency' => 'EVERY', 'starts_on' => '2026-09-01', 'ends_on' => null], ...$changes], 'Loan 42');

    return PayrollRecurringLine::query()->latest('created_at')->latest('id')->firstOrFail();
}

/*
|--------------------------------------------------------------------------
| The ledger
|--------------------------------------------------------------------------
*/

it('records, approves and discards loan entries, refusing the preparer and the own account, and only an approved one changes what is left', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 02:00:00', 'UTC'));
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $loanType = PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);
    $loan = lneLoan($actor, $employee->id, $loanType->id);
    $open = lneLoan($actor, $employee->id, PayrollDeduction::create(['code' => '8', 'name' => 'Open deduction'])->id, ['total' => null]);

    $preparer = lneActor(name: 'Preparer');
    $save = app(SaveRecurringLineEntry::class);
    expect(fn () => $save->handle($preparer, $open->id, 'OUTSIDE_PAYMENT', '100', '2026-09-10', 'No total'))->toThrow(RuntimeException::class, 'total loan payable')
        ->and(fn () => $save->handle($preparer, $loan->id, 'REFUND', '100', '2026-09-10', 'Bad kind'))->toThrow(RuntimeException::class, 'payment made outside payroll')
        ->and(fn () => $save->handle($preparer, $loan->id, 'OUTSIDE_PAYMENT', '0', '2026-09-10', 'Zero'))->toThrow(RuntimeException::class, 'above zero')
        ->and(fn () => $save->handle($preparer, $loan->id, 'ADJUSTMENT', '0', '2026-09-10', 'Zero'))->toThrow(RuntimeException::class, 'more is owed')
        ->and(fn () => $save->handle($preparer, $loan->id, 'OUTSIDE_PAYMENT', '100', 'soon', 'Bad date'))->toThrow(RuntimeException::class, 'date')
        ->and(fn () => $save->handle($preparer, $loan->id, 'OUTSIDE_PAYMENT', '100', '2026-09-10', ''))->toThrow(RuntimeException::class, 'reason')
        ->and(fn () => $save->handle($preparer, (string) Str::ulid(), 'OUTSIDE_PAYMENT', '100', '2026-09-10', 'Gone'))->toThrow(RuntimeException::class, 'no longer exists');
    expect($save->handle($preparer, $loan->id, 'OUTSIDE_PAYMENT', '1,000', '2026-09-10', 'Paid at the branch', 'OR 123'))->toContain('once it is approved');
    $entry = PayrollRecurringLineEntry::query()->sole();
    expect($entry->status)->toBe('DRAFT')->and($entry->amount)->toBe(1000.0)->and($entry->reference)->toBe('OR 123')
        ->and(AuditLog::query()->where('action', AuditEvent::LOAN_ENTRY_RECORDED->value)->where('entity_id', $entry->id)->exists())->toBeTrue();

    // A draft changes nothing: the next payroll still proposes the full amount against the full balance.
    $actor = lneActAs($actor);
    $before = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    expect($before['inputs']->recurringLines[$employee->id][0]['paid_before'])->toBe(0.0)
        ->and($before['customDeductionLines'][$employee->id][0]['amount'])->toBe(500.0)
        ->and(app(RecurringLineRecords::class)->find($actor, $loan->id, '2026-09-20')->left)->toBe(2000.0);

    // A viewer cannot approve; the employee's own all-data account can — one administrator runs the whole flow.
    $viewer = lneActor(['PAYROLL'], allData: false, name: 'Viewer');
    expect(fn () => app(ApproveRecurringLineEntry::class)->handle($viewer, $entry->id))->toThrow(RuntimeException::class, 'cannot manage');
    expect($entry->refresh()->status)->toBe('DRAFT');

    $approver = lneActor(employeeId: $employee->id, name: 'Synthetic Evidence');
    expect(app(ApproveRecurringLineEntry::class)->handle($approver, $entry->id))->toContain('approved');
    expect($entry->refresh()->status)->toBe('APPROVED')->and($entry->approved_by)->toBe((int) $approver->userId)
        ->and(AuditLog::query()->where('action', AuditEvent::LOAN_ENTRY_APPROVED->value)->where('entity_id', $entry->id)->exists())->toBeTrue()
        ->and(fn () => app(ApproveRecurringLineEntry::class)->handle($approver, $entry->id))->toThrow(RuntimeException::class, 'already approved')
        ->and(fn () => app(DiscardRecurringLineEntry::class)->handle($approver, $entry->id))->toThrow(RuntimeException::class, 'not discarded');

    // Approved: 1,000 paid outside, 1,000 left; the evidence captures it and the preview stales; a replay never reads the table.
    $actor = lneActAs($actor);
    $after = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    expect($after['inputs']->recurringLines[$employee->id][0]['paid_before'])->toBe(1000.0)
        ->and($after['inputs']->fingerprint())->not->toBe($before['inputs']->fingerprint())
        ->and(app(RecurringLineRecords::class)->find($actor, $loan->id, '2026-09-20')->left)->toBe(1000.0)->and(app(RecurringLineRecords::class)->find($actor, $loan->id, '2026-09-20')->paid)->toBe(1000.0);
    // The lender says 300 more is owed: an adjustment above zero raises the balance once approved.
    $save->handle($preparer, $loan->id, 'ADJUSTMENT', '300', '2026-09-12', 'Lender statement');
    $adjustment = PayrollRecurringLineEntry::query()->where('kind', 'ADJUSTMENT')->sole();
    app(ApproveRecurringLineEntry::class)->handle(lneActor(name: 'Approver 2'), $adjustment->id);
    $actor = lneActAs($actor);
    expect(app(RecurringLineRecords::class)->find($actor, $loan->id, '2026-09-20')->left)->toBe(1300.0)
        ->and(PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor)['inputs']->recurringLines[$employee->id][0]['paid_before'])->toBe(700.0);
    $restored = PayrollInputs::restore(PayrollInputs::json($after['inputs']->toArray()));
    PayrollRecurringLineEntry::query()->delete();
    expect(PayrollData::calculateInputs($restored, strict: true)['inputs']->recurringLines[$employee->id][0]['paid_before'])->toBe(1000.0);

    // A big enough payment pays the loan off: nothing more is proposed.
    $save->handle($preparer, $loan->id, 'OUTSIDE_PAYMENT', '5,000', '2026-09-15', 'Settled in full');
    $settled = PayrollRecurringLineEntry::query()->sole();
    app(ApproveRecurringLineEntry::class)->handle(lneActor(name: 'Approver 3'), $settled->id);
    $actor = lneActAs($actor);
    expect(array_values(array_filter(PayrollData::calculateDrafts([$employee->id], '2026-09-16', '2026-09-30', actor: $actor)['customDeductionLines'][$employee->id] ?? [], static fn (array $line): bool => $line['type_id'] === $loanType->id)))->toBe([])
        ->and(app(RecurringLineRecords::class)->find($actor, $loan->id, '2026-09-20')->status)->toBe(RecurringLineRecords::PAID_OFF);

    // A draft is discarded, audited.
    $save->handle($preparer, $loan->id, 'ADJUSTMENT', '-50', '2026-09-16', 'Overstated');
    $draft = PayrollRecurringLineEntry::query()->where('status', 'DRAFT')->sole();
    expect(app(DiscardRecurringLineEntry::class)->handle($approver, $draft->id))->toContain('Draft discarded');
    expect(PayrollRecurringLineEntry::query()->whereKey($draft->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditEvent::LOAN_ENTRY_DISCARDED->value)->where('entity_id', $draft->id)->exists())->toBeTrue();
});

it('records entries from the employee pay page, in effect at once, and a viewer without all data changes nothing', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 02:00:00', 'UTC'));
    ['employee' => $employee] = PayrollEvidenceFixture::make();
    $preparer = lneActor(name: 'Preparer');
    $loan = lneLoan($preparer, $employee->id, PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan'])->id);

    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->assertSee('Record payment or adjustment')
        ->call('openEntry', $loan->id)->assertSet('entryOpen', true)->assertSet('entryLineId', $loan->id)
        ->set('entryForm.amount', '250')->set('entryForm.reason', 'Paid at the branch')
        ->call('saveEntry')->assertSet('entryOpen', false)->assertSet('entryError', null)
        // In effect at once: the balance already counts it.
        ->assertSeeHtml('data-loan-entries')->assertDontSee('Awaiting approval')->assertSee('Payment made outside payroll')
        ->assertSee('Approved by')->assertSee('1,750.00');
    $entry = PayrollRecurringLineEntry::query()->sole();
    expect($entry->status)->toBe('APPROVED')->and($entry->amount)->toBe(250.0)->and($entry->approved_by)->toBe((int) $preparer->userId);

    // A viewer changes nothing.
    lneActor(['PAYROLL', 'RECORDS'], allData: false, name: 'Viewer');
    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->call('openEntry', $loan->id)->assertSet('entryOpen', false)
        ->call('discardEntry', $entry->id);
    expect(PayrollRecurringLineEntry::query()->count())->toBe(1)->and($entry->refresh()->status)->toBe('APPROVED');

    // An adjustment takes effect on save as well.
    lneActor(name: 'Second officer');
    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->call('openEntry', $loan->id)->set('entryForm.kind', 'ADJUSTMENT')->set('entryForm.amount', '-100')->set('entryForm.reason', 'Statement')
        ->call('saveEntry')->assertSet('entryError', null)
        ->assertDontSee('Awaiting approval')->assertSee('Balance adjustment');
    expect(PayrollRecurringLineEntry::query()->where('status', 'APPROVED')->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| The minimum take-home pay
|--------------------------------------------------------------------------
*/

it('reads the minimum take-home pay leniently, saves it from the Pay Basis & Rates page and flags a draft under it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 02:00:00', 'UTC'));
    $figures = PayRules::fromFormulas()->toArray();
    expect($figures['take_home'])->toBe(['minimum' => null]);
    $older = $figures;
    unset($older['take_home']);
    expect(PayRules::fromArray($older)->minimumTakeHome)->toBeNull()
        ->and(PayRules::fromArray([...$figures, 'take_home' => ['minimum' => 3000.0]])->minimumTakeHome)->toBe(3000.0)
        ->and(PayRules::error([...$figures, 'take_home' => ['minimum' => -1]]))->toContain('Minimum take-home pay');

    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $clean = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    expect($clean['issues'])->toBe([]);

    Livewire::test(PayRulesPage::class)
        ->assertSet('formMinimumTakeHome', '')
        ->set('formMinimumTakeHome', '99,999')
        ->call('save')
        ->assertSet('formError', null);
    $saved = PayrollPayRule::query()->sole();
    expect($saved->params['take_home'])->toEqual(['minimum' => 99999.0]);

    $flagged = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    expect($flagged['issues'])->toHaveCount(1)
        ->and($flagged['issues'][0]['employee_id'])->toBe($employee->id)
        ->and($flagged['issues'][0]['message'])->toContain('under the minimum take-home pay of 99,999.00')
        // Nothing is cut: the draft's figures are the ones without the floor.
        ->and($flagged['drafts'][0]->net_pay)->toBe($clean['drafts'][0]->net_pay);
});
