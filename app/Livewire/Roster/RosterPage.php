<?php

declare(strict_types=1);

namespace App\Livewire\Roster;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\Position;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Shift Roster — the port of src/app/(protected)/roster/roster-page.tsx and
 * add-shift-dialog.tsx, one screen with its dialog.
 *
 * Which shift pattern each employee works: an employee × pattern table with
 * search, status/position/pattern filters, per-row inline assignment and an
 * "Assign shift" dialog. Assigning copies the pattern's hours, grace and break
 * onto the employee's own `shifts` row (one per employee, an upsert).
 *
 * Permissions, exactly as the reference:
 *  - the PAGE needs ROSTER (roster/layout.tsx) — route middleware;
 *  - the EMPLOYEE LIST is tiered like GET /api/employees: all_data plus any
 *    operational module reads the whole directory, otherwise your own row;
 *  - the SHIFT LIST is scoped like GET /api/shifts (ROSTER, EDTR, PAYROLL);
 *  - a WRITE needs all_data + ROSTER (PUT /api/shifts/[employeeId]) and is
 *    re-checked inside the action, never trusted to the route.
 */
#[Layout('layouts.app')]
#[Title('Shift Roster')]
class RosterPage extends Component
{
    use WithSorting;
    use WithTableFilters;

    /** Status filter — 'ALL', a status name, or 'INACTIVE'. Plain filter state. */
    public string $status = 'ALL';

    /** Position filter — 'ALL' or a position name. Plain filter state. */
    public string $position = 'ALL';

    /** Pattern filter — 'ALL', a shift_type id, or 'UNASSIGNED'. Plain filter state. */
    public string $pattern = 'ALL';

    /**
     * The dialog's picked employee. Id-bearing, so #[Locked]: the selects
     * write it through `dialogEmployeeChanged()`, which re-validates the id
     * against the actor's visible directory before accepting it.
     */
    #[Locked]
    public string $dialogEmployeeId = '';

    /** The dialog's picked pattern. Id-bearing → #[Locked]; set via action only. */
    #[Locked]
    public string $dialogPatternId = '';

    /**
     * Bumped on every inline assignment attempt so the row selects re-key.
     * A FAILED assign then snaps the select back to what is stored, the way
     * the reference's controlled <Select> did.
     */
    #[Locked]
    public int $assignNonce = 0;

    public function mount(): void
    {
        // useSort<SortKey>("employee") — roster-page.tsx:68.
        $this->sortKey = 'employee';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['id', 'employee', 'shift'];
    }

    /** The three FilterSelects reset the page on change (roster-page.tsx:192-231). */
    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPosition(): void
    {
        $this->resetPage();
    }

    public function updatedPattern(): void
    {
        $this->resetPage();
    }

    /** Open the Assign shift dialog clean (the source resets state on close). */
    public function openAssign(): void
    {
        $this->dialogEmployeeId = '';
        $this->dialogPatternId = '';

        $this->modal('assign-shift')->show();
    }

    /**
     * Commit the dialog's employee pick. The id arrives from the client, so it
     * is re-resolved against the actor-visible directory — an id outside it is
     * dropped on the floor, not selected.
     */
    public function dialogEmployeeChanged(string $id): void
    {
        if ($id === '') {
            $this->dialogEmployeeId = '';

            return;
        }

        $employee = $this->visibleEmployees(app(Access::class))->firstWhere('id', $id);

        if ($employee !== null) {
            $this->dialogEmployeeId = (string) $employee->id;
        }
    }

    /** Commit the dialog's pattern pick, re-validated against shift_types. */
    public function dialogPatternChanged(string $id): void
    {
        if ($id === '') {
            $this->dialogPatternId = '';

            return;
        }

        if (ShiftType::query()->whereKey($id)->exists()) {
            $this->dialogPatternId = $id;
        }
    }

    /** The dialog's save (add-shift-dialog.tsx:71-95). */
    public function dialogSave(): void
    {
        if ($this->dialogEmployeeId === '' || $this->dialogPatternId === '') {
            Flux::toast(variant: 'danger', text: 'Pick an employee and a shift pattern.');

            return;
        }

        if (! $this->performAssign($this->dialogEmployeeId, $this->dialogPatternId)) {
            return;
        }

        $this->dialogEmployeeId = '';
        $this->dialogPatternId = '';
        $this->modal('assign-shift')->close();
    }

