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

/** Reports compose current custody and movement evidence without deciding financial liability. */
#[Title('Inventory reports')]
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

        return view('livewire.inventory.reports-page', [
            'rows' => $this->acceptPage(InventoryReportRows::arrange($access, ['report' => $this->report, 'q' => $this->search, 'employee' => $this->employeeId], (int) $this->getPage(), $this->perPage)),
            'columns' => $query->columns($this->report),
            'totals' => $query->totals($access),
        ]);
    }
}
