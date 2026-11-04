<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Livewire\Payroll\RunWorkspace;
use App\Livewire\Records\EmployeePaySettingsPage;
use App\Livewire\Records\EmployeePaySettingsShow;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollDeduction;
use App\Models\PayrollIncentiveType;
use App\Models\PayrollRecurringLine;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Models\PayslipDeduction;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Catalogs\Deductions;
use App\Modules\Payroll\Application\Commands\DeleteIncentiveType;
use App\Modules\Payroll\Application\Commands\DeleteRecurringLine;
use App\Modules\Payroll\Application\Commands\EndRecurringLine;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\Commands\SaveIncentiveType;
use App\Modules\Payroll\Application\Commands\SavePayroll;
use App\Modules\Payroll\Application\Commands\SaveRecurringLine;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollDraft;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\Queries\RecurringLineRecords;
use App\Modules\Payroll\Infrastructure\EmployeeObligations;
use App\Support\Access;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PayrollEvidenceFixture;
use Tests\Support\PayrollReviewFixture;

/**
 * Recurring lines (Employee Pay Settings): an employee's standing deduction
 * or incentive every Basic Payroll it is due on proposes by itself. A typed
 * amount on its row replaces the proposal and counts as its payment; a
 * skip leaves it off one payroll; a deduction's total is paid down by the
 * posted payslips naming it, trimmed on the last payroll and proposed no
 * more once paid off. Lines are captured in the payroll evidence; their
 * catalog rows cannot be deleted or re-priced under them; a balance left
 * is an obligation when the employee is made inactive.
 */

