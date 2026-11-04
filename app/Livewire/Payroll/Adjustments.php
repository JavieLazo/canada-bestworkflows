<?php

declare(strict_types=1);

namespace App\Livewire\Payroll;

use App\Domain\Enums\ModuleCode;
use App\Modules\Payroll\Application\Adjustments as AdjustmentLedger;
use App\Modules\Payroll\Application\Commands\DiscardAdjustment;
use App\Modules\Payroll\Application\Commands\ReviewAdjustment;
use App\Modules\Payroll\Application\Commands\SaveAdjustment;
use App\Modules\Payroll\Application\Queries\AdjustmentRecords;
use App\Modules\Payroll\Contracts\Views\AdjustmentView;
use App\Modules\Payroll\Domain\Adjustments\AdjustmentRule;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Payroll → Adjustments: the ledger of differences paid or recovered on a
 * later payroll. The list (status filter, search), an Add/Correct dialog
 * (employee, category, amount, reason, reference, optionally the open
 * payroll it goes on) that prepares a PENDING line (SaveAdjustment), a
 * review dialog that approves or rejects it with a note (ReviewAdjustment;
 * the preparer may do it themselves) — and a discard of one's pending
 * line (DiscardAdjustment). An all-data PAYROLL holder sees everyone's
 * lines; a linked PAYROLL holder their own, read-only. The next payroll
 * finalized with the employee on it pays an approved line.
 */
#[Layout('layouts.app')]
#[Title('Adjustments')]
class Adjustments extends Component
{
    public string $status = AdjustmentRule::PENDING;

    public string $search = '';

    /** The add/correct dialog's visibility. Plain UI state. */
    public bool $dialogOpen = false;

    /** The line being corrected, or null when adding. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $editingId = null;

    /** The picked employee. Id-bearing → #[Locked]; set only by the picker's event, re-checked by the command. */
    #[Locked]
    public string $formEmployeeId = '';

    /** The category the select binds. Not Locked: the command re-resolves it (a sanctioned wire:model draft). */
    public string $formCategoryId = '';

    /** The open payroll the select binds, '' for the next one. Not Locked: the command re-resolves it (a sanctioned wire:model draft). */
    public string $formTargetRunId = '';

    public string $formAmount = '';

    public string $formReason = '';

    public string $formReference = '';

    public ?string $formError = null;

    /** The review dialog's visibility. Plain UI state. */
    public bool $reviewOpen = false;

    /** The line under review. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $reviewingId = null;

    public string $reviewNote = '';

    /** The open payroll the approver picks, '' for the next one. Not Locked: a select draft the command re-resolves. */
    public string $reviewTargetRunId = '';

    public ?string $reviewError = null;

    /** The discard confirmation's visibility. Plain UI state. */
    public bool $discardOpen = false;

    /** The line queued for discarding. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $discardingId = null;

    /** Any edit clears the last refusal. */
    public function updated(string $name): void
    {
        if (str_starts_with($name, 'form')) {
            $this->formError = null;
        }
        if (str_starts_with($name, 'review')) {
            $this->reviewError = null;
        }
    }

    /** A pick in the employee search names the line's employee; re-checked by the command on save. */
    #[On('kit-employee-picked')]
    public function employeePicked(string $name, string $id): void
    {
        if ($name !== 'adjustment-employee' || ! app(Access::class)->mayReadAllData(ModuleCode::PAYROLL)) {
            return;
        }
        $this->formEmployeeId = $id;
        $this->formError = null;
    }

    public function openAdd(): void
    {
        if (! app(Access::class)->mayReadAllData(ModuleCode::PAYROLL)) {
            return;
        }
        $this->editingId = null;
        $this->formEmployeeId = '';
        $this->formCategoryId = '';
        $this->formTargetRunId = '';
        $this->formAmount = '';
        $this->formReason = '';
        $this->formReference = '';
        $this->formError = null;
        $this->dialogOpen = true;
    }

