<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Livewire\Payroll\Adjustments as AdjustmentsPage;
use App\Livewire\Payroll\RunWorkspace;
use App\Livewire\Records\AdjustmentCategoriesPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollAdjustment;
use App\Models\PayrollAdjustmentCategory;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipDeduction;
use App\Models\PayslipIncentive;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Adjustments;
use App\Modules\Payroll\Application\Commands\DeleteAdjustmentCategory;
use App\Modules\Payroll\Application\Commands\DiscardAdjustment;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\Commands\ReviewAdjustment;
use App\Modules\Payroll\Application\Commands\SaveAdjustment;
use App\Modules\Payroll\Application\Commands\SaveAdjustmentCategory;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\Queries\AdjustmentRecords;
use App\Modules\Payroll\Domain\Adjustments\AdjustmentRule;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PayrollEvidenceFixture;
use Tests\Support\PayrollReviewFixture;

/**
 * The adjustment ledger (2026_11_02_0005): a difference paid or recovered
 * on a later payroll is prepared as a PENDING line by a payroll officer,
 * approved or rejected (by the same officer included), captured in the payroll
 * evidence (schema 16) of the next regular payroll with the employee on
 * it — an earning taxed like pay or added after the tax, a deduction after
 * the tax — and marked POSTED by the payslip that paid it. A pending line
 * holds the payroll. Categories say what a line is.
 */

/** @param list<string> $modules */
function adjActor(array $modules = ['PAYROLL'], bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Adjustments '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        (new RoleModuleGrant)->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function adjEmployee(string $lastName): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'ADJ-'.Str::upper(Str::random(5)), 'first_name' => 'Ada', 'last_name' => $lastName,
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'monthly_rate' => 30000, 'is_active' => true])->save();

    return $employee->refresh();
}

function adjCategory(string $name): PayrollAdjustmentCategory
{
    return PayrollAdjustmentCategory::query()->where('name', $name)->sole();
}

/** The migration that makes the tax CHECK refuse an earning category with no tax — an anonymous class, so untyped here. */
function adjTaxCheckMigration(): mixed
{
    return require base_path('database/migrations/2026_11_19_0001_require_tax_on_earning_adjustment_categories_table.php');
}

/* |---| The migration |---| */

it('creates the ledger with its starting categories and the posted-line columns, and resumes from a partial run', function () {
    expect(Adjustments::ready())->toBeTrue()
        ->and(PayrollAdjustmentCategory::query()->pluck('name')->sort()->values()->all())
        ->toBe(['Correction of salary', 'Overpayment recovery', 'Refund – Pag-IBIG loan', 'Refund – cash advance', 'Tax refund', 'Tax shortfall'])
        ->and(adjCategory('Tax shortfall')->kind)->toBe(AdjustmentRule::KIND_DEDUCTION)
        ->and(adjCategory('Tax shortfall')->tax)->toBeNull()
        ->and(adjCategory('Correction of salary')->tax)->toBe(AdjustmentRule::TAX_TAXABLE);

    $m = require base_path('database/migrations/2026_11_02_0005_create_payroll_adjustments_tables.php');
    $m->down();
    expect(Schema::hasTable('payroll_adjustments'))->toBeFalse()->and(Schema::hasColumn('payslip_deductions', 'adjustment_id'))->toBeFalse();
    $m->up();
    $m->up();
    expect(Schema::hasTable('payroll_adjustment_categories'))->toBeTrue()
        ->and(Schema::hasColumns('payroll_adjustments', ['employee_id', 'category_id', 'amount', 'reason', 'status', 'target_run_id', 'payslip_id', 'prepared_by', 'approved_by']))->toBeTrue()
        ->and(Schema::hasColumn('payslip_incentives', 'adjustment_id'))->toBeTrue()
        ->and(PayrollAdjustmentCategory::query()->count())->toBe(6);

    // The create alone brings the tax CHECK back in its first wording; the
    // migration that tightened it runs again so the table ends as migrated.
    adjTaxCheckMigration()->up();
});

