<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Domain\Projects\ProjectPolicy;
use App\Modules\WorkManagement\Domain\Projects\ManualProjectStatus;

/**
 * Detached aggregates avoid hydrating every task to render project health and progress.
 *
 * `review` counts the tasks waiting on a reviewer by WorkScope::NEEDS_REVIEW_SQL
 * — a GROUP task handed in, or an INDIVIDUAL task in progress with at least
 * one response submitted — the rule {@see ProjectTaskView::awaitsReview()}
 * applies to one task. Such an INDIVIDUAL task is still IN_PROGRESS, so it
 * stays among the open tasks manualStatusError() counts.
 */
final readonly class TaskTotals
{
    public function __construct(
        public int $total = 0,
        public int $completed = 0,
        public int $cancelled = 0,
        public int $review = 0,
        public int $overdue = 0,
        public int $dueSoon = 0,
        public int $unassigned = 0,
        public ?string $earliestOpenDue = null,
        public ?int $leafTotal = null,
        public ?int $leafCompleted = null,
        public ?int $leafCancelled = null,
    ) {}

    /** @return array{total: int, completed: int, percent: int} */
    public function progress(): array
    {
        return ProjectPolicy::progressCounts(($this->leafTotal ?? $this->total) - ($this->leafCancelled ?? $this->cancelled), $this->leafCompleted ?? $this->completed);
    }

    public function health(ProjectView $project, string $today): string
    {
        // Health asks whether any open task is overdue or due soon. The earliest
        // open deadline answers both without changing the domain decision order.
        $tasks = $this->earliestOpenDue === null ? [] : [['status' => 'PENDING', 'due_date' => $this->earliestOpenDue]];

        return ProjectPolicy::health(['status' => $project->status, 'due_date' => $project->due_date?->format('Y-m-d')], $tasks, $today);
    }

    /**
     * ProjectPolicy::statusError() for a status set BY HAND
     * (ManualProjectStatus): what the header select disables. Display only —
     * ChangeProjectStatus decides on the locked task rows. Whole-project for
     * whoever runs the project, because a Task Leader's task scope covers
     * every task they lead.
     */
    public function manualStatusError(ProjectView $project, string $next): ?string
    {
        return ManualProjectStatus::error($this->projectShape($project), $this->openShape(), $next);
    }

    /** @return array{status: string, manager_id: string|null} */
    private function projectShape(ProjectView $project): array
    {
        return ['status' => $project->status, 'manager_id' => $project->manager_id === null ? null : (string) $project->manager_id];
    }

    /**
     * One stand-in open task when any task is still open — all
     * ManualProjectStatus::error() looks at.
     *
     * @return list<array{status: string}>
     */
    private function openShape(): array
    {
        return $this->total - $this->completed - $this->cancelled > 0 ? [['status' => 'PENDING']] : [];
    }
}