/** @param list<string> $modules */
function rlActor(array $modules = ['PAYROLL'], bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Recurring '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

/**
 * A line's figures over a ₱500-a-payroll loan of ₱750 from 1 Sep 2026.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function rlFigures(array $changes = []): array
{
    return [...['kind' => 'DEDUCTION', 'amount' => 500.0, 'total' => 750.0, 'frequency' => 'EVERY', 'starts_on' => '2026-09-01', 'ends_on' => null], ...$changes];
}

/** @param array<string, mixed> $changes */
function rlLine(Access $actor, string $employeeId, string $typeId, array $changes = [], string $note = 'Loan 42'): PayrollRecurringLine
{
    app(SaveRecurringLine::class)->handle($actor, null, $employeeId, $typeId, rlFigures($changes), $note);

    return PayrollRecurringLine::query()->latest('created_at')->latest('id')->firstOrFail();
}

/**
 * @param  array<string, float|int>  $typed  deduction row id => amount
 * @param  list<string>  $skipped  line ids
 * @param  array<string, float|int>  $incentives  incentive type id => amount
 * @return array<string, mixed>
 */
function rlDraft(Access $actor, string $employeeId, string $start = '2026-09-01', string $end = '2026-09-15', array $typed = [], array $skipped = [], array $incentives = []): array
{
    return PayrollData::calculateDrafts([$employeeId], $start, $end, actor: $actor,
        draftIncentives: $incentives === [] ? [] : [$employeeId => $incentives],
        draftCustomDeductions: $typed === [] ? [] : [$employeeId => $typed],
        draftSkippedRecurring: $skipped === [] ? [] : [$employeeId => $skipped]);
}

/* |---| The command |---| */

it('saves a recurring deduction for an all-data payroll officer, audited, and refuses what it cannot charge', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    $loan = PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);
    $command = app(SaveRecurringLine::class);

    expect($command->handle($actor, null, $employee->id, $loan->id, rlFigures(), 'Loan 42'))->toBe('Recurring SSS Loan added for Synthetic Evidence.');
    $line = PayrollRecurringLine::query()->sole();
    expect($line->only(['kind', 'deduction_id', 'incentive_type_id', 'amount', 'total', 'frequency', 'note']))
        ->toEqual(['kind' => 'DEDUCTION', 'deduction_id' => $loan->id, 'incentive_type_id' => null, 'amount' => 500.0, 'total' => 750.0, 'frequency' => 'EVERY', 'note' => 'Loan 42'])
        ->and($line->created_by_name)->not->toBeNull()
        ->and(AuditLog::query()->where('action', AuditEvent::RECURRING_LINE_SAVED->value)->value('details'))->toBe('Synthetic Evidence — recurring deduction SSS Loan added (Loan 42)');

    // One live line per row at a time; another row, or dates after it ends, are fine.
    expect(fn () => $command->handle($actor, null, $employee->id, $loan->id, rlFigures(['starts_on' => '2026-10-01']), ''))
        ->toThrow(RuntimeException::class, 'Synthetic Evidence already has a recurring SSS Loan in those dates. End that line first, or change it.');
    app(EndRecurringLine::class)->handle($actor, $line->id, '2026-09-30', 'Refinanced');
    $command->handle($actor, null, $employee->id, $loan->id, rlFigures(['starts_on' => '2026-10-01']), 'Loan 43');

    $computed = PayrollDeduction::query()->whereNotNull('calculation')->firstOrFail();
    $percent = PayrollDeduction::create(['code' => '8', 'name' => 'Fund', 'percent' => ['base' => 'MONTHLY', 'employee' => 2.0, 'employer' => 0.0, 'frequency' => 'EVERY', 'cap' => null, 'before_tax' => false]]);
    $scheduled = PayrollIncentiveType::create(['code' => '1', 'name' => 'Rice', 'is_taxable' => false, 'amount_type' => 'FIXED', 'amount' => 500, 'frequency' => 'EVERY', 'min_service_months' => 0, 'proration' => 'NONE', 'tax' => 'EXEMPT', 'counts_toward_13th' => false]);
    expect(fn () => $command->handle($actor, null, $employee->id, $computed->id, rlFigures(['total' => null]), ''))->toThrow(RuntimeException::class, 'is worked out by payroll itself')
        ->and(fn () => $command->handle($actor, null, $employee->id, $percent->id, rlFigures(['total' => null]), ''))->toThrow(RuntimeException::class, 'is worked out by payroll itself')
        ->and(fn () => $command->handle($actor, null, $employee->id, $scheduled->id, rlFigures(['kind' => 'INCENTIVE', 'total' => null]), ''))->toThrow(RuntimeException::class, 'Rice is already proposed by the catalog for everyone who qualifies.')
        ->and(fn () => $command->handle($actor, null, (string) Str::ulid(), $loan->id, rlFigures(), ''))->toThrow(RuntimeException::class, 'That employee no longer exists.')
        ->and(fn () => $command->handle($actor, null, $employee->id, $loan->id, rlFigures(['starts_on' => '2027-01-05']), ''))->toThrow(RuntimeException::class, 'Choose the cut-off it starts on')
        ->and(fn () => $command->handle($actor, null, $employee->id, $loan->id, rlFigures(['starts_on' => '2027-01-01']), str_repeat('x', 161)))->toThrow(RuntimeException::class, 'The note must be at most 160 characters.');

    $records = rlActor(['RECORDS', 'EMPLOYEES']);
    expect(fn () => $command->handle($records, null, $employee->id, $loan->id, rlFigures(['starts_on' => '2027-01-01']), ''))
        ->toThrow(RuntimeException::class, 'Your role cannot manage employee pay settings.')
        ->and(PayrollRecurringLine::query()->count())->toBe(2);
});

/* |---| What payroll proposes |---| */

