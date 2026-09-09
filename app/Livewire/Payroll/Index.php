<?php

declare(strict_types=1);

namespace App\Livewire\Payroll;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunStatus;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Payroll\Support\PayrollData;
use App\Models\PayrollRun;
use App\Support\Access;
use App\Support\AuditService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    /** The run the delete dialog is asking about. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $deletingId = null;

    /** Whether the delete dialog is showing. Flipping it reveals nothing. */
    public bool $confirmingDelete = false;

    public function mount(): void
    {
        // useSort<SortKey>("from", "desc") — payroll-page.tsx:48.
        $this->sortKey = 'from';
        $this->sortDir = 'desc';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['name', 'from', 'to', 'employees', 'status'];
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
        $run = PayrollRun::query()->whereKey($id)->first();

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
    public function delete(): void
    {
        $actor = app(Access::class);

        // requireAllDataModule("PAYROLL") — route middleware is not enough:
        // this action is reachable without the page load.
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            Flux::toast(text: 'Your role cannot manage organization-wide data.', variant: 'danger');

            return;
        }

        if ($this->deletingId === null) {
            return;
        }

        $id = $this->deletingId;

        try {
            $run = DB::transaction(function () use ($id): PayrollRun {
                PayrollData::lockMembership();

                $existing = PayrollRun::query()->whereKey($id)->first();

                if ($existing === null) {
                    throw new \RuntimeException('That payroll no longer exists.');
                }

                if ($existing->status === PayrollRunStatus::FINALIZED) {
                    // Divergence: the reference deletes finalized runs and their
                    // posted payslips. Refused — finalized runs are immutable.
                    throw new \RuntimeException('A finalized payroll is immutable and cannot be deleted.');
                }

                $deleted = PayrollRun::query()
                    ->whereKey($id)
                    ->where('status', PayrollRunStatus::DRAFT)
                    ->delete();

                if ($deleted === 0) {
                    throw new \RuntimeException('That payroll changed before deletion completed.');
                }

                return $existing;
            });
        } catch (\RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');
            $this->confirmingDelete = false;
            $this->deletingId = null;

            return;
        }

        AuditService::record(
            $actor,
            AuditEvent::PAYROLL_RUN_DELETED,
            'payroll_runs',
            $run->id,
            "{$run->name} (".strtolower($run->status).') deleted',
        );

        Flux::toast(text: "{$run->name} deleted.", variant: 'success');

        $this->confirmingDelete = false;
        $this->deletingId = null;
    }

    public function render(): View
    {
        $actor = app(Access::class);

        // GET /api/payroll/runs requires all_data + PAYROLL; without it the
        // reference page's fetch 403s and the table stays empty. Same here.
        $runs = $actor->mayReadAllData(ModuleCode::PAYROLL)
            ? PayrollRun::query()->withCount('members')->orderByDesc('period_start')->get()
            : new Collection;

        // The filter pipeline (payroll-page.tsx:53-59).
        $needle = mb_strtolower(trim($this->search));

        $visible = $runs->filter(function (PayrollRun $r) use ($needle): bool {
            if ($this->status !== 'ALL' && $r->status !== $this->status) {
                return false;
            }

            return $needle === ''
                || str_contains(mb_strtolower("{$r->code} {$r->name}"), $needle);
        })->values();

        // The sort (payroll-page.tsx:61-76) — comparator, then a whole-list
        // reverse for descending, exactly as the source did it.
        $sorted = $visible->sort(function (PayrollRun $a, PayrollRun $b): int {
            return match ($this->sortKey) {
                'employees' => $a->members_count <=> $b->members_count,
                'to' => PayrollData::compareText($a->period_end->format('Y-m-d'), $b->period_end->format('Y-m-d')),
                'status' => PayrollData::compareText($a->status, $b->status),
                'name' => PayrollData::compareText($a->name, $b->name),
                default => PayrollData::compareText($a->period_start->format('Y-m-d'), $b->period_start->format('Y-m-d')),
            };
        })->values();

        if ($this->sortDir === 'desc') {
            $sorted = $sorted->reverse()->values();
        }

        $paginator = $this->paginateClamped($sorted);

        $deleting = $this->deletingId !== null
            ? $runs->firstWhere('id', $this->deletingId)
            : null;

        return view('livewire.payroll.index', [
            'rows' => $paginator,
            'total' => $sorted->count(),
            'deleting' => $deleting,
        ]);
    }
}
