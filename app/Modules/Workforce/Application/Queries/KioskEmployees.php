<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Support\Access;
use App\Support\EmployeeName;

/**
 * The staff a biometric kiosk can register a face or fingerprint for.
 * Searching everyone is the kiosk operation, so it needs what punching for
 * someone else needs: eDTR with all data. Otherwise only the actor's own
 * linked record is reachable. Inactive records are listed and flagged, so the
 * device can say why their punches will be refused.
 *
 * @phpstan-type KioskEmployee array{id: string, employee_code: string, employee_no: mixed, name: string, position: mixed, is_active: bool, email: mixed}
 */
final class KioskEmployees
{
    /**
     * The most staff one answer holds. A DEVICE'S LIST HAS NO NEXT PAGE: what
     * is cut off here is simply missing on the device, with nothing to say
     * so, and can only be found by typing a name. So the cap sits above the
     * whole roster and exists only to bound the answer.
     */
    private const int LIMIT = 100;

    private const int MAX_TERM = 80;

    private const array COLUMNS = ['id', 'employee_code', 'employee_id_no', 'first_name', 'middle_name', 'last_name', 'suffix', 'position', 'is_active', 'email'];

    private const array SEARCHED = ['first_name', 'middle_name', 'last_name', 'employee_code', 'employee_id_no', 'email', 'position'];

    /** @return list<KioskEmployee> */
    public function search(Access $actor, string $term): array
    {
        if (! $actor->mayReadAllData(ModuleCode::EDTR)) {
            return [];
        }

        $term = mb_substr(trim($term), 0, self::MAX_TERM);
        $like = '%'.addcslashes($term, '\\%_').'%';

        $rows = EmployeeProfile::query()
            ->select(self::COLUMNS)
            ->when($term !== '', static function ($query) use ($like): void {
                $query->where(static function ($inner) use ($like): void {
                    foreach (self::SEARCHED as $column) {
                        $inner->orWhere($column, 'like', $like);
                    }
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(self::LIMIT)
            ->get();

        $employees = [];
        foreach ($rows as $row) {
            $employees[] = self::view($row);
        }

        return $employees;
    }

    /** @return KioskEmployee|null */
    public function own(Access $actor): ?array
    {
        if (! $actor->hasEmployeeLink()) {
            return null;
        }

        $row = EmployeeProfile::query()->select(self::COLUMNS)->find($actor->ownEmployeeId());

        return $row === null ? null : self::view($row);
    }

    /** @return KioskEmployee */
    private static function view(EmployeeProfile $employee): array
    {
        return [
            'id' => (string) $employee->getKey(),
            'employee_code' => (string) $employee->employee_code,
            'employee_no' => $employee->employee_id_no,
            'name' => EmployeeName::display($employee),
            'position' => $employee->position,
            'is_active' => (bool) $employee->is_active,
            'email' => $employee->email,
        ];
    }
}