it('proposes a due line on the draft, lets a typed amount replace and pay it, and leaves a skipped one off', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $loan = PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);
    $without = rlDraft($actor, $employee->id)['drafts'][0];
    $line = rlLine($actor, $employee->id, $loan->id);

    $proposed = rlDraft($actor, $employee->id);
    expect($proposed['customDeductionLines'][$employee->id])->toBe([
        ['type_id' => $loan->id, 'code' => '7', 'name' => 'SSS Loan', 'amount' => 500.0, 'employer_amount' => null, 'automatic' => true, 'recurring_line_id' => $line->id],
    ])
        ->and($proposed['drafts'][0]->net_pay)->toBe(round($without->net_pay - 500, 2))
        // After tax, like any loan: the withholding does not move.
        ->and($proposed['drafts'][0]->withholding_tax)->toBe($without->withholding_tax)
        ->and($proposed['inputs']->recurringLines[$employee->id][0])->toMatchArray(['line_id' => $line->id, 'amount' => 500.0, 'total' => 750.0, 'paid_before' => 0.0])
        ->and($proposed['inputs']->fingerprint())->not->toBe(rlDraft($actor, $employee->id, skipped: [$line->id])['inputs']->fingerprint());

    $typed = rlDraft($actor, $employee->id, typed: [$loan->id => 300]);
    expect($typed['customDeductionLines'][$employee->id])->toBe([
        ['type_id' => $loan->id, 'code' => '7', 'name' => 'SSS Loan', 'amount' => 300.0, 'employer_amount' => null, 'automatic' => false, 'recurring_line_id' => $line->id],
    ])->and($typed['draftErrors'])->toBe([]);

    // A typed payment cannot pay more than is left.
    expect(rlDraft($actor, $employee->id, typed: [$loan->id => 800])['draftErrors'])
        ->toContain(['employee_id' => $employee->id, 'message' => 'SSS Loan: ₱800.00 is more than the ₱750.00 left to pay. Type at most ₱750.00.']);

    $skipped = rlDraft($actor, $employee->id, skipped: [$line->id]);
    expect($skipped['customDeductionLines'])->toBe([])
        ->and($skipped['inputs']->skippedRecurringLines)->toBe([$employee->id => [$line->id]])
        ->and($skipped['drafts'][0]->net_pay)->toBe($without->net_pay);

    // Not due: a first-cut-off line on the second cut-off.
    $line->forceFill(['frequency' => 'FIRST'])->save();
    expect(rlDraft($actor, $employee->id, '2026-09-16', '2026-09-30')['customDeductionLines'])->toBe([])
        ->and(rlDraft($actor, $employee->id, '2026-09-16', '2026-09-30')['inputs']->recurringLines)->toHaveKey($employee->id);
});

it('proposes a recurring incentive under its type\'s tax category', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $allowance = PayrollIncentiveType::create(['code' => '3', 'name' => 'Transport Allowance', 'is_taxable' => true]);
    $line = rlLine($actor, $employee->id, $allowance->id, ['kind' => 'INCENTIVE', 'amount' => 800.0, 'total' => null]);

    $lines = rlDraft($actor, $employee->id)['incentiveLines'][$employee->id];
    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toMatchArray(['type_id' => $allowance->id, 'amount' => 800.0, 'tax' => 'TAXABLE', 'automatic' => true, 'recurring_line_id' => $line->id])
        ->and(rlDraft($actor, $employee->id, incentives: [$allowance->id => 1000])['incentiveLines'][$employee->id][0])
        ->toMatchArray(['amount' => 1000.0, 'automatic' => false, 'recurring_line_id' => $line->id]);
});

it('prices a recurring tax-exempt incentive against what the year has already spent of the ceiling', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $pay = PayrollIncentiveType::create(['code' => '4', 'name' => 'Productivity Pay', 'is_taxable' => false]);
    rlLine($actor, $employee->id, $pay->id, ['kind' => 'INCENTIVE', 'amount' => 5000.0, 'total' => null]);
    $before = rlDraft($actor, $employee->id);

    // ₱88,000 of the ceiling already spent this year leaves ₱2,000 of the
    // ₱5,000 tax-free; the other ₱3,000 is taxed with the pay.
    $posted = new Payslip;
    $posted->forceFill(['employee_id' => $employee->id, 'period_start' => '2026-08-16', 'period_end' => '2026-08-31',
        'gross_pay' => 15000, 'total_deductions' => 0, 'net_pay' => 15000, 'exempt_benefits' => 88000])->save();
    $after = rlDraft($actor, $employee->id);

    expect($before['statutory'][$employee->id]['benefits'])->toMatchArray(['ytd_before' => 0.0, 'taxable_excess' => 0.0])
        ->and($after['inputs']->exemptBenefitsYearToDate)->toBe([$employee->id => 88000.0])
        ->and($after['statutory'][$employee->id]['benefits'])->toMatchArray(['ytd_before' => 88000.0, 'taxable_excess' => 3000.0])
        ->and($after['drafts'][0]->taxable_compensation)->toBe(Money::round($before['drafts'][0]->taxable_compensation + 3000))
        ->and($after['inputs']->fingerprint())->not->toBe($before['inputs']->fingerprint());
});

