<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Queries;

use App\Models\Position;
use App\Modules\Organization\Application\StructureReads;
use App\Modules\Organization\Contracts\Views\PositionRowView;
use App\Modules\Organization\Domain\OrgStructure;
use App\Platform\ReadModels\QueryPage;
use App\Platform\ReadModels\Record;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The Position page's reads: one page of positions in the structure's
 * order (department order; a department's own positions, then each unit's
 * in unit order; Not placed last), filtered by ID, name or description and
 * by department in SQL before the page is cut.
 *
 * The department filter is `ALL`, `NONE` (Not placed), a department (its own
 * positions and every unit's) or one unit; an id that is not in the
 * structure matches nothing.
 */
final class PositionRecords
{
    public function find(Access $actor, string $id): ?PositionRowView
    {
        abort_if($actor->userId === null, 403);
        $row = Position::query()->find($id);
        if ($row === null) {
            return null;
        }
        $structure = StructureReads::structure();

        return $this->view($row, $structure, ['first' => true, 'last' => true]);
    }

    /** @return LengthAwarePaginator<int, PositionRowView> */
    public function page(Access $actor, string $search, string $department, int $page, int $perPage): LengthAwarePaginator
    {
        abort_if($actor->userId === null, 403);
        $structure = StructureReads::structure();
        $query = Position::query();
        $needle = trim($search);
        if ($needle !== '') {
            $like = '%'.addcslashes($needle, '%_\\').'%';
            $query->where(static fn ($q) => $q->where('code', 'like', $like)->orWhere('name', 'like', $like)->orWhere('description', 'like', $like));
        }
        if ($department === OrgStructure::NONE) {
            // Before the migration every position is Not placed.
            if (StructureReads::ready()) {
                $query->whereNull('org_node_id');
            }
        } elseif ($department !== OrgStructure::ALL) {
            $ids = $structure->selection($department) ?? [];
            $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('org_node_id', $ids);
        }
        StructureReads::orderPositions($query, $structure);

        $rows = QueryPage::get($query, $page, $perPage, PositionRowView::class);
        $ends = $this->ends(array_values(array_unique(array_filter(array_map(static fn (Record $row): ?string => $row->org_node_id, $rows->items())))));

        return $rows->through(function (Record $row) use ($structure, $ends): PositionRowView {
            $node = $row->org_node_id;

            return new PositionRowView([
                ...$row->toArray(),
                ...$this->placement($node, $structure),
                'first' => $node !== null && ($ends[$node][0] ?? null) === $row->id,
                'last' => $node !== null && ($ends[$node][1] ?? null) === $row->id,
            ]);
        });
    }

    /**
     * The first and last position of each place, in the order Move up and
     * Move down walk (`sort_order`, name, id).
     *
     * @param  list<string>  $nodeIds
     * @return array<string, array{0: string, 1: string}>
     */
    private function ends(array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }
        $ends = [];
        foreach (Position::query()->whereIn('org_node_id', $nodeIds)->orderBy('sort_order')->orderBy('name')->orderBy('id')->get(['id', 'org_node_id']) as $row) {
            $node = (string) $row->org_node_id;
            $ends[$node] = [$ends[$node][0] ?? $row->id, $row->id];
        }

        return $ends;
    }

    /** @return array{department_id: string|null, department_name: string|null, unit_id: string|null, unit_name: string|null} */
    private function placement(?string $node, OrgStructure $structure): array
    {
        $department = $node === null ? null : $structure->departmentOf($node);
        $unit = $node !== null && $structure->isUnit($node) ? $node : null;

        return [
            'department_id' => $department,
            'department_name' => $department === null ? null : $structure->name($department),
            'unit_id' => $unit,
            'unit_name' => $unit === null ? null : $structure->name($unit),
        ];
    }

    /** @param  array{first: bool, last: bool}  $ends */
    private function view(Position $row, OrgStructure $structure, array $ends): PositionRowView
    {
        $snapshot = Snapshot::of($row, PositionRowView::class);

        return new PositionRowView([...$snapshot->toArray(), ...$this->placement($row->org_node_id, $structure), ...$ends]);
    }
}
