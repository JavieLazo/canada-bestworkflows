<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Models\ProjectBudget;
use App\Models\ProjectBudgetLine;
use App\Models\ProjectCashEntry;
use App\Models\ProjectContributionDelivery;
use App\Models\ProjectCostCategory;
use App\Models\ProjectIndicator;
use App\Models\ProjectRecordValue;
use App\Models\ProjectReportingPeriod;
use App\Models\ProjectResultEntry;
use App\Models\ProjectTask;
use App\Models\ProjectTaskGroup;
use App\Modules\WorkManagement\Application\Commands\ImportTasks;
use App\Modules\WorkManagement\Application\Commands\RecordAllocatedCash;
use App\Modules\WorkManagement\Application\Commands\RecordCashEntry;
use App\Modules\WorkManagement\Application\Commands\RecordContributionDelivery;
use App\Modules\WorkManagement\Application\Commands\RecordResultEntry;
use App\Modules\WorkManagement\Application\Commands\SaveBudgetLine;
use App\Modules\WorkManagement\Application\Commands\SaveRecordValues;
use App\Modules\WorkManagement\Application\Queries\ProjectFinancials;
use App\Modules\WorkManagement\Application\Queries\ProjectReports;
use App\Modules\WorkManagement\Application\Queries\ProjectResults;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Modules\WorkManagement\Domain\Projects\ResultValue;
use App\Platform\Transfer\ImportLayout;
use App\Platform\Transfer\ImportOutcome;
use App\Platform\Transfer\ImportRow;
use App\Platform\Transfer\TableImport;
use App\Support\Access;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Workbook rows use the same commands and rollback-only preview as CSV imports. */
final class WorkbookRows implements TableImport
{
    public const MODES = ['RESULT_ENTRY' => 'Accomplishments', 'BUDGET_LINE' => 'Budget cost items', 'CASH_ENTRY' => 'Cash entries', 'CONTRIBUTION_DELIVERY' => 'In-kind deliveries', 'TASK' => 'Work plan tasks', 'FIELDS' => 'Additional fields'];

    /** @var array<string, array<string, string>> */
    public const COLUMNS = [
        'RESULT_ENTRY' => ['id' => 'Entry ID', 'indicator' => 'Indicator ID / name', 'date' => 'Actual date', 'value' => 'Numeric actual', 'text' => 'Qualitative actual', 'state' => 'Actual state', 'narrative' => 'Accomplishment', 'issues' => 'Issues', 'action' => 'Corrective action', 'reference' => 'Reference'],
        'BUDGET_LINE' => ['id' => 'Cost ID', 'label' => 'Cost item', 'category' => 'Category', 'group' => 'Activity', 'basis' => 'Cost basis', 'quantity' => 'Quantity', 'frequency' => 'Frequency', 'unit' => 'Unit cost', 'amount' => 'Entered amount / flat amount', 'period' => 'Reporting period', 'notes' => 'Notes'],
        'CASH_ENTRY' => ['id' => 'Cash ID', 'date' => 'Actual date', 'kind' => 'Type', 'description' => 'Description', 'category' => 'Category', 'amount' => 'Amount', 'party' => 'Payer / payee', 'reference' => 'Reference', 'notes' => 'Notes'],
        'CONTRIBUTION_DELIVERY' => ['id' => 'Delivery ID', 'contribution' => 'Contribution ID', 'date' => 'Actual date', 'quantity' => 'Delivered quantity', 'amount' => 'Recorded value', 'basis' => 'Valuation basis', 'reference' => 'Reference', 'notes' => 'Notes'],
        'TASK' => ['id' => 'Task code', 'title' => 'Task', 'assignees' => 'People', 'priority' => 'Priority', 'start' => 'Start', 'due' => 'Deadline', 'status' => 'Status'],
        'FIELDS' => ['id' => 'Record ID'],
    ];

    /** @param array<string, mixed> $options
     * @param  list<string>  $mapped
     */
    public function __construct(private readonly string $mode, private readonly array $options = [], private readonly array $mapped = []) {}

    public function allows(Access $actor, string $context): bool
    {
        if (! isset(self::MODES[$this->mode])) {
            return false;
        }
        if ($this->mode === 'FIELDS') {
            return isset(self::fieldEntities($actor, $context)[$this->option('entity', 'REPORT')]);
        }

        return in_array($this->mode, ['RESULT_ENTRY', 'TASK'], true)
            ? app(ProjectResults::class)->permissions($actor, $context)['edit']
            : app(ProjectFinancials::class)->permissions($actor, $context)['edit'];
    }