it('restores current evidence exactly, and older evidence with no recurring lines', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $loan = PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);
    $line = rlLine($actor, $employee->id, $loan->id);
    $inputs = rlDraft($actor, $employee->id, skipped: [$line->id])['inputs'];

    $restored = PayrollInputs::restore(PayrollInputs::json($inputs->toArray()));
    expect($restored->fingerprint())->toBe($inputs->fingerprint())
        ->and($restored->recurringLines)->toEqual($inputs->recurringLines);
    $line->delete();
    expect(PayrollData::calculateInputs($restored, strict: true)['customDeductionLines'])->toBe([]);

    $broken = $inputs->toArray();
    $broken['recurring_lines'][$employee->id][0]['tax'] = 'TAXABLE';
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($broken)))->toThrow(RuntimeException::class, 'Invalid payroll recurring line evidence.');

    $nine = $inputs->toArray();
    unset($nine['recurring_lines'], $nine['skipped_recurring_lines'], $nine['attendance_through'], $nine['settlements'], $nine['leave_charges'], $nine['leave_cash_outs'], $nine['cash_out_type'], $nine['gov_policies'], $nine['adjustments']);
    $nine['schema_version'] = 9;
    $nine = PayrollEvidenceFixture::beforeSchema17($nine);
    expect(PayrollInputs::restore(PayrollInputs::json($nine))->recurringLines)->toBe([]);
});

/* |---| The ledger |---| */

it('pays a loan down with each finalized payslip, trims the last payment and stops once it is paid off', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 02:00:00', 'UTC'));
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $loan = PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);
    $line = rlLine($actor, $employee->id, $loan->id);

    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    $posted = PayslipDeduction::query()->sole();
    expect($posted->recurring_line_id)->toBe($line->id)
        ->and($posted->amount)->toBe(500.0);
    $view = app(RecurringLineRecords::class)->find($actor, $line->id, '2026-09-20');
    expect($view->paid)->toBe(500.0)->and($view->left)->toBe(250.0)->and($view->posted)->toBeTrue()->and($view->status)->toBe(RecurringLineRecords::ACTIVE);

    // The next payroll proposes only what is left.
    expect(rlDraft($actor, $employee->id, '2026-09-16', '2026-09-30')['customDeductionLines'][$employee->id][0]['amount'])->toBe(250.0)
        ->and(app(EmployeeObligations::class)->outstanding($employee->id))->toBe([['label' => 'SSS Loan · Loan 42', 'reference' => '₱250.00 left to pay']]);

    // Paid on a payslip: it keeps its row and kind, its total never drops below what it paid, and it cannot be deleted.
    $other = PayrollDeduction::create(['code' => '9', 'name' => 'Cash Advance']);
    expect(fn () => app(SaveRecurringLine::class)->handle($actor, $line->id, $employee->id, $other->id, rlFigures(), ''))->toThrow(RuntimeException::class, 'keeps what it charges')
        ->and(fn () => app(SaveRecurringLine::class)->handle($actor, $line->id, $employee->id, $loan->id, rlFigures(['total' => 400.0]), ''))->toThrow(RuntimeException::class, 'The total to pay off must be at least the amount per payroll')
        ->and(fn () => app(SaveRecurringLine::class)->handle($actor, $line->id, $employee->id, $loan->id, rlFigures(['amount' => 300.0, 'total' => 400.0]), ''))->toThrow(RuntimeException::class, 'cannot be less than the ₱500.00 already paid.')
        ->and(fn () => app(DeleteRecurringLine::class)->handle($actor, $line->id))->toThrow(RuntimeException::class, 'End it instead.');

    // A total equal to what was paid: paid off, proposed no more, no longer an obligation.
    app(SaveRecurringLine::class)->handle($actor, $line->id, $employee->id, $loan->id, rlFigures(['total' => 500.0]), 'Loan 42');
    expect(rlDraft($actor, $employee->id, '2026-09-16', '2026-09-30')['customDeductionLines'])->toBe([])
        ->and(app(RecurringLineRecords::class)->find($actor, $line->id, '2026-09-20')->status)->toBe(RecurringLineRecords::PAID_OFF)
        ->and(app(EmployeeObligations::class)->outstanding($employee->id))->toBe([]);

    // An amount typed on a paid-off loan's row is refused, not deducted loose past its total…
    $typed = rlDraft($actor, $employee->id, '2026-09-16', '2026-09-30', typed: [$loan->id => 300]);
    expect($typed['draftErrors'])->toContain(['employee_id' => $employee->id,
        'message' => 'SSS Loan is already paid off, so ₱300.00 cannot be deducted for it. Clear the amount, or end the paid-off line in the employee\'s pay settings on 15 Sep 2026 or earlier.'])
        ->and($typed['customDeductionLines'][$employee->id][0]['recurring_line_id'])->toBe($line->id)
        // Captured only to refuse that amount, it offers the grid no line to skip.
        ->and(PayrollData::recurringMembers($typed['inputs']))->toBe([]);

    // Ending it on the day the refusal names frees the row: the amount posts as a plain deduction.
    app(EndRecurringLine::class)->handle($actor, $line->id, '2026-09-15', 'Settled');
    $freed = rlDraft($actor, $employee->id, '2026-09-16', '2026-09-30', typed: [$loan->id => 300]);
    expect(array_filter(array_column($freed['draftErrors'], 'message'), static fn (string $message): bool => str_contains($message, 'SSS Loan')))->toBe([])
        ->and($freed['customDeductionLines'][$employee->id][0])->toMatchArray(['amount' => 300.0, 'recurring_line_id' => null]);

    // …unless a live loan on the same row takes it as its payment.
    $next = rlLine($actor, $employee->id, $loan->id, ['starts_on' => '2026-09-16', 'total' => 1000.0], 'Loan 43');
    $paying = rlDraft($actor, $employee->id, '2026-09-16', '2026-09-30', typed: [$loan->id => 300]);
    expect(array_filter(array_column($paying['draftErrors'], 'message'), static fn (string $message): bool => str_contains($message, 'SSS Loan')))->toBe([])
        ->and(PayrollData::recurringMembers($paying['inputs']))->toBe([$employee->id])
        ->and($paying['customDeductionLines'][$employee->id])->toBe([
            ['type_id' => $loan->id, 'code' => '7', 'name' => 'SSS Loan', 'amount' => 300.0, 'employer_amount' => null, 'automatic' => false, 'recurring_line_id' => $next->id],
        ]);
});

