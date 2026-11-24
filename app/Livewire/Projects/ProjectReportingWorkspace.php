<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Modules\WorkManagement\Application\Commands\AmendProjectReport;
use App\Modules\WorkManagement\Application\Commands\RecordResultEntry;
use App\Modules\WorkManagement\Application\Commands\ReviewProjectReport;
use App\Modules\WorkManagement\Application\Commands\SaveIndicator;
use App\Modules\WorkManagement\Application\Commands\SaveOutcome;
use App\Modules\WorkManagement\Application\Commands\SaveProjectReport;
use App\Modules\WorkManagement\Application\Commands\SaveReportField;
use App\Modules\WorkManagement\Application\Commands\SaveReportingPeriod;
use App\Modules\WorkManagement\Application\Commands\SaveReportTemplate;
use App\Modules\WorkManagement\Application\Commands\SubmitProjectReport;
use App\Modules\WorkManagement\Application\Commands\UseGeneralReportTemplates;
use App\Modules\WorkManagement\Application\Commands\VoidResultEntry;
use App\Modules\WorkManagement\Application\IndicatorDraft;
use App\Modules\WorkManagement\Application\Queries\ProjectFinancials;
use App\Modules\WorkManagement\Application\Queries\ProjectReports;
use App\Modules\WorkManagement\Application\Queries\ProjectResults;
use App\Modules\WorkManagement\Application\Queries\ProjectTaskGroups;
use App\Modules\WorkManagement\Application\ResultEntryDraft;
use App\Modules\WorkManagement\Domain\Projects\ResultValue;
use App\Support\Access;
use App\Support\LocalClock;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class ProjectReportingWorkspace extends Component
{
    use WithSorting;
    use WithTableFilters;

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['name', 'due', 'status', 'updated', 'title', 'period'];
    }

    #[Locked]
    public string $projectId;

    public string $section = 'reports';

    public string $status = 'ACTIVE';

    public string $reportStatus = 'ALL';

    /** View filters, not #[Locked]; each choice is resolved inside the current project and access scope. */
    public string $periodChoice = 'ALL';

    public string $versionChoice = 'CURRENT';

    #[Locked]
    public ?string $reportId = null;

    #[Locked]
    public ?string $indicatorId = null;

    #[Locked]
    public ?string $entryId = null;

    #[Locked]
    public ?string $editingId = null;

    #[Locked]
    public string $editingKind = '';

    #[Locked]
    public int $revision = 0;

    #[Locked]
    public string $requestId = '';

    public bool $editorOpen = false;

    public bool $indicatorOpen = false;

    public bool $showVoided = false;

    public string $entrySearch = '';

    public string $entryDirection = 'desc';

    /** Editable drafts, including picker ids; not #[Locked]. Every command revalidates their project, type and current permissions. */
    /** @var array<string, mixed> */
    public array $form = [];

    #[On('ppm-quick-indicator')]
    public function quickCreate(string $projectId): void
    {
        if ($projectId !== $this->projectId || ! app(ProjectResults::class)->permissions(app(Access::class), $this->projectId)['edit']) {
            return;
        }
        $this->section = 'results';
        $this->openIndicator();
    }

    public function updatedSection(): void
    {
        $this->search = '';
        $this->sortKey = $this->section === 'reports' ? 'updated' : 'name';
        $this->sortDir = $this->section === 'reports' ? 'desc' : 'asc';
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = 'ACTIVE';
        $this->reportStatus = 'ALL';
        $this->periodChoice = 'ALL';
        $this->resetPage('indicatorsPage');
        $this->resetPage('reportsPage');
    }

    /** @return list<string> */
    protected function pageSizePageNames(): array
    {
        return ['indicatorsPage', 'reportsPage'];
    }

    public function mount(): void
    {
        $id = request()->query('report');
        if (is_string($id) && app(ProjectReports::class)->report(app(Access::class), $this->projectId, $id) !== null) {
            $this->openReport($id);
        }
    }

    public function openOutcome(?string $id = null): void
    {
        $row = $id === null ? null : app(ProjectResults::class)->outcome(app(Access::class), $this->projectId, $id);
        abort_if($id !== null && $row === null, 404);
        $this->begin('outcome', $id, $row->revision ?? 0, ['name' => $row->name ?? '', 'description' => $row->description ?? '', 'kind' => $row->kind ?? 'OBJECTIVE', 'parent' => $row->parent_id ?? '', 'status' => $row->status ?? 'ACTIVE', 'reason' => '']);
    }

    public function openIndicator(?string $id = null): void
    {
        $row = $id === null ? null : app(ProjectResults::class)->indicator(app(Access::class), $this->projectId, $id);
        abort_if($id !== null && $row === null, 404);
        $this->begin('indicator', $id, $row->revision ?? 0, ['name' => $row->name ?? '', 'description' => $row->description ?? '', 'kind' => $row->kind ?? 'NUMERIC', 'unit' => $row->unit ?? '',
            'aggregation' => $row->aggregation ?? 'SUM', 'direction' => $row->direction ?? 'AT_LEAST', 'target' => $row?->target_scaled === null ? '' : ResultValue::input($row->target_scaled),
            'targetText' => $row->target_text ?? '', 'outcome' => $row->outcome_id ?? '', 'group' => $row->group_id ?? '', 'task' => $row->task_id ?? '',
            'startsOn' => $row->starts_on ?? '', 'endsOn' => $row->ends_on ?? '', 'evidenceRequired' => $row->evidence_required ?? false, 'status' => $row->status ?? 'ACTIVE', 'reason' => '']);
    }

    public function viewIndicator(string $id): void
    {
        abort_if(app(ProjectResults::class)->indicator(app(Access::class), $this->projectId, $id) === null, 404);
        $this->indicatorId = $id;
        $this->entrySearch = '';
        $this->entryDirection = 'desc';
        $this->entryId = null;
        $this->indicatorOpen = true;
        $this->resetPage('entriesPage');
    }

    public function openResult(string $indicatorId, ?string $entryId = null): void
    {
        $query = app(ProjectResults::class);
        $indicator = $query->indicator(app(Access::class), $this->projectId, $indicatorId);
        abort_if($indicator === null, 404);
        $entry = $entryId === null ? null : $query->entry(app(Access::class), $this->projectId, $entryId);
        abort_if($entryId !== null && ($entry === null || $entry->indicator_id !== $indicator->id || $entry->voided_at !== null), 404);
        $this->indicatorId = $indicator->id;
        $this->requestId = (string) Str::ulid();
        $this->begin('result', $entryId, 0, ['occurredOn' => $entry->occurred_on ?? LocalClock::todayKey(), 'value' => $entry?->value_scaled === null ? '' : ResultValue::input($entry->value_scaled),
            'valueText' => $entry->value_text ?? '', 'state' => $entry->state ?? 'IN_PROGRESS', 'narrative' => $entry->narrative ?? '', 'issues' => $entry->issues ?? '', 'correctiveAction' => $entry->corrective_action ?? '', 'reference' => $entry->reference ?? '', 'reason' => '']);
    }

    public function viewEntry(string $id): void
    {
        $entry = app(ProjectResults::class)->entry(app(Access::class), $this->projectId, $id);
        abort_if($entry === null || $entry->indicator_id !== $this->indicatorId, 404);
        $this->entryId = $entry->id;
    }

    public function openVoid(string $id): void
    {
        $this->viewEntry($id);
        $this->begin('void', $id, 0, ['reason' => '']);
    }

    public function openReport(?string $id = null, bool $edit = false): void
    {
        $query = app(ProjectReports::class);
        $row = $id === null ? null : $query->report(app(Access::class), $this->projectId, $id);
        abort_if($id !== null && $row === null, 404);
        $this->section = 'reports';
        $this->reportId = $row->id ?? null;
        $this->versionChoice = 'CURRENT';
        if ($id === null || $edit) {
            abort_unless($id === null || $query->canEdit(app(Access::class), $this->projectId, $id), 403);
            $this->begin('report', $id, $row->revision ?? 0, ['title' => $row->title ?? '', 'template' => $row->template_id ?? '', 'period' => $row->period_id ?? '', 'budget' => $row->budget_id ?? '', 'summary' => $row->summary ?? '', 'issues' => $row->issues ?? '', 'correctiveAction' => $row->corrective_action ?? '']);
        }
    }

    public function reportAction(string $action): void
    {
        $row = $this->reportId === null ? null : app(ProjectReports::class)->report(app(Access::class), $this->projectId, $this->reportId);
        abort_if($row === null, 404);
        abort_unless(in_array($action, ['submit', 'accept', 'return', 'amend', 'reassign'], true), 404);
        $this->begin($action, $row->id, $row->revision, ['reviewer' => '', 'notes' => '', 'reason' => '']);
    }

    public function openPeriod(?string $id = null): void
    {
        $row = $id === null ? null : app(ProjectReports::class)->periods(app(Access::class), $this->projectId, true)->firstWhere('id', $id);
        abort_if($id !== null && $row === null, 404);
        $this->begin('period', $id, $row->revision ?? 0, ['name' => $row->name ?? '', 'startsOn' => $row->starts_on ?? '', 'endsOn' => $row->ends_on ?? '', 'dueOn' => $row->due_on ?? '', 'archived' => $row->archived ?? false, 'reason' => '']);
    }

    public function openField(?string $id = null): void
    {
        $row = $id === null ? null : app(ProjectReports::class)->fields(app(Access::class), $this->projectId, true)->firstWhere('id', $id);
        abort_if($id !== null && $row === null, 404);
        $this->begin('field', $id, $row->revision ?? 0, ['name' => $row->name ?? '', 'entity' => $row->entity_type ?? 'REPORT', 'type' => $row->type ?? 'TEXT', 'unit' => $row->unit ?? '', 'visibility' => $row->visibility ?? 'PROJECT', 'required' => $row->required ?? false, 'total' => $row->total ?? false, 'archived' => $row->archived ?? false, 'options' => implode("\n", $row->options['choices'] ?? []), 'reason' => '']);
    }

    public function openTemplate(?string $id = null): void
    {
        $row = $id === null ? null : app(ProjectReports::class)->templates(app(Access::class), $this->projectId, true)->firstWhere('id', $id);
        abort_if($id !== null && $row === null, 404);
        $this->begin('template', $id, $row->revision ?? 0, ['name' => $row->name ?? '', 'kind' => $row->kind ?? 'PERIOD_RESULTS', 'description' => $row->description ?? '', 'sections' => $row->definition['sections'] ?? ['profile', 'results'], 'fields' => $row->definition['fields'] ?? [], 'archived' => $row->archived ?? false, 'reason' => '']);
    }

    public function useTemplates(): void
    {
        app(UseGeneralReportTemplates::class)->handle(app(Access::class), $this->projectId);
        Flux::toast('General templates added.');
    }

    public function moveField(int $index, int $direction): void
    {
        $fields = $this->form['fields'] ?? [];
        if (! is_array($fields) || ! array_is_list($fields) || ! in_array($direction, [-1, 1], true) || ! isset($fields[$index], $fields[$index + $direction])) {
            return;
        }
        [$fields[$index], $fields[$index + $direction]] = [$fields[$index + $direction], $fields[$index]];
        $this->form['fields'] = $fields;
    }

    public function save(): void
    {
        $actor = app(Access::class);
        $f = $this->form;
        $text = static fn (string $key): string => is_scalar($f[$key] ?? null) ? (string) $f[$key] : '';
        $optional = static fn (string $key): ?string => $text($key) === '' ? null : $text($key);
        try {
            switch ($this->editingKind) {
                case 'outcome':
                    app(SaveOutcome::class)->handle($actor, $this->projectId, $this->editingId, $this->revision, $text('name'), $text('kind'), $text('description'), $optional('parent'), $text('status'), $text('reason'));
                    break;
                case 'indicator':
                    app(SaveIndicator::class)->handle($actor, $this->projectId, $this->editingId, $this->revision, new IndicatorDraft($text('name'), $text('kind'), $text('unit'), $text('aggregation'), $text('direction'), $text('target'), $text('targetText'), $text('description'), $optional('outcome'), $optional('group'), $optional('task'), $optional('startsOn'), $optional('endsOn'), (bool) ($f['evidenceRequired'] ?? false), $text('status'), $text('reason')));
                    break;
                case 'result':
                    abort_if($this->indicatorId === null, 404);
                    $this->entryId = app(RecordResultEntry::class)->handle($actor, $this->projectId, $this->indicatorId, new ResultEntryDraft($text('occurredOn'), $text('value'), $text('valueText'), $optional('state'), $text('narrative'), $text('issues'), $text('correctiveAction'), $text('reference'), $this->editingId, $text('reason'), $this->requestId));
                    $this->indicatorOpen = true;
                    break;
                case 'void':
                    abort_if($this->editingId === null, 404);
                    app(VoidResultEntry::class)->handle($actor, $this->projectId, $this->editingId, $text('reason'));
                    break;
                case 'report':
                    $row = app(SaveProjectReport::class)->handle($actor, $this->projectId, $this->editingId, $this->revision, $text('template'), $text('period'), $text('title'), $text('summary'), $text('issues'), $text('correctiveAction'), $optional('budget'));
                    $this->reportId = $row->id;
                    break;
                case 'submit':
                    abort_if($this->editingId === null, 404);
                    app(SubmitProjectReport::class)->handle($actor, $this->projectId, $this->editingId, $this->revision, (int) $text('reviewer'));
                    break;
                case 'accept':
                case 'return':
                    abort_if($this->editingId === null, 404);
                    $row = app(ProjectReports::class)->report($actor, $this->projectId, $this->editingId);
                    abort_if($row?->current_submission_id === null, 404);
                    app(ReviewProjectReport::class)->handle($actor, $this->projectId, $this->editingId, $row->current_submission_id, $this->revision, $this->editingKind === 'accept' ? 'ACCEPTED' : 'RETURNED', $text('notes'));
                    break;
                case 'amend':
                    abort_if($this->editingId === null, 404);
                    app(AmendProjectReport::class)->handle($actor, $this->projectId, $this->editingId, $this->revision, $text('reason'));
                    $this->versionChoice = 'CURRENT';
                    break;
                case 'reassign':
                    abort_if($this->editingId === null, 404);
                    app(AmendProjectReport::class)->reassign($actor, $this->projectId, $this->editingId, $this->revision, (int) $text('reviewer'), $text('reason'));
                    break;
                case 'period':
                    app(SaveReportingPeriod::class)->handle($actor, $this->projectId, $this->editingId, $this->revision, $text('name'), $text('startsOn'), $text('endsOn'), $optional('dueOn'), (bool) ($f['archived'] ?? false), $text('reason'));
                    break;
                case 'field':
                    app(SaveReportField::class)->handle($actor, $this->projectId, $this->editingId, $this->revision, $text('name'), $text('entity'), $text('type'), $text('unit'), $text('visibility'), (bool) ($f['required'] ?? false), (bool) ($f['total'] ?? false), array_values(array_filter(array_map(trim(...), explode("\n", $text('options'))))), (bool) ($f['archived'] ?? false), $text('reason'));
                    break;
                case 'template':
                    app(SaveReportTemplate::class)->handle($actor, $this->projectId, $this->editingId, $this->revision, $text('name'), $text('kind'), ['sections' => $f['sections'] ?? [], 'fields' => $f['fields'] ?? []], $text('description'), (bool) ($f['archived'] ?? false), $text('reason'));
                    break;
                default:
                    abort(404);
            }
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->editorOpen = false;
        Flux::toast(match ($this->editingKind) {
            'submit' => 'Report submitted for review.', 'accept' => 'Report accepted.', 'return' => 'Report returned.', default => 'Saved.'
        });
    }

    #[On('record-evidence-changed')]
    #[On('report-answers-changed')]
    #[On('workbook-imported')]
    public function refreshRecords(): void {}

    public function render(): View
    {
        $actor = app(Access::class);
        $query = app(ProjectReports::class);
        $permissions = $query->permissions($actor, $this->projectId);
        if (! $permissions['results'] && $this->section === 'results') {
            $this->section = 'reports';
        }
        $periods = $permissions['view'] ? $query->periods($actor, $this->projectId, true) : collect();
        $templates = $permissions['view'] ? $query->templates($actor, $this->projectId, true) : collect();
        $fields = $permissions['view'] ? $query->fields($actor, $this->projectId, true) : collect();
        $period = $periods->firstWhere('id', $this->periodChoice);
        $from = $period?->starts_on;
        $to = $period === null ? LocalClock::todayKey() : min($period->ends_on, LocalClock::todayKey());
        $results = app(ProjectResults::class);
        $indicator = $this->indicatorId === null ? null : $results->indicator($actor, $this->projectId, $this->indicatorId, $from, $to);
        $report = $this->reportId === null ? null : $query->report($actor, $this->projectId, $this->reportId);
        $submissions = $report === null ? collect() : $query->submissions($actor, $this->projectId, $report->id);
        $submissionId = $this->versionChoice === 'CURRENT' ? $report?->current_submission_id : $submissions->firstWhere('id', $this->versionChoice)?->id;
        $draft = $report !== null && $report->status === 'DRAFT' && $this->versionChoice === 'CURRENT';
        $submission = $submissionId === null || $draft ? null : $query->submission($actor, $this->projectId, $submissionId);
        $snapshot = $draft ? $query->preview($actor, $this->projectId, $report->id) : $submission?->snapshot;

        return view('livewire.projects.project-reporting-workspace', compact('permissions', 'periods', 'templates', 'fields', 'indicator', 'report', 'submissions', 'submission', 'snapshot', 'draft') + [
            'resultsPermissions' => $results->permissions($actor, $this->projectId),
            'indicators' => $this->section === 'results' ? $this->acceptPage($results->indicators($actor, $this->projectId, $this->search, $this->status, (int) $this->getPage('indicatorsPage'), $from, $to, $this->sortKey, $this->sortDir, $this->perPage), 'indicatorsPage') : null,
            'outcomes' => $permissions['results'] ? $results->outcomeChoices($actor, $this->projectId) : collect(),
            'groups' => $permissions['results'] ? app(ProjectTaskGroups::class)->forProject($actor, $this->projectId) : collect(),
            'tasks' => $this->editingKind === 'indicator' && $this->editorOpen ? $results->workChoices($actor, $this->projectId) : [],
            'entries' => $this->indicatorOpen && $indicator !== null ? $this->acceptPage($results->entries($actor, $this->projectId, $indicator->id, $this->showVoided, (int) $this->getPage('entriesPage'), search: $this->entrySearch, direction: $this->entryDirection), 'entriesPage') : null,
            'entry' => $this->entryId === null ? null : $results->entry($actor, $this->projectId, $this->entryId),
            'reports' => $this->section === 'reports' ? $this->acceptPage($query->reports($actor, $this->projectId, (int) $this->getPage('reportsPage'), $this->reportStatus, $this->search, $this->sortKey === '' ? 'updated' : $this->sortKey, $this->sortKey === '' ? 'desc' : $this->sortDir, $this->perPage), 'reportsPage') : null,
            'canEditReport' => $report !== null && $query->canEdit($actor, $this->projectId, $report->id),
            'reviewers' => $report === null ? collect() : $query->reviewers($actor, $this->projectId, $report->id)->where('id', '!=', (int) $actor->userId),
            'canReview' => $report?->status === 'SUBMITTED' && $submission?->id === $report->current_submission_id && $submission?->reviewer_id === (int) $actor->userId && $submission->submitted_by !== (int) $actor->userId,
            'budgets' => $permissions['finance'] ? app(ProjectFinancials::class)->versions($actor, $this->projectId)->whereIn('status', ['APPROVED', 'DRAFT']) : collect(),
            'currentUserId' => (int) $actor->userId,
        ]);
    }

    /** @param array<string, mixed> $form */
    private function begin(string $kind, ?string $id, int $revision, array $form): void
    {
        $this->editingKind = $kind;
        $this->editingId = $id;
        $this->revision = $revision;
        $this->form = $form;
        $this->resetValidation();
        $this->editorOpen = true;
    }
}
