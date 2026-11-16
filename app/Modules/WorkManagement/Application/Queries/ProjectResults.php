<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Models\ProjectIndicator;
use App\Models\ProjectOutcome;
use App\Models\ProjectResultEntry;
use App\Models\ProjectTask;
use App\Modules\WorkManagement\Application\ReportSnapshotData;
use App\Modules\WorkManagement\Application\TableOrder;
use App\Modules\WorkManagement\Contracts\Views\IndicatorView;
use App\Modules\WorkManagement\Contracts\Views\OutcomeView;
use App\Modules\WorkManagement\Contracts\Views\ResultEntryView;
use App\Modules\WorkManagement\Infrastructure\PlanningScope;
use App\Modules\WorkManagement\Infrastructure\SharedWorkScope;
use App\Platform\ReadModels\QueryPage;
use App\Platform\ReadModels\Snapshot;
use App\Platform\ReadModels\SqlText;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProjectResults
{
    /** @return list<array{id: string, title: string}> */
    public function workChoices(Access $actor, string $projectId): array
    {
        $rows = [];
        foreach (ProjectTask::query()->where('project_id', $projectId)->whereIn('project_id', SharedWorkScope::projects($actor)->select('projects.id'))
            ->select(['id', 'title'])->orderBy('title')->limit(200)->get() as $row) {
            $rows[] = ['id' => $row->id, 'title' => $row->title];
        }

        return $rows;
    }

    /** @return array{view: bool, edit: bool} */
    public function permissions(Access $actor, string $projectId): array
    {
        return ['view' => SharedWorkScope::projects($actor)->whereKey($projectId)->exists(), 'edit' => PlanningScope::projects($actor)->whereKey($projectId)->exists()];
    }

    /** @return LengthAwarePaginator<int, OutcomeView> */
    public function outcomes(Access $actor, string $projectId, int $page = 1): LengthAwarePaginator
    {
        return QueryPage::get(ProjectOutcome::query()->where('project_id', $projectId)->whereIn('project_id', SharedWorkScope::projects($actor)->select('projects.id'))->orderBy('kind')->orderBy('name')->orderBy('id'), $page, 20, OutcomeView::class, 'outcomesPage');
    }

    /** @return Collection<int, OutcomeView> */
    public function outcomeChoices(Access $actor, string $projectId): Collection
    {
        return ProjectOutcome::query()->where('project_id', $projectId)->whereIn('project_id', SharedWorkScope::projects($actor)->select('projects.id'))
            ->orderBy('kind')->orderBy('name')->limit(200)->get()->map(static fn (ProjectOutcome $row): OutcomeView => Snapshot::of($row, OutcomeView::class));
    }

    public function outcome(Access $actor, string $projectId, string $id): ?OutcomeView
    {
        $row = ProjectOutcome::query()->where('project_id', $projectId)->whereIn('project_id', SharedWorkScope::projects($actor)->select('projects.id'))->whereKey($id)->first();

        return $row === null ? null : Snapshot::of($row, OutcomeView::class);
    }

    /** @return LengthAwarePaginator<int, IndicatorView> */
    public function indicators(Access $actor, string $projectId, string $search = '', string $status = 'ACTIVE', int $page = 1, ?string $from = null, ?string $to = null, string $sort = 'name', string $direction = 'asc', int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->query($actor, $projectId, $from, $to);
        if (in_array($status, ['ACTIVE', 'DISCONTINUED'], true)) {
            $query->where('status', $status);
        }
        if (trim($search) !== '') {
            $query->where('name', 'like', '%'.addcslashes(mb_substr(trim($search), 0, 100), '%_\\').'%');
        }

        TableOrder::apply($query, ['name' => 'name', 'due' => 'ends_on', 'status' => 'status'], $sort, $direction, ['name', 'status']);

        return QueryPage::get($query->orderBy('id'), $page, max(1, min(100, $perPage)), IndicatorView::class, 'indicatorsPage');
    }

    /** @return Collection<int, IndicatorView> */
    public function reportRows(Access $actor, string $projectId, ?string $from, string $to): Collection
    {
        return $this->query($actor, $projectId, $from, $to)->orderBy('id')->limit(ReportSnapshotData::MAX_ROWS + 1)->get()
            ->map(static fn (ProjectIndicator $row): IndicatorView => Snapshot::of($row, IndicatorView::class));
    }

    public function indicator(Access $actor, string $projectId, string $id, ?string $from = null, ?string $to = null): ?IndicatorView
    {
        $row = $this->query($actor, $projectId, $from, $to)->whereKey($id)->first();

        return $row === null ? null : Snapshot::of($row, IndicatorView::class);
    }

    /** @return LengthAwarePaginator<int, ResultEntryView> */
    public function entries(Access $actor, string $projectId, string $indicatorId, bool $voided = false, int $page = 1, ?string $from = null, ?string $to = null, string $search = '', string $direction = 'desc'): LengthAwarePaginator
    {
        $query = $this->entryQuery($actor, $projectId)->where('indicator_id', $indicatorId)
            ->when(! $voided, static fn (Builder $query) => $query->whereNull('voided_at'))
            ->when($from !== null, static fn (Builder $query) => $query->where('occurred_on', '>=', $from))
            ->when($to !== null, static fn (Builder $query) => $query->where('occurred_on', '<=', $to));

        SqlText::contains($query, ['narrative', 'reference', 'issues', 'corrective_action'], $search);

        return QueryPage::get($query->orderBy('occurred_on', $direction === 'asc' ? 'asc' : 'desc')->orderByDesc('created_at')->orderByDesc('id'), $page, 20, ResultEntryView::class, 'resultEntriesPage');
    }

    public function entry(Access $actor, string $projectId, string $id): ?ResultEntryView
    {
        $row = $this->entryQuery($actor, $projectId)->whereKey($id)->first();

        return $row === null ? null : Snapshot::of($row, ResultEntryView::class);
    }

    /** @return array{indicators: int, planned: int, recorded: int, met: int, missing_evidence: int} */
    public function totals(Access $actor, ?string $projectId = null, string $portfolio = 'ALL', string $client = 'ALL', ?string $from = null, ?string $to = null): array
    {
        $query = $this->query($actor, $projectId, $from, $to, $portfolio, $client)->where('status', 'ACTIVE');
        $row = DB::query()->fromSub($query, 'results')->selectRaw("COUNT(*) AS indicators, COALESCE(SUM(CASE WHEN (kind = 'NUMERIC' AND target_scaled IS NOT NULL) OR (kind = 'QUALITATIVE' AND target_text IS NOT NULL) THEN 1 ELSE 0 END), 0) AS planned,
            COALESCE(SUM(CASE WHEN actual_scaled IS NOT NULL OR actual_state IS NOT NULL THEN 1 ELSE 0 END), 0) AS recorded,
            COALESCE(SUM(CASE WHEN kind = 'QUALITATIVE' AND target_text IS NOT NULL AND actual_state = 'ACHIEVED' THEN 1 WHEN kind = 'NUMERIC' AND actual_scaled IS NOT NULL AND target_scaled IS NOT NULL AND
                ((direction = 'AT_LEAST' AND actual_scaled >= target_scaled) OR (direction = 'AT_MOST' AND actual_scaled <= target_scaled) OR (direction = 'EXACT' AND actual_scaled = target_scaled)) THEN 1 ELSE 0 END), 0) AS met")->first();
        $missing = $this->entryQuery($actor, $projectId, $portfolio, $client)->whereNull('voided_at')->where('evidence_required', true)
            ->where('occurred_on', '<=', $to ?? ManilaClock::todayKey())->when($from !== null, static fn (Builder $query) => $query->where('occurred_on', '>=', $from))
            ->whereNotExists(static fn ($query) => $query->selectRaw('1')->from('project_record_files')->whereColumn('project_id', 'project_result_entries.project_id')
                ->where('entity_type', 'RESULT_ENTRY')->whereColumn('record_id', 'project_result_entries.id')->whereNull('removed_at'))->count();

        return ['indicators' => (int) ($row->indicators ?? 0), 'planned' => (int) ($row->planned ?? 0), 'recorded' => (int) ($row->recorded ?? 0), 'met' => (int) ($row->met ?? 0), 'missing_evidence' => $missing];
    }

    /** @return Builder<ProjectIndicator> */
    private function query(Access $actor, ?string $projectId, ?string $from, ?string $to, string $portfolio = 'ALL', string $client = 'ALL'): Builder
    {
        $entries = ProjectResultEntry::query()->whereColumn('indicator_id', 'project_indicators.id')->whereNull('voided_at')
            ->where('occurred_on', '<=', $to ?? ManilaClock::todayKey())->when($from !== null, static fn (Builder $query) => $query->where('occurred_on', '>=', $from));
        $latest = ProjectResultEntry::query()->whereColumn('indicator_id', 'project_indicators.id')->whereNull('voided_at')->where('occurred_on', '<=', $to ?? ManilaClock::todayKey())->orderByDesc('occurred_on')->orderByDesc('created_at')->orderByDesc('id')->limit(1);
        $base = ProjectIndicator::query()->whereIn('project_id', SharedWorkScope::projects($actor, $portfolio, $client)->select('projects.id'))
            ->when($projectId !== null, static fn (Builder $query) => $query->where('project_id', $projectId))->select('project_indicators.*')
            ->selectSub((clone $entries)->selectRaw('SUM(value_scaled)'), 'sum_scaled')->selectSub((clone $latest)->select('value_scaled'), 'latest_scaled')
            ->selectSub((clone $latest)->select('value_text'), 'actual_text')->selectSub((clone $latest)->select('state'), 'actual_state')->selectSub((clone $latest)->select('occurred_on'), 'last_recorded_on')->selectSub((clone $latest)->select('id'), 'latest_entry_id')
            ->selectSub((clone $entries)->selectRaw('COUNT(*)'), 'entry_count')
            ->selectSub((clone $entries)->where('evidence_required', true)->whereNotExists(static fn ($query) => $query->selectRaw('1')->from('project_record_files')->where('entity_type', 'RESULT_ENTRY')->whereColumn('record_id', 'project_result_entries.id')->whereNull('removed_at'))->selectRaw('COUNT(*)'), 'evidence_count');

        return ProjectIndicator::query()->fromSub($base, 'project_indicators')->select('project_indicators.*')
            ->selectRaw("CASE WHEN kind = 'NUMERIC' AND aggregation = 'SUM' THEN sum_scaled ELSE latest_scaled END AS actual_scaled");
    }

    /** @return Builder<ProjectResultEntry> */
    private function entryQuery(Access $actor, ?string $projectId, string $portfolio = 'ALL', string $client = 'ALL'): Builder
    {
        return ProjectResultEntry::query()->whereIn('project_id', SharedWorkScope::projects($actor, $portfolio, $client)->select('projects.id'))
            ->when($projectId !== null, static fn (Builder $query) => $query->where('project_id', $projectId));
    }
}
