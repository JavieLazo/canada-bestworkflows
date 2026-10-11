<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Contracts\PunchEmployee;
use App\Modules\Workforce\Contracts\PunchEmployees;
use App\Support\Access;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Limits the live station to its existing self-service and kiosk scopes. */
final class PunchEmployeeQuery implements PunchEmployees
{
    public function find(Access $actor, string $id, bool $lock = false): ?PunchEmployee
    {
        if (! $actor->isSignedIn() || (! $actor->mayReadAllData(ModuleCode::EDTR) && (! $actor->hasEmployeeLink() || $actor->ownEmployeeId() !== $id))) {
            return null;
        }
        if ($lock && DB::transactionLevel() === 0) {
            throw new LogicException('Punch employee locks need an existing transaction.');
        }
        $row = EmployeeProfile::query()->whereKey($id)->when($lock, static fn ($query) => $query->lockForUpdate())->first();

        return $row === null ? null : new PunchEmployee($row->id, EmployeeName::display($row), $row->is_active, $row->work_site_id);
    }
}
