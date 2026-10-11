<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Modules\Workforce\Application\EmployeeDetailRows;
use App\Modules\Workforce\Application\EmployeePlacements;
use App\Modules\Workforce\Application\EmployeeProjection;
use App\Modules\Workforce\Application\PdsLists;
use App\Modules\Workforce\Contracts\Views\EmployeeProfileView;
use App\Support\Access;
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
 * find(), the Other info lines (`otherItems`). list() — whose one details
 * consumer is the CSV, which exports no Other info list — loads the schools
 * only.
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
        $query = EmployeeProfile::query()->with('children')->orderBy('last_name');

        if ($details && EmployeeDetailRows::tablesReady()) {
            $query->with(['pdsDetail', 'addresses']);
        }

        if ($details && PdsLists::ready('education')) {
            $query->with('educations');
        }

        match ($actor->rowVisibility(ModuleCode::EMPLOYEES, ModuleCode::PAYROLL)) {
            Access::ROWS_ALL => null,
            Access::ROWS_OWN => $query->whereKey($actor->ownEmployeeId()),
            Access::ROWS_NONE => $query->whereRaw('1 = 0'),
        };

        $rows = $query->get();
        // One placements read for the whole list (EmployeePlacements).
        $placements = EmployeePlacements::of($rows);

        return $rows->map(static fn (EmployeeProfile $row): EmployeeProfileView => EmployeeProjection::view($row, $details, $placements[$row->id] ?? []));
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
