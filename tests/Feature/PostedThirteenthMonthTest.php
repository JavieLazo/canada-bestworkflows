<?php

declare(strict_types=1);

use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Livewire\Payroll\Index;
use App\Livewire\Payroll\RunWorkspace;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\Commands\SavePayroll;
use App\Modules\Payroll\Application\PayrollDraft;
use App\Modules\Payroll\Application\Queries\PayslipRecords;
use App\Modules\Payroll\Contracts\Views\DeductionColumnView;
use App\Modules\Payroll\Domain\DeductionCalculation;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\PayrollEvidenceFixture;

/**
 * 13th Month Payroll is not a feature: no route, menu entry or command makes
 * a 13th month run. What an installation ALREADY POSTED stays readable — a
 * FINALIZED run of that kind is listed with the other payrolls and opens
 * read-only in the payroll workspace, showing what it posted — while a DRAFT
 * one, which posted nothing, cannot be opened, saved or finalized, and the
 * data migration `2026_11_24_0005` removes it. (Its payslip printing as a
 * pay statement on every surface is PayslipDocumentTest's.)
 */
function ptmRun(string $code, string $status, EmployeeProfile $employee, string $kind = PayrollRunKind::THIRTEENTH_MONTH, int $year = 2025): PayrollRun
{
    $yearly = $kind === PayrollRunKind::THIRTEENTH_MONTH;
    [$start, $end] = $yearly ? ["{$year}-01-01", "{$year}-12-31"] : ['2026-10-01', '2026-10-15'];
    $run = PayrollRun::create(['code' => $code, 'name' => "Payroll {$code}", 'status' => $status, 'period_start' => $start, 'period_end' => $end, 'kind' => $kind]);
    PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->id, 'position' => 0, 'period_start' => $start, 'period_end' => $end]);

    return $run;
}

function ptmPayslip(PayrollRun $run, EmployeeProfile $employee): Payslip
{
    $payslip = new Payslip;
    $payslip->forceFill([
        'run_id' => $run->id, 'employee_id' => $employee->id,
        'period_start' => $run->period_start->format('Y-m-d'), 'period_end' => $run->period_end->format('Y-m-d'),
        'thirteenth_month' => 12345.67, 'gross_pay' => 12345.67,
        'exempt_benefits' => 9000, 'taxable_benefits_excess' => 3345.67,
        'withholding_tax' => 345.67, 'total_deductions' => 345.67, 'net_pay' => 12000,
    ])->save();

    return $payslip;
}

/** @param array<string, mixed> $payload */
function ptmEvidence(PayrollRun $run, array $payload): void
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $snapshot = new PayrollFinalizationSnapshot;
    $snapshot->forceFill([
        'run_id' => $run->id, 'schema_version' => 5, 'calculator_version' => 'payroll-v1:fixture',
        'input_hash' => hash('sha256', 'inputs'), 'payload_hash' => hash('sha256', $json), 'payload' => $json,
        'finalized_by' => (int) auth()->id(),
    ])->save();
}

/*
|--------------------------------------------------------------------------
| No way in
|--------------------------------------------------------------------------
*/

it('has no 13th month route, menu entry or screen', function () {
    PayrollEvidenceFixture::make();

    foreach (['payroll.thirteenth.index', 'payroll.thirteenth.new', 'payroll.thirteenth.show', 'payroll.thirteenth.payslip', 'payroll.thirteenth.export'] as $name) {
        expect(Route::has($name))->toBeFalse();
    }
    // The sidebar's Payroll entry is one link, with nothing under it.
    $payroll = collect((array) config('modules'))->firstWhere('code', 'PAYROLL');
    expect($payroll['path'] ?? null)->toBe('/payroll')->and(array_key_exists('groups', (array) $payroll))->toBeFalse();

    $this->get('/payroll/thirteenth-month/new')->assertNotFound();
    // The former list's address reads as a payroll id nobody has.
    $this->get('/payroll/thirteenth-month')->assertOk()->assertSee('That payroll no longer exists.');
    $this->get('/payroll')->assertOk()->assertSee('Create Payroll')->assertDontSee('13th Month');
});

