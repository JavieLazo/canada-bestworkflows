<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Records\Support\EmployeePaySettingsRows;
use App\Support\Access;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Employee Pay Settings — who is paid differently from the standard
 * computation, under Records → Payroll Settings. One row per employee, its
 * contributions and tax in one phrase naming only what differs and its
 * hourly rate in another; a row opens the employee's own page, where the
 * settings are edited (EmployeePaySettingsShow). By default the list shows
 * every active employee.
 *
 * Like Incentives & Bonuses the page opens for RECORDS or PAYROLL, but what
 * it lists is pay data (SEC-01): only an all-data PAYROLL holder sees the
 * rows; anyone else gets a notice. Read-only here — no action writes.
 */
#[Layout('layouts.app')]
#[Title('Employee Pay Settings')]
class EmployeePaySettingsPage extends Component
{
    use WithSorting;
    use WithTableFilters;

    /** Only the employees whose settings differ from the standard computation. A filter. */
    public bool $onlyCustomized = false;

    /** Only the active employees. A filter. */
    public bool $activeOnly = true;

    public function mount(): void
    {
        $this->sortKey = 'name';
    }

    /** A narrower or wider filter starts over from page 1. */
    public function updated(string $name): void
    {
        if (in_array($name, ['search', 'onlyCustomized', 'activeOnly'], true)) {
            $this->resetPage();
        }
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['name', 'status'];
    }

    public function render(): View
    {
        $access = app(Access::class);
        $canRead = $access->mayReadAllData(ModuleCode::PAYROLL);
        // Everyone the search and Active only leave in view: the figures above the table count them all, and
        // Only customized then narrows the table alone.
        $listed = $canRead
            ? EmployeePaySettingsRows::list($access, $this->search, false, $this->activeOnly, $this->sortKey, $this->sortDir)
            : [];
        $shown = $this->onlyCustomized
            ? array_values(array_filter($listed, EmployeePaySettingsRows::differs(...)))
            : $listed;

        return view('livewire.records.employee-pay-settings-page', [
            'canRead' => $canRead,
            'summary' => EmployeePaySettingsRows::summary($listed),
            'rows' => $canRead ? $this->paginateClamped($shown) : new LengthAwarePaginator([], 0, max(1, $this->perPage)),
        ]);
    }
}
