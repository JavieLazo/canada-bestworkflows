<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithChangeStamp;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Inventory\Support\InventoryReportRows;
use App\Modules\Inventory\Application\Queries\InventoryReports;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Reports compose current custody and movement evidence without deciding
 * financial liability. The three item reports also say what their items add
 * up to at acquisition cost (`InventoryReports::value`), read under the same
 * filters as the rows; an item with no cost recorded adds nothing and is
 * counted in a note.
 */
#[Title('Inventory Reports')]
class ReportsPage extends Component
{
    use WithChangeStamp;
    use WithTableFilters;

    public string $report = 'holdings';

    /** Read filter applied after manager authorization, deliberately not Locked. */
    public string $employeeId = '';

    #[On('kit-employee-picked')]
    public function employeePicked(string $name, string $id): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        if ($name === 'inventory-report-employee' && app(OperationalDirectory::class)->find($access, $id) !== null) {
            $this->employeeId = $id;
            $this->resetPage();
        }
    }

    public function clearEmployee(): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        $this->employeeId = '';
        $this->resetPage();
    }

    protected function changeStamp(): string
    {
        return app(InventoryReports::class)->changeStamp(app(Access::class));
    }

    public function render(): View
    {
        $this->markStampRendered();
        $access = app(Access::class);
        $query = app(InventoryReports::class);
        $filters = ['report' => $this->report, 'q' => $this->search, 'employee' => $this->employeeId];

        return view('livewire.inventory.reports-page', [
            'rows' => $this->acceptPage(InventoryReportRows::arrange($access, $filters, (int) $this->getPage(), $this->perPage)),
            'columns' => $query->columns($this->report),
            'totals' => $query->totals($access),
            // What every row of the report under these filters adds up to,
            // not the page shown; null for the movement history.
            'value' => InventoryReportRows::value($access, $filters),
        ]);
    }
}
