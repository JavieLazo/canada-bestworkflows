<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Workforce\Application\EmployeeDetailRows;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Application\EmployeePlacements;
use App\Modules\Workforce\Application\EmployeeProjection;
use App\Modules\Workforce\Application\PdsLists;
use App\Modules\Workforce\Application\PlaceFilter;
use App\Modules\Workforce\Application\TableFilters;
use App\Modules\Workforce\Contracts\Views\EmployeeProfileView;
use App\Platform\ReadModels\SqlText;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The shared record visibility rule; permission to edit is checked separately
 * by commands. This is the one query that projects whole records
 * (EmployeeProjection): always with their children (`employee_children`),
 * and with the details row and the address rows only when `$details` asks —
 * each relation loaded with the rows in one query, never per row.
 *
 * Only the consumers that show an address, a citizenship or an ID number ask
 * for the details: the record page with its 201 sheet, My Profile and the
 * Employee Records CSV. The punch station and the directory never do, so
 * they never touch those tables. While the tables do not exist yet the
 * details are not loaded, and the views read as blank details — the legacy
 * address, citizenship, spouse and schools show as stored.
 *
 * The details also carry the stored schools (`educations`), because the 201
 * sheet and the CSV read College and Graduate Studies from them, and, on
 * find(), the Other info lines (`otherItems`). arranged() — whose details
 * consumer is the CSV, which exports no Other info list — loads the schools
 * only, as list() does when asked. page(), the Employee Records table, loads
 * no details.
 *
 * Every view carries where the employee's position sits (EmployeePlacements:
 * one batched read per call, after the row scope).
 */
final class EmployeeRecords
{
    public function find(Access $actor, string $id, bool $details = false): ?EmployeeProfileView
    {
        $query = EmployeeProfile::query()->with('children')->whereKey($id);

        if ($details && EmployeeDetailRows::tablesReady()) {
            $query->with(['pdsDetail', 'addresses']);
        }

        if ($details && PdsLists::ready('education')) {
            $query->with('educations');
        }

        if ($details && PdsLists::ready('other')) {
            $query->with('otherItems');
        }

        $row = match ($actor->rowVisibility(ModuleCode::EMPLOYEES, ModuleCode::PAYROLL)) {
            Access::ROWS_ALL => $query->first(),
            Access::ROWS_OWN => $query->whereKey($actor->ownEmployeeId())->first(),
            Access::ROWS_NONE => null,
        };

        return $row === null ? null : EmployeeProjection::view($row, $details, EmployeePlacements::of(collect([$row]))[$row->id] ?? []);
    }

    /** @return Collection<int, EmployeeProfileView> */
    public function list(Access $actor, bool $details = false): Collection
    {
        return $this->views($this->withDetails($this->scoped($actor)->with('children')->orderBy('last_name'), $details)->get(), $details);
    }

    /**
     * One page of the Employee Records table: the scoped rows filtered,
     * searched and sorted in SQL, so the database returns only that page's
     * records, and their children and placements are read for those rows
     * alone. An overflowing page is clamped to the last one.
     *
     * @param  array{search: string, status: string, position: string, department: string}  $filters
     * @return LengthAwarePaginator<int, EmployeeProfileView>
     */
    public function page(Access $actor, array $filters, string $sort, string $direction, int $page, int $perPage): LengthAwarePaginator
    {
        $perPage = max(1, min(100, $perPage));
        $query = $this->filtered($actor, $filters, $sort, $direction)->with('children');
        $rows = $query->paginate($perPage, ['*'], 'page', max(1, $page));

        if ($rows->currentPage() > $rows->lastPage()) {
            $rows = $query->paginate($perPage, ['*'], 'page', $rows->lastPage());
        }

        return new LengthAwarePaginator($this->views($rows->getCollection(), false), $rows->total(), $rows->perPage(), $rows->currentPage(), $rows->getOptions());
    }

    /**
     * Every row of the same filtered, sorted table, for its CSV — one rule
     * for the table and the file, so the file holds exactly what the table
     * lists, in its order.
     *
     * @param  array{search: string, status: string, position: string, department: string}  $filters
     * @return Collection<int, EmployeeProfileView>
     */
    public function arranged(Access $actor, array $filters, string $sort, string $direction, bool $details = false): Collection
    {
        return $this->views($this->withDetails($this->filtered($actor, $filters, $sort, $direction)->with('children'), $details)->get(), $details);
    }