    /**
     * The per-row inline assignment (roster-page.tsx:132-158). `$employeeId`
     * and `$patternId` are client arguments — the same shape as the reference
     * PUT's URL param and body — and are re-validated here, behind the same
     * all_data + ROSTER gate.
     */
    public function assign(string $employeeId, string $patternId): void
    {
        $this->assignNonce++;

        if ($patternId === '') {
            return;
        }

        $this->performAssign($employeeId, $patternId);
    }

    public function render(): View
    {
        $access = app(Access::class);

        $employees = $this->visibleEmployees($access);

        // GET /api/shifts scoping: all_data + (ROSTER | EDTR | PAYROLL) reads
        // the whole roster, otherwise only the row you are rostered on — and an
        // account with no employee link reads NONE (api/shifts/route.ts:11-17).
        $shifts = Shift::query()
            ->toEmployee($access, 'employee_id', ModuleCode::ROSTER, ModuleCode::EDTR, ModuleCode::PAYROLL)
            ->get()
            ->keyBy('employee_id');

        $patterns = ShiftType::query()->orderBy('name')->get();

        // Records Management's positions, plus any job title still in use
        // (roster-page.tsx:71-75).
        $positionNames = Position::query()->pluck('name')->all();

        foreach ($employees as $employee) {
            if ($employee->position !== null && $employee->position !== '') {
                $positionNames[] = $employee->position;
            }
        }

        $positions = (new Collection($positionNames))
            ->unique()
            ->sort(Hris::compareText(...))
            ->values();

        // Records Management's statuses, plus any an employee still carries
        // (roster-page.tsx:78-84).
        $statusNames = EmploymentStatusOption::query()->pluck('name')->all();

        foreach ($employees as $employee) {
            if ($employee->employment_status !== '') {
                $statusNames[] = Hris::employmentStatusLabel($employee->employment_status);
            }
        }

        $statuses = (new Collection($statusNames))
            ->unique()
            ->sort(static fn (string $a, string $b): int => strcasecmp($a, $b))
            ->values();

        $rows = $this->paginateClamped($this->buildRows($employees, $shifts));

        // The dialog's derived state (add-shift-dialog.tsx:51-64): actives
        // with no shift row come first — they are why the dialog exists.
        $active = $employees->filter(static fn (EmployeeProfile $e): bool => $e->is_active)->values();
        $unassigned = $active->filter(static fn (EmployeeProfile $e): bool => ! $shifts->has($e->id))->values();
        $assigned = $active->filter(static fn (EmployeeProfile $e): bool => $shifts->has($e->id))->values();

        $dialogSelected = $this->dialogEmployeeId === ''
            ? null
            : $employees->firstWhere('id', $this->dialogEmployeeId);
        $dialogExisting = $this->dialogEmployeeId === '' ? null : $shifts->get($this->dialogEmployeeId);
        $dialogChosen = $this->dialogPatternId === ''
            ? null
            : $patterns->firstWhere('id', $this->dialogPatternId);

        return view('livewire.roster.roster-page', [
            'rows' => $rows,
            'patterns' => $patterns,
            'statuses' => $statuses,
            'positions' => $positions,
            'unassigned' => $unassigned,
            'assigned' => $assigned,
            'dialogSelected' => $dialogSelected,
            'dialogExisting' => $dialogExisting,
            'dialogChosen' => $dialogChosen,
        ]);
    }

