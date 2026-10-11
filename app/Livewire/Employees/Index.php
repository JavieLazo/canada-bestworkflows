<?php

declare(strict_types=1);

namespace App\Livewire\Employees;

use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Employees\Support\EmployeeRows;
use App\Livewire\Kit\Support\DepartmentFilter;
use App\Models\Position;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Modules\Workforce\Contracts\Views\EmployeeProfileView as EmployeeProfile;
use App\Support\Access;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Employee Records — the port of
 * `src/app/(protected)/employees/employees-page.tsx`.
 *
 * Filtering, the status/position option unions and the sorts all run in PHP
 * over the scoped collection, exactly as the source runs them in the client
 * over the fetched list — `statusKey` comparison and the legacy-token → label
 * mapping cannot be pushed into SQL. The department filter and the
 * Department column read the placement on each view, so they run there too.
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
     * id. A filter applied after row scoping (DepartmentFilter only narrows
     * the scoped rows), so deliberately not #[Locked].
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

    /**
     * The rows this actor may see — the full-directory tier of
     * GET /api/employees (api/employees/route.ts:38-44): every row for
     * `all_data` + EMPLOYEES/PAYROLL, the actor's OWN record otherwise, and
     * NO rows for an account with no employee link. The scope column here is
     * the primary key, so the narrowing goes through Access's three-state
     * answer directly rather than through ScopesToActor (which narrows on a
     * foreign key).
     *
     * @return Collection<int, EmployeeProfile>
     */
    private function visibleEmployees(Access $access): Collection
    {
        return app(EmployeeRecords::class)->list($access);
    }

    public function render(): View
    {
        $access = app(Access::class);
        $employees = $this->visibleEmployees($access);

        // The list Records Management holds, plus any value still on a record
        // but no longer on the list (employees-page.tsx:112-131).
        $positions = app(OrganizationDirectory::class)->positions($access, false);

        foreach ($employees as $employee) {
            if ($employee->position !== null && $employee->position !== '') {
                $positions[] = $employee->position;
            }
        }

        $positions = array_values(array_unique($positions));
        usort($positions, EmployeeFields::compareText(...));

        $statuses = app(EmployeeRecords::class)->statuses($access, false);

        foreach ($employees as $employee) {
            if ($employee->employment_status !== '') {
                $statuses[] = EmployeeFields::employmentStatusLabel($employee->employment_status);
            }
        }

        $statuses = array_values(array_unique($statuses));
        usort($statuses, EmployeeFields::compareText(...));

        // A department that has since been deleted (or a value typed in by
        // hand) falls back to every department instead of an empty list.
        $departments = DepartmentFilter::options($access);
        $this->department = DepartmentFilter::choice($this->department, $departments);

        // Filter and sort (employees-page.tsx:133-177): one copy shared with
        // the CSV export.
        $rows = EmployeeRows::arrange($employees, $this->search, $this->status, $this->position, $this->department, $this->sortKey, $this->sortDir);

        $list = $this->paginateClamped(new Collection($rows));

        return view('livewire.employees.index', [
            'list' => $list,
            'visibleCount' => count($rows),
            'statuses' => $statuses,
            'positions' => $positions,
            'departments' => $departments,
        ]);
    }
}