    /** @return array<string, string> */
    public static function fieldEntities(Access $actor, string $projectId): array
    {
        $work = app(ProjectResults::class)->permissions($actor, $projectId)['edit'];
        $finance = app(ProjectFinancials::class)->permissions($actor, $projectId)['edit'];

        return array_filter(ReportDefinitions::ENTITIES, static fn (string $entity): bool => $entity === 'REPORT' ? $work || $finance
            : (in_array($entity, ['BUDGET_LINE', 'CASH_ENTRY', 'FUNDING', 'CONTRIBUTION', 'CONTRIBUTION_DELIVERY'], true) ? $finance : $work), ARRAY_FILTER_USE_KEY);
    }

    /** @return array<string, string> */
    public function columns(Access $actor, string $context): array
    {
        abort_unless($this->allows($actor, $context), 403);
        $columns = self::COLUMNS[$this->mode];
        $entity = $this->mode === 'FIELDS' ? $this->option('entity', 'REPORT') : $this->mode;
        foreach (ReportFieldValues::definitions($actor, $context, $entity, edit: $entity !== 'REPORT') as $field) {
            $columns['field:'.$field->id] = 'Field: '.$field->name.($field->unit ? ' ('.$field->unit.')' : '');
        }

        return $columns;
    }

    public function layout(Access $actor, string $context): ImportLayout
    {
        return new ImportLayout(self::MODES[$this->mode], 'id', array_keys($this->columns($actor, $context)));
    }

    public function row(Access $actor, string $context, ImportRow $row): ImportOutcome
    {
        abort_unless($this->allows($actor, $context), 403);

        return match ($this->mode) {
            'RESULT_ENTRY' => $this->result($actor, $context, $row),
            'BUDGET_LINE' => $this->budget($actor, $context, $row),
            'CASH_ENTRY' => $this->cash($actor, $context, $row),
            'CONTRIBUTION_DELIVERY' => $this->delivery($actor, $context, $row),
            'TASK' => $this->task($actor, $context, $row),
            'FIELDS' => $this->fields($actor, $context, $this->option('entity', 'REPORT'), $row->identifier('id'), $row) ? ImportOutcome::UPDATED : ImportOutcome::UNCHANGED,
            default => throw new RuntimeException('Choose an import destination.'),
        };
    }

    private function result(Access $actor, string $projectId, ImportRow $row): ImportOutcome
    {
        $project = ReportingRules::locked($actor, $projectId, edit: true);
        $old = $row->identifier('id') === '' ? null : ProjectResultEntry::query()->where('project_id', $project->id)->whereKey($row->identifier('id'))->lockForUpdate()->firstOrFail();
        $indicator = $this->named(ProjectIndicator::class, $project->id, $row->text('indicator') ?: ($old->indicator_id ?? $this->option('indicatorId')), 'name');
        $numeric = $indicator->getAttribute('kind') === 'NUMERIC';
        $values = ['indicator_id' => $indicator->getKey(), 'occurred_on' => $row->date('date'), 'value_scaled' => $numeric ? ResultValue::parse($row->money('value')) : null,
            'value_text' => $numeric ? null : ($row->text('text') ?: null), 'state' => $numeric ? null : ($row->text('state') ?: 'IN_PROGRESS'), 'narrative' => $row->text('narrative') ?: null,
            'issues' => $row->text('issues') ?: null, 'corrective_action' => $row->text('action') ?: null, 'reference' => $row->text('reference') ?: null];
        if ($old !== null && $this->same($old, $values)) {
            return $this->fields($actor, $project->id, 'RESULT_ENTRY', $old->id, $row) ? ImportOutcome::UPDATED : ImportOutcome::UNCHANGED;
        }
        if ($old === null && $this->duplicate(ProjectResultEntry::class, $project->id, $values)) {
            return ImportOutcome::UNCHANGED;
        }
        $id = app(RecordResultEntry::class)->handle($actor, $project->id, (string) $indicator->getKey(), new ResultEntryDraft($row->date('date') ?? '', $row->money('value'), $row->text('text'), $numeric ? null : ($row->text('state') ?: 'IN_PROGRESS'), $row->text('narrative'), $row->text('issues'), $row->text('action'), $row->text('reference'), $old?->id, $this->option('reason')));
        $this->fields($actor, $project->id, 'RESULT_ENTRY', $id, $row);

        return $old === null ? ImportOutcome::CREATED : ImportOutcome::UPDATED;
    }

