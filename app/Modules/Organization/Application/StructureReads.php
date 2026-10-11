<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application;

use App\Models\OrgNode;
use App\Models\Position;
use App\Modules\Organization\Contracts\Views\PositionPlacementView;
use App\Modules\Organization\Domain\OrgStructure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * The reads every structure screen and contract shares: the structure itself
 * (one query — there are tens of rows), the Position page's order, and where
 * a position sits. Kept in one place so the Position page, the contracts and
 * the commands that renumber positions agree on one order.
 *
 * Files reach the host before the cron runs their migrations, so every read
 * asks `ready()` first: until `org_nodes` and `positions.org_node_id` exist
 * the structure is empty and every position reads as Not placed.
 */
final class StructureReads
{
    /**
     * Whether the structure's schema exists yet. Asked once per request
     * through `once()`; a test that adds or drops the schema and then runs
     * app code in the same test calls `Once::flush()`.
     */
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('org_nodes') && Schema::hasColumn('positions', 'org_node_id'));
    }

    /** The whole structure. `$lock` reads it FOR UPDATE, for a command that holds the mutex. */
    public static function structure(bool $lock = false): OrgStructure
    {
        if (! self::ready()) {
            return new OrgStructure([]);
        }
        $query = OrgNode::query();
        if ($lock) {
            $query->lockForUpdate();
        }

        return new OrgStructure($query->get(['id', 'kind', 'parent_id', 'name', 'sort_order'])
            ->map(static fn (OrgNode $node): array => [
                'id' => $node->id,
                'kind' => $node->kind,
                'parent_id' => $node->parent_id,
                'name' => $node->name,
                'sort_order' => $node->sort_order,
            ])->all());
    }

    /**
     * Order positions the way the Position page lists them: the structure's
     * HR order (a department, then each of its units), then HR's order inside
     * one place, then name and id; Not placed last. A CASE over the node ids
     * rather than a join back onto `org_nodes`, so it reads one table.
     *
     * @param  Builder<Position>  $query
     * @return Builder<Position>
     */
    public static function orderPositions(Builder $query, OrgStructure $structure): Builder
    {
        if (! self::ready()) {
            return $query->orderBy('name')->orderBy('id');
        }
        $ids = $structure->ordered();
        $count = count($ids);
        $sql = 'case when org_node_id is null then ?';
        $bindings = [$count + 1];
        foreach ($ids as $rank => $id) {
            $sql .= ' when org_node_id = ? then ?';
            $bindings[] = $id;
            $bindings[] = $rank;
        }
        $sql .= ' else ? end';
        $bindings[] = $count;

        return $query->orderByRaw($sql, $bindings)->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }

    /** Where one position sits, from rows already read. */
    public static function placement(Position $position, OrgStructure $structure): PositionPlacementView
    {
        $node = $position->org_node_id;
        $department = $node === null ? null : $structure->departmentOf($node);
        $unit = $node !== null && $structure->isUnit($node) ? $node : null;

        return new PositionPlacementView([
            'id' => $position->id,
            'code' => $position->code,
            'name' => $position->name,
            'org_node_id' => $node,
            'department_id' => $department,
            'department_name' => $department === null ? null : $structure->name($department),
            'unit_id' => $unit,
            'unit_name' => $unit === null ? null : $structure->name($unit),
        ]);
    }
}
