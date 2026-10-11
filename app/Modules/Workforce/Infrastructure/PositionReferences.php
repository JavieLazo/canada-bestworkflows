<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Models\EmployeeProfile;
use App\Modules\Workforce\Application\PositionLink;
use App\Platform\Retention\PositionReferenceSource;

/**
 * A position stays while ANY employee record holds it, active or not: a
 * delete must never leave a record linked to nothing. The foreign key on
 * `employee_profiles.position_id` (RESTRICT) is the backstop.
 */
final class PositionReferences implements PositionReferenceSource
{
    public function refusal(string $positionId): ?string
    {
        if (! PositionLink::ready()) {
            return null;
        }
        $count = EmployeeProfile::query()->where('position_id', $positionId)->count();

        return $count === 0 ? null : sprintf('Reassign %d employee%s before deleting this position.', $count, $count === 1 ? '' : 's');
    }
}
