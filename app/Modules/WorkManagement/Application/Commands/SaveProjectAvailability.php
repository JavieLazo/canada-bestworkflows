<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\ProjectAvailability;
use App\Modules\WorkManagement\Domain\Projects\CapacityWindow;
use App\Modules\WorkManagement\Domain\Projects\PlanningHours;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveProjectAvailability
{
    public function handle(Access $actor, string $week, string $hours, bool $shared, int $revision): int
    {
        abort_unless($actor->isSignedIn() && $actor->hasEmployeeLink() && $actor->hasModule(ModuleCode::PROJECTS, ModuleCode::TASKS), 403);
        CapacityWindow::validateWeek($week);
        $amount = PlanningHours::parse($hours);
        $shared = $amount !== null && $shared;
        try {
            return DB::transaction(static function () use ($actor, $week, $amount, $shared, $revision): int {
                $row = ProjectAvailability::query()->where('employee_id', $actor->ownEmployeeId())->where('week_start', $week)->lockForUpdate()->first();
                if (($row->revision ?? 0) !== $revision) {
                    throw ValidationException::withMessages(['hours' => 'Your availability changed. Reopen this week before saving.']);
                }
                if (($row === null && $amount === null) || ($row !== null && $row->hours_hundredths === $amount && $row->shared === $shared)) {
                    return $revision;
                }
                $row ??= new ProjectAvailability;
                $row->forceFill(['employee_id' => $actor->ownEmployeeId(), 'week_start' => $week, 'hours_hundredths' => $amount,
                    'shared' => $shared, 'revision' => $revision + 1, 'updated_by' => (int) $actor->userId])->save();
                AuditService::record($actor, AuditEvent::PROJECT_AVAILABILITY_SAVED, 'project_availability', $row->id, 'Weekly project availability updated');

                return $row->revision;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['hours' => 'Your availability changed. Reopen this week before saving.']);
        }
    }
}
