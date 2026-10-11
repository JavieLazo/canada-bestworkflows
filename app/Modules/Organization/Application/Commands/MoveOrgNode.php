<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\OrgNode;
use App\Modules\Organization\Application\StructureInput;
use App\Modules\Organization\Application\StructureReads;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Organization\Contracts\OrgLevel;
use App\Modules\Organization\Domain\OrgStructure;
use App\Platform\Database\TransactionMutex;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Move up / Move down: swaps a department with its neighbour among the
 * departments, or a unit with its neighbour among its own department's
 * units. The siblings are renumbered 0..n-1 in their current order first, so
 * equal or gapped numbers can never make a swap a no-op.
 */
final class MoveOrgNode
{
    public function handle(Access $access, string $id, string $direction): string
    {
        StructureInput::authorize($access);
        $direction = StructureInput::direction($direction);

        $node = DB::transaction(static function () use ($id, $direction): OrgNode {
            TransactionMutex::acquire(OrganizationReferences::STRUCTURE_LOCK);
            $structure = StructureReads::structure(lock: true);
            $node = $structure->exists($id) ? OrgNode::query()->find($id) : null;
            if ($node === null || $structure->siblingsOf($id) === []) {
                throw new RuntimeException('That department or unit no longer exists.');
            }
            $order = OrgStructure::swap($structure->siblingsOf($id), $id, $direction);
            if ($order === null) {
                throw new RuntimeException("{$node->name} is already ".($direction === 'up' ? 'first.' : 'last.'));
            }
            foreach ($order as $rank => $sibling) {
                OrgNode::query()->whereKey($sibling)->where('sort_order', '!=', $rank)->update(['sort_order' => $rank]);
            }

            return $node;
        });

        AuditService::record($access, AuditEvent::STRUCTURE_UPDATED, 'org_nodes', $id, OrgLevel::noun($node->kind)." {$node->code} \"{$node->name}\" moved {$direction}");

        return "{$node->name} moved {$direction}.";
    }
}
