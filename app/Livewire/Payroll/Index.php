<?php

declare(strict_types=1);

namespace App\Livewire\Payroll;

use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Payroll\Support\PayrollRunRows;
use App\Modules\Payroll\Application\Commands\DeletePayroll;
use App\Modules\Payroll\Application\Queries\PayrollRecords;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Payroll — the run list, the port of payroll/payroll-page.tsx.
 *
 * Every payroll, draft or finalized. Open one to work on it, or create a new
 * cut-off. Filtering, sorting and pagination run over the loaded list in PHP,
 * mirroring the source's client-side useMemo pipeline — including `sort()`
 * then a whole-list `reverse()` for descending.
 *
 * DELIBERATE DIVERGENCE, the one this module carries: the reference happily
 * hard-deletes a FINALIZED run, payslips and all (DELETE /api/payroll/runs/[id]
 * even documents it: "the employees who were notified lose the payslip the
 * notification points at"). That is a documented bug, not a feature. Here a
 * finalized run is IMMUTABLE — the delete action refuses it and the row's
 * delete button is disabled — so a posted payslip can never silently vanish
 * from an employee's portal. Only DRAFT runs delete.
 *
 * It lists ONE kind of run: the sidebar's "Basic Payroll" (/payroll,
 * REGULAR) or "13th Month Payroll" (/payroll/thirteenth-month,
 * THIRTEENTH_MONTH — the route passes the kind), each opening and creating
 * its own workspace.
 */
#[Layout('layouts.app')]
#[Title('Payroll')]
class Index extends Component
{
    use WithSorting;
    use WithTableFilters;

    /** The status filter's rows (payroll-page.tsx:35-38), in order. */
    public const array STATUSES = [
        'DRAFT' => 'Draft',
        'FINALIZED' => 'Finalized',
    ];

    /** Status filter. Plain filter state, deliberately unlocked. */
    public string $status = 'ALL';

    /** Which runs this list shows — from the route, never the client. #[Locked]. */
    #[Locked]
    public string $kind = PayrollRunKind::REGULAR;

    /** The run the delete dialog is asking about. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $deletingId = null;

    /** Whether the delete dialog is showing. Flipping it reveals nothing. */
    public bool $confirmingDelete = false;

    public function mount(string $kind = PayrollRunKind::REGULAR): void
    {
        $this->kind = in_array($kind, PayrollRunKind::all(), true) ? $kind : PayrollRunKind::REGULAR;

        // useSort<SortKey>("from", "desc") — payroll-page.tsx:48.
        $this->sortKey = 'from';
        $this->sortDir = 'desc';
    }

    /**
     * DELIBERATE DIVERGENCE — `id`. The reference has no column for the run
     * code, which it prints under the payroll name; this list gives it its own
     * sortable column, because the code is what an operator quotes and a
     * number buried under a name cannot be sorted on.
     *
     * @return list<string>
     */
    protected function sortableKeys(): array
    {
        return ['id', 'name', 'from', 'to', 'employees', 'status'];
    }

    /** The status select resets to page 1 (payroll-page.tsx:124-127); search relies on the clamp. */
    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /** Closing the dialog (esc, backdrop, Cancel) forgets the pending row. */
    public function updatedConfirmingDelete(mixed $value): void
    {
        if (! $value) {
            $this->deletingId = null;
        }
    }

    /** The row's trash button — arms the confirm dialog (payroll-page.tsx:207-216). */
    public function confirmDelete(string $id): void
    {
        $run = app(PayrollRecords::class)->find(app(Access::class), $id);

        if ($run === null) {
            return;
        }

        // Divergence (see class docblock): a finalized run never reaches the
        // dialog — its payslips are posted and must stay.
        if ($run->status === PayrollRunStatus::FINALIZED) {
            Flux::toast(text: 'A finalized payroll is immutable and cannot be deleted.', variant: 'danger');

            return;
        }

        $this->deletingId = $id;
        $this->confirmingDelete = true;
    }

    /**
     * Delete the armed DRAFT run — the port of DELETE /api/payroll/runs/[id],
     * minus its finalized-run branch (refused here, see the class docblock).
     * Members cascade with the run; a draft has no payslips to take along.
     */
    public function deleteConfirmed(): void
    {
        $actor = app(Access::class);
        if ($this->deletingId === null) {
            return;
        }
        try {
            $name = app(DeletePayroll::class)->handle($actor, $this->deletingId);
            Flux::toast(text: "{$name} deleted.", variant: 'success');
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: 'That payroll could not be deleted. Try again.', variant: 'danger');
        } catch (\RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');
        }
        $this->confirmingDelete = false;
        $this->deletingId = null;
    }

    public function render(): View
    {
        $actor = app(Access::class);

        // GET /api/payroll/runs requires all_data + PAYROLL; without it the
        // reference page's fetch 403s and the table stays empty. Same here.
        $runs = app(PayrollRecords::class)->list($actor, $this->kind);

        // The filter pipeline and sort (payroll-page.tsx:53-76): one copy,
        // shared with the CSV export.
        $sorted = PayrollRunRows::arrange($runs, $this->search, $this->status, $this->sortKey, $this->sortDir);

        $paginator = $this->paginateClamped($sorted);

        $deleting = $this->deletingId !== null
            ? $runs->firstWhere('id', $this->deletingId)
            : null;

        $yearly = $this->kind === PayrollRunKind::THIRTEENTH_MONTH;

        return view('livewire.payroll.index', [
            'rows' => $paginator,
            'total' => $sorted->count(),
            'deleting' => $deleting,
            'yearly' => $yearly,
            'heading' => PayrollRunKind::LABELS[$this->kind],
            'createRoute' => $yearly ? 'payroll.thirteenth.new' : 'payroll.new',
            'showRoute' => $yearly ? 'payroll.thirteenth.show' : 'payroll.show',
        ])->title(PayrollRunKind::LABELS[$this->kind]);
    }
}