    /**
     * The non-empty job titles and employment statuses the actor's records
     * hold, for the filters' "still on a record" options — each value as
     * stored, read without loading a record.
     *
     * @return array{positions: list<string>, statuses: list<string>}
     */
    public function heldValues(Access $actor): array
    {
        $filled = static fn (array $values): array => array_values(array_filter($values, static fn (string $value): bool => $value !== ''));

        return [
            'positions' => $filled(SqlText::distinctValues($this->scoped($actor), 'position')),
            'statuses' => $filled(SqlText::distinctValues($this->scoped($actor), 'employment_status')),
        ];
    }

    /**
     * The table's filters and order on the scoped rows — the SQL twin of the
     * source's client-side rules (employees-page.tsx:133-177):
     *
     *  - status and position: {@see TableFilters}, shared with the Shift
     *    Roster's table;
     *  - department: {@see PlaceFilter}, after the scope;
     *  - search: one phrase anywhere in the record's code, Employee ID, name
     *    (as EmployeeName spells it), job title or email;
     *  - sort: the column, then last name, first name and the key, so ties
     *    are stable and desc is the exact reverse of asc.
     *
     * @param  array{search: string, status: string, position: string, department: string}  $filters
     * @return Builder<EmployeeProfile>
     */
    private function filtered(Access $actor, array $filters, string $sort, string $direction): Builder
    {
        $query = $this->scoped($actor);
        TableFilters::status($query, $filters['status']);
        TableFilters::position($query, $filters['position']);
        PlaceFilter::apply($query, $actor, $filters['department']);
        SqlText::contains($query, ['employee_code', 'employee_id_no', SqlText::joinedWords($query, ['first_name', 'last_name', 'suffix']), 'position', 'email'], $filters['search']);
        $this->sort($query, $actor, $sort, $direction === 'desc' ? 'desc' : 'asc');

        return $query;
    }

    /**
     * @param  Builder<EmployeeProfile>  $query
     * @param  'asc'|'desc'  $direction
     */
    private function sort(Builder $query, Access $actor, string $sort, string $direction): void
    {
        match ($sort) {
            // A minted id, so CodeSort's SQL twin.
            'id' => SqlText::orderNumeric($query, 'employee_code', $direction),
            'employee_id_no' => SqlText::order($query, 'employee_id_no', $direction),
            'email' => SqlText::order($query, 'email', $direction),
            'department' => $this->orderByPlacement($query, $actor, $direction),
            'status' => $this->orderByStatus($query, $direction),
            default => null,
        };

        SqlText::order($query, 'last_name', $direction);
        SqlText::order($query, 'first_name', $direction);
        $query->orderBy('id', $direction);
    }

    /**
     * Department, then the people in the department itself before each
     * unit's, then the unit's name; a record with no department sorts last.
     * Organization names the places, so they arrive through its contract
     * and are mapped onto `position_id` — no join.
     *
     * @param  Builder<EmployeeProfile>  $query
     */
    private function orderByPlacement(Builder $query, Access $actor, string $direction): void
    {
        if (! EmployeePlacements::ready()) {
            return;
        }

        $departments = [];
        $inUnit = [];
        $units = [];

        foreach (app(OrganizationDirectory::class)->positionChoices($actor) as $position) {
            if ($position->department_name !== null) {
                $departments[$position->id] = $position->department_name;
            }

            if ($position->unit_name !== null) {
                $inUnit[$position->id] = 1;
                $units[$position->id] = $position->unit_name;
            }
        }

        if ($departments !== []) {
            SqlText::orderMapped($query, 'position_id', $departments, "''", $direction);
        }

        if ($units !== []) {
            SqlText::orderMapped($query, 'position_id', $inUnit, '0', $direction, false);
            SqlText::orderMapped($query, 'position_id', $units, "''", $direction);
        }
    }

