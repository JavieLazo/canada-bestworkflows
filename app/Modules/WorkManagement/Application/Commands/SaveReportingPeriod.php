<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectBudget;
use App\Models\ProjectBudgetLine;
use App\Models\ProjectReport;
use App\Models\ProjectReportingPeriod;
use App\Modules\WorkManagement\Application\ReportingRules;
use App\Modules\WorkManagement\Contracts\Views\ReportPeriodView;
use App\Modules\WorkManagement\Domain\Tasks\TaskSchedule;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveReportingPeriod
{
    public function handle(Access $actor, string $projectId, ?string $id, int $revision, string $name, string $startsOn, string $endsOn, ?string $dueOn = null, bool $archived = false, string $reason = ''): ReportPeriodView
    {
        return DB::transaction(static function () use ($actor, $projectId, $id, $revision, $name, $startsOn, $endsOn, $dueOn, $archived, $reason): ReportPeriodView {
            $project = ReportingRules::locked($actor, $projectId, admin: true);
            Validator::make(['name' => trim($name)], ['name' => 'required|string|max:120'])->validate();
            foreach (['startsOn' => $startsOn, 'endsOn' => $endsOn, 'dueOn' => $dueOn] as $field => $date) {
                if ($date !== null && ! TaskSchedule::isDateKey($date)) {
                    throw ValidationException::withMessages([$field => 'Choose a valid date.']);
                }
            }
            if ($startsOn > $endsOn) {
                throw ValidationException::withMessages(['endsOn' => 'The period must end on or after its start.']);
            }
            $row = $id === null ? null : ProjectReportingPeriod::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->first();
            abort_if($id !== null && $row === null, 404);
            ReportingRules::revision($row, $revision);
            if ($row !== null && ($row->starts_on !== $startsOn || $row->ends_on !== $endsOn) && (ProjectReport::query()->where('period_id', $row->id)->exists() || ProjectBudgetLine::query()->where('period_id', $row->id)->whereIn('budget_id', ProjectBudget::query()->where('status', 'APPROVED')->select('id'))->exists())) {
                throw ValidationException::withMessages(['startsOn' => 'This period is used by reports. Create another period to change its dates.']);
            }
            $row ??= new ProjectReportingPeriod;
            ReportingRules::history($row, $actor, $reason);
            $row->forceFill(['project_id' => $project->id, 'name' => trim($name), 'starts_on' => $startsOn, 'ends_on' => $endsOn,
                'due_on' => $dueOn, 'archived' => $archived, 'revision' => $revision + 1, 'created_by' => $row->created_by ?? (int) $actor->userId, 'updated_by' => (int) $actor->userId])->save();
            AuditService::record($actor, AuditEvent::PROJECT_REPORT_CONFIGURED, 'projects', $project->id, 'Reporting period saved');

            return Snapshot::of($row, ReportPeriodView::class);
        });
    }
}