    private function budget(Access $actor, string $projectId, ImportRow $row): ImportOutcome
    {
        $project = FinanceRules::locked($actor, $projectId, edit: true);
        $budget = ProjectBudget::query()->where('project_id', $project->id)->whereKey($this->option('budgetId'))->lockForUpdate()->firstOrFail();
        FinanceRules::draft($project->id, $budget->id, $budget->revision);
        $old = $row->identifier('id') === '' ? null : ProjectBudgetLine::query()->where('budget_id', $budget->id)->whereKey($row->identifier('id'))->firstOrFail();
        $category = $this->named(ProjectCostCategory::class, $project->id, $row->text('category') ?: ($old->category_id ?? $this->option('categoryId')), 'name');
        $groupValue = $row->text('group') ?: (! in_array('group', $this->mapped, true) ? ($old->group_id ?? '') : '');
        $group = $groupValue === '' ? null : $this->named(ProjectTaskGroup::class, $project->id, $groupValue, 'name');
        $basis = $row->text('basis') ?: 'FLAT';
        $quantity = $basis === 'CALCULATED' ? FinanceAmount::quantity($row->money('quantity')) : null;
        $unit = $basis === 'CALCULATED' ? FinanceAmount::minor($row->money('unit'), 'unitAmount') : null;
        $frequency = $basis === 'CALCULATED' ? ($row->integer('frequency') ?? 1) : null;
        $total = $quantity !== null && $unit !== null ? FinanceAmount::calculated($quantity, $frequency ?? 1, $unit) : FinanceAmount::minor($row->money('amount'), 'amount');
        $values = ['budget_id' => $budget->id, 'label' => $row->text('label'), 'category_id' => $category->getKey(), 'group_id' => $group?->getKey(), 'basis' => $basis,
            'quantity_scaled' => $quantity, 'unit_minor' => $unit, 'frequency' => $frequency, 'total_minor' => $total, 'notes' => $row->text('notes') ?: null];
        $reporting = null;
        if (in_array('period', $this->mapped, true)) {
            $period = $row->text('period') === '' ? null : $this->named(ProjectReportingPeriod::class, $project->id, $row->text('period'), 'name');
            $values['period_id'] = $period?->getKey();
            $reporting = ['periodId' => $period?->getKey(), 'sourceKind' => $old?->funding_id !== null ? 'FUNDING' : ($old?->contribution_id !== null ? 'CONTRIBUTION' : 'NONE'), 'sourceId' => $old->funding_id ?? $old->contribution_id ?? null];
        }
        if ($old !== null && $this->same($old, $values)) {
            return $this->fields($actor, $project->id, 'BUDGET_LINE', $old->id, $row) ? ImportOutcome::UPDATED : ImportOutcome::UNCHANGED;
        }
        if ($old === null && $this->duplicate(ProjectBudgetLine::class, $project->id, $values)) {
            return ImportOutcome::UNCHANGED;
        }
        app(SaveBudgetLine::class)->handle($actor, $project->id, $budget->id, $budget->revision, $old?->id, new BudgetLineDraft($row->text('label'), (string) $category->getKey(), $row->money('amount'), $basis, $row->money('quantity'), $frequency ?? 1, $row->money('unit'), $group?->getKey(), $row->text('notes'), $reporting));
        $savedQuery = ProjectBudgetLine::query()->where('project_id', $project->id);
        foreach ($values as $key => $value) {
            $savedQuery->where($key, $value);
        }
        $saved = $old ?? $savedQuery->sole();
        $this->fields($actor, $project->id, 'BUDGET_LINE', $saved->id, $row);

        return $old === null ? ImportOutcome::CREATED : ImportOutcome::UPDATED;
    }