it('reads a stored earning category with no tax as not taxed, once, and leaves every other category alone', function () {
    // MySQL/MariaDB cannot hold the row this starts from once the CHECK is
    // tightened; there the migration is tests/Integration/AdjustmentsSchemaTest's.
    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('On MySQL/MariaDB this migration is covered by AdjustmentsSchemaTest.');
    }
    $stray = new PayrollAdjustmentCategory;
    $stray->forceFill(['name' => 'Written around the app', 'kind' => AdjustmentRule::KIND_EARNING, 'tax' => null])->save();
    $stray->newQuery()->whereKey($stray->id)->update(['updated_at' => '2026-01-01 00:00:00.000']);
    $others = static fn (): string => PayrollAdjustmentCategory::query()->whereKeyNot($stray->id)->orderBy('id')->get()->toJson();
    $before = $others();

    $migration = adjTaxCheckMigration();
    $migration->up();
    $migration->up();

    // Payroll already read an empty tax as not taxed, so no pay figure moves.
    $settled = PayrollAdjustmentCategory::query()->findOrFail($stray->id);
    expect($settled->tax)->toBe(AdjustmentRule::TAX_NOT_TAXED)
        ->and($settled->updated_at?->format('Y-m-d'))->toBe('2026-01-01')
        ->and($others())->toBe($before);

    // Rolling back rewrites no row.
    $migration->down();
    expect(PayrollAdjustmentCategory::query()->findOrFail($stray->id)->tax)->toBe(AdjustmentRule::TAX_NOT_TAXED);
});

/* |---| Categories |---| */

it('adds, renames and deletes categories for whoever manages the Payroll Settings, keeping a used category\'s kind', function () {
    $reader = adjActor(['PAYROLL'], allData: false);
    expect(fn () => app(SaveAdjustmentCategory::class)->handle($reader, null, 'Mine', AdjustmentRule::KIND_EARNING, AdjustmentRule::TAX_TAXABLE))->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');

    $actor = adjActor();
    $save = app(SaveAdjustmentCategory::class);
    expect(fn () => $save->handle($actor, null, ' ', AdjustmentRule::KIND_EARNING, AdjustmentRule::TAX_TAXABLE))->toThrow(RuntimeException::class, 'Name the category')
        ->and(fn () => $save->handle($actor, null, 'Bad', 'GIFT', null))->toThrow(RuntimeException::class, 'Choose whether the category adds to pay or deducts from it.')
        ->and(fn () => $save->handle($actor, null, 'Bad', AdjustmentRule::KIND_EARNING, null))->toThrow(RuntimeException::class, 'Choose whether an earning under this category is taxed.')
        ->and(fn () => $save->handle($actor, null, 'Tax refund', AdjustmentRule::KIND_EARNING, AdjustmentRule::TAX_TAXABLE))->toThrow(RuntimeException::class, 'A category with that name already exists.');
    $id = $save->handle($actor, null, 'Hazard pay', AdjustmentRule::KIND_EARNING, AdjustmentRule::TAX_TAXABLE);
    expect(AuditLog::query()->where('action', AuditEvent::ADJUSTMENT_CATEGORY_SAVED->value)->sole()->details)->toBe('Adjustment category "Hazard pay" added: a taxable earning');
    // A deduction ignores a tax category; a used category keeps its kind.
    $save->handle($actor, $id, 'Hazard pay', AdjustmentRule::KIND_DEDUCTION, AdjustmentRule::TAX_TAXABLE);
    expect(adjCategory('Hazard pay')->tax)->toBeNull();
    $employee = adjEmployee('Used');
    app(SaveAdjustment::class)->handle($actor, null, $employee->id, $id, '100', 'Used it', null, null);
    expect(fn () => $save->handle($actor, $id, 'Hazard pay', AdjustmentRule::KIND_EARNING, AdjustmentRule::TAX_TAXABLE))->toThrow(RuntimeException::class, 'Lines already exist under this category')
        ->and(fn () => app(DeleteAdjustmentCategory::class)->handle($actor, $id))->toThrow(RuntimeException::class, 'One adjustment was made under this category, so it stays on record.');
    $spare = $save->handle($actor, null, 'Spare', AdjustmentRule::KIND_DEDUCTION, null);
    expect(app(DeleteAdjustmentCategory::class)->handle($actor, $spare))->toBe('Category "Spare" deleted.')
        ->and(AuditLog::query()->where('action', AuditEvent::ADJUSTMENT_CATEGORY_DELETED->value)->sole()->details)->toBe('Adjustment category "Spare" deleted');

    Livewire::test(AdjustmentCategoriesPage::class)
        ->call('openAdd')->set('formName', 'Uniform allowance')->set('formKind', AdjustmentRule::KIND_EARNING)->set('formTax', AdjustmentRule::TAX_NOT_TAXED)->call('save')
        ->assertSet('dialogOpen', false)->assertSee('Uniform allowance')->assertSee('Not compensation');
    expect(adjCategory('Uniform allowance')->tax)->toBe(AdjustmentRule::TAX_NOT_TAXED);
});

