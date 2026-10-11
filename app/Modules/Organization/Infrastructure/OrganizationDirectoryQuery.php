<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure;

use App\Models\Position;
use App\Models\WorkSite;
use App\Modules\Organization\Application\StructureReads;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\OrgLevel;
use App\Modules\Organization\Contracts\Views\PositionPlacementView;
use App\Modules\Organization\Contracts\Views\StructureOptionView;
use App\Modules\Organization\Contracts\Views\WorkSiteView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

/** Reference reads are available to signed-in actors, as in the existing reference API. */
final class OrganizationDirectoryQuery implements OrganizationDirectory
{
    /** @return list<string> */
    public function positions(Access $actor, bool $ordered = true): array
    {
        abort_if($actor->userId === null, 403);
        $query = Position::query();
        if ($ordered) {
            $query->orderBy('name');
        }
        $names = [];
        foreach ($query->get(['name']) as $row) {
            $names[$row->name] = true;
        }

        return array_map('strval', array_keys($names));
    }

    /** @return Collection<int, WorkSiteView> */
    public function sites(Access $actor): Collection
    {
        abort_if($actor->userId === null, 403);

        return WorkSite::query()->orderBy('name')->get()
            ->map(static fn (WorkSite $site): WorkSiteView => Snapshot::of($site, WorkSiteView::class));
    }

    /** @return list<StructureOptionView> */
    public function structureOptions(Access $actor): array
    {
        abort_if($actor->userId === null, 403);
        $structure = StructureReads::structure();
        $options = [];
        foreach ($structure->ordered() as $id) {
            $unit = $structure->isUnit($id);
            $options[] = new StructureOptionView([
                'id' => $id,
                'kind' => $unit ? OrgLevel::UNIT : OrgLevel::DEPARTMENT,
                'name' => (string) $structure->name($id),
                'department_id' => (string) $structure->departmentOf($id),
                'unit' => $unit,
            ]);
        }

        return $options;
    }

    /** @return list<PositionPlacementView> */
    public function positionChoices(Access $actor): array
    {
        abort_if($actor->userId === null, 403);
        $structure = StructureReads::structure();
        $columns = StructureReads::ready() ? ['id', 'code', 'name', 'org_node_id', 'sort_order'] : ['id', 'code', 'name'];
        $query = StructureReads::orderPositions(Position::query()->select($columns), $structure);

        return array_values($query->get()->map(static fn (Position $row): PositionPlacementView => StructureReads::placement($row, $structure))->all());
    }
}