it('ends a line only earlier, never later, and offers End only on a line still running', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 02:00:00', 'UTC'));
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    $loan = PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);
    $line = rlLine($actor, $employee->id, $loan->id, ['ends_on' => '2026-11-16']);
    $end = app(EndRecurringLine::class);

    expect(fn () => $end->handle($actor, $line->id, '2026-12-01', 'Extend'))
        ->toThrow(RuntimeException::class, 'It already ends after 16 Nov 2026. To move that date later, edit the line.')
        ->and($line->refresh()->ends_on?->format('Y-m-d'))->toBe('2026-11-16');
    $end->handle($actor, $line->id, '2026-10-16', 'Settled');
    expect($line->refresh()->ends_on?->format('Y-m-d'))->toBe('2026-10-16');
    // The date it already has changes nothing and audits nothing.
    $audits = AuditLog::query()->count();
    expect($end->handle($actor, $line->id, '2026-10-16', 'Again'))->toBe('The recurring line already ends after 16 Oct 2026.')
        ->and(AuditLog::query()->count())->toBe($audits);

    // An ended line offers no End, and the page refuses one called directly.
    $line->forceFill(['ends_on' => '2026-10-01'])->save();
    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->assertDontSee('wire:click="startEnd(', false)
        ->call('startEnd', $line->id)
        ->assertSet('endOpen', false)
        ->assertSet('endingId', null);
});

it('changes nothing from the page for a role without all-data Payroll', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    $loan = PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);
    $line = rlLine($actor, $employee->id, $loan->id);
    $audits = AuditLog::query()->count();

    rlActor(['RECORDS', 'EMPLOYEES']);
    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->assertDontSee('SSS Loan')
        ->call('addLine')
        ->set('lineTypeId', $loan->id)
        ->set('lineAmount', '100')
        ->call('saveLine')
        ->assertSet('lineError', 'Your role cannot manage employee pay settings.')
        ->call('startEnd', $line->id)->assertSet('endingId', null)->call('endLine')
        ->call('confirmDeleteLine', $line->id)->assertSet('deletingId', null)->call('deleteLine');

    expect(PayrollRecurringLine::query()->count())->toBe(1)
        ->and($line->refresh()->ends_on)->toBeNull()
        ->and(AuditLog::query()->count())->toBe($audits);
});