/* |---| The ledger |---| */

it('prepares a line, lets the preparer review it, and discards a pending one, audited', function () {
    $employee = adjEmployee('Lined');
    $preparer = adjActor();
    $save = app(SaveAdjustment::class);
    $category = adjCategory('Correction of salary');

    expect(fn () => $save->handle($preparer, null, $employee->id, $category->id, '0', 'Nothing', null, null))->toThrow(RuntimeException::class, 'Enter the amount as a peso figure above zero.')
        ->and(fn () => $save->handle($preparer, null, $employee->id, $category->id, '1,250.50', ' ', null, null))->toThrow(RuntimeException::class, 'Say why')
        ->and(fn () => $save->handle($preparer, null, $employee->id, (string) Str::ulid(), '100', 'Why', null, null))->toThrow(RuntimeException::class, 'Choose the adjustment category.')
        ->and(fn () => $save->handle($preparer, null, $employee->id, $category->id, '100', 'Why', null, (string) Str::ulid()))->toThrow(RuntimeException::class, 'Choose an open regular payroll')
        ->and(fn () => $save->handle($preparer, null, (string) Str::ulid(), $category->id, '100', 'Why', null, null))->toThrow(RuntimeException::class, 'That employee no longer exists.')
        ->and(PayrollAdjustment::query()->count())->toBe(0);

    test()->actingAs(User::query()->findOrFail($preparer->userId));
    app()->forgetInstance(Access::class);
    $id = $save->handle($preparer, null, $employee->id, $category->id, '1,250.50', 'Short on the October payroll', 'Memo 12', null);
    $row = PayrollAdjustment::query()->whereKey($id)->sole();
    expect($row->status)->toBe(AdjustmentRule::PENDING)
        ->and($row->amount)->toBe(1250.5)
        ->and($row->reference)->toBe('Memo 12')
        ->and($row->prepared_by)->toBe((int) $preparer->userId)
        ->and(AuditLog::query()->where('action', AuditEvent::ADJUSTMENT_SAVED->value)->sole()->details)->toBe('Ada Lined · Correction of salary earning of ₱1,250.50 prepared: Short on the October payroll');

    // Corrected in place while pending; a rejection needs a note.
    $save->handle($preparer, $id, $employee->id, $category->id, '1,300', 'Short on the October payroll', 'Memo 12', null);
    expect($row->refresh()->amount)->toBe(1300.0)->and(PayrollAdjustment::query()->count())->toBe(1);
    $review = app(ReviewAdjustment::class);
    expect(fn () => $review->handle($preparer, $id, false, ''))->toThrow(RuntimeException::class, 'Say why the adjustment is rejected.')
        ->and($row->refresh()->status)->toBe(AdjustmentRule::PENDING);

    // The preparer approves their own line: one administrator runs the whole flow.
    $approver = $preparer;
    expect($review->handle($approver, $id, true, 'Checked against the October register'))->toBe("Adjustment approved: Ada Lined's Correction of salary earning of ₱1,300.00 goes on their next payroll.");
    $row->refresh();
    expect($row->status)->toBe(AdjustmentRule::APPROVED)
        ->and($row->approved_by)->toBe((int) $approver->userId)
        ->and($row->review_note)->toBe('Checked against the October register')
        ->and(AuditLog::query()->where('action', AuditEvent::ADJUSTMENT_APPROVED->value)->sole()->details)->toContain('approved (prepared by')
        ->and(fn () => $review->handle($approver, $id, true))->toThrow(RuntimeException::class, 'That adjustment was already reviewed.')
        ->and(fn () => $save->handle($approver, $id, $employee->id, $category->id, '10', 'Again', null, null))->toThrow(RuntimeException::class, 'A reviewed adjustment never changes.')
        ->and(fn () => app(DiscardAdjustment::class)->handle($approver, $id))->toThrow(RuntimeException::class, 'A reviewed adjustment stays on record.');

    // A rejection and a discard.
    $rejected = $save->handle($approver, null, $employee->id, adjCategory('Tax shortfall')->id, '200', 'Under-withheld', null, null);
    expect($review->handle($preparer, $rejected, false, 'Already settled'))->toBe("Adjustment rejected: Ada Lined's Tax shortfall deduction of ₱200.00.")
        ->and(PayrollAdjustment::query()->whereKey($rejected)->sole()->status)->toBe(AdjustmentRule::REJECTED);
    $discarded = $save->handle($approver, null, $employee->id, adjCategory('Tax refund')->id, '50', 'Oops', null, null);
    expect(app(DiscardAdjustment::class)->handle($approver, $discarded))->toBe('Adjustment discarded.')
        ->and(PayrollAdjustment::query()->whereKey($discarded)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditEvent::ADJUSTMENT_DISCARDED->value)->sole()->details)->toBe('Ada Lined · Tax refund of ₱50.00 discarded before review (Oops)');

    // Pay data: a linked PAYROLL holder reads their own, a Records manager nothing.
    $own = adjActor(['PAYROLL'], allData: false, employeeId: $employee->id);
    expect(app(AdjustmentRecords::class)->list($own, 'ALL')->pluck('id')->all())->toBe([$rejected, $id])
        ->and(app(AdjustmentRecords::class)->list(adjActor(['RECORDS']), 'ALL'))->toHaveCount(0)
        ->and(fn () => $save->handle($own, null, $employee->id, $category->id, '100', 'Mine', null, null))->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
});

