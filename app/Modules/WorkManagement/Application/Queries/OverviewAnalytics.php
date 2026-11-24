<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Models\Project;
use App\Modules\WorkManagement\Application\OverviewPeriod;
use App\Modules\WorkManagement\Contracts\Views\ProjectView;
use App\Modules\WorkManagement\Contracts\Views\TaskTotals;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/** The top-level overview's numbers: trends over a picked period, and where work stands today. */
final class OverviewAnalytics
{
    private const CLOSED = ['COMPLETED', 'CANCELLED'];

    /** Task statuses as the Work ahead chart groups them. */
    private const STAGES = ['IN_PROGRESS' => 'progress', 'RETURNED' => 'progress', 'SUBMITTED' => 'submitted', 'PENDING' => 'pending'];

    /** A budget needs a look once its spent share runs this many points ahead of the share of work done. */
    public const BUDGET_AHEAD_POINTS = 20;

    /**
     * Why a budget needs a look, if it does: OVER when more was paid than
     * approved, AHEAD when spending runs well ahead of the work done. Without
     * visible tasks there's no work to compare with, so only OVER applies.
     *
     * @return 'OVER'|'AHEAD'|null
     */
    public static function budgetRisk(int $approved, int $paid, ?int $donePercent): ?string
    {
        return match (true) {
            $paid > $approved => 'OVER',
            $donePercent !== null && $approved > 0 && 100 * $paid / $approved - $donePercent >= self::BUDGET_AHEAD_POINTS => 'AHEAD',
            default => null,
        };
    }

    /**
     * Each trend as this period's values and the previous period's, one per
     * bucket. Only created_at and completed_at carry history. On time is a
     * running share, so its last point is the whole period's figure, read
     * against each task's current due date.
     *
     * shortcut: earlier open points skip tasks cancelled since, because the
     * task row doesn't keep when it was cancelled. Reading cancel times from
     * the audit log would make them exact if anyone needs that.
     *
     * @return array{open: array{current: list<int>, previous: list<int>}, created: array{current: list<int>, previous: list<int>}, completed: array{current: list<int>, previous: list<int>}, onTime: array{current: list<int|null>, previous: list<int|null>}}
     */
    public function trends(Access $actor, OverviewPeriod $period, string $portfolio = 'ALL'): array
    {
        $since = OverviewPeriod::instants($period->previous[0])[0];
        $rows = WorkScope::tasks($actor)->whereIn('project_id', WorkScope::projects($actor, $portfolio)->select('projects.id'))
            ->where(static fn (Builder $query) => $query->where('created_at', '>=', $since)->orWhereNotIn('status', self::CLOSED)
                ->orWhere(static fn (Builder $done) => $done->where('status', 'COMPLETED')->where('completed_at', '>=', $since)))
            ->toBase()->get(['created_at', 'completed_at', 'status', 'due_date']);

        // A task counts as open from creation until completion. Sorted lists
        // of both turn "open at the end of this bucket" into two lookups.
        $starts = $finishes = [];
        $deadlines = [];
        $sides = ['current' => $this->edges($period->buckets), 'previous' => $this->edges($period->previous)];
        $empty = array_fill(0, count($period->buckets), 0);
        $created = $completed = $due = $onTime = ['current' => $empty, 'previous' => $empty];
        foreach ($rows as $object) {
            $row = (array) $object;
            $made = substr((string) $row['created_at'], 0, 19);
            $done = $row['completed_at'] === null ? null : substr((string) $row['completed_at'], 0, 19);
            $dueDate = $row['due_date'] === null ? null : (string) $row['due_date'];
            foreach ($sides as $side => $edges) {
                $bucket = $this->bucketOf($edges, $made);
                if ($bucket !== null) {
                    $created[$side][$bucket]++;
                }
            }
            if ($row['status'] === 'CANCELLED' || ($row['status'] === 'COMPLETED' && $done === null)) {
                continue;
            }
            $starts[] = $made;
            if ($row['status'] !== 'COMPLETED') {
                continue;
            }
            $finishes[] = $done;
            foreach ($sides as $side => $edges) {
                $bucket = $this->bucketOf($edges, $done);
                if ($bucket === null) {
                    continue;
                }
                $completed[$side][$bucket]++;
                if ($dueDate !== null) {
                    // Finished before the local midnight that ends its due day.
                    $deadlines[$dueDate] ??= CarbonImmutable::parse($dueDate, LocalClock::zone())->addDay()->utc()->format('Y-m-d H:i:s');
                    $due[$side][$bucket]++;
                    $onTime[$side][$bucket] += $done < $deadlines[$dueDate] ? 1 : 0;
                }
            }
        }
        sort($starts);
        sort($finishes);

        $result = [];
        foreach ($sides as $side => $edges) {
            $result['open'][$side] = array_map(fn (array $edge): int => max(0, $this->below($starts, $edge[1]) - $this->below($finishes, $edge[1])), $edges);
            $result['created'][$side] = array_values($created[$side]);
            $result['completed'][$side] = array_values($completed[$side]);
            [$hits, $total, $rates] = [0, 0, []];
            foreach ($due[$side] as $bucket => $count) {
                [$hits, $total] = [$hits + $onTime[$side][$bucket], $total + $count];
                $rates[] = $total === 0 ? null : (int) round(100 * $hits / $total);
            }
            $result['onTime'][$side] = $rates;
        }

        return $result;
    }

