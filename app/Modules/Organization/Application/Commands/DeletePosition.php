<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\Position;
use App\Modules\Organization\Application\PositionRetention;
use App\Modules\Organization\Application\StructureInput;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Platform\Database\TransactionMutex;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deletes a position nobody holds. Every owner whose records link to it is
 * asked first ({@see PositionRetention}): a position stays while ANY
 * employee holds it, active or inactive, and the RESTRICT key on
 * `employee_profiles.position_id` is the backstop. The check runs under the
 * structure mutex, which every write of an employee's position takes too, so
 * no one can be given the position between the check and the delete.
 */
final class DeletePosition
{
    public function handle(Access $access, string $id): string
    {
        StructureInput::authorize($access);

        $position = DB::transaction(static function () use ($id): Position {
            TransactionMutex::acquire(OrganizationReferences::STRUCTURE_LOCK);
            $position = Position::query()->whereKey($id)->lockForUpdate()->first();
            if ($position === null) {
                throw new RuntimeException('That position no longer exists.');
            }
            $reason = app(PositionRetention::class)->refusal($id);
            if ($reason !== null) {
                throw new RuntimeException($reason);
            }
            $position->delete();

            return $position;
        });

        // The noun, then the id: "Position 4 \"Driver\" deleted".
        AuditService::record($access, AuditEvent::REFERENCE_DELETED, 'positions', $id, "Position {$position->code} \"{$position->name}\" deleted");

        return "{$position->name} deleted.";
    }
}
