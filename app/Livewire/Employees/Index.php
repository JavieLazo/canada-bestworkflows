<?php

declare(strict_types=1);

namespace App\Livewire\Employees;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\Position;
use App\Support\Access;
use App\Support\EmployeeName;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Employee Records — the port of
 * `src/app/(protected)/employees/employees-page.tsx`.
 *
 * Filtering, the status/position option unions and the four sorts all run in
 * PHP over the scoped collection, exactly as the source ran them in the
 * client over the fetched list — `statusKey` comparison and the
 * legacy-token → label mapping cannot be pushed into SQL.
 */
#[Title('Employee Records')]
class Index extends Component
{
    use WithSorting;
    use WithTableFilters;

    /** An employment status name, "INACTIVE" for separated staff, or "ALL". */
    public string $status = 'ALL';

    public string $position = 'ALL';

    public function mount(): void
    {
        $this->sortKey = 'employee';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['id', 'employee', 'email', 'status'];
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
        $query = EmployeeProfile::query()->orderBy('last_name');

        $query = match ($access->rowVisibility(ModuleCode::EMPLOYEES, ModuleCode::PAYROLL)) {
            Access::ROWS_ALL => $query,
            Access::ROWS_OWN => $query->whereKey($access->ownEmployeeId()),
            Access::ROWS_NONE => $query->whereRaw('1 = 0'),
        };

        return $query->get();
    }

    public function render(): View
    {
        $access = app(Access::class);
        $employees = $this->visibleEmployees($access);

        // The list Records Management holds, plus any value still on a record
        // but no longer on the list (employees-page.tsx:112-131).
        $positions = Position::query()->pluck('name')->all();

        foreach ($employees as $employee) {
            if ($employee->position !== null && $employee->position !== '') {
                $positions[] = $employee->position;
            }
        }

        $positions = array_values(array_unique($positions));
        usort($positions, EmployeeFields::compareText(...));

        $statuses = EmploymentStatusOption::query()->pluck('name')->all();

        foreach ($employees as $employee) {
            if ($employee->employment_status !== '') {
                $statuses[] = EmployeeFields::employmentStatusLabel($employee->employment_status);
            }
        }

        $statuses = array_values(array_unique($statuses));
        usort($statuses, EmployeeFields::compareText(...));

        // ── Filter (employees-page.tsx:133-150) ─────────────────────────────
        $needle = mb_strtolower(trim($this->search));

        $visible = $employees->filter(function (EmployeeProfile $e) use ($needle): bool {
            if ($this->status === 'INACTIVE') {
                if ($e->is_active) {
                    return false;
                }
            } elseif ($this->status !== 'ALL'
                && EmployeeFields::statusKey($e->employment_status) !== EmployeeFields::statusKey($this->status)) {
                return false;
            }

            if ($this->position !== 'ALL' && ($e->position ?? '') !== $this->position) {
                return false;
            }

            if ($needle === '') {
                return true;
            }

            $haystack = mb_strtolower(
                "{$e->employee_code} ".EmployeeName::display($e, '').' '.($e->position ?? '').' '.($e->email ?? '')
            );

            return str_contains($haystack, $needle);
        })->values();

        // ── Sort (employees-page.tsx:154-177): comparator, then a whole-array
        //    reverse for desc, exactly as the source did. ───────────────────
        $rows = $visible->all();

        usort($rows, function (EmployeeProfile $a, EmployeeProfile $b): int {
            return match ($this->sortKey) {
                'id' => EmployeeFields::compareText($a->employee_code, $b->employee_code),
                'email' => EmployeeFields::compareText($a->email, $b->email),
                // Active first, then by status label — an inactive Regular
                // belongs with the other inactive rows.
                'status' => ((int) $b->is_active <=> (int) $a->is_active)
                    ?: EmployeeFields::compareText(
                        EmployeeFields::employmentStatusLabel($a->employment_status),
                        EmployeeFields::employmentStatusLabel($b->employment_status),
                    ),
                default => EmployeeFields::compareText($a->last_name, $b->last_name)
                    ?: EmployeeFields::compareText($a->first_name, $b->first_name),
            };
        });

        if ($this->sortDir === 'desc') {
            $rows = array_reverse($rows);
        }

        $list = $this->paginateClamped(new Collection($rows));

        return view('livewire.employees.index', [
            'list' => $list,
            'visibleCount' => $visible->count(),
            'statuses' => $statuses,
            'positions' => $positions,
        ]);
    }
}
