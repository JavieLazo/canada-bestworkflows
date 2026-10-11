<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Workforce\Application\EmployeePlacements;
use App\Modules\Workforce\Contracts\InventoryEmployees;
use App\Platform\Database\TransactionMutex;
use App\Support\Access;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Keeps MR identity snapshots narrow and serializes them with employment and placement edits. */
final class InventoryEmployeeQuery implements InventoryEmployees
{
    public function changeStamp(Access $actor): string
    {
        if (! $actor->isSignedIn() || ! $actor->hasModule(ModuleCode::INVENTORY)) {
            return 'unavailable';
        }
        $query = EmployeeProfile::query();
        if (! $actor->mayReadAllData(ModuleCode::INVENTORY)) {
            if (! $actor->hasEmployeeLink()) {
                return 'unlinked';
            }
            $query->whereKey($actor->ownEmployeeId());
        }
        $row = (clone $query)->selectRaw('COUNT(*) AS total, MAX(updated_at) AS latest, MAX(id) AS last_id')->toBase()->first();
        $placementLabels = [];
        if (EmployeePlacements::ready()) {
            $positionIds = array_values(array_filter(
                (clone $query)->whereNotNull('position_id')->distinct()->orderBy('position_id')->pluck('position_id')->all(),
                static fn (mixed $id): bool => is_string($id) && $id !== '',
            ));
            $placements = app(OrganizationReferences::class)->placements($positionIds);
            foreach ($positionIds as $id) {
                $placement = $placements[$id] ?? null;
                $placementLabels[] = [$id, $placement?->department_name, $placement?->unit_name];
            }
        }

        // Placement labels can change without touching an employee's timestamp.
        return hash('sha256', json_encode([$row, $placementLabels], JSON_THROW_ON_ERROR));
    }

    public function identities(Access $actor, array $ids, bool $lock = false): array
    {
        if ($lock) {
            abort_unless($actor->mayReadAllData(ModuleCode::INVENTORY), 403);
            if (DB::transactionLevel() === 0) {
                throw new LogicException('A locked employee lookup requires a transaction.');
            }
            TransactionMutex::acquire(OrganizationReferences::STRUCTURE_LOCK);
        }
        $query = EmployeeProfile::query()->whereKey(array_values(array_unique($ids)));
        if (! $actor->mayReadAllData(ModuleCode::INVENTORY, ModuleCode::EMPLOYEES)) {
            if (! $actor->hasModule(ModuleCode::INVENTORY, ModuleCode::EMPLOYEES, ModuleCode::PORTAL) || ! $actor->hasEmployeeLink()) {
                return [];
            }
            $query->whereKey($actor->ownEmployeeId());
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = $query->orderBy('id')->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'position', 'position_id', 'is_active']);
        $placements = EmployeePlacements::of($rows);
        $identities = [];
        foreach ($rows as $row) {
            $placement = $placements[$row->id] ?? [];
            $identities[$row->id] = [
                'id' => $row->id,
                'name' => EmployeeName::full($row),
                'position' => $row->position,
                'department' => isset($placement['department_name']) ? (string) $placement['department_name'] : null,
                'unit' => isset($placement['unit_name']) ? (string) $placement['unit_name'] : null,
                'is_active' => $row->is_active,
            ];
        }

        return $identities;
    }
}