    /**
     * Where work stands today: whole-scope totals, open tasks by due week and
     * stage, projects by status and the active projects most at risk.
     *
     * @return array{totals: TaskTotals, unassigned: int, ahead: list<array{key: string, label: string, title: string, progress: int, submitted: int, pending: int}>, statuses: array<string, int>, attention: list<array{project: ProjectView, totals: TaskTotals, health: string}>, atRisk: int}
     */
    public function outlook(Access $actor, string $today, string $portfolio = 'ALL'): array
    {
        $projects = WorkScope::projects($actor, $portfolio);
        $open = WorkScope::tasks($actor)->whereIn('project_id', (clone $projects)->select('projects.id'))->whereNotIn('status', self::CLOSED);

        $columns = $this->aheadColumns($today);
        $counts = array_fill(0, count($columns), ['progress' => 0, 'submitted' => 0, 'pending' => 0]);
        foreach ((clone $open)->select(['status', 'due_date'])->selectRaw('COUNT(*) AS n')->groupBy('status', 'due_date')->toBase()->get() as $object) {
            $row = (array) $object;
            $stage = self::STAGES[(string) $row['status']] ?? null;
            if ($stage !== null) {
                $index = $this->aheadIndex($columns, $row['due_date'] === null ? null : (string) $row['due_date']);
                $counts[$index][$stage] = ($counts[$index][$stage] ?? 0) + (int) $row['n'];
            }
        }
        $ahead = array_map(static fn (array $column, array $count): array => ['key' => $column['key'], 'label' => $column['label'], 'title' => $column['title'],
            'progress' => $count['progress'] ?? 0, 'submitted' => $count['submitted'] ?? 0, 'pending' => $count['pending'] ?? 0], $columns, $counts);

        // Health is the project list's own rule, read from aggregates, so the
        // two screens always agree on which projects are overdue or due soon.
        $active = (clone $projects)->whereNotIn('projects.status', self::CLOSED)->select('projects.*')->selectRaw(WorkScope::PROJECT_CLIENT_NAME_SQL.' AS client_name')->get()
            ->map(static fn (Project $project): ProjectView => Snapshot::of($project, ProjectView::class));
        $totals = app(WorkSummary::class)->projects($actor, array_values($active->map(static fn (ProjectView $project): string => $project->id)->all()), $today);
        $attention = [];
        foreach ($active as $project) {
            $health = $totals[$project->id]->health($project, $today);
            if ($health === 'OVERDUE' || $health === 'DUE_SOON') {
                $attention[] = ['project' => $project, 'totals' => $totals[$project->id], 'health' => $health];
            }
        }
        usort($attention, static fn (array $a, array $b): int => [$a['health'] === 'OVERDUE' ? 0 : 1, -$a['totals']->overdue, $a['project']->due_date?->format('Y-m-d') ?? '9999', $a['project']->name]
            <=> [$b['health'] === 'OVERDUE' ? 0 : 1, -$b['totals']->overdue, $b['project']->due_date?->format('Y-m-d') ?? '9999', $b['project']->name]);

        return [
            'totals' => app(WorkSummary::class)->scopeTotals($actor, $today, $portfolio),
            'unassigned' => (clone $open)->whereDoesntHave('assignments')->count(),
            'ahead' => $ahead,
            'statuses' => (clone $projects)->select('status')->selectRaw('COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(static fn ($n): int => (int) $n)->all(),
            'attention' => array_slice($attention, 0, 6),
            'atRisk' => count($attention),
        ];
    }

    /**
     * Open projects whose money needs a look, worst first. Each budget is read
     * against itself, so currencies never mix. Null when the viewer can't see
     * any open project's finances.
     *
     * @return array{budgeted: int, unbudgeted: list<ProjectView>, atRisk: int, rows: list<array{project: ProjectView, currency: string, approved: int, paid: int, spent: int|null, done: int|null, risk: 'OVER'|'AHEAD'}>}|null
     */
    public function budgets(Access $actor, string $today, string $portfolio = 'ALL'): ?array
    {
        $money = app(ProjectFinancials::class)->openProjects($actor, $today, $portfolio);
        if ($money === []) {
            return null;
        }
        $budgeted = array_filter($money, static fn (array $row): bool => $row['approved'] !== null);
        $work = app(WorkSummary::class)->projects($actor, array_values(array_map(static fn (array $row): string => $row['project']->id, $budgeted)), $today);
        $rows = [];
        foreach ($budgeted as $row) {
            $approved = (int) $row['approved'];
            $progress = $work[$row['project']->id]->progress();
            $done = $progress['total'] > 0 ? $progress['percent'] : null;
            $risk = self::budgetRisk($approved, $row['paid'], $done);
            if ($risk !== null) {
                $rows[] = [...$row, 'approved' => $approved, 'spent' => $approved > 0 ? (int) round(100 * $row['paid'] / $approved) : null, 'done' => $done, 'risk' => $risk];
            }
        }
        // Furthest over budget first, then the widest gap between money spent and work done.
        $rank = static fn (array $row): array => $row['risk'] === 'OVER'
            ? [0, -($row['spent'] ?? PHP_INT_MAX), $row['project']->name]
            : [1, (int) $row['done'] - (int) $row['spent'], $row['project']->name];
        usort($rows, static fn (array $a, array $b): int => $rank($a) <=> $rank($b));

        return ['budgeted' => count($budgeted), 'unbudgeted' => array_values(array_map(static fn (array $row): ProjectView => $row['project'], array_filter($money, static fn (array $row): bool => $row['approved'] === null))), 'atRisk' => count($rows), 'rows' => array_slice($rows, 0, 5)];
    }

    /**
     * Overdue, this week from today, five more Monday-to-Sunday weeks, later
     * and no date.
     *
     * @return list<array{key: string, label: string, title: string, from: string|null, to: string|null}>
     */
    private function aheadColumns(string $today): array
    {
        $day = CarbonImmutable::parse($today, LocalClock::zone());
        $sunday = $day->endOfWeek(CarbonInterface::SUNDAY)->startOfDay();
        $span = static fn (CarbonImmutable $from, CarbonImmutable $to): string => $from->format('M j').' to '.($from->isSameMonth($to) ? $to->format('j') : $to->format('M j'));
        $columns = [['key' => 'overdue', 'label' => 'Overdue', 'title' => 'Overdue', 'from' => $today, 'to' => null]];
        $columns[] = ['key' => 'week0', 'label' => 'This week', 'title' => 'Due this week, '.$span($day, $sunday), 'from' => $today, 'to' => $sunday->format('Y-m-d')];
        for ($week = 1; $week <= 5; $week++) {
            [$from, $to] = [$sunday->addDays(7 * $week - 6), $sunday->addDays(7 * $week)];
            $columns[] = ['key' => 'week'.$week, 'label' => $week === 1 ? 'Next week' : $from->format('M j'), 'title' => 'Due '.$span($from, $to), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
        }
        $last = $sunday->addDays(35);
        $columns[] = ['key' => 'later', 'label' => 'Later', 'title' => 'Due after '.$last->format('M j'), 'from' => $last->format('Y-m-d'), 'to' => null];
        $columns[] = ['key' => 'none', 'label' => 'No date', 'title' => 'No due date', 'from' => null, 'to' => null];

        return $columns;
    }

    /**
     * Which Work ahead column a due date falls in: overdue, a week, later or none.
     *
     * @param  list<array{key: string, label: string, title: string, from: string|null, to: string|null}>  $columns
     */
    private function aheadIndex(array $columns, ?string $due): int
    {
        if ($due === null) {
            return count($columns) - 1;
        }
        foreach ($columns as $index => $column) {
            $fits = match ($column['key']) {
                'overdue' => $due < (string) $column['from'],
                'later' => $due > (string) $column['from'],
                'none' => false,
                default => $due >= (string) $column['from'] && $due <= (string) $column['to'],
            };
            if ($fits) {
                return $index;
            }
        }

        return count($columns) - 2;
    }

    /**
     * @param  list<array{from: string, to: string}>  $buckets
     * @return list<array{string, string}>
     */
    private function edges(array $buckets): array
    {
        return array_map(static fn (array $bucket): array => OverviewPeriod::instants($bucket), $buckets);
    }

    /** @param list<array{string, string}> $edges */
    private function bucketOf(array $edges, string $at): ?int
    {
        [$low, $high] = [0, count($edges) - 1];
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            if ($at < $edges[$middle][0]) {
                $high = $middle - 1;
            } elseif ($at >= $edges[$middle][1]) {
                $low = $middle + 1;
            } else {
                return $middle;
            }
        }

        return null;
    }

    /**
     * How many sorted values sit strictly before $value.
     *
     * @param  list<string>  $sorted
     */
    private function below(array $sorted, string $value): int
    {
        [$low, $high] = [0, count($sorted)];
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($sorted[$middle] < $value) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }
}
