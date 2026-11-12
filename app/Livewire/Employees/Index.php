<?php

declare(strict_types=1);

namespace App\Livewire\Employees;

use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Kit\Support\DepartmentFilter;
use App\Models\Position;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Support\Access;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Employee Records — the port of
 * `src/app/(protected)/employees/employees-page.tsx`.
 *
 * The table is one page from the database: `EmployeeRecords::page()` scopes,
 * filters, searches, sorts and paginates in SQL with the same rules the
 * source applies in the client over the fetched list, and returns only that
 * page's records. The CSV reads the same rule through `arranged()`. The
 * status and position options join the catalogs with the values still held
 * on a record (`heldValues()`), read without loading the records.
 */
#[Title('Employee Records')]
class Index extends Component
{
    use WithSorting;
    use WithTableFilters;

    /** An employment status name, "INACTIVE" for separated staff, or "ALL". */
    public string $status = 'ALL';

    public string $position = 'ALL';

    /**
     * "Filter by department": ALL, NONE (Not placed), or a department or unit
     * id. A filter applied after row scoping (Workforce's PlaceFilter only
     * narrows the scoped rows), so deliberately not #[Locked].
     */
    public string $department = DepartmentFilter::ALL;

    public function mount(): void
    {
        $this->sortKey = 'employee';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['id', 'employee_id_no', 'employee', 'department', 'email', 'status'];
    }

    /** employees-page.tsx:203 — a changed filter starts over from page 1. */
    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPosition(): void
    {
        $this->resetPage();
    }

    public function updatedDepartment(): void
    {
        $this->resetPage();
    }

    /** Re-render after the add dialog created a record. */
    #[On('employee-created')]
    public function refreshList(): void
    {
        // The re-render itself is the refresh.
    }

    public function render(): View
    {
        // The rows this actor may see are EmployeeRecords' visibility rule —
        // the full-directory tier of GET /api/employees
        // (api/employees/route.ts:38-44): every row for `all_data` +
        // EMPLOYEES/PAYROLL, the actor's OWN record otherwise, and NO rows for
        // an account with no employee link.
        $access = app(Access::class);
        $records = app(EmployeeRecords::class);
        $held = $records->heldValues($access);

        // The list Records Management holds, plus any value still on a record
        // but no longer on the list (employees-page.tsx:112-131).
        $positions = array_values(array_unique([...app(OrganizationDirectory::class)->positions($access, false), ...$held['positions']]));
        usort($positions, EmployeeFields::compareText(...));

        $statuses = $records->statuses($access, false);

        foreach ($held['statuses'] as $status) {
            $statuses[] = EmployeeFields::employmentStatusLabel($status);
        }

        $statuses = array_values(array_unique($statuses));
        usort($statuses, EmployeeFields::compareText(...));

        // A department that has since been deleted (or a value typed in by
        // hand) falls back to every department instead of an empty list.
        $departments = DepartmentFilter::options($access);
        $this->department = DepartmentFilter::choice($this->department, $departments);

        // Filter and sort (employees-page.tsx:133-177) in the owner query,
        // the same rule the CSV export reads.
        $list = $this->acceptPage($records->page($access, [
            'search' => $this->search, 'status' => $this->status,
            'position' => $this->position, 'department' => $this->department,
        ], $this->sortKey, $this->sortDir, (int) $this->getPage(), $this->perPage));

        return view('livewire.employees.index', [
            'list' => $list,
            'visibleCount' => $list->total(),
            'statuses' => $statuses,
            'positions' => $positions,
            'departments' => $departments,
        ]);
    }
}
