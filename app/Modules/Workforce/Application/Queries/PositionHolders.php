<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Application\PositionLink;
use App\Support\Access;

/**
 * How many employee records hold each position, for the Position page's
 * Delete refusal ("Delete · held by N"). A RECORDS read, counted over every
 * record and not row scoped: the page decides whether a position can be
 * deleted, and a scoped count would show a held position as free. It returns
 * counts only, never who the holders are.
 */
final class PositionHolders
{
    /**
     * Active and inactive holders by position id; a position nobody holds is
     * left out.
     *
     * @param  list<string>  $positionIds
     * @return array<string, array{active: int, inactive: int}>
     */
    public function counts(Access $actor, array $positionIds): array
    {
        abort_unless($actor->hasModule(ModuleCode::RECORDS), 403);
        $ids = array_values(array_unique(array_filter($positionIds, static fn (string $id): bool => $id !== '')));
        if ($ids === [] || ! PositionLink::ready()) {
            return [];
        }
        $counts = [];
        $rows = EmployeeProfile::query()
            ->whereIn('position_id', $ids)
            ->selectRaw('position_id, is_active, count(*) as holders')
            ->groupBy('position_id', 'is_active')
            ->toBase()
            ->get();
        foreach ($rows as $row) {
            $id = (string) $row->position_id;
            $counts[$id] ??= ['active' => 0, 'inactive' => 0];
            $counts[$id][(bool) $row->is_active ? 'active' : 'inactive'] += (int) $row->holders;
        }

        return $counts;
    }
}