    public function startEdit(string $id): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            return;
        }
        $line = app(AdjustmentRecords::class)->find($access, $id);
        if ($line === null || $line->status !== AdjustmentRule::PENDING) {
            Flux::toast(variant: 'danger', text: 'Only a pending adjustment can be corrected.');

            return;
        }
        $this->editingId = $line->id;
        $this->formEmployeeId = $line->employee_id;
        $this->formCategoryId = $line->category_id;
        $this->formTargetRunId = $line->target_run_id ?? '';
        $this->formAmount = number_format($line->amount, 2, '.', '');
        $this->formReason = $line->reason;
        $this->formReference = $line->reference ?? '';
        $this->formError = null;
        $this->dialogOpen = true;
    }

    public function save(): void
    {
        $access = app(Access::class);
        try {
            app(SaveAdjustment::class)->handle($access, $this->editingId, $this->formEmployeeId, $this->formCategoryId, $this->formAmount, $this->formReason, $this->formReference, $this->formTargetRunId);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The adjustment could not be saved. Nothing was changed. Please try again.');

            return;
        } catch (RuntimeException $e) {
            $this->formError = $e->getMessage();

            return;
        }
        Flux::toast(variant: 'success', text: $this->editingId === null ? 'Adjustment prepared. Approve it and the next payroll pays it.' : 'Adjustment corrected.');
        $this->dialogOpen = false;
        $this->editingId = null;
        $this->status = AdjustmentRule::PENDING;
    }

    public function openReview(string $id): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            return;
        }
        $line = app(AdjustmentRecords::class)->find($access, $id);
        if ($line === null || $line->status !== AdjustmentRule::PENDING) {
            Flux::toast(variant: 'danger', text: 'That adjustment was already reviewed.');

            return;
        }
        $this->reviewingId = $line->id;
        $this->reviewNote = '';
        $this->reviewTargetRunId = $line->target_run_id ?? '';
        $this->reviewError = null;
        $this->reviewOpen = true;
    }

    public function approve(): void
    {
        $this->review(true);
    }

    public function reject(): void
    {
        $this->review(false);
    }

    public function confirmDiscard(string $id): void
    {
        if (! app(Access::class)->mayReadAllData(ModuleCode::PAYROLL)) {
            return;
        }
        $this->discardingId = $id;
        $this->discardOpen = true;
    }

    public function discard(): void
    {
        $id = $this->discardingId;
        $this->discardOpen = false;
        $this->discardingId = null;
        if ($id === null) {
            return;
        }
        try {
            $message = app(DiscardAdjustment::class)->handle(app(Access::class), $id);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The adjustment could not be discarded. Try again.');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: $message);
    }

    public function render(): View
    {
        $access = app(Access::class);
        $records = app(AdjustmentRecords::class);
        $canManage = $access->mayReadAllData(ModuleCode::PAYROLL);
        $rows = $records->list($access, $this->status, $this->search);

        return view('livewire.payroll.adjustments', [
            'rows' => $rows,
            'ready' => AdjustmentLedger::ready(),
            'canManage' => $canManage,
            'categories' => $canManage ? $records->categories($access) : collect(),
            'openRuns' => $canManage ? $records->openRuns($access) : [],
            'reviewing' => $this->reviewingId === null ? null : $rows->first(fn (AdjustmentView $row): bool => $row->id === $this->reviewingId),
            'discarding' => $this->discardingId === null ? null : $rows->first(fn (AdjustmentView $row): bool => $row->id === $this->discardingId),
            'statuses' => ['ALL' => 'All', AdjustmentRule::PENDING => 'Awaiting approval', AdjustmentRule::APPROVED => 'Approved, not yet paid', AdjustmentRule::POSTED => 'Paid', AdjustmentRule::REJECTED => 'Rejected'],
        ]);
    }

    private function review(bool $approve): void
    {
        $access = app(Access::class);
        if ($this->reviewingId === null) {
            return;
        }
        try {
            $message = app(ReviewAdjustment::class)->handle($access, $this->reviewingId, $approve, $this->reviewNote, $this->reviewTargetRunId);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The adjustment could not be reviewed. Try again.');

            return;
        } catch (RuntimeException $e) {
            $this->reviewError = $e->getMessage();

            return;
        }
        Flux::toast(variant: 'success', text: $message);
        $this->reviewOpen = false;
        $this->reviewingId = null;
    }
}