/*
|--------------------------------------------------------------------------
| A finalized run stays readable
|--------------------------------------------------------------------------
*/

it('lists a finalized 13th month payroll with the others and opens it read-only, showing what it posted', function () {
    ['employee' => $employee] = PayrollEvidenceFixture::make();
    $run = ptmRun('PR-13', PayrollRunStatus::FINALIZED, $employee);
    $payslip = ptmPayslip($run, $employee);
    $posted = $payslip->refresh()->getRawOriginal();

    Livewire::test(Index::class)->assertSee('Payroll PR-13')->assertSee('Evidence payroll')
        ->assertSeeHtml(route('payroll.show', ['id' => $run->id]));

    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->assertSet('missing', false)
        ->assertSee('Payroll PR-13')
        ->assertSee('This payroll paid 13th month pay for the year.')
        ->assertSee('Finalized — these payslips are posted and no longer change.')
        // Its one earning has a column of its own, footed like the rest.
        ->assertSee('13th Month Pay')
        ->assertSeeHtml('data-thirteenth-month-pay')
        ->assertSee('12,345.67')
        ->assertSee('Withholding Tax')
        ->assertSee('345.67')
        ->assertSee('12,000.00')
        // The row opens the payslip at the one payslip address.
        ->assertSeeHtml(route('payroll.payslip', ['id' => $run->id, 'employeeId' => $employee->id]))
        // Nothing on it writes.
        ->assertDontSeeHtml('wire:click="save"')
        ->assertDontSeeHtml('wire:click="finalize"')
        ->assertDontSeeHtml('wire:click="openPicker"');

    // What it posted still exports.
    $page->call('exportCsv')->assertFileDownloaded();

    // The actions are callable without the buttons, and change nothing.
    $audits = AuditLog::query()->count();
    $page->set('name', 'Renamed')->call('save')->call('finalize')->call('removeMember', $employee->id)->call('save');

    expect($run->refresh()->only(['name', 'status', 'kind']))->toBe(['name' => 'Payroll PR-13', 'status' => 'FINALIZED', 'kind' => 'THIRTEENTH_MONTH'])
        ->and(PayrollRunMember::query()->where('run_id', $run->id)->count())->toBe(1)
        ->and($payslip->refresh()->getRawOriginal())->toEqual($posted)
        ->and(Payslip::query()->where('run_id', $run->id)->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe($audits);
});

it('shows a 13th month payroll its statutory columns, whose evidence recorded none', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    $yearly = ptmRun('PR-13', PayrollRunStatus::FINALIZED, $employee);
    $yearlySlip = ptmPayslip($yearly, $employee);
    ptmEvidence($yearly, ['kind' => 'THIRTEENTH_MONTH', 'deduction_columns' => []]);
    // A regular payroll finalized while the Payroll Deductions list was empty: its empty list stands.
    $regular = ptmRun('PR-REG', PayrollRunStatus::FINALIZED, $employee, PayrollRunKind::REGULAR);
    $regularSlip = ptmPayslip($regular, $employee);
    ptmEvidence($regular, ['kind' => 'REGULAR', 'deduction_columns' => []]);

    $calculations = static fn (string $payslipId): array => app(PayslipRecords::class)->deductionColumns($actor, [$payslipId])
        ->map(static fn (DeductionColumnView $column): ?string => $column->calculation)->all();

    expect($calculations($yearlySlip->id))->toContain(DeductionCalculation::WITHHOLDING_TAX)
        ->and($calculations($regularSlip->id))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| A draft posts nothing and goes
|--------------------------------------------------------------------------
*/

it('does not open, save or finalize a draft 13th month payroll, and still lets it be deleted', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    $draft = ptmRun('PR-13D', PayrollRunStatus::DRAFT, $employee);
    $audits = AuditLog::query()->count();

    Livewire::test(RunWorkspace::class, ['id' => $draft->id])
        ->assertSet('missing', true)
        ->assertSee('That payroll no longer exists.')
        ->assertDontSee('Payroll PR-13D')
        ->call('save')
        ->call('finalize');

    // The commands refuse it themselves, called without a screen.
    expect(fn () => app(SavePayroll::class)->handle($actor, new PayrollDraft($draft->id, 'Payroll PR-13D', '2026-10-01', '2026-10-15', [$employee->id], [], [], [])))
        ->toThrow(RuntimeException::class, 'That draft is a 13th month payroll, which can no longer be saved or finalized here.');
    expect(fn () => app(FinalizePayroll::class)->handle($actor, $draft->id, null))
        ->toThrow(RuntimeException::class, 'That draft is a 13th month payroll, which can no longer be saved or finalized here.');

    expect($draft->refresh()->only(['status', 'kind', 'period_start', 'period_end']))
        ->toMatchArray(['status' => 'DRAFT', 'kind' => 'THIRTEENTH_MONTH'])
        ->and($draft->period_start->format('Y-m-d'))->toBe('2025-01-01')
        ->and(Payslip::query()->where('run_id', $draft->id)->count())->toBe(0)
        ->and(PayrollFinalizationSnapshot::query()->where('run_id', $draft->id)->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($audits);

    // It is on the list until the migration runs, and the list can delete it.
    Livewire::test(Index::class)->assertSee('Payroll PR-13D')->call('confirmDelete', $draft->id)->call('deleteConfirmed');
    expect(PayrollRun::query()->whereKey($draft->id)->exists())->toBeFalse();
});

it('saves every new payroll as a regular one', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();

    $saved = app(SavePayroll::class)->handle($actor, new PayrollDraft(null, 'October first half', '2026-10-01', '2026-10-15', [$employee->id], [], [], []));

    expect(PayrollRun::query()->whereKey($saved['id'])->value('kind'))->toBe('REGULAR');
});

it('deletes draft 13th month payrolls in the migration and nothing else, twice over', function () {
    ['employee' => $employee, 'run' => $regularDraft] = PayrollEvidenceFixture::make();
    $draft = ptmRun('PR-13D', PayrollRunStatus::DRAFT, $employee);
    $finalized = ptmRun('PR-13F', PayrollRunStatus::FINALIZED, $employee, year: 2024);
    $payslip = ptmPayslip($finalized, $employee);
    // Cannot happen, and is never deleted: a draft that holds a payslip, and one that holds evidence.
    $withPayslip = ptmRun('PR-13P', PayrollRunStatus::DRAFT, $employee, year: 2023);
    ptmPayslip($withPayslip, $employee);
    $withEvidence = ptmRun('PR-13E', PayrollRunStatus::DRAFT, $employee, year: 2022);
    ptmEvidence($withEvidence, ['kind' => 'THIRTEENTH_MONTH']);

    $migration = require base_path('database/migrations/2026_11_24_0005_delete_draft_thirteenth_month_runs_from_payroll_runs_table.php');
    $migration->up();

    $left = static fn (): array => PayrollRun::query()->orderBy('code')->pluck('code')->all();
    expect($left())->toBe(['PR-13E', 'PR-13F', 'PR-13P', 'PR-EVID'])
        // The draft's members went with it; everyone else's stayed.
        ->and(PayrollRunMember::query()->where('run_id', $draft->id)->count())->toBe(0)
        ->and(PayrollRunMember::query()->count())->toBe(4)
        ->and($regularDraft->refresh()->status)->toBe('DRAFT')
        ->and($finalized->refresh()->only(['status', 'kind']))->toBe(['status' => 'FINALIZED', 'kind' => 'THIRTEENTH_MONTH'])
        ->and(Payslip::query()->whereKey($payslip->id)->exists())->toBeTrue()
        ->and(Payslip::query()->count())->toBe(2);

    // Retry-safe: a second run finds nothing to delete; rollback restores nothing.
    $migration->up();
    $migration->down();
    expect($left())->toBe(['PR-13E', 'PR-13F', 'PR-13P', 'PR-EVID'])
        ->and(PayrollRunMember::query()->count())->toBe(4);
});
