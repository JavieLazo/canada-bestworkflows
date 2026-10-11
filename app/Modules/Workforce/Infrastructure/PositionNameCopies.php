<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Models\EmployeeProfile;
use App\Modules\Workforce\Application\PositionLink;
use App\Platform\Records\PositionNameCopy;

/**
 * `employee_profiles.position` is the record's copy of its linked position's
 * name. A rename rewrites the copy on every holder, active or not, in the
 * renaming transaction. A query-builder update on purpose: it fires no model
 * events, so it writes no Employment History rows — history keeps the name
 * each entry was saved with.
 */
final class PositionNameCopies implements PositionNameCopy
{
    public function follow(string $positionId, string $name): int
    {
        if (! PositionLink::ready()) {
            return 0;
        }

        return EmployeeProfile::query()->where('position_id', $positionId)->update(['position' => $name]);
    }
}
