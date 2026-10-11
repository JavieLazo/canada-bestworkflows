<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\OrgNode;
use App\Models\Position;
use App\Modules\Organization\Application\StructureInput;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Organization\Contracts\OrgLevel;
use App\Platform\Database\TransactionMutex;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deletes a department or a unit that holds nothing. A department with
 * units, or any row with positions placed in it, is refused here; the
 * RESTRICT keys on `org_nodes.parent_id` and `positions.org_node_id` are the
 * backstop. Nothing is ever deleted along with it.
 */
final class DeleteOrgNode
{
    public function handle(Access $access, string $id): string
    {
        StructureInput::authorize($access);

        $node = DB::transaction(static function () use ($id): OrgNode {
            TransactionMutex::acquire(OrganizationReferences::STRUCTURE_LOCK);
            $node = OrgNode::query()->whereKey($id)->lockForUpdate()->first();
            if ($node === null) {
                throw new RuntimeException('That department or unit no longer exists.');
            }
            $units = OrgNode::query()->where('parent_id', $id)->count();
            $positions = Position::query()->where('org_node_id', $id)->count();
            $held = array_filter([
                $units > 0 ? $units.' '.($units === 1 ? 'unit' : 'units') : null,
                $positions > 0 ? $positions.' '.($positions === 1 ? 'position' : 'positions') : null,
            ]);
            if ($held !== []) {
                throw new RuntimeException('Move or delete its '.implode(' and ', $held)." before deleting {$node->name}.");
            }
            $node->delete();

            return $node;
        });

        AuditService::record($access, AuditEvent::STRUCTURE_DELETED, 'org_nodes', $id, OrgLevel::noun($node->kind)." {$node->code} \"{$node->name}\" deleted");

        return "{$node->name} deleted.";
    }
}