/* |---| The payroll |---| */

it('pays approved lines on the next payroll from the evidence, holds a pending one, and marks the paid ones posted', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $preparer = adjActor();
    $save = app(SaveAdjustment::class);
    $taxable = $save->handle($preparer, null, $employee->id, adjCategory('Correction of salary')->id, '1000', 'Short', null, null);
    $refund = $save->handle($preparer, null, $employee->id, adjCategory('Tax refund')->id, '300', 'Over-withheld', null, null);
    $recovery = $save->handle($preparer, null, $employee->id, adjCategory('Overpayment recovery')->id, '250', 'Paid twice', null, null);
    $elsewhere = $save->handle($preparer, null, $employee->id, adjCategory('Tax refund')->id, '999', 'Another payroll', null, null);
    $otherRun = new PayrollRun;
    $otherRun->forceFill(['code' => 'PR-OTHER', 'name' => 'Other draft', 'status' => 'DRAFT', 'kind' => 'REGULAR', 'period_start' => '2026-09-16', 'period_end' => '2026-09-30'])->save();

    test()->actingAs(User::query()->findOrFail($actor->userId));
    app()->forgetInstance(Access::class);
    $compute = static fn (): array => PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor, runId: $run->id);
    $before = $compute();
    expect($before['inputs']->adjustments)->toBe([])
        ->and(array_keys(Adjustments::pendingFor([$employee->id], $run->id)))->toBe([$employee->id])
        ->and(fn () => app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id)))
        ->toThrow(RuntimeException::class, 'An adjustment is awaiting approval for Synthetic Evidence. Review or discard it on Adjustments before finalizing.');
    Livewire::test(RunWorkspace::class, ['id' => $run->id])->assertSee('data-pending-adjustments', false)->assertSee('An adjustment is awaiting approval for');

    // Approved by the fixture's actor, who is linked to the employee: their own lines.
    $review = app(ReviewAdjustment::class);
    $approver = $actor;
    foreach ([$taxable, $refund, $recovery] as $id) {
        $review->handle($approver, $id, true);
    }
    $review->handle($approver, $elsewhere, true, '', $otherRun->id);
    test()->actingAs(User::query()->findOrFail($actor->userId));
    app()->forgetInstance(Access::class);

    $after = $compute();
    $draft = $after['drafts'][0];
    $baseline = $before['drafts'][0];
    expect(array_column($after['inputs']->adjustments[$employee->id], 'id'))->toBe([$taxable, $refund, $recovery])
        ->and($after['inputs']->fingerprint())->not->toBe($before['inputs']->fingerprint())
        // The taxable earning is taxed like pay; the refund joins gross after the tax; the recovery is a named deduction after the tax.
        ->and($draft->bonus)->toBe(1000.0)
        ->and($draft->exempt_benefits)->toBe(300.0)
        ->and($draft->gross_pay)->toBe(round($baseline->gross_pay + 1300.0, 2))
        ->and($draft->custom_deductions)->toBe(250.0)
        ->and(array_column($after['customDeductionLines'][$employee->id], 'adjustment_id'))->toBe([$recovery])
        ->and(array_column($after['incentiveLines'][$employee->id], 'adjustment_id'))->toBe([$taxable, $refund]);

    // The evidence replays the lines, whatever the ledger holds later.
    $json = PayrollInputs::json($after['inputs']->toArray());
    expect(json_decode($json, true)['schema_version'])->toBe(24);
    $restored = PayrollInputs::restore($json);
    expect($restored->fingerprint())->toBe($after['inputs']->fingerprint());
    $data = json_decode($json, true);
    $data['adjustments'][$employee->id][0]['amount'] = -5;
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($data)))->toThrow(RuntimeException::class, 'Invalid payroll adjustment evidence.');

    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    $payslip = Payslip::query()->where('run_id', $run->id)->sole();
    expect(PayrollAdjustment::query()->whereKey($taxable)->sole())->toMatchArray(['status' => AdjustmentRule::POSTED, 'payslip_id' => $payslip->id])
        ->and(PayrollAdjustment::query()->whereKey($recovery)->sole()->status)->toBe(AdjustmentRule::POSTED)
        ->and(PayrollAdjustment::query()->whereKey($elsewhere)->sole()->status)->toBe(AdjustmentRule::APPROVED)
        ->and(PayslipIncentive::query()->where('payslip_id', $payslip->id)->whereNotNull('adjustment_id')->count())->toBe(2)
        ->and(PayslipDeduction::query()->where('payslip_id', $payslip->id)->where('adjustment_id', $recovery)->sole()->amount)->toBe(250.0)
        ->and(AuditLog::query()->where('action', AuditEvent::PAYROLL_FINALIZED->value)->sole()->details)->toContain('3 adjustments paid');

    // The posted ledger stays: a posted line's category cannot go, the line cannot be deleted under it.
    expect(fn () => app(DeleteAdjustmentCategory::class)->handle($actor, adjCategory('Correction of salary')->id))->toThrow(RuntimeException::class, 'stays on record');
});

