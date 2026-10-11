<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\Views\PositionPlacementView;
use App\Modules\Organization\Contracts\Views\StructureOptionView;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Application\PositionLink;
use App\Modules\Workforce\Contracts\Views\OrgChartDepartmentView;
use App\Modules\Workforce\Contracts\Views\OrgChartPersonView;
use App\Modules\Workforce\Contracts\Views\OrgChartUnitView;
use App\Support\Access;
use App\Support\EmployeeName;

/**
 * The Organization view of Employee Records: every department in HR order,
 * the people placed in the department itself, then each of its units with
 * theirs.
 *
 * DELIBERATELY WIDER THAN THE EMPLOYEES LIST: every holder of EMPLOYEES reads
 * every placed ACTIVE employee here — name, photo and position only — even a
 * holder whose list shows only their own record (access-control guide). The
 * record behind each name keeps its own visibility: `canOpen` is the
 * EmployeeRecords::find rule, and the screen links a name only when it holds.
 *
 * Only filled positions appear: a person shows under the department or unit
 * their linked position is placed in, so an unlinked record, a title that is
 * not on the Position list, a Not placed position and an inactive record
 * never reach the view. Departments and units with nobody in them stay.
 *
 * Organization owns the structure and the position order, so both come
 * through its directory contract; this reads only `employee_profiles`, in one
 * query. Before the structure's migrations have run the contract returns no
 * departments, and before `position_id` exists nobody is linked, so the view
 * is empty rather than broken.
 */
final class OrganizationChart
{
    public function __construct(private readonly OrganizationDirectory $directory) {}

    /** @return list<OrgChartDepartmentView> */
    public function departments(Access $actor): array
    {
        abort_unless($actor->hasModule(ModuleCode::EMPLOYEES), 403);

        // The Position page's order: department order, a department's own
        // positions before each unit's, HR's order inside one place.
        $positions = [];
        foreach ($this->directory->positionChoices($actor) as $position) {
            if ($position->org_node_id !== null && $position->department_id !== null) {
                $positions[$position->id] = $position;
            }
        }

        $byNode = [];
        foreach ($this->holders($actor, $positions) as $positionId => $people) {
            $node = (string) $positions[$positionId]->org_node_id;
            $byNode[$node] = [...($byNode[$node] ?? []), ...$people];
        }

        $departments = [];
        $units = [];
        foreach ($this->directory->structureOptions($actor) as $option) {
            if ($option->unit) {
                $units[$option->department_id][] = new OrgChartUnitView($option->id, $option->name, $byNode[$option->id] ?? []);
            } else {
                $departments[] = $option;
            }
        }

        return array_map(static fn (StructureOptionView $department): OrgChartDepartmentView => new OrgChartDepartmentView(
            $department->id,
            $department->name,
            $byNode[$department->id] ?? [],
            $units[$department->id] ?? [],
        ), $departments);
    }

    /**
     * The active people holding each placed position, keyed by position id in
     * the positions' own order; several people in one position by name, the
     * way the Employees list sorts it.
     *
     * @param  array<string, PositionPlacementView>  $positions
     * @return array<string, list<OrgChartPersonView>>
     */
    private function holders(Access $actor, array $positions): array
    {
        if ($positions === [] || ! PositionLink::ready()) {
            return [];
        }

        $rows = EmployeeProfile::query()
            ->where('is_active', true)
            ->whereNotNull('position_id')
            ->get(['id', 'first_name', 'last_name', 'suffix', 'photo_path', 'position_id'])
            ->filter(static fn (EmployeeProfile $row): bool => isset($positions[(string) $row->getAttribute('position_id')]))
            ->sort(static fn (EmployeeProfile $a, EmployeeProfile $b): int => EmployeeFields::compareText($a->last_name, $b->last_name)
                ?: EmployeeFields::compareText($a->first_name, $b->first_name)
                ?: strcmp($a->id, $b->id));

        $visibility = $actor->rowVisibility(ModuleCode::EMPLOYEES, ModuleCode::PAYROLL);
        $own = $visibility === Access::ROWS_OWN ? $actor->ownEmployeeId() : null;

        $people = [];
        foreach ($rows as $row) {
            $positionId = (string) $row->getAttribute('position_id');
            $people[$positionId][] = new OrgChartPersonView(
                $row->id,
                EmployeeName::display($row),
                EmployeeName::initials($row),
                $positions[$positionId]->name,
                $row->photo_path,
                match ($visibility) {
                    Access::ROWS_ALL => true,
                    Access::ROWS_OWN => $row->id === $own,
                    Access::ROWS_NONE => false,
                },
            );
        }

        $ordered = [];
        foreach (array_keys($positions) as $positionId) {
            if (isset($people[$positionId])) {
                $ordered[$positionId] = $people[$positionId];
            }
        }

        return $ordered;
    }
}
