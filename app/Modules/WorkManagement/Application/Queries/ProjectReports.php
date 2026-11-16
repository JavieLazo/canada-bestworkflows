<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Models\ProjectBudget;
use App\Models\ProjectIndicator;
use App\Models\ProjectRecordValue;
use App\Models\ProjectReportField;
use App\Models\ProjectReportingPeriod;
use App\Models\ProjectReportSubmission;
use App\Models\ProjectReportTemplate;
use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\WorkManagement\Application\ReportAccess;
use App\Modules\WorkManagement\Application\ReportDefinitions;
use App\Modules\WorkManagement\Application\ReportFieldValues;
use App\Modules\WorkManagement\Application\ReportSnapshotData;
use App\Modules\WorkManagement\Application\TableOrder;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Contracts\Views\ProjectView;
use App\Modules\WorkManagement\Contracts\Views\ReportFieldView;
use App\Modules\WorkManagement\Contracts\Views\ReportPeriodView;
use App\Modules\WorkManagement\Contracts\Views\ReportSubmissionView;
use App\Modules\WorkManagement\Contracts\Views\ReportTemplateView;
use App\Modules\WorkManagement\Contracts\Views\ReportView;
use App\Modules\WorkManagement\Infrastructure\FinanceScope;
use App\Modules\WorkManagement\Infrastructure\SharedWorkScope;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Platform\ReadModels\QueryPage;
use App\Platform\ReadModels\Snapshot;
use App\Platform\ReadModels\SqlText;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProjectReports
{
    /** @return LengthAwarePaginator<int, ProjectView> */
    public function sourceProjects(Access $actor, string $kind, string $portfolio = 'ALL', string $client = 'ALL', int $page = 1): LengthAwarePaginator
    {
        abort_unless(in_array($kind, ['results', 'reports'], true), 404);
        $query = $kind === 'results' ? SharedWorkScope::projects($actor, $portfolio, $client)
            ->whereIn('projects.id', ProjectIndicator::query()->where('status', 'ACTIVE')->select('project_id'))
            : WorkScope::projects($actor, $portfolio)->whereIn('projects.id', ReportAccess::reports($actor, portfolio: $portfolio, client: $client)->select('project_id'));
        $query->select('projects.*')->selectRaw(WorkScope::PROJECT_CLIENT_NAME_SQL.' AS client_name')->orderBy('projects.name')->orderBy('projects.id');

        return QueryPage::get($query, $page, 10, ProjectView::class, 'reportingSourcePage');
    }

    /** @return array{view: bool, edit: bool, admin: bool, results: bool, finance: bool} */
    public function permissions(Access $actor, string $projectId): array
    {
        $work = SharedWorkScope::projects($actor)->whereKey($projectId)->exists();
        $finance = FinanceScope::projects($actor)->whereKey($projectId)->exists();
        $project = app(WorkRecords::class)->project($actor, $projectId);

        return ['view' => $work || $finance, 'edit' => ($work && ReportAccess::allows($actor, $projectId, true, false, true)) || ($finance && ReportAccess::allows($actor, $projectId, false, true, true)),
            'admin' => ($work || $finance) && WorkRules::managesProject($actor, $project), 'results' => $work, 'finance' => $finance];
    }

    public function canEdit(Access $actor, string $projectId, string $id): bool
    {
        $row = $this->report($actor, $projectId, $id);
        if ($row === null) {
            return false;
        }
        $work = $row->work;
        $financial = $row->financial;
        if (in_array($row->status, ['RETURNED', 'ACCEPTED'], true)) {
            $template = ProjectReportTemplate::query()->where('project_id', $projectId)->whereKey($row->template_id)->firstOrFail();
            $scope = ReportDefinitions::scope($template->definition);
            $work = $work || $scope['work'];
            $financial = $financial || $scope['financial'];
        }

        return ReportAccess::allows($actor, $projectId, $work, $financial, true);
    }

    public function canEditRecord(Access $actor, string $projectId, string $entity, string $id): bool
    {
        if ($entity === 'REPORT') {
            return $this->report($actor, $projectId, $id)?->status === 'DRAFT' && $this->canEdit($actor, $projectId, $id);
        }
        if (in_array($entity, ['BUDGET_LINE', 'CASH_ENTRY', 'FUNDING', 'CONTRIBUTION', 'CONTRIBUTION_DELIVERY'], true)) {
            if (! FinanceScope::projects($actor, edit: true)->whereKey($projectId)->exists()) {
                return false;
            }

            return DB::transaction(static function () use ($actor, $projectId, $entity, $id): bool {
                $row = ReportFieldValues::record($actor, $projectId, $entity, $id, false);
                if ($row->getAttribute('voided_at') !== null) {
                    return false;
                }

                return $entity !== 'BUDGET_LINE' || ProjectBudget::query()->whereKey($row->getAttribute('budget_id'))->where('status', 'DRAFT')->exists();
            });
        }

        return app(ProjectResults::class)->permissions($actor, $projectId)['edit'];
    }

    /** @return LengthAwarePaginator<int, ReportView> */
    public function reports(Access $actor, string $projectId, int $page = 1, string $status = 'ALL', string $search = '', string $sort = 'updated', string $direction = 'desc', int $perPage = 20): LengthAwarePaginator
    {
        $query = ReportAccess::reports($actor, $projectId)->select('project_reports.*')
            ->selectSub(ProjectReportingPeriod::query()->whereColumn('id', 'project_reports.period_id')->select('name'), 'period_name')
            ->selectSub(ProjectReportingPeriod::query()->whereColumn('id', 'project_reports.period_id')->select('due_on'), 'due_on')
            ->selectSub(ProjectReportTemplate::query()->whereColumn('id', 'project_reports.template_id')->select('name'), 'template_name')
            ->when($status !== 'ALL', static fn (Builder $query) => $query->where('status', $status));

        SqlText::contains($query, ['project_reports.title'], $search);
        TableOrder::apply($query, ['updated' => 'updated_at', 'title' => 'title', 'period' => 'period_name', 'status' => 'status', 'due' => 'due_on'], $sort, $direction, ['title', 'status']);

        return QueryPage::get($query->orderByDesc('id'), $page, max(1, min(100, $perPage)), ReportView::class, 'reportsPage');
    }

    public function report(Access $actor, string $projectId, string $id): ?ReportView
    {
        $row = ReportAccess::reports($actor, $projectId)->whereKey($id)->first();

        return $row === null ? null : Snapshot::of($row, ReportView::class);
    }

    /** @return Collection<int, ReportPeriodView> */
    public function periods(Access $actor, string $projectId, bool $archived = false): Collection
    {
        $this->read($actor, $projectId);

        return ProjectReportingPeriod::query()->where('project_id', $projectId)->when(! $archived, static fn (Builder $query) => $query->where('archived', false))
            ->orderByDesc('starts_on')->limit(200)->get()->map(static fn (ProjectReportingPeriod $row): ReportPeriodView => Snapshot::of($row, ReportPeriodView::class));
    }

    /** @return Collection<int, ReportTemplateView> */
    public function templates(Access $actor, string $projectId, bool $archived = false): Collection
    {
        $this->read($actor, $projectId);
        $work = SharedWorkScope::projects($actor)->whereKey($projectId)->exists();
        $finance = FinanceScope::projects($actor)->whereKey($projectId)->exists();

        return ProjectReportTemplate::query()->where('project_id', $projectId)->when(! $archived, static fn (Builder $query) => $query->where('archived', false))
            ->when(! $work, static fn (Builder $query) => $query->where('work', false))->when(! $finance, static fn (Builder $query) => $query->where('financial', false))
            ->orderBy('name')->limit(200)->get()->map(static fn (ProjectReportTemplate $row): ReportTemplateView => Snapshot::of($row, ReportTemplateView::class));
    }

    /** @return Collection<int, ReportFieldView> */
    public function fields(Access $actor, string $projectId, bool $archived = false): Collection
    {
        $this->read($actor, $projectId);
        $finance = FinanceScope::projects($actor)->whereKey($projectId)->exists();

        return ProjectReportField::query()->where('project_id', $projectId)->when(! $archived, static fn (Builder $query) => $query->where('archived', false))
            ->when(! $finance, static fn (Builder $query) => $query->where('visibility', 'PROJECT'))->orderBy('name')->limit(200)->get()
            ->map(static fn (ProjectReportField $row): ReportFieldView => Snapshot::of($row, ReportFieldView::class));
    }

    /** @return array{fields: Collection<int, ReportFieldView>, values: array<string, mixed>, signature: string} */
    public function answers(Access $actor, string $projectId, string $entity, string $id, bool $edit = false): array
    {
        return DB::transaction(static function () use ($actor, $projectId, $entity, $id, $edit): array {
            $record = ReportFieldValues::record($actor, $projectId, $entity, $id, $edit);
            $projectId = $entity === 'PROJECT' ? (string) $record->getKey() : (string) $record->getAttribute('project_id');
            $fields = ReportFieldValues::definitions($actor, $projectId, $entity, false, $edit && $entity !== 'REPORT');
            $values = ProjectRecordValue::query()->where('project_id', $projectId)->where('record_id', $record->getKey())->whereIn('field_id', $fields->pluck('id')->all())->get();
            $answers = [];
            foreach ($fields as $field) {
                $value = $values->firstWhere('field_id', $field->id)?->value['value'] ?? null;
                if ($value !== null && in_array($field->type, ['NUMBER', 'MONEY'], true)) {
                    $scale = $field->type === 'MONEY' ? 100 : 10000;
                    $decimals = $field->type === 'MONEY' ? 2 : 4;
                    $value = number_format($value / $scale, $decimals, '.', '');
                }
                $answers[$field->id] = $value;
            }

            return ['fields' => $fields->map(static fn (ProjectReportField $row): ReportFieldView => Snapshot::of($row, ReportFieldView::class)), 'values' => $answers, 'signature' => ReportFieldValues::signature($fields, $values)];
        });
    }

    /** @return array<string, mixed> */
    public function preview(Access $actor, string $projectId, string $id): array
    {
        return DB::transaction(static function () use ($actor, $projectId, $id): array {
            WorkRules::lockedProject($actor, $projectId);
            $row = ReportAccess::reports($actor, $projectId)->whereKey($id)->lockForUpdate()->firstOrFail();
            ReportAccess::locked($actor, $row->project_id, $row->work, $row->financial, false);

            return app(ReportSnapshotData::class)->build($actor, $row);
        });
    }

    /** @return Collection<int, ReportSubmissionView> */
    public function submissions(Access $actor, string $projectId, string $id): Collection
    {
        $report = $this->report($actor, $projectId, $id);
        abort_if($report === null, 404);

        return ProjectReportSubmission::query()->where('report_id', $report->id)->orderByDesc('version')->limit(100)->get()
            ->filter(static fn (ProjectReportSubmission $row): bool => ReportAccess::allows($actor, $projectId, $row->work, $row->financial))
            ->map(static fn (ProjectReportSubmission $row): ReportSubmissionView => Snapshot::of($row, ReportSubmissionView::class, ['payload' => '']))->values();
    }

    public function submission(Access $actor, string $projectId, string $id): ReportSubmissionView
    {
        $row = ProjectReportSubmission::query()->where('project_id', $projectId)->whereIn('report_id', ReportAccess::reports($actor, $projectId)->select('id'))->whereKey($id)->firstOrFail();
        abort_unless(ReportAccess::allows($actor, $projectId, $row->work, $row->financial), 403);
        abort_unless(hash_equals($row->sha256, hash('sha256', $row->payload)), 409, 'The saved submission failed its integrity check.');
        $data = json_decode($row->payload, true, 128, JSON_THROW_ON_ERROR);
        abort_unless(is_array($data) && ($data['schema_version'] ?? null) === 1, 409);
        abort_unless(app(ProjectRecordFiles::class)->allowsReferences($actor, $projectId, array_column($data['files'] ?? [], 'id')), 403, 'Access to every captured evidence source is required for this submission.');

        return Snapshot::of($row, ReportSubmissionView::class, ['snapshot' => $data]);
    }

    /** @return Collection<int, AccountLabel> */
    public function reviewers(Access $actor, string $projectId, string $id): Collection
    {
        $row = ReportAccess::reports($actor, $projectId)->whereKey($id)->firstOrFail();

        return ReportAccess::reviewers($actor, $row);
    }

    /** @return array{draft: int, submitted: int, returned: int, accepted: int, overdue: int} */
    public function totals(Access $actor, ?string $projectId = null, string $portfolio = 'ALL', string $client = 'ALL'): array
    {
        $rows = ReportAccess::reports($actor, $projectId, $portfolio, $client)->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $overdue = ReportAccess::reports($actor, $projectId, $portfolio, $client)->whereIn('status', ['DRAFT', 'RETURNED'])->whereIn('period_id', ProjectReportingPeriod::query()->where('due_on', '<', ManilaClock::todayKey())->select('id'))->count();

        return ['draft' => (int) ($rows['DRAFT'] ?? 0), 'submitted' => (int) ($rows['SUBMITTED'] ?? 0), 'returned' => (int) ($rows['RETURNED'] ?? 0), 'accepted' => (int) ($rows['ACCEPTED'] ?? 0), 'overdue' => $overdue];
    }

    private function read(Access $actor, string $projectId): void
    {
        abort_unless(ReportAccess::allows($actor, $projectId, true, false) || ReportAccess::allows($actor, $projectId, false, true), 403);
    }
}
