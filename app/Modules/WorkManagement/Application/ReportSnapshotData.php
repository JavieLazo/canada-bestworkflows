<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Models\ProjectBudget;
use App\Models\ProjectBudgetLine;
use App\Models\ProjectCashAllocation;
use App\Models\ProjectCashEntry;
use App\Models\ProjectContributionDelivery;
use App\Models\ProjectFinance;
use App\Models\ProjectFundingInstallment;
use App\Models\ProjectOutcome;
use App\Models\ProjectRecordFile;
use App\Models\ProjectRecordValue;
use App\Models\ProjectReport;
use App\Models\ProjectReportField;
use App\Models\ProjectReportingPeriod;
use App\Models\ProjectReportTemplate;
use App\Models\ProjectResultEntry;
use App\Modules\Workforce\Contracts\AssignmentEmployees;
use App\Modules\WorkManagement\Application\Queries\ProjectFunding as FundingQuery;
use App\Modules\WorkManagement\Application\Queries\ProjectResults;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Support\Access;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class ReportSnapshotData
{
    public const MAX_ROWS = 1000;

    public const MAX_BYTES = 8000000;

    /** @return array<string, mixed> */
    public function build(Access $actor, ProjectReport $report): array
    {
        $template = ProjectReportTemplate::query()->where('project_id', $report->project_id)->whereKey($report->template_id)->firstOrFail();
        $period = ProjectReportingPeriod::query()->where('project_id', $report->project_id)->whereKey($report->period_id)->firstOrFail();
        $scope = ReportDefinitions::scope($template->definition);
        $work = $report->work || $scope['work'];
        $financial = $report->financial || $scope['financial'];
        abort_unless(ReportAccess::allows($actor, $report->project_id, $work, $financial), 403);
        $project = app(WorkRecords::class)->project($actor, $report->project_id);
        abort_if($project === null, 403);
        $asOf = min($period->ends_on, LocalClock::todayKey());
        $rows = [];
        $ids = ['PROJECT' => [$project->id], 'REPORT' => [$report->id]];
        $warnings = [];
        $blocking = [];
        foreach ($template->definition['sections'] as $section) {
            if ($section === 'profile') {
                $rows['profile'] = [['id' => $project->id, 'name' => $project->name, 'description' => $project->description,
                    'client' => $project->client_name ?? 'Internal', 'starts_on' => $project->start_date->format('Y-m-d'), 'ends_on' => $project->due_date->format('Y-m-d')]];
            } elseif ($section === 'work') {
                $rows['work'] = [];
                WorkScope::tasks($actor)->where('project_tasks.project_id', $project->id)
                    ->where(static fn (Builder $query) => $query->whereNull('start_date')->whereNull('due_date')
                        ->orWhere(static fn (Builder $query) => $query->whereDate('start_date', '<=', $period->ends_on)->where(static fn (Builder $query) => $query->whereNull('due_date')->orWhereDate('due_date', '>=', $period->starts_on)))
                        ->orWhere(static fn (Builder $query) => $query->whereNull('start_date')->whereDate('due_date', '>=', $period->starts_on)->whereDate('due_date', '<=', $period->ends_on)))
                    ->with('assignments')->orderBy('id')->chunkById(250, function ($tasks) use (&$rows): void {
                        $employeeIds = [];
                        foreach ($tasks as $task) {
                            foreach ($task->assignments as $assignment) {
                                $employeeIds[] = $assignment->employee_id;
                            }
                        }
                        $names = app(AssignmentEmployees::class)->names(array_values(array_unique($employeeIds)));
                        foreach ($tasks as $task) {
                            $rows['work'][] = ['id' => $task->id, 'code' => $task->code, 'parent_id' => $task->parent_id, 'group_id' => $task->group_id,
                                'title' => $task->title, 'status' => $task->status, 'priority' => $task->priority, 'starts_on' => $task->start_date?->format('Y-m-d'),
                                'due_on' => $task->due_date?->format('Y-m-d'), 'people' => implode(', ', array_map(static fn ($assignment): string => $names[$assignment->employee_id] ?? 'Employee', $task->assignments->all()))];
                        }
                        $this->bounded($rows['work']);
                    });
                $ids['TASK'] = array_column($rows['work'], 'id');
            } elseif ($section === 'results') {
                $query = app(ProjectResults::class);
                $indicators = $query->reportRows($actor, $project->id, $period->starts_on, $asOf);
                $cumulative = $query->reportRows($actor, $project->id, null, $asOf)->keyBy('id');
                $rows['results'] = [];
                foreach ($indicators as $indicator) {
                    $rows['results'][] = ['id' => $indicator->id, 'outcome_id' => $indicator->outcome_id, 'outcome' => $indicator->outcome_label,
                        'work' => $indicator->work_label, 'name' => $indicator->name, 'description' => $indicator->description, 'kind' => $indicator->kind,
                        'unit' => $indicator->unit, 'aggregation' => $indicator->aggregation, 'direction' => $indicator->direction, 'plan_version' => $indicator->plan_version,
                        'target_scaled' => $indicator->target_scaled, 'target_text' => $indicator->target_text, 'target_starts_on' => $indicator->starts_on, 'target_ends_on' => $indicator->ends_on,
                        'actual_scaled' => $indicator->actual_scaled, 'cumulative_scaled' => $cumulative->get($indicator->id)?->actual_scaled,
                        'actual_text' => $indicator->actual_text, 'actual_state' => $indicator->actual_state, 'last_recorded_on' => $indicator->last_recorded_on,
                        'status' => $indicator->status, 'period_entries' => $indicator->entry_count];
                }
                $this->bounded($rows['results']);
                $ids['INDICATOR'] = $indicators->pluck('id')->all();
                $rows['outcomes'] = ProjectOutcome::query()->where('project_id', $project->id)->orderBy('id')->limit(self::MAX_ROWS + 1)->get()->map->attributesToArray()->all();
                $this->bounded($rows['outcomes']);
                $ids['OUTCOME'] = array_column($rows['outcomes'], 'id');
                $latest = $indicators->where('aggregation', 'LATEST')->pluck('latest_entry_id')->filter()->all();
                $entries = ProjectResultEntry::query()->where('project_id', $project->id)->whereNull('voided_at')
                    ->where(static fn (Builder $query) => $query->whereBetween('occurred_on', [$period->starts_on, $asOf])->orWhereIn('id', $latest))
                    ->orderBy('occurred_on')->orderBy('id')->limit(self::MAX_ROWS + 1)->get();
                $rows['accomplishments'] = $entries->map->attributesToArray()->all();
                $this->bounded($rows['accomplishments']);
                $ids['RESULT_ENTRY'] = $entries->pluck('id')->all();
            } elseif ($section === 'budget') {
                $budget = $report->budget_id === null ? null : ProjectBudget::query()->where('project_id', $project->id)->whereIn('status', ['APPROVED', 'DRAFT'])->whereKey($report->budget_id)->first();
                if ($budget === null) {
                    $warnings[] = 'No budget plan selected.';
                    $rows['budget'] = [];
                } else {
                    $rows['budget'] = ProjectBudgetLine::query()->where('budget_id', $budget->id)->orderBy('id')->limit(self::MAX_ROWS + 1)->get()->map->attributesToArray()->all();
                    $this->bounded($rows['budget']);
                    $ids['BUDGET_LINE'] = array_column($rows['budget'], 'id');
                    $baseline = ['id' => $budget->id, 'version' => $budget->version, 'revision' => $budget->revision, 'status' => $budget->status, 'approved_at' => $budget->approved_at?->toIso8601String(), 'total_minor' => $budget->status === 'APPROVED' ? $budget->total_minor : (int) ProjectBudgetLine::query()->where('budget_id', $budget->id)->sum('total_minor')];
                }
            } elseif ($section === 'cash') {
                $entries = ProjectCashEntry::query()->where('project_id', $project->id)->whereNull('voided_at')
                    ->whereDate('occurred_on', '>=', $period->starts_on)->whereDate('occurred_on', '<=', $asOf)->orderBy('occurred_on')->orderBy('id')->limit(self::MAX_ROWS + 1)->get();
                $rows['cash'] = $entries->map->attributesToArray()->all();
                $this->bounded($rows['cash']);
                $ids['CASH_ENTRY'] = $entries->pluck('id')->all();
                $rows['allocations'] = [];
                foreach (array_chunk($ids['CASH_ENTRY'], 500) as $chunk) {
                    $allocations = ProjectCashAllocation::query()->whereIn('entry_id', $chunk)->whereExists(static fn ($query) => $query->selectRaw('1')->from('project_cash_entries')->whereColumn('id', 'project_cash_allocations.entry_id')->whereColumn('allocation_revision', 'project_cash_allocations.revision'))->get();
                    foreach ($allocations as $allocation) {
                        $rows['allocations'][] = $allocation->attributesToArray();
                    }
                }
                $this->bounded($rows['allocations']);
            } elseif ($section === 'funding') {
                $rows['funding'] = $this->fundingRows($actor, $project->id, $period->starts_on, $asOf, false);
                $ids['FUNDING'] = array_column($rows['funding'], 'id');
                $rows['installments'] = ProjectFundingInstallment::query()->where('project_id', $project->id)->orderBy('id')->limit(self::MAX_ROWS + 1)->get()->map->attributesToArray()->all();
                $this->bounded($rows['installments']);
            } elseif ($section === 'contributions') {
                $rows['contributions'] = $this->fundingRows($actor, $project->id, $period->starts_on, $asOf, true);
                $ids['CONTRIBUTION'] = array_column($rows['contributions'], 'id');
                $rows['deliveries'] = ProjectContributionDelivery::query()->where('project_id', $project->id)->whereNull('voided_at')->whereBetween('occurred_on', [$period->starts_on, $asOf])->orderBy('id')->limit(self::MAX_ROWS + 1)->get()->map->attributesToArray()->all();
                $this->bounded($rows['deliveries']);
                $ids['CONTRIBUTION_DELIVERY'] = array_column($rows['deliveries'], 'id');
            }
        }
        $fields = ProjectReportField::query()->where('project_id', $project->id)->whereKey($template->definition['fields'])->get()
            ->sortBy(static fn (ProjectReportField $field) => array_search($field->id, $template->definition['fields'], true))->values();
        $answers = [];
        $answerBytes = 0;
        foreach ($fields->groupBy('entity_type') as $entity => $definitions) {
            foreach (array_chunk($ids[$entity] ?? [], 500) as $chunk) {
                ProjectRecordValue::query()->where('project_id', $project->id)->whereIn('field_id', $definitions->pluck('id')->all())->whereIn('record_id', $chunk)
                    ->chunkById(100, function ($values) use (&$answers, &$answerBytes): void {
                        foreach ($values as $value) {
                            $answerBytes += strlen(json_encode($value->value, JSON_THROW_ON_ERROR));
                            if ($answerBytes > self::MAX_BYTES) {
                                throw ValidationException::withMessages(['report' => 'The additional answers exceed the report limit. Choose a narrower period or fewer fields.']);
                            }
                            $answers[$value->field_id][$value->record_id] = $value->value['value'] ?? null;
                        }
                    });
            }
            foreach ($definitions as $field) {
                if ($field->required) {
                    foreach ($ids[$entity] ?? [] as $id) {
                        $value = $answers[$field->id][$id] ?? null;
                        if ($value === null || $value === '' || $value === []) {
                            $blocking[] = $field->name.' is missing for record '.$id;
                        }
                    }
                }
            }
        }
        $files = [];
        foreach (['RESULT_ENTRY', 'CASH_ENTRY', 'CONTRIBUTION_DELIVERY', 'REPORT'] as $type) {
            foreach (array_chunk($ids[$type] ?? [], 500) as $chunk) {
                foreach (ProjectRecordFile::query()->where('project_id', $project->id)->where('entity_type', $type)->whereIn('record_id', $chunk)->whereNull('removed_at')->limit(self::MAX_ROWS + 1)->get(['id', 'entity_type', 'record_id', 'file_name', 'mime_type', 'file_size', 'sha256']) as $file) {
                    $files[$file->id] = $file->only(['id', 'entity_type', 'record_id', 'file_name', 'mime_type', 'file_size', 'sha256']);
                    $this->bounded($files);
                }
            }
        }
        $extraFileIds = [];
        foreach ($fields->where('type', 'EVIDENCE') as $field) {
            foreach ($answers[$field->id] ?? [] as $value) {
                foreach (is_array($value) ? $value : [] as $fileId) {
                    if (is_string($fileId)) {
                        $extraFileIds[] = $fileId;
                    }
                }
            }
        }
        foreach (array_chunk(array_unique($extraFileIds), 500) as $chunk) {
            foreach (ProjectRecordFile::query()->where('project_id', $project->id)->whereKey($chunk)->whereNull('removed_at')->get() as $file) {
                $work = $work || $file->entity_type === 'RESULT_ENTRY';
                $sourceReport = $file->entity_type === 'REPORT' ? ProjectReport::query()->where('project_id', $project->id)->whereKey($file->record_id)->first(['work', 'financial']) : null;
                $work = $work || ($sourceReport->work ?? false);
                $sensitive = in_array($file->entity_type, ['CASH_ENTRY', 'CONTRIBUTION_DELIVERY'], true)
                    || ($sourceReport->financial ?? false);
                $financial = $financial || $sensitive;
                $files[$file->id] = $file->only(['id', 'entity_type', 'record_id', 'file_name', 'mime_type', 'file_size', 'sha256']);
                $this->bounded($files);
            }
        }
        abort_unless(ReportAccess::allows($actor, $report->project_id, $work, $financial), 403);
        if ($period->ends_on > $asOf) {
            $warnings[] = 'Actuals are recorded only through '.$asOf.'. The reporting period has not ended.';
        }
        foreach ($rows['accomplishments'] ?? [] as $entry) {
            if ($entry['evidence_required'] && ! collect($files)->contains(static fn (array $file): bool => $file['entity_type'] === 'RESULT_ENTRY' && $file['record_id'] === $entry['id'])) {
                $blocking[] = 'Required evidence is missing for accomplishment '.$entry['id'];
            }
        }
        $settings = $financial ? ProjectFinance::query()->where('project_id', $project->id)->first() : null;
        if (! isset($baseline) && $report->budget_id !== null) {
            $selected = ProjectBudget::query()->where('project_id', $project->id)->whereKey($report->budget_id)->whereIn('status', ['APPROVED', 'DRAFT'])->first();
            if ($selected !== null) {
                $baseline = ['id' => $selected->id, 'version' => $selected->version, 'revision' => $selected->revision, 'status' => $selected->status, 'approved_at' => $selected->approved_at?->toIso8601String(), 'total_minor' => $selected->status === 'APPROVED' ? $selected->total_minor : (int) ProjectBudgetLine::query()->where('budget_id', $selected->id)->sum('total_minor')];
            }
        }
        if (($baseline['status'] ?? '') === 'DRAFT') {
            $blocking[] = 'Approve the selected budget before submitting this report.';
        }
        $cashPosition = $financial && $settings !== null ? app(FundingQuery::class)->cashPosition($actor, $project->id, $asOf) : null;
        $periodOpening = $settings === null || $period->starts_on > LocalClock::todayKey() ? null : ($settings->opening_on === $period->starts_on ? $settings->opening_minor
            : app(FundingQuery::class)->cashPosition($actor, $project->id, CarbonImmutable::parse($period->starts_on)->subDay()->format('Y-m-d')));
        $variance = $financial ? app(FundingQuery::class)->periodVariance($actor, $project->id, $period->id, $report->budget_id) : null;
        if ($financial && $settings === null) {
            $blocking[] = 'Choose a project finance currency before submitting a financial report.';
        }
        if (($variance['unallocated_costs'] ?? 0) > 0) {
            $warnings[] = $variance['unallocated_costs'].' budget cost items have no reporting period.';
        }
        if ($financial && $periodOpening === null) {
            $warnings[] = 'The opening cash position for this reporting period is not recorded.';
        }
        $data = ['schema_version' => 1, 'actual_through' => $asOf, 'report_id' => $report->id, 'title' => $report->title, 'project_id' => $project->id,
            'template' => $template->only(['id', 'name', 'kind', 'revision', 'definition']), 'period' => $period->only(['id', 'name', 'starts_on', 'ends_on', 'due_on', 'revision']),
            'scope' => ['work' => $work, 'financial' => $financial], 'currency' => $settings?->currency, 'budget_baseline' => $baseline ?? null,
            'opening' => $settings?->only(['opening_on', 'opening_minor', 'opening_reference', 'opening_notes', 'opening_revision']), 'recorded_cash_position_minor' => $cashPosition,
            'period_variance' => $variance, 'period_opening_minor' => $periodOpening,
            'summary' => $report->summary, 'issues' => $report->issues, 'corrective_action' => $report->corrective_action, 'amendment_reason' => $report->amendment_reason,
            'sections' => $rows, 'fields' => $fields->map(static fn (ProjectReportField $field): array => $field->only(['id', 'entity_type', 'name', 'type', 'unit', 'visibility', 'required', 'total', 'options', 'revision']))->all(), 'answers' => $answers, 'files' => array_values($files),
            'coverage' => ['blocking' => array_values(array_unique($blocking)), 'warnings' => $warnings], 'captured_at' => now()->toIso8601String(), 'captured_by' => ['id' => (int) $actor->userId, 'name' => $actor->fullName]];
        if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['report' => 'This report is too large. Choose a narrower period or fewer sections.']);
        }

        return $data;
    }

    /** @param array<mixed> $rows */
    private function bounded(array $rows): void
    {
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['report' => 'A report section exceeds 1,000 rows. Choose a narrower period or fewer sections.']);
        }
    }

    /** @return list<array<string, mixed>> */
    private function fundingRows(Access $actor, string $projectId, string $from, string $to, bool $contributions): array
    {
        $query = app(FundingQuery::class);
        $rows = $query->reportRows($actor, $projectId, $from, $to, $contributions);
        $this->bounded($rows);
        $cumulative = [];
        foreach ($query->reportRows($actor, $projectId, null, $to, $contributions) as $row) {
            $cumulative[$row['id']] = $row;
        }
        foreach ($rows as &$row) {
            unset($row['history']);
            $row['cumulative_received_minor'] = $cumulative[$row['id']]['received_minor'] ?? null;
            if ($contributions) {
                $row['cumulative_quantity_scaled'] = $cumulative[$row['id']]['received_quantity'] ?? null;
            }
        }
        unset($row);

        return $rows;
    }
}
