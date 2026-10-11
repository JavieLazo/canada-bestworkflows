<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\Position;
use App\Modules\Organization\Application\PositionRenames;
use App\Modules\Organization\Application\StructureInput;
use App\Modules\Organization\Application\StructureReads;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Organization\Contracts\OrgLevel;
use App\Modules\Organization\Domain\OrgStructure;
use App\Platform\Database\TransactionMutex;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\CodeSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Adds or edits a position and places it: in a department, in one of its
 * units, or nowhere (Not placed, `org_node_id` NULL).
 *
 * The same title may be entered in more than one place, never twice in one:
 * `positions_org_node_name_unq` keeps it once inside a department or unit,
 * and this command keeps it once among the Not placed positions, whose NULL
 * placements that unique cannot compare. Both checks run under the
 * structure mutex.
 *
 * A RENAME carries the new name onto every holder's record in the SAME
 * transaction ({@see PositionRenames}); Employment History and posted
 * payroll are not copies and keep the old name. A MOVE touches no employee
 * record: the department and unit a holder shows are read through the link.
 * A new or moved position goes last in its place.
 */
final class SavePosition
{
    public function handle(Access $access, ?string $id, string $name, string $description, ?string $orgNodeId): string
    {
        StructureInput::authorize($access);
        $name = StructureInput::name($name, StructureInput::POSITION_NAME_MAX);
        $text = StructureInput::description($description);
        $orgNodeId = $orgNodeId === '' ? null : $orgNodeId;

        $result = DB::transaction(static function () use ($id, $name, $text, $orgNodeId): array {
            TransactionMutex::acquire(OrganizationReferences::STRUCTURE_LOCK);
            $codes = Position::query()->lockForUpdate()->pluck('code');
            $structure = StructureReads::structure();
            if ($orgNodeId !== null && ! $structure->isDepartment($orgNodeId) && ! $structure->isUnit($orgNodeId)) {
                throw new RuntimeException('Choose a department or unit that still exists.');
            }
            $position = null;
            if ($id !== null) {
                $position = Position::query()->whereKey($id)->lockForUpdate()->first();
                if ($position === null) {
                    throw new RuntimeException('That position no longer exists.');
                }
            }
            $taken = Position::query()->where('name', $name)
                ->when($orgNodeId === null, static fn ($query) => $query->whereNull('org_node_id'), static fn ($query) => $query->where('org_node_id', $orgNodeId))
                ->when($id !== null, static fn ($query) => $query->whereKeyNot($id))->exists();
            if ($taken) {
                throw new RuntimeException($orgNodeId === null
                    ? "\"{$name}\" is already on the list."
                    : "\"{$name}\" is already a position in {$structure->name($orgNodeId)}.");
            }
            $last = $orgNodeId === null ? null : Position::query()->where('org_node_id', $orgNodeId)
                ->when($id !== null, static fn ($query) => $query->whereKeyNot($id))->max('sort_order');
            $next = $last === null ? 0 : (int) $last + 1;

            if ($position === null) {
                $position = Position::query()->create([
                    'code' => CodeSequence::next('POS', $codes),
                    'name' => $name,
                    'description' => $text,
                    'org_node_id' => $orgNodeId,
                    'sort_order' => $next,
                ]);

                return ['position' => $position, 'created' => true, 'renamed' => false, 'moved' => false, 'holders' => 0, 'place' => self::place($structure, $orgNodeId)];
            }

            $renamed = $position->name !== $name;
            $moved = $position->org_node_id !== $orgNodeId;
            $position->fill(['name' => $name, 'description' => $text] + ($moved ? ['org_node_id' => $orgNodeId, 'sort_order' => $next] : []))->save();
            $holders = $renamed ? app(PositionRenames::class)->follow($position->id, $name) : 0;

            return ['position' => $position, 'created' => false, 'renamed' => $renamed, 'moved' => $moved, 'holders' => $holders, 'place' => self::place($structure, $orgNodeId)];
        });

        /** @var Position $position */
        $position = $result['position'];
        if ($result['created']) {
            AuditService::record($access, AuditEvent::REFERENCE_CREATED, 'positions', $position->id,
                "\"{$name}\" added as position {$position->code}".($orgNodeId === null ? '' : " in {$result['place']}"));

            return "{$name} added.";
        }
        $details = "Position {$position->code} updated to \"{$name}\"";
        if ($result['renamed'] && $result['holders'] > 0) {
            $details .= "; {$result['holders']} employee ".($result['holders'] === 1 ? 'record follows' : 'records follow');
        }
        if ($result['moved']) {
            $details .= "; moved to {$result['place']}";
        }
        AuditService::record($access, AuditEvent::REFERENCE_UPDATED, 'positions', $position->id, $details);

        return "{$name} updated.";
    }

    /** "Unit \"People Operations\"", "Department \"HR\"" or "Not placed", for the audit sentence. */
    private static function place(OrgStructure $structure, ?string $orgNodeId): string
    {
        if ($orgNodeId === null) {
            return 'Not placed';
        }

        return OrgLevel::noun($structure->isUnit($orgNodeId) ? OrgLevel::UNIT : OrgLevel::DEPARTMENT)." \"{$structure->name($orgNodeId)}\"";
    }
}