    private function cash(Access $actor, string $projectId, ImportRow $row): ImportOutcome
    {
        $project = FinanceRules::locked($actor, $projectId, edit: true);
        $old = $row->identifier('id') === '' ? null : ProjectCashEntry::query()->where('project_id', $project->id)->whereKey($row->identifier('id'))->lockForUpdate()->firstOrFail();
        $category = $this->named(ProjectCostCategory::class, $project->id, $row->text('category') ?: ($old->category_id ?? $this->option('categoryId')), 'name');
        $kind = $row->choice('kind', ['RECEIPT' => 'Cash received', 'EXPENSE' => 'Paid expense']);
        $values = ['kind' => $kind, 'occurred_on' => $row->date('date'), 'description' => $row->text('description'), 'amount_minor' => FinanceAmount::minor($row->money('amount'), 'amount', true),
            'category_id' => $category->getKey(), 'party' => $row->text('party') ?: null, 'reference' => $row->text('reference') ?: null, 'notes' => $row->text('notes') ?: null];
        if ($old !== null && $this->same($old, $values)) {
            return $this->fields($actor, $project->id, 'CASH_ENTRY', $old->id, $row) ? ImportOutcome::UPDATED : ImportOutcome::UNCHANGED;
        }
        if ($old === null && $this->duplicate(ProjectCashEntry::class, $project->id, $values)) {
            return ImportOutcome::UNCHANGED;
        }
        $input = new CashEntryDraft($kind, $row->date('date') ?? '', $row->text('description'), $row->money('amount'), (string) $category->getKey(), $old->group_id ?? null, $row->text('party'), $row->text('reference'), $row->text('notes'), $old?->id, $this->option('reason'));
        $source = $this->option('source');
        [$sourceKind, $sourceId] = array_pad(explode(':', $source, 2), 2, null);
        $id = $source === '' ? app(RecordCashEntry::class)->handle($actor, $project->id, $input) : app(RecordAllocatedCash::class)->handle($actor, $project->id, $input, $sourceKind, $sourceId);
        $this->fields($actor, $project->id, 'CASH_ENTRY', $id, $row);

        return $old === null ? ImportOutcome::CREATED : ImportOutcome::UPDATED;
    }

    private function delivery(Access $actor, string $projectId, ImportRow $row): ImportOutcome
    {
        $project = FinanceRules::locked($actor, $projectId, edit: true);
        $old = $row->identifier('id') === '' ? null : ProjectContributionDelivery::query()->where('project_id', $project->id)->whereKey($row->identifier('id'))->lockForUpdate()->firstOrFail();
        $contribution = $row->identifier('contribution') ?: ($old->contribution_id ?? $this->option('contributionId'));
        $values = ['contribution_id' => $contribution, 'occurred_on' => $row->date('date'), 'quantity_scaled' => ResultValue::parse($row->money('quantity')),
            'value_minor' => $row->money('amount') === '' ? null : FinanceAmount::minor($row->money('amount'), 'amount'), 'valuation_basis' => $row->text('basis') ?: null, 'reference' => $row->text('reference') ?: null, 'notes' => $row->text('notes') ?: null];
        if ($old !== null && $this->same($old, $values)) {
            return $this->fields($actor, $project->id, 'CONTRIBUTION_DELIVERY', $old->id, $row) ? ImportOutcome::UPDATED : ImportOutcome::UNCHANGED;
        }
        if ($old === null && $this->duplicate(ProjectContributionDelivery::class, $project->id, $values)) {
            return ImportOutcome::UNCHANGED;
        }
        $id = app(RecordContributionDelivery::class)->handle($actor, $project->id, $contribution, $row->date('date') ?? '', $row->money('quantity'), $row->money('amount'), $row->text('basis'), $row->text('reference'), $row->text('notes'), $old?->id, $this->option('reason'));
        $this->fields($actor, $project->id, 'CONTRIBUTION_DELIVERY', $id, $row);

        return $old === null ? ImportOutcome::CREATED : ImportOutcome::UPDATED;
    }

    private function task(Access $actor, string $projectId, ImportRow $row): ImportOutcome
    {
        $project = ReportingRules::locked($actor, $projectId, edit: true);
        if ($row->identifier('id') !== '') {
            $task = ProjectTask::query()->where('project_id', $project->id)->where(static fn ($query) => $query->where('code', $row->identifier('id'))->orWhere('id', $row->identifier('id')))->firstOrFail();
            foreach (['title' => $task->title, 'priority' => $task->priority, 'start' => WorkRules::dateKey($task->start_date) ?? '', 'due' => WorkRules::dateKey($task->due_date) ?? '', 'status' => $task->status] as $key => $current) {
                $value = in_array($key, ['start', 'due'], true) ? ($row->date($key) ?? '') : $row->text($key);
                if (in_array($key, $this->mapped, true) && $value !== $current) {
                    throw new RuntimeException('Existing task planning stays in its task panel or CSV import. Workbook rows may add new tasks and additional fields.');
                }
            }

            return $this->fields($actor, $project->id, 'TASK', $task->id, $row) ? ImportOutcome::UPDATED : ImportOutcome::UNCHANGED;
        }
        if (ProjectTask::query()->where('project_id', $project->id)->where('title', $row->text('title'))->exists()) {
            throw new RuntimeException('A task with this title already exists. Put its code in Task code, or use a distinct title for a new task.');
        }
        app(ImportTasks::class)->row($actor, $project->id, new ImportRow($row->line, ['Code' => '', 'Task' => $row->text('title'), 'Assignees' => $row->text('assignees'), 'Priority' => $row->text('priority'), 'Start date' => $row->date('start') ?? '', 'Due date' => $row->date('due') ?? '', 'Status' => $row->text('status')]));
        $saved = ProjectTask::query()->where('project_id', $project->id)->where('title', $row->text('title'))->sole();
        $this->fields($actor, $project->id, 'TASK', $saved->id, $row);

        return ImportOutcome::CREATED;
    }