it('never stages another employee\'s line for an edit, an end or a delete', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    $loan = PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);
    $colleague = EmployeeProfile::create(['employee_code' => 'EVID-002', 'first_name' => 'Other', 'last_name' => 'Colleague',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'monthly_rate' => 30000, 'is_active' => true]);
    $theirs = rlLine($actor, $colleague->id, $loan->id);

    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->call('editLine', $theirs->id)->assertSet('lineOpen', false)->assertSet('lineId', null)
        ->call('startEnd', $theirs->id)->assertSet('endOpen', false)->assertSet('endingId', null)
        ->call('confirmDeleteLine', $theirs->id)->assertSet('deleteOpen', false)->assertSet('deletingId', null)
        ->call('deleteLine');
    expect(PayrollRecurringLine::query()->whereKey($theirs->id)->exists())->toBeTrue()
        ->and($theirs->refresh()->ends_on)->toBeNull();
});

it('keeps a skip on the draft through save and reload, and drops it from the proposal', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $loan = PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);
    $line = rlLine($actor, $employee->id, $loan->id);

    Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->assertSee('Recurring lines for Synthetic Evidence')
        ->call('openRecurring', $employee->id)
        ->assertSee('₱500.00')
        ->assertSee('₱750.00')
        ->call('toggleRecurringSkip', $line->id)
        ->assertSet('skippedRecurring', [$employee->id => [$line->id]])
        ->assertSee('Skipped on this payroll.')
        // Only a live line of an open member can be skipped.
        ->call('toggleRecurringSkip', (string) Str::ulid())
        ->assertSet('skippedRecurring', [$employee->id => [$line->id]])
        ->call('save');

    expect(PayrollRunMember::query()->where('run_id', $run->id)->sole()->skipped_recurring_lines)->toBe([$line->id])
        ->and(AuditLog::query()->where('action', AuditEvent::PAYROLL_RUN_UPDATED->value)->latest('id')->value('details'))->toContain('; 1 recurring line skipped');
    Livewire::test(RunWorkspace::class, ['id' => $run->id])->assertSet('skippedRecurring', [$employee->id => [$line->id]]);

    // The finalized run honours the skip: nothing posted against the line.
    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    expect(PayslipDeduction::query()->count())->toBe(0);
});

it('refuses a skip of another employee\'s line, and forgets one whose line was deleted', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    $loan = PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);
    $line = rlLine($actor, $employee->id, $loan->id);
    $draft = static fn (array $skips): PayrollDraft => new PayrollDraft($run->id, 'Evidence payroll', '2026-09-01', '2026-09-15', [$employee->id], [], [], [], skippedRecurring: $skips);

    $colleague = EmployeeProfile::create(['employee_code' => 'EVID-002', 'first_name' => 'Other', 'last_name' => 'Colleague',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'monthly_rate' => 30000, 'is_active' => true]);
    $theirs = rlLine($actor, $colleague->id, $loan->id);
    expect(fn () => app(SavePayroll::class)->handle($actor, $draft([$employee->id => [$theirs->id]])))
        ->toThrow(RuntimeException::class, 'A skipped recurring line is not one this payroll knows.');

    app(SavePayroll::class)->handle($actor, $draft([$employee->id => [$line->id]]));
    expect(PayrollRunMember::query()->where('run_id', $run->id)->sole()->skipped_recurring_lines)->toBe([$line->id]);

    app(DeleteRecurringLine::class)->handle($actor, $line->id);
    app(SavePayroll::class)->handle($actor, $draft([$employee->id => [$line->id]]));
    expect(PayrollRunMember::query()->where('run_id', $run->id)->sole()->skipped_recurring_lines)->toBeNull();
});

/* |---| The catalogs |---| */

