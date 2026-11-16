<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Models\ProjectBudget;
use App\Models\ProjectBudgetLine;
use App\Models\ProjectCashAllocation;
use App\Models\ProjectCashEntry;
use App\Models\ProjectContribution;
use App\Models\ProjectContributionDelivery;
use App\Models\ProjectCostCategory;
use App\Models\ProjectFunding;
use App\Models\ProjectIndicator;
use App\Models\ProjectOutcome;
use App\Models\ProjectRecordValue;
use App\Models\ProjectReport;
use App\Models\ProjectReportField;
use App\Models\ProjectReportingPeriod;
use App\Models\ProjectResultEntry;
use App\Models\ProjectTask;
use App\Models\ProjectTaskGroup;
use App\Platform\Transfer\CsvSheet;
use App\Platform\Transfer\ImportReport;
use App\Platform\Transfer\ImportRunner;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WorkbookTransfer
{
    /** @param array<string, mixed> $options
     * @param  array<string, mixed>  $mapping  Destination key to source column number, or blank when unmapped.
     * @return array{report: ImportReport, stamp: string}
     */
    public function run(Access $actor, string $projectId, string $mode, CsvSheet $source, array $options, array $mapping, ?string $expected = null): array
    {
        foreach ($mapping as $value) {
            if (! is_string($value)) {
                throw ValidationException::withMessages(['workbook' => 'Column mappings must be column numbers or blank.']);
            }
        }
        $adapter = new WorkbookRows($mode, $options, array_keys(array_filter($mapping, static fn ($value): bool => $value !== '')));
        abort_unless($adapter->allows($actor, $projectId), 403);
        $columns = $adapter->columns($actor, $projectId);
        if (array_diff(array_keys($mapping), array_keys($columns)) !== []) {
            throw ValidationException::withMessages(['workbook' => 'The destination fields changed. Map this sheet again.']);
        }
        $rows = [];
        foreach ($source->rows as $row) {
            $cells = [];
            foreach ($columns as $key => $label) {
                $index = $mapping[$key] ?? '';
                if ($index !== '' && (! ctype_digit($index) || ! array_key_exists((int) $index, $source->headers))) {
                    throw ValidationException::withMessages(['workbook' => 'Choose columns from this sheet.']);
                }
                $cells[] = $index === '' ? '' : ($row['cells'][(int) $index] ?? '');
            }
            if (implode('', $cells) !== '') {
                $rows[] = ['line' => $row['line'], 'cells' => $cells];
            }
        }
        $sheet = new CsvSheet(array_keys($columns), $rows);

        return DB::transaction(function () use ($actor, $projectId, $mode, $source, $options, $mapping, $adapter, $sheet, $expected): array {
            $project = WorkRules::lockedProject($actor, $projectId);
            abort_if($project === null, 403);
            abort_unless($adapter->allows($actor, $project->id), 403);
            $entity = $mode === 'FIELDS' && is_string($options['entity'] ?? null) ? $options['entity'] : $mode;
            if ($entity !== 'REPORT') {
                if (in_array($entity, ['BUDGET_LINE', 'CASH_ENTRY', 'FUNDING', 'CONTRIBUTION', 'CONTRIBUTION_DELIVERY'], true)) {
                    FinanceRules::locked($actor, $project->id, edit: true);
                } else {
                    ReportingRules::locked($actor, $project->id, edit: true);
                }
            }
            $stamp = hash('sha256', json_encode([$project->id, $mode, $source->headers, $source->rows, $options, $mapping, $this->state($actor, $project->id, $mode, $mode === 'FIELDS' && is_string($options['entity'] ?? null) ? $options['entity'] : $mode)], JSON_THROW_ON_ERROR));
            if ($expected !== null && ! hash_equals($expected, $stamp)) {
                throw ValidationException::withMessages(['workbook' => 'The file, mapping or project records changed after preview. Preview again before importing.']);
            }
            $report = $expected === null ? app(ImportRunner::class)->previewSheet($actor, $adapter, $project->id, $sheet) : app(ImportRunner::class)->commitSheet($actor, $adapter, $project->id, $sheet);

            return compact('report', 'stamp');
        }, 3);
    }

    /** @return array<string, mixed> */
    private function state(Access $actor, string $projectId, string $mode, string $entity): array
    {
        $fieldIds = ReportFieldValues::definitions($actor, $projectId, $entity)->pluck('id')->all();
        $classes = [ProjectReportField::class, ProjectRecordValue::class, ProjectReportingPeriod::class];
        $classes = array_merge($classes, match ($mode) {
            'RESULT_ENTRY' => [ProjectIndicator::class, ProjectResultEntry::class],
            'BUDGET_LINE' => [ProjectBudget::class, ProjectBudgetLine::class, ProjectCostCategory::class, ProjectTaskGroup::class],
            'CASH_ENTRY' => [ProjectCashEntry::class, ProjectCashAllocation::class, ProjectCostCategory::class],
            'CONTRIBUTION_DELIVERY' => [ProjectContribution::class, ProjectContributionDelivery::class],
            'TASK' => [ProjectTask::class], default => match ($entity) {
                'REPORT' => [ProjectReport::class], 'BUDGET_LINE' => [ProjectBudget::class, ProjectBudgetLine::class], 'TASK' => [ProjectTask::class],
                'OUTCOME' => [ProjectOutcome::class], 'INDICATOR' => [ProjectIndicator::class], 'RESULT_ENTRY' => [ProjectResultEntry::class],
                'CASH_ENTRY' => [ProjectCashEntry::class], 'FUNDING' => [ProjectFunding::class], 'CONTRIBUTION' => [ProjectContribution::class],
                'CONTRIBUTION_DELIVERY' => [ProjectContributionDelivery::class], default => [],
            },
        });
        $state = [];
        foreach ($classes as $class) {
            $query = $class::query()->where('project_id', $projectId)->orderBy('id');
            if ($class === ProjectTask::class) {
                $query->select(['id', 'project_id', 'code', 'title', 'priority', 'status', 'start_date', 'due_date', 'parent_id', 'group_id']);
            }
            if ($class === ProjectReportField::class) {
                $query->whereKey($fieldIds);
            }
            if ($class === ProjectRecordValue::class) {
                $query->whereIn('field_id', $fieldIds);
                if ($entity === 'REPORT') {
                    $query->whereIn('record_id', ReportAccess::reports($actor, $projectId)->select('id'));
                }
            }
            if ($class === ProjectReport::class) {
                $query->whereIn('id', ReportAccess::reports($actor, $projectId)->select('id'));
            }

            // Work commands lock Task before Project. Reading task planning here must keep that order intact.
            $rows = $class === ProjectTask::class ? $query->get() : $query->lockForUpdate()->get();
            $state[$class] = hash('sha256', $rows->toJson(JSON_THROW_ON_ERROR));
        }

        return $state;
    }
}
