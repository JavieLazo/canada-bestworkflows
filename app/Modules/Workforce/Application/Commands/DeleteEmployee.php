<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Application\EmployeeRetention;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Keeps inactive-only deletion and historical attribution protection inside Workforce. */
final class DeleteEmployee
{
    public function __construct(private readonly EmployeeRetention $retention) {}

    public function handle(Access $actor, string $id): ?string
    {
        abort_unless($actor->mayReadAllData(ModuleCode::EMPLOYEES), 403);
        $employee = EmployeeProfile::query()->find($id);
        if ($employee === null) {
            return null;
        }
        if ($employee->is_active) {
            throw new RuntimeException('Mark the employee inactive before deleting a test record.');
        }
        if ($this->retention->any($id)) {
            throw new RuntimeException('This employee has linked or historical records and must remain inactive instead of being deleted.');
        }
        $name = EmployeeName::display($employee);
        $employee->delete();
        if ($employee->photo_path !== null) {
            Storage::disk('public')->delete($employee->photo_path);
        }
        AuditService::record($actor, AuditEvent::EMPLOYEE_DELETED, 'employee_profiles', $id, "{$name} employee record deleted");

        return $name;
    }
}
