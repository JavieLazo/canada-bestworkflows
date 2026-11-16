<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Models\ProjectBudget;
use App\Models\ProjectBudgetLine;
use App\Models\ProjectCashEntry;
use App\Models\ProjectContribution;
use App\Models\ProjectContributionDelivery;
use App\Models\ProjectFunding;
use App\Models\ProjectIndicator;
use App\Models\ProjectOutcome;
use App\Models\ProjectRecordValue;
use App\Models\ProjectReport;
use App\Models\ProjectReportField;
use App\Models\ProjectResultEntry;
use App\Models\ProjectTask;
use App\Modules\WorkManagement\Application\Queries\ProjectRecordFiles;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Modules\WorkManagement\Domain\Projects\ResultValue;
use App\Modules\WorkManagement\Domain\Tasks\TaskSchedule;
use App\Modules\WorkManagement\Infrastructure\FinanceScope;
use App\Support\Access;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ReportFieldValues
{
    public static function record(Access $actor, string $projectId, string $entity, string $id, bool $edit): Model
    {
        $financial = in_array($entity, ['BUDGET_LINE', 'CASH_ENTRY', 'FUNDING', 'CONTRIBUTION', 'CONTRIBUTION_DELIVERY'], true);
        $report = $entity === 'REPORT' ? ProjectReport::query()->where('project_id', $projectId)->whereKey($id)->first() : null;
        if ($entity === 'REPORT') {
            abort_if($report === null, 404);
            $project = ReportAccess::locked($actor, $projectId, $report->work, $report->financial, $edit);
        } else {
            $project = $financial ? FinanceRules::locked($actor, $projectId, edit: $edit) : ReportingRules::locked($actor, $projectId, edit: $edit);
        }
        $row = match ($entity) {
            'PROJECT' => $project->id === $id ? $project : null,
            'TASK' => ProjectTask::query()->where('project_id', $project->id)->whereKey($id)->first(),
            'OUTCOME' => ProjectOutcome::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->first(),
            'INDICATOR' => ProjectIndicator::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->first(),
            'RESULT_ENTRY' => ProjectResultEntry::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->first(),
            'BUDGET_LINE' => ProjectBudgetLine::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->first(),
            'CASH_ENTRY' => ProjectCashEntry::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->first(),
            'FUNDING' => ProjectFunding::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->first(),
            'CONTRIBUTION' => ProjectContribution::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->first(),
            'CONTRIBUTION_DELIVERY' => ProjectContributionDelivery::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->first(),
            'REPORT' => ProjectReport::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->first(),
            default => null,
        };
        abort_if($row === null, 404);
        if ($entity === 'REPORT') {
            ReportAccess::locked($actor, $project->id, (bool) $row->getAttribute('work'), (bool) $row->getAttribute('financial'), $edit);
        }
        if ($edit && $entity === 'REPORT' && $row->getAttribute('status') !== 'DRAFT') {
            throw ValidationException::withMessages(['fields' => 'Start an amendment before changing submitted report answers.']);
        }
        if ($edit && in_array($entity, ['RESULT_ENTRY', 'CASH_ENTRY'], true) && $row->getAttribute('voided_at') !== null) {
            throw ValidationException::withMessages(['fields' => 'Keep the earlier entry in history. Edit the current replacement.']);
        }
        if ($edit && $entity === 'BUDGET_LINE' && ProjectBudget::query()->whereKey($row->getAttribute('budget_id'))->lockForUpdate()->first()?->status !== 'DRAFT') {
            throw ValidationException::withMessages(['fields' => 'Approved budget fields are retained. Start a budget revision first.']);
        }

        return $row;
    }

    /** @return Collection<int, ProjectReportField> */
    public static function definitions(Access $actor, string $projectId, string $entity, bool $lock = false, bool $edit = false): Collection
    {
        $financial = FinanceScope::projects($actor, edit: $edit)->whereKey($projectId)->exists();

        return ProjectReportField::query()->where('project_id', $projectId)->where('entity_type', $entity)->where('archived', false)
            ->when(! $financial, static fn ($query) => $query->where('visibility', 'PROJECT'))->orderBy('created_at')->orderBy('id')->when($lock, static fn ($query) => $query->lockForUpdate())->get();
    }

    /** @param Collection<int, ProjectReportField> $fields
     * @param  Collection<int, ProjectRecordValue>  $values
     */
    public static function signature(Collection $fields, Collection $values): string
    {
        return hash('sha256', json_encode([$fields->map(static fn (ProjectReportField $field): array => [$field->id, $field->revision])->all(),
            $values->sortBy('field_id')->map(static fn (ProjectRecordValue $value): array => [$value->field_id, $value->revision])->values()->all()], JSON_THROW_ON_ERROR));
    }

    public static function normalize(Access $actor, ProjectReportField $field, mixed $value, string $projectId, string $recordId): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            if ($field->required) {
                throw ValidationException::withMessages(['fieldValues.'.$field->id => $field->name.' is required.']);
            }

            return null;
        }
        $key = 'fieldValues.'.$field->id;
        if ($field->type === 'EVIDENCE') {
            if (! is_array($value) || ! array_is_list($value) || count($value) > 20) {
                throw ValidationException::withMessages([$key => 'Choose up to 20 evidence files attached to this record.']);
            }
            $ids = [];
            foreach ($value as $id) {
                $file = is_string($id) ? app(ProjectRecordFiles::class)->file($actor, $id) : null;
                $financialFile = $file !== null && (in_array($file->entity_type, ['CASH_ENTRY', 'CONTRIBUTION_DELIVERY'], true)
                    || ($file->entity_type === 'REPORT' && ProjectReport::query()->whereKey($file->record_id)->where('financial', true)->exists()));
                if ($file === null || $file->project_id !== $projectId || ($financialFile && $field->visibility !== 'FINANCE')) {
                    throw ValidationException::withMessages([$key => 'Choose evidence from this record.']);
                }
                $ids[] = $file->id;
            }

            return array_values(array_unique($ids));
        }
        if (! is_string($value) || mb_strlen($value) > 4000) {
            throw ValidationException::withMessages([$key => 'Enter a value in up to 4,000 characters.']);
        }
        $value = trim($value);

        return match ($field->type) {
            'NUMBER' => ResultValue::parse($value, $key),
            'MONEY' => FinanceAmount::signed($value, $key),
            'DATE' => TaskSchedule::isDateKey($value) ? $value : throw ValidationException::withMessages([$key => 'Choose a valid date.']),
            'CHOICE' => in_array($value, $field->options['choices'] ?? [], true) ? $value : throw ValidationException::withMessages([$key => 'Choose an available option.']),
            default => $value,
        };
    }
}