/* |---| The page |---| */

it('prepares, reviews and discards lines from the Adjustments page, and reads its own lines only without all data', function () {
    $employee = adjEmployee('Paged');
    $preparer = adjActor();
    $category = adjCategory('Refund – cash advance');

    $this->get('/payroll/adjustments')->assertOk()->assertSee('Adjustments')->assertSee('openAdd');
    Livewire::test(AdjustmentsPage::class)
        ->call('openAdd')
        ->assertSet('dialogOpen', true)
        ->call('employeePicked', 'adjustment-employee', $employee->id)
        ->assertSet('formEmployeeId', $employee->id)
        ->set('formCategoryId', $category->id)
        ->set('formAmount', '450')
        ->call('save')
        ->assertSet('dialogOpen', true)
        ->assertSet('formError', 'Say why, in 1 to 160 characters.')
        ->set('formReason', 'Cash advance deducted twice')
        ->call('save')
        ->assertSet('dialogOpen', false)
        ->assertSee('Ada Paged')->assertSee('Refund – cash advance')->assertSee('Awaiting approval');
    $line = PayrollAdjustment::query()->sole();

    // The preparer approves from the page themselves.
    Livewire::test(AdjustmentsPage::class)->call('openReview', $line->id)->assertSet('reviewOpen', true)->call('approve')->assertSet('reviewOpen', false)
        ->set('status', AdjustmentRule::APPROVED)->assertSee('Approved');
    expect($line->refresh()->status)->toBe(AdjustmentRule::APPROVED);

    // A second line discarded from the page; a linked holder without all data sees only their own, and writes nothing.
    test()->actingAs(User::query()->findOrFail($preparer->userId));
    app()->forgetInstance(Access::class);
    $second = app(SaveAdjustment::class)->handle($preparer, null, $employee->id, $category->id, '10', 'Oops', null, null);
    Livewire::test(AdjustmentsPage::class)->call('confirmDiscard', $second)->assertSet('discardOpen', true)->call('discard');
    expect(PayrollAdjustment::query()->whereKey($second)->exists())->toBeFalse();

    adjActor(['PAYROLL'], allData: false, employeeId: $employee->id);
    Livewire::test(AdjustmentsPage::class)->set('status', 'ALL')->assertSee('Ada Paged')->assertDontSee('openAdd')->call('openAdd')->assertSet('dialogOpen', false);
    expect($this->get('/payroll/adjustments/export?status=ALL')->assertOk()->streamedContent())->toContain('Refund – cash advance');
    adjEmployee('Stranger');
    adjActor(['PAYROLL'], allData: false, employeeId: adjEmployee('Other')->id);
    Livewire::test(AdjustmentsPage::class)->set('status', 'ALL')->assertDontSee('Ada Paged');
});
