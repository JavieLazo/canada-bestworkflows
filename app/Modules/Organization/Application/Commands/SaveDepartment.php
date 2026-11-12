<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\OrgNode;
use App\Modules\Organization\Application\StructureInput;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Platform\Database\TransactionMutex;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\CodeSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Adds or edits a department. Department names are unique among
 * departments, which the composite `(parent_id, name)` unique cannot say —
 * every department's parent is NULL, and NULLs are distinct — so the check
 * runs here, under the structure mutex, comparing the way the column's
 * collation does. A new department goes last.
 */
final class SaveDepartment
{
    public function handle(Access $access, ?string $id, string $name, string $description, ?string $headName = null, ?string $headDesignation = null): string
    {
        StructureInput::authorize($access);
        $name = StructureInput::name($name, StructureInput::NODE_NAME_MAX);
        $text = StructureInput::description($description);
        $head = StructureInput::head($headName, $headDesignation);

        [$node, $created] = DB::transaction(static function () use ($id, $name, $text, $head): array {
            TransactionMutex::acquire(OrganizationReferences::STRUCTURE_LOCK);
            $codes = OrgNode::query()->lockForUpdate()->pluck('code');
            $node = null;
            if ($id !== null) {
                $node = OrgNode::query()->whereKey($id)->where('kind', OrgNode::KIND_DEPARTMENT)->first();
                if ($node === null) {
                    throw new RuntimeException('That department no longer exists.');
                }
            }
            $taken = OrgNode::query()->where('kind', OrgNode::KIND_DEPARTMENT)->whereNull('parent_id')
                ->where('name', $name)->when($id !== null, static fn ($query) => $query->whereKeyNot($id))->exists();
            if ($taken) {
                throw new RuntimeException("\"{$name}\" is already a department.");
            }
            if ($node !== null) {
                $node->fill(['name' => $name, 'description' => $text] + $head)->save();

                return [$node, false];
            }
            $last = OrgNode::query()->where('kind', OrgNode::KIND_DEPARTMENT)->whereNull('parent_id')->max('sort_order');

            return [OrgNode::query()->create([
                'code' => CodeSequence::next('ORG', $codes),
                'kind' => OrgNode::KIND_DEPARTMENT,
                'name' => $name,
                'description' => $text,
                'parent_id' => null,
                'sort_order' => $last === null ? 0 : (int) $last + 1,
            ] + $head), true];
        });

        if ($created) {
            AuditService::record($access, AuditEvent::STRUCTURE_CREATED, 'org_nodes', $node->id, "Department {$node->code} \"{$name}\" added".StructureInput::headSentence($node));

            return "{$name} added.";
        }
        AuditService::record($access, AuditEvent::STRUCTURE_UPDATED, 'org_nodes', $node->id, "Department {$node->code} updated to \"{$name}\"".StructureInput::headSentence($node));

        return "{$name} updated.";
    }
}