    /**
     * Active first, then by status label — an inactive Regular belongs with
     * the other inactive rows.
     *
     * @param  Builder<EmployeeProfile>  $query
     */
    private function orderByStatus(Builder $query, string $direction): void
    {
        $query->orderBy('is_active', $direction === 'asc' ? 'desc' : 'asc');
        $labels = [];

        foreach (SqlText::distinctValues(EmployeeProfile::query(), 'employment_status') as $value) {
            $labels[$value] = EmployeeFields::employmentStatusLabel($value);
        }

        if ($labels === []) {
            return;
        }

        // The CASE compares byte for byte, as the labels were keyed: under
        // MariaDB's case- and accent-insensitive collation "CASUAL" would
        // otherwise match "Casual"'s WHEN and take its label.
        $subject = $query->getModel()->getConnection()->getDriverName() === 'sqlite' ? 'employment_status' : 'CAST(employment_status AS BINARY)';
        SqlText::orderMapped($query, $subject, $labels, 'employment_status', $direction);
    }

    /**
     * The record visibility rule: every row for all_data + EMPLOYEES or
     * PAYROLL, the actor's own otherwise, none for an unlinked account.
     *
     * @return Builder<EmployeeProfile>
     */
    private function scoped(Access $actor): Builder
    {
        $query = EmployeeProfile::query();

        match ($actor->rowVisibility(ModuleCode::EMPLOYEES, ModuleCode::PAYROLL)) {
            Access::ROWS_ALL => null,
            Access::ROWS_OWN => $query->whereKey($actor->ownEmployeeId()),
            Access::ROWS_NONE => $query->whereRaw('1 = 0'),
        };

        return $query;
    }

    /**
     * @param  Builder<EmployeeProfile>  $query
     * @return Builder<EmployeeProfile>
     */
    private function withDetails(Builder $query, bool $details): Builder
    {
        if ($details && EmployeeDetailRows::tablesReady()) {
            $query->with(['pdsDetail', 'addresses']);
        }

        if ($details && PdsLists::ready('education')) {
            $query->with('educations');
        }

        return $query;
    }

    /**
     * @param  Collection<int, EmployeeProfile>  $rows
     * @return Collection<int, EmployeeProfileView>
     */
    private function views(Collection $rows, bool $details): Collection
    {
        // One placements read for all the rows (EmployeePlacements).
        $placements = EmployeePlacements::of($rows);

        return $rows->map(static fn (EmployeeProfile $row): EmployeeProfileView => EmployeeProjection::view($row, $details, $placements[$row->id] ?? []))->values();
    }

    /**
     * The name and placement the Application for Leave states: for the
     * employee's own form, or a leave manager reviewing one. Deliberately no
     * pay, contacts or private details, because a leave manager need not
     * hold Employee Records: the salary line reads through find() instead.
     *
     * @return array{first_name: string, middle_name: string|null, last_name: string, suffix: string|null, position: string|null, department_id: string|null, department_name: string|null, unit_id: string|null, unit_name: string|null}|null
     */
    public function applicantIdentity(Access $actor, string $employeeId): ?array
    {
        $own = $actor->hasEmployeeLink() && $actor->ownEmployeeId() === $employeeId;
        if (! $own && ! $actor->mayReadAllData(ModuleCode::LEAVES)) {
            return null;
        }
        $row = EmployeeProfile::query()->whereKey($employeeId)->first();
        if ($row === null) {
            return null;
        }
        $placement = EmployeePlacements::of(collect([$row]))[$employeeId] ?? [];

        return [
            'first_name' => (string) $row->first_name,
            'middle_name' => $row->middle_name,
            'last_name' => (string) $row->last_name,
            'suffix' => $row->suffix,
            'position' => $row->position,
            'department_id' => $placement['department_id'] ?? null,
            'department_name' => $placement['department_name'] ?? null,
            'unit_id' => $placement['unit_id'] ?? null,
            'unit_name' => $placement['unit_name'] ?? null,
        ];
    }

    public function own(Access $actor, bool $details = false): ?EmployeeProfileView
    {
        return $actor->hasEmployeeLink() ? $this->find($actor, $actor->ownEmployeeId(), $details) : null;
    }

    /** @return list<string> */
    public function statuses(Access $actor, bool $ordered = true): array
    {
        abort_if($actor->userId === null, 403);
        $query = EmploymentStatusOption::query();
        if ($ordered) {
            $query->orderBy('name');
        }

        return array_values($query->get(['name'])->map(static fn (EmploymentStatusOption $row): string => $row->name)->all());
    }
}
