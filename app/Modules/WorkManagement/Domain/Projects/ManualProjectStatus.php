<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Domain\Projects;

use App\Domain\Projects\ProjectPolicy;

/**
 * A project status set BY HAND — the header select on the project screen and
 * the status field of the edit dialog.
 *
 * DELIBERATE DIVERGENCE: the reference makes COMPLETED and CANCELLED terminal
 * (ProjectPolicy::TRANSITIONS, a literal port of PROJECT_TRANSITIONS). ems lets
 * a Projects manager, or the project's own Task Leader, move a project to ANY
 * other status — including reopening one that was completed or called off.
 * ProjectPolicy::TRANSITIONS stays the literal port: a new project is created
 * along it (CreateProject).
 *
 * What a hand-set status may NOT do is leave the project contradicting its
 * own facts, so the two safeguards of ProjectPolicy::statusError() stand: an
 * ACTIVE project needs a Task Leader, and a COMPLETED one needs every task
 * completed or cancelled.
 *
 * The map is still keyed by the CURRENT status — every status to every other
 * one — never a flat list of allowed targets, so an unknown current status
 * reaches nothing, as everywhere else.
 *
 * Pure: no Eloquent, no Carbon, no config(). Rows arrive as plain arrays.
 */
final class ManualProjectStatus
{
    private function __construct() {}

    /**
     * Keyed by the current status: every OTHER status. Built from
     * ProjectPolicy::STATUSES so a status added there is reachable here.
     *
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        $map = [];
        foreach (array_keys(ProjectPolicy::STATUSES) as $from) {
            $map[$from] = array_values(array_filter(
                array_keys(ProjectPolicy::STATUSES),
                static fn (string $to): bool => $to !== $from,
            ));
        }

        return $map;
    }

    /** @return list<string> */
    public static function canMoveTo(string $from): array
    {
        return self::transitions()[$from] ?? [];
    }

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::canMoveTo($from), true);
    }

    /**
     * Why the project cannot be set to `$next` by hand, or null when it can.
     * Staying on the same status is always allowed, as in statusError(), so a
     * details-only save that echoes the status passes.
     *
     * @param  array{status: string, manager_id?: string|null}  $project
     * @param  list<array{status: string}>  $tasks  every task of the project
     */
    public static function error(array $project, array $tasks, string $next): ?string
    {
        if (! ProjectPolicy::isStatus($next)) {
            return 'That project status is not supported.';
        }

        if ($next !== $project['status'] && ! self::canMove($project['status'], $next)) {
            return sprintf(
                'A %s project cannot move to %s.',
                mb_strtolower(ProjectPolicy::statusLabel($project['status'])),
                mb_strtolower(ProjectPolicy::statusLabel($next)),
            );
        }

        $manager = $project['manager_id'] ?? null;
        if ($next === 'ACTIVE' && ($manager === null || $manager === '')) {
            return 'Assign a task leader before activating this project.';
        }

        if ($next === 'COMPLETED') {
            foreach ($tasks as $task) {
                if ($task['status'] !== 'COMPLETED' && $task['status'] !== 'CANCELLED') {
                    return 'Complete or cancel every task before completing the project.';
                }
            }
        }

        return null;
    }
}
