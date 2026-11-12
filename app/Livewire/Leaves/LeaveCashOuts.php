<?php

declare(strict_types=1);

namespace App\Livewire\Leaves;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\TableDefaults;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Modules\Timekeeping\Application\Commands\FileTerminalLeave;
use App\Modules\Timekeeping\Application\Commands\ReviewLeaveCashOut;
use App\Modules\Timekeeping\Application\Queries\LeaveCashOutRecords;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

/**
 * Leave Management's Cash conversions tab: every request to cash leave
 * credits out that the actor may see — pending first — and, for an
 * all-data LEAVES holder, the approve / reject dialog and the File terminal
 * leave dialog (an employee who has left: the picker, then what would be
 * paid out). A child of Leaves\Index with its own search, status filter and
 * page name. Reads go through LeaveCashOutRecords behind the leave list's
 * scope; the writes are ReviewLeaveCashOut and FileTerminalLeave, which
 * gate, re-resolve and audit for themselves.
 */
class LeaveCashOuts extends Component
{
    use WithSorting;
    use WithTableFilters;

    /** 'PENDING' by default; 'ALL' or any status. A filter applied after row scoping. */
    public string $status = LeaveCashOutRecords::PENDING;

    /** The request the review dialog decides. Id-bearing → #[Locked]; set only by openReview(). */
    #[Locked]
    public ?string $reviewingId = null;

    public bool $reviewOpen = false;

    public bool $reviewApprove = true;

    public string $reviewNote = '';

    public bool $terminalOpen = false;

    /** The employee terminal leave is filed for, set only by the picker through employeePicked(). Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $terminalEmployeeId = null;

    public string $terminalNote = '';

    public function mount(): void
    {
        $this->sortKey = 'requested';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['requested'];
    }

    public function updatedStatus(string $value): void
    {
        $this->status = $value === 'ALL' || in_array($value, LeaveCashOutRecords::STATUSES, true) ? $value : LeaveCashOutRecords::PENDING;
        $this->resetPage(LeaveCashOutRecords::PAGE_NAME);
    }

    /** The trait's hook resets the default page name; this table has its own. */
    public function updatedPerPage(mixed $value): void
    {
        $size = (int) $value;
        $this->perPage = in_array($size, TableDefaults::PAGE_SIZE_OPTIONS, true) ? $size : TableDefaults::DEFAULT_PAGE_SIZE;
        $this->resetPage(LeaveCashOutRecords::PAGE_NAME);
    }

    public function updatedSearch(): void
    {
        $this->resetPage(LeaveCashOutRecords::PAGE_NAME);
    }

    public function openReview(string $id, bool $approve): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::LEAVES)) {
            return;
        }
        $row = app(LeaveCashOutRecords::class)->find($access, $id);
        if ($row === null || $row->status !== LeaveCashOutRecords::PENDING) {
            return;
        }
        $this->reviewingId = $row->id;
        $this->reviewApprove = $approve;
        $this->reviewNote = '';
        $this->reviewOpen = true;
    }

    public function confirmReview(): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::LEAVES)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }
        if ($this->reviewingId === null) {
            return;
        }
        try {
            $message = app(ReviewLeaveCashOut::class)->handle($access, $this->reviewingId, $this->reviewApprove, $this->reviewNote);
        } catch (QueryException) {
            Flux::toast(variant: 'danger', text: 'The decision could not be saved. Try again.');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        $this->reviewOpen = false;
        $this->reviewingId = null;
        $this->reviewNote = '';
        Flux::toast(variant: 'success', text: $message);
    }

    public function openTerminal(): void
    {
        if (! app(Access::class)->mayReadAllData(ModuleCode::LEAVES)) {
            return;
        }
        $this->terminalEmployeeId = null;
        $this->terminalNote = '';
        $this->terminalOpen = true;
    }

    /** The picker's choice, kept only when the actor may file terminal leave for that employee. */
    #[On('kit-employee-picked')]
    public function employeePicked(string $name, string $id): void
    {
        $access = app(Access::class);
        if ($name !== 'terminal-employee' || ! $access->mayReadAllData(ModuleCode::LEAVES)) {
            return;
        }
        $this->terminalEmployeeId = app(LeaveCashOutRecords::class)->terminal($access, $id) === null ? null : $id;
    }

    public function fileTerminal(): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::LEAVES)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }
        if ($this->terminalEmployeeId === null) {
            Flux::toast(variant: 'danger', text: 'Choose the employee who left.');

            return;
        }
        try {
            $message = app(FileTerminalLeave::class)->handle($access, $this->terminalEmployeeId, $this->terminalNote);
        } catch (QueryException) {
            Flux::toast(variant: 'danger', text: 'Terminal leave could not be filed. Try again.');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        $this->terminalOpen = false;
        $this->terminalEmployeeId = null;
        $this->terminalNote = '';
        $this->status = LeaveCashOutRecords::PENDING;
        $this->resetPage(LeaveCashOutRecords::PAGE_NAME);
        Flux::toast(variant: 'success', text: $message);
    }

    public function render(): View
    {
        $access = app(Access::class);
        $records = app(LeaveCashOutRecords::class);
        $rows = $this->acceptPage($records->page($access, $this->status, trim($this->search), $this->getPage(LeaveCashOutRecords::PAGE_NAME), $this->perPage), LeaveCashOutRecords::PAGE_NAME);
        $reviewing = $this->reviewingId === null ? null : $records->find($access, $this->reviewingId);

        return view('livewire.leaves.leave-cash-outs', [
            'rows' => $rows,
            'ready' => LeaveCashOutRecords::ready(),
            'canManage' => $access->mayReadAllData(ModuleCode::LEAVES),
            'ownEmployeeId' => $access->hasEmployeeLink() ? $access->ownEmployeeId() : null,
            'reviewing' => $reviewing,
            'terminalReady' => LeaveCashOutRecords::terminalReady(),
            'terminal' => $this->terminalOpen && $this->terminalEmployeeId !== null ? $records->terminal($access, $this->terminalEmployeeId) : null,
        ]);
    }
}