    /**
     * Filter, join and sort — the in-memory pipeline of roster-page.tsx:86-128,
     * kept in PHP so blanks-last sorting and the desc `reverse()` behave
     * exactly as the source's array pipeline did.
     *
     * @param  EloquentCollection<int, EmployeeProfile>  $employees
     * @param  Collection<array-key, Shift>  $shifts
     * @return list<array{employee: EmployeeProfile, shift: Shift|null}>
     */
    private function buildRows(EloquentCollection $employees, Collection $shifts): array
    {
        $needle = mb_strtolower(trim($this->search));

        $withShift = $employees
            ->filter(function (EmployeeProfile $e) use ($needle): bool {
                // "INACTIVE" keeps only inactive people; a status name keeps
                // matching statusKeys, active or not (roster-page.tsx:88-94).
                $excluded = $this->status === 'INACTIVE'
                    ? $e->is_active
                    : ($this->status !== 'ALL'
                        && Hris::statusKey($e->employment_status) !== Hris::statusKey($this->status));

                if ($excluded) {
                    return false;
                }

                if ($this->position !== 'ALL' && ($e->position ?? '') !== $this->position) {
                    return false;
                }

                if ($needle === '') {
                    return true;
                }

                $haystack = mb_strtolower(
                    "{$e->employee_code} ".EmployeeName::display($e, '').' '.($e->position ?? '')
                );

                return str_contains($haystack, $needle);
            })
            ->map(static fn (EmployeeProfile $e): array => [
                'employee' => $e,
                'shift' => $shifts->get($e->id),
            ])
            ->filter(function (array $row): bool {
                if ($this->pattern === 'ALL') {
                    return true;
                }

                /** @var Shift|null $shift */
                $shift = $row['shift'];

                if ($this->pattern === 'UNASSIGNED') {
                    return ! ($shift?->shift_type_id);
                }

                return $shift?->shift_type_id === $this->pattern;
            })
            ->values();

        $sorted = $withShift
            ->sort(function (array $a, array $b): int {
                /** @var EmployeeProfile $ea */
                $ea = $a['employee'];
                /** @var EmployeeProfile $eb */
                $eb = $b['employee'];

                return match ($this->sortKey) {
                    'id' => Hris::compareText($ea->employee_code, $eb->employee_code),
                    'shift' => Hris::compareText(
                        $a['shift']->start_time ?? '',
                        $b['shift']->start_time ?? '',
                    ),
                    default => Hris::compareText($ea->last_name, $eb->last_name)
                        ?: Hris::compareText($ea->first_name, $eb->first_name),
                };
            })
            ->values();

        // sort.dir === "asc" ? withShift : withShift.reverse() — roster-page.tsx:127.
        $rows = $this->sortDir === 'desc' ? $sorted->reverse() : $sorted;

        return array_values($rows->all());
    }

    /**
     * The three read tiers of GET /api/employees (api/employees/route.ts:36-70),
     * collapsed to what this screen renders: the full and operational tiers
     * show the same roster columns, so any of the widening modules reads the
     * whole directory; otherwise the actor's OWN row — and NO rows for an
     * account with no employee link, never every row.
     *
     * @return EloquentCollection<int, EmployeeProfile>
     */
    private function visibleEmployees(Access $access): EloquentCollection
    {
        $query = EmployeeProfile::query()->orderBy('last_name');

        return match ($access->rowVisibility(
            ModuleCode::EMPLOYEES,
            ModuleCode::PAYROLL,
            ModuleCode::EDTR,
            ModuleCode::ROSTER,
            ModuleCode::LEAVES,
            ModuleCode::PROJECTS,
            ModuleCode::TASKS,
        )) {
            Access::ROWS_ALL => $query->get(),
            Access::ROWS_OWN => $query->whereKey($access->ownEmployeeId())->get(),
            Access::ROWS_NONE => $query->whereRaw('1 = 0')->get(),
        };
    }

    /**
     * The write path both flows share — the port of PUT
     * /api/shifts/[employeeId] with `shift_type_id` set (route.ts:47-95):
     * gate, resolve, re-derive from the pattern, shape-check, upsert, audit.
     */
    private function performAssign(string $employeeId, string $patternId): bool
    {
        $access = app(Access::class);

        // requireAllDataModule("ROSTER") — all_data AND the module, re-checked
        // in the action itself, never trusted to route middleware.
        if (! $access->mayReadAllData(ModuleCode::ROSTER)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return false;
        }

        $employee = EmployeeProfile::query()->find($employeeId);

        if ($employee === null) {
            Flux::toast(variant: 'danger', text: 'That employee no longer exists.');

            return false;
        }

        $pattern = ShiftType::query()->find($patternId);

        if ($pattern === null) {
            Flux::toast(variant: 'danger', text: 'That shift pattern no longer exists.');

            return false;
        }

        $attributes = ShiftPolicy::attributesFromPattern($pattern);
        $shapeError = ShiftPolicy::shapeError($attributes);

        if ($shapeError !== null) {
            Flux::toast(variant: 'danger', text: $shapeError);

            return false;
        }

        // One shift row per employee, so this is an upsert keyed on employee_id.
        // updateOrCreate, never upsert() — MySQL ignores its $uniqueBy.
        Shift::query()->updateOrCreate(
            ['employee_id' => $employee->id],
            $attributes,
        );

        // The server-side audit row (route.ts:87-92); actor from the session.
        AuditService::record(
            $access,
            AuditEvent::SHIFT_ASSIGNED,
            'shifts',
            (string) $employee->id,
            EmployeeName::display($employee)." assigned {$attributes['start_time']}–{$attributes['end_time']}",
        );

        Flux::toast(
            variant: 'success',
            text: "{$pattern->name} assigned to ".Hris::employeeName($employee).'.',
        );

        return true;
    }
}
