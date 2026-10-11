<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Queries;

use App\Models\OrgNode;
use App\Models\Position;
use App\Modules\Organization\Application\StructureReads;
use App\Modules\Organization\Contracts\Views\OrgNodeView;
use App\Modules\Organization\Contracts\Views\StructureRowView;
use App\Platform\ReadModels\QueryPage;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The Departments & Units page's reads. The structure is tens of rows, so
 * it is read whole (two queries: the rows, and the positions placed in each)
 * and paged in PHP, in HR order: each department followed by its units.
 * A search keeps the rows whose ID, name or description match, and keeps a
 * matching unit's department above it, so the unit is never shown under the
 * wrong department.
 */
final class StructureRecords
{
    public function find(Access $actor, string $id): ?OrgNodeView
    {
        abort_if($actor->userId === null, 403);
        if (! StructureReads::ready()) {
            return null;
        }
        $row = OrgNode::query()->find($id);

        return $row === null ? null : Snapshot::of($row, OrgNodeView::class);
    }

    /** @return LengthAwarePaginator<int, StructureRowView> */
    public function page(Access $actor, string $search, int $page, int $perPage): LengthAwarePaginator
    {
        abort_if($actor->userId === null, 403);
        if (! StructureReads::ready()) {
            return QueryPage::items(collect(), $page, $perPage);
        }
        $structure = StructureReads::structure();
        $nodes = OrgNode::query()->get()->keyBy('id');
        $positions = Position::query()->whereNotNull('org_node_id')->selectRaw('org_node_id, count(*) as placed')
            ->groupBy('org_node_id')->toBase()->get()->mapWithKeys(static fn (object $row): array => [(string) $row->org_node_id => (int) $row->placed])->all();

        $needle = mb_strtolower(trim($search));
        $hit = static function (OrgNode $node) use ($needle): bool {
            if ($needle === '') {
                return true;
            }
            foreach ([$node->code, $node->name, (string) $node->description] as $text) {
                if (str_contains(mb_strtolower($text), $needle)) {
                    return true;
                }
            }

            return false;
        };

        $rows = [];
        foreach ($structure->departments() as $department) {
            $units = $structure->unitsOf($department);
            $shownUnits = array_values(array_filter($units, static fn (string $unit): bool => $hit($nodes[$unit])));
            if (! $hit($nodes[$department]) && $shownUnits === []) {
                continue;
            }
            $rows[] = $this->row($nodes[$department], $structure->siblingsOf($department), count($units), $positions[$department] ?? 0);
            foreach ($shownUnits as $unit) {
                $rows[] = $this->row($nodes[$unit], $units, 0, $positions[$unit] ?? 0);
            }
        }

        return QueryPage::items(collect($rows), $page, $perPage);
    }

    /** @param  list<string>  $siblings */
    private function row(OrgNode $node, array $siblings, int $units, int $positions): StructureRowView
    {
        $held = array_filter([
            $units > 0 ? $units.' '.($units === 1 ? 'unit' : 'units') : null,
            $positions > 0 ? $positions.' '.($positions === 1 ? 'position' : 'positions') : null,
        ]);

        return new StructureRowView([
            'id' => $node->id,
            'code' => $node->code,
            'kind' => $node->kind,
            'name' => $node->name,
            'description' => $node->description,
            'parent_id' => $node->parent_id,
            'first' => ($siblings[0] ?? null) === $node->id,
            'last' => ($siblings[count($siblings) - 1] ?? null) === $node->id,
            'blocked_by' => implode(', ', $held),
        ]);
    }
}
