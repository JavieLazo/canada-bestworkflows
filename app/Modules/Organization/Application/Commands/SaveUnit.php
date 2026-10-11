<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\OrgNode;
use App\Modules\Organization\Application\StructureInput;
use App\Modules\Organization\Application\StructureReads;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Platform\Database\TransactionMutex;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\CodeSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Adds or edits a unit, and moves one to another department. The parent
 * must be a DEPARTMENT — a CHECK cannot read another row, so the command
 * re-resolves it from the structure it reads under the mutex, never from the
 * dialog. A unit's name is unique inside its department. A new unit, or one
 * moved to another department, goes last among that department's units; its
 * positions, and the people who hold them, go with it.
 */
final class SaveUnit
{
    public function handle(Access $access, ?string $id, string $name, string $departmentId, string $description): string
    {
        StructureInput::authorize($access);
        $name = StructureInput::name($name, StructureInput::NODE_NAME_MAX);
        $text = StructureInput::description($description);

        [$node, $created, $moved, $departmentName] = DB::transaction(static function () use ($id, $name, $departmentId, $text): array {
            TransactionMutex::acquire(OrganizationReferences::STRUCTURE_LOCK);
            $codes = OrgNode::query()->lockForUpdate()->pluck('code');
            $structure = StructureReads::structure();
            if (! $structure->isDepartment($departmentId)) {
                throw new RuntimeException('Choose the department this unit belongs to.');
            }
            $departmentName = (string) $structure->name($departmentId);
            $node = null;
            if ($id !== null) {
                $node = OrgNode::query()->whereKey($id)->where('kind', OrgNode::KIND_UNIT)->first();
                if ($node === null) {
                    throw new RuntimeException('That unit no longer exists.');
                }
            }
            $taken = OrgNode::query()->where('parent_id', $departmentId)->where('name', $name)
                ->when($id !== null, static fn ($query) => $query->whereKeyNot($id))->exists();
            if ($taken) {
                throw new RuntimeException("\"{$name}\" is already a unit of {$departmentName}.");
            }
            $last = OrgNode::query()->where('parent_id', $departmentId)->when($id !== null, static fn ($query) => $query->whereKeyNot($id))->max('sort_order');
            $next = $last === null ? 0 : (int) $last + 1;
            if ($node !== null) {
                $moved = $node->parent_id !== $departmentId;
                $node->fill(['name' => $name, 'description' => $text] + ($moved ? ['parent_id' => $departmentId, 'sort_order' => $next] : []))->save();

                return [$node, false, $moved, $departmentName];
            }

            return [OrgNode::query()->create([
                'code' => CodeSequence::next('ORG', $codes),
                'kind' => OrgNode::KIND_UNIT,
                'name' => $name,
                'description' => $text,
                'parent_id' => $departmentId,
                'sort_order' => $next,
            ]), true, false, $departmentName];
        });

        if ($created) {
            AuditService::record($access, AuditEvent::STRUCTURE_CREATED, 'org_nodes', $node->id, "Unit {$node->code} \"{$name}\" added to {$departmentName}");

            return "{$name} added.";
        }
        AuditService::record($access, AuditEvent::STRUCTURE_UPDATED, 'org_nodes', $node->id, $moved
            ? "Unit {$node->code} \"{$name}\" moved to {$departmentName}"
            : "Unit {$node->code} updated to \"{$name}\"");

        return "{$name} updated.";
    }
}