it('keeps a catalog row a recurring line charges from being deleted or re-priced', function () {
    ['employee' => $employee] = PayrollEvidenceFixture::make();
    $actor = rlActor(['PAYROLL', 'RECORDS']);
    $loan = PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);
    $allowance = PayrollIncentiveType::create(['code' => '3', 'name' => 'Transport Allowance', 'is_taxable' => true]);
    rlLine($actor, $employee->id, $loan->id);
    rlLine($actor, $employee->id, $allowance->id, ['kind' => 'INCENTIVE', 'total' => null]);

    expect(fn () => app(Deductions::class)->delete($actor, $loan->id))->toThrow(RuntimeException::class, 'Employees have recurring lines on SSS Loan, so it cannot be deleted.')
        ->and(fn () => app(Deductions::class)->save($actor, $loan->id, 'SSS Loan', '', ['percent' => ['base' => 'MONTHLY', 'employee' => 2.0, 'employer' => 0.0, 'frequency' => 'EVERY', 'cap' => null, 'before_tax' => false]]))
        ->toThrow(RuntimeException::class, 'cannot become a percentage deduction')
        ->and(fn () => app(DeleteIncentiveType::class)->handle($actor, $allowance->id))->toThrow(RuntimeException::class, 'Employees have recurring lines on Transport Allowance, so it cannot be deleted.')
        ->and(fn () => app(SaveIncentiveType::class)->handle($actor, $allowance->id, 'Transport Allowance', null, true, [
            'amount_type' => 'FIXED', 'amount' => 500.0, 'frequency' => 'EVERY', 'month' => null, 'statuses' => null, 'min_service_months' => 0,
            'proration' => 'NONE', 'tax' => 'TAXABLE', 'cap' => null, 'approval_reference' => null, 'counts_toward_13th' => false,
        ]))->toThrow(RuntimeException::class, 'payroll cannot schedule it')
        ->and(PayrollDeduction::query()->whereKey($loan->id)->exists())->toBeTrue();
});

/* |---| The pages |---| */

it('adds, edits, ends and deletes a recurring line on the employee\'s page, and lists it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 02:00:00', 'UTC'));
    ['employee' => $employee] = PayrollEvidenceFixture::make();
    $loan = PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);

    $page = Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->assertSee('No recurring line yet.')
        ->call('addLine')
        ->assertSet('lineStartsOn', '2026-10-16')
        ->assertSee('SSS Loan')
        ->set('lineTypeId', $loan->id)
        ->set('lineAmount', '1,000')
        ->set('lineTotal', '12000')
        ->set('lineNote', 'Loan 42')
        ->call('saveLine')
        ->assertSet('lineOpen', false)
        ->assertSee('₱12,000.00')
        ->assertSee('Upcoming');
    $line = PayrollRecurringLine::query()->sole();
    expect($line->amount)->toBe(1000.0)->and($line->total)->toBe(12000.0)->and($line->starts_on->format('Y-m-d'))->toBe('2026-10-16');

    $page->call('editLine', $line->id)
        ->assertSet('lineAmount', '1000')
        ->set('lineAmount', 'lots')
        ->call('saveLine')
        ->assertSet('lineError', 'Enter the amount per payroll, above ₱0.00 and up to ₱99,999,999.00.')
        ->set('lineAmount', '2000')
        ->call('saveLine')
        ->assertSet('lineOpen', false);
    expect($line->refresh()->amount)->toBe(2000.0);

    Livewire::test(EmployeePaySettingsPage::class)
        ->assertSee('Synthetic Evidence')
        ->assertSee('1 line, ₱12,000.00 left');

    $page->call('startEnd', $line->id)
        ->set('endLastDay', '2027-03-16')
        ->call('endLine')
        ->assertSet('endError', 'Enter the reason it ends, at most 160 characters.')
        ->set('endReason', 'Settled')
        ->call('endLine')
        ->assertSet('endOpen', false);
    expect($line->refresh()->ends_on?->format('Y-m-d'))->toBe('2027-03-16')
        ->and(AuditLog::query()->where('action', AuditEvent::RECURRING_LINE_ENDED->value)->value('details'))->toBe('Synthetic Evidence — recurring line ends after 16 Mar 2027 (Settled)');

    $page->call('confirmDeleteLine', $line->id)->call('deleteLine');
    expect(PayrollRecurringLine::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditEvent::RECURRING_LINE_DELETED->value)->exists())->toBeTrue();
});