    private function fields(Access $actor, string $projectId, string $entity, string $id, ImportRow $row): bool
    {
        $fields = ReportFieldValues::definitions($actor, $projectId, $entity, edit: $entity !== 'REPORT');
        $mapped = $fields->filter(fn ($field): bool => in_array('field:'.$field->id, $this->mapped, true));
        if ($mapped->isEmpty()) {
            if ($this->mode === 'FIELDS') {
                throw new RuntimeException('Map at least one additional field.');
            }

            return false;
        }
        $data = app(ProjectReports::class)->answers($actor, $projectId, $entity, $id, true);
        $answers = $data['values'];
        $stored = ProjectRecordValue::query()->where('project_id', $projectId)->where('record_id', $id)->whereIn('field_id', $fields->pluck('id')->all())->get()->keyBy('field_id');
        $changed = false;
        foreach ($mapped as $field) {
            $value = $row->text('field:'.$field->id);
            if ($field->type === 'EVIDENCE') {
                $value = array_values(array_filter(array_map(trim(...), explode(';', $value))));
            }
            if ($field->type === 'DATE') {
                $value = $row->date('field:'.$field->id);
            }
            if (in_array($field->type, ['NUMBER', 'MONEY'], true)) {
                $value = $row->money('field:'.$field->id);
            }
            $normalized = ReportFieldValues::normalize($actor, $field, $value, $projectId, $id);
            $changed = $changed || ($stored->get($field->id)?->value['value'] ?? null) !== $normalized;
            $answers[$field->id] = $value;
        }
        if ($changed) {
            app(SaveRecordValues::class)->handle($actor, $projectId, $entity, $id, $data['signature'], $answers);
        }

        return $changed;
    }

    /** @param class-string<Model> $class */
    private function named(string $class, string $projectId, string $value, string $label): Model
    {
        if ($value === '') {
            throw new RuntimeException('A required project reference is missing. Map it or choose its default.');
        }
        $rows = $class::query()->where('project_id', $projectId)->where(static fn ($query) => $query->where('id', $value)->orWhere($label, $value))->limit(2)->get();
        if ($rows->count() !== 1) {
            throw new RuntimeException('Choose an unambiguous ID or name from this project: '.$value);
        }

        return $rows->firstOrFail();
    }

    /** @param array<string, mixed> $values */
    private function same(Model $row, array $values): bool
    {
        if ($row->getAttribute('voided_at') !== null) {
            throw new RuntimeException('This entry is already voided or corrected. Use the current entry.');
        }
        foreach ($values as $key => $value) {
            $current = $row->getAttribute($key);
            if ($key === 'occurred_on') {
                $current = WorkRules::dateKey($current);
            }
            if ($current !== $value) {
                return false;
            }
        }

        return true;
    }

    /** @param class-string<Model> $class
     * @param  array<string, mixed>  $values
     */
    private function duplicate(string $class, string $projectId, array $values): bool
    {
        $query = $class::query()->where('project_id', $projectId);
        if ($class !== ProjectBudgetLine::class) {
            $query->whereNull('voided_at');
        }
        foreach ($values as $key => $value) {
            if ($key === 'occurred_on') {
                $query->whereDate($key, $value);
            } else {
                $query->where($key, $value);
            }
        }

        return $query->exists();
    }

    private function option(string $key, string $default = ''): string
    {
        return is_scalar($this->options[$key] ?? null) ? (string) $this->options[$key] : $default;
    }
}
