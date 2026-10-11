<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\Position;
use App\Modules\Organization\Application\StructureInput;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Organization\Domain\OrgStructure;
use App\Platform\Database\TransactionMutex;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Move up / Move down among the positions of one department or unit — the
 * order the Position page and every position select show them in. The
 * place's positions are renumbered 0..n-1 in their current order first, so
 * a swap always changes something. A Not placed position has no order of its
 * own: those list by name.
 */
final class MovePosition
{
    public function handle(Access $access, string $id, string $direction): string
    {
        StructureInput::authorize($access);
        $direction = StructureInput::direction($direction);

        $position = DB::transaction(static function () use ($id, $direction): Position {
            TransactionMutex::acquire(OrganizationReferences::STRUCTURE_LOCK);
            $position = Position::query()->whereKey($id)->lockForUpdate()->first();
            if ($position === null) {
                throw new RuntimeException('That position no longer exists.');
            }
            if ($position->org_node_id === null) {
                throw new RuntimeException('A position that is not placed has no order to change.');
            }
            $siblings = Position::query()->where('org_node_id', $position->org_node_id)->lockForUpdate()
                ->orderBy('sort_order')->orderBy('name')->orderBy('id')->pluck('id')->map(static fn (mixed $value): string => (string) $value)->all();
            $order = OrgStructure::swap(array_values($siblings), $id, $direction);
            if ($order === null) {
                throw new RuntimeException("{$position->name} is already ".($direction === 'up' ? 'first.' : 'last.'));
            }
            foreach ($order as $rank => $sibling) {
                Position::query()->whereKey($sibling)->where('sort_order', '!=', $rank)->update(['sort_order' => $rank]);
            }

            return $position;
        });

        AuditService::record($access, AuditEvent::REFERENCE_UPDATED, 'positions', $id, "Position {$position->code} \"{$position->name}\" moved {$direction}");

        return "{$position->name} moved {$direction}.";
    }
}
