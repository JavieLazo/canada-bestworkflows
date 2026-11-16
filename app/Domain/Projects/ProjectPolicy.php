<?php

declare(strict_types=1);

namespace App\Domain\Projects;

/**
 * A literal port of the project half of copems/src/lib/projects.ts.
 *
 * Management creates a project, adds tasks and assigns them to employees.
 * This class is the ONLY place a project status change is allowed to
 * come from — the reference README makes that claim about projects.ts and the
 * reference API layer then failed to honour it (see TaskPolicy's note on the
 * flat-list bug). Controllers, Livewire components and Blade all route through
 * here — with ONE sanctioned exception (DELIBERATE DIVERGENCE): a status set
 * BY HAND from the project screen goes through the WorkManagement module's
 * ManualProjectStatus, which reaches every other status, the "terminal" ones
 * included, and keeps this class's two safeguards. TRANSITIONS below is the
 * reference's map and governs creation.
 *
 * Pure: no Eloquent, no Carbon, no config(). Calendar days are 'Y-m-d'
 * STRINGS, rows arrive as plain arrays. Nothing here touches money, so
 * Money::round() is not in play; the one place JS's Math.round appears is the
 * completion percentage, transcribed below.
 */
final class ProjectPolicy
{
    /**
     * TS: PROJECT_STATUSES — a list of {value,label} pairs. An ordered PHP map
     * keeps both properties: iteration order is the order a picker renders,
     * and the key is the lookup projectStatusLabel() did with .find().
     *
     * @var array<string, string>
     */
    public const STATUSES = [
        'PLANNING' => 'Planning',
        'ACTIVE' => 'Active',
        'ON_HOLD' => 'On Hold',
        'COMPLETED' => 'Completed',
        'CANCELLED' => 'Cancelled',
    ];

    /**
     * TS: PROJECT_TRANSITIONS — keyed by the CURRENT status, not a flat list of
     * reachable targets. COMPLETED and CANCELLED are terminal: nothing moves
     * out of them, which is the whole point of the map form.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'PLANNING' => ['ACTIVE', 'ON_HOLD', 'CANCELLED'],
        'ACTIVE' => ['ON_HOLD', 'COMPLETED', 'CANCELLED'],
        'ON_HOLD' => ['ACTIVE', 'CANCELLED'],
        'COMPLETED' => [],
        'CANCELLED' => [],
    ];

    /**
     * TS: projectHealth() returns one of these four.
     *
     * @var array<string, string>
     */
    public const HEALTH_LABELS = [
        'ON_TRACK' => 'On track',
        'DUE_SOON' => 'Due soon',
        'OVERDUE' => 'Overdue',
        'CLOSED' => 'Closed',
    ];

    /**
     * A task inside this project falling due within this many days makes the
     * project read DUE_SOON.
     *
     * projects.ts writes this as the bare literal 2 inside projectHealth().
     * reminders.ts has its own TASK_DUE_SOON_DAYS = 2 for a different purpose
     * (announcing the task to its assignee). The two numbers agree today and
     * are NOT linked in the reference — changing one does not move the other.
     */
    public const HEALTH_TASK_DUE_SOON_DAYS = 2;

    /**
     * The project's own due date landing within this many days makes it read
     * DUE_SOON. The bare literal 7 in projectHealth(); reminders.ts separately
     * holds PROJECT_DUE_SOON_DAYS = 7. Same non-relationship as above.
     */
    public const HEALTH_PROJECT_DUE_SOON_DAYS = 7;

    /** TS: projectStatusLabel() */
    public static function statusLabel(string $value): string
    {
        return self::STATUSES[$value] ?? $value;
    }

    /** TS: isProjectStatus() */
    public static function isStatus(string $value): bool
    {
        return array_key_exists($value, self::STATUSES);
    }

    /** TS: projectAuditAction() */
    public static function auditAction(string $from, string $to): string
    {
        return $from === $to ? 'PROJECT_UPDATED' : 'PROJECT_STATUS_CHANGED';
    }

    /**
     * TS: projectStatusPill() — mapped onto the app.css pill palette rather
     * than carried across verbatim (the React app used info/warning, which
     * this stylesheet renders as plain grey). Planning is quiet, active is the
     * in-flight brand tint, on hold is amber because someone has to decide
     * whether it resumes, completed is the strong "accepted" green, cancelled
     * is red.
     */
    public static function statusPill(string $value): string
    {
        return match ($value) {
            'PLANNING' => 'pill pill-muted',
            'ACTIVE' => 'pill pill-primary',
            'ON_HOLD' => 'pill pill-caution',
            'COMPLETED' => 'pill pill-success-strong',
            'CANCELLED' => 'pill pill-danger',
            default => 'pill pill-muted',
        };
    }

    /**
     * TS: PROJECT_TRANSITIONS[from] ?? [] — an unknown status reaches nothing.
     *
     * @return list<string>
     */
    public static function canMoveTo(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    /**
     * No direct TS counterpart: the reference inlines
     * `PROJECT_TRANSITIONS[status]?.includes(next)` at each call site. Same
     * semantics, including "an unknown current status can move nowhere".
     */
    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::canMoveTo($from), true);
    }

    /**
     * TS: daysUntilDate() — whole calendar days between two 'Y-m-d' keys,
     * without timezone drift.
     *
     * The reference builds both ends with Date.UTC(y, m - 1, d) and divides by
     * 86_400_000. gmmktime() is the same construction one power of ten down
     * (seconds, not milliseconds), and rolls over out-of-range components the
     * same way Date.UTC does, so 2026-13-01 still means 2027-01-01.
     *
     * Divergence, unreachable for well-formed keys: JS `Number("abc")` is NaN
     * and PHP `(int) "abc"` is 0, so a malformed key yields NaN there and a
     * number here. Both are garbage; neither is reachable from a DATE column.
     */
    public static function daysUntilDate(string $date, string $today): int
    {
        return self::jsRound((self::utcDay($date) - self::utcDay($today)) / 86400);
    }

    /**
     * TS: projectHealth() — the compact signal used by the manager queue and
     * the project header.
     *
     * @param  array{status: string, due_date?: string|null}  $project
     * @param  list<array{status: string, due_date?: string|null}>  $tasks
     * @return 'ON_TRACK'|'DUE_SOON'|'OVERDUE'|'CLOSED'
     */
    public static function health(array $project, array $tasks, string $today): string
    {
        if ($project['status'] === 'COMPLETED' || $project['status'] === 'CANCELLED') {
            return 'CLOSED';
        }

        foreach ($tasks as $task) {
            if (TaskPolicy::isOverdue($task, $today)) {
                return 'OVERDUE';
            }
        }

        foreach ($tasks as $task) {
            $due = $task['due_date'] ?? null;
            if ($due === null || $due === '' || TaskPolicy::isClosed($task['status'])) {
                continue;
            }
            $left = self::daysUntilDate($due, $today);
            if ($left >= 0 && $left <= self::HEALTH_TASK_DUE_SOON_DAYS) {
                return 'DUE_SOON';
            }
        }

        $projectDue = $project['due_date'] ?? null;
        if ($projectDue !== null && $projectDue !== ''
            && self::daysUntilDate($projectDue, $today) <= self::HEALTH_PROJECT_DUE_SOON_DAYS) {
            return 'DUE_SOON';
        }

        return 'ON_TRACK';
    }

    /** TS: projectHealthLabel() */
    public static function healthLabel(string $value): string
    {
        return self::HEALTH_LABELS[$value] ?? $value;
    }

    /**
     * TS: projectHealthPill(). DUE_SOON is the caution tier (amber) rather
     * than the reference's warning class, which app.css renders as grey — the
     * whole point of the signal is that it stands out before it turns red.
     */
    public static function healthPill(string $value): string
    {
        return match ($value) {
            'ON_TRACK' => 'pill pill-success',
            'DUE_SOON' => 'pill pill-caution',
            'OVERDUE' => 'pill pill-danger',
            'CLOSED' => 'pill pill-muted',
            default => 'pill pill-muted',
        };
    }

    /**
     * TS: projectDateError().
     *
     * Compared with strcmp rather than PHP's `<`: 'Y-m-d' keys are not numeric
     * strings so PHP would in fact compare them as strings today, but the
     * numeric-string comparison rules are exactly the kind of thing that would
     * silently change the answer, and the reference is doing a lexicographic
     * compare on ISO date keys.
     */
    public static function dateError(string $startDate, ?string $dueDate): ?string
    {
        if ($dueDate !== null && $dueDate !== '' && $startDate !== ''
            && strcmp($dueDate, $startDate) < 0) {
            return 'The due date cannot fall before the start date.';
        }

        return null;
    }

    /**
     * TS: projectStatusError().
     *
     * Note the first guard: moving to the SAME status is always allowed, so a
     * details-only PATCH that echoes the current status passes. Only an actual
     * change is put through the transition map.
     *
     * @param  array{status: string, manager_id?: string|null}  $project
     * @param  list<array{status: string, has_subtasks?: bool}>  $tasks
     */
    public static function statusError(array $project, array $tasks, string $nextStatus): ?string
    {
        if (! self::isStatus($nextStatus)) {
            return 'That project status is not supported.';
        }

        if ($nextStatus !== $project['status'] && ! self::canMove($project['status'], $nextStatus)) {
            return sprintf(
                'A %s project cannot move to %s.',
                mb_strtolower(self::statusLabel($project['status'])),
                mb_strtolower(self::statusLabel($nextStatus)),
            );
        }

        $manager = $project['manager_id'] ?? null;
        if ($nextStatus === 'ACTIVE' && ($manager === null || $manager === '')) {
            return 'Assign a project lead before activating this project.';
        }

        if ($nextStatus === 'COMPLETED') {
            foreach ($tasks as $task) {
                if ($task['status'] !== 'COMPLETED' && $task['status'] !== 'CANCELLED') {
                    return 'Complete or cancel every task before completing the project.';
                }
            }
        }

        return null;
    }

    /**
     * TS: projectProgress().
     *
     * Cancelled tasks leave the denominator — a project whose only open task
     * was called off reads as done, not as stuck at 0%.
     *
     * @param  list<array{status: string, has_subtasks?: bool}>  $tasks
     * @return array{total: int, completed: int, percent: int}
     */
    public static function progress(array $tasks): array
    {
        $total = 0;
        $completed = 0;

        foreach ($tasks as $task) {
            if ($task['status'] === 'CANCELLED' || ($task['has_subtasks'] ?? false)) {
                continue;
            }
            $total++;
            if ($task['status'] === 'COMPLETED') {
                $completed++;
            }
        }

        return self::progressCounts($total, $completed);
    }

    /** @return array{total: int, completed: int, percent: int} */
    public static function progressCounts(int $total, int $completed): array
    {
        return [
            'total' => $total,
            'completed' => $completed,
            'percent' => $total === 0 ? 0 : self::jsRound($completed / $total * 100),
        ];
    }

    /** Date.UTC(y, m - 1, d), in seconds rather than milliseconds. */
    private static function utcDay(string $key): int
    {
        $parts = explode('-', substr($key, 0, 10));

        // The reference destructures with defaults [year = 0, month = 1, day = 1],
        // which only fire when a component is MISSING. `explode()` always returns
        // at least one element, so the year default is unreachable: an absent key
        // is the empty string, which casts to the same 0.
        $year = (int) $parts[0];
        $month = isset($parts[1]) ? (int) $parts[1] : 1;
        $day = isset($parts[2]) ? (int) $parts[2] : 1;

        return gmmktime(0, 0, 0, $month, $day, $year) ?: 0;
    }

    /**
     * JS Math.round, to an integer.
     *
     * NOT PHP's round(): that is half-away-from-zero, while JS is half-UP
     * (toward +Infinity), so Math.round(-0.5) is -0 and round(-0.5) is -1. Day
     * differences do go negative here (an overdue project), so the difference
     * is reachable. Money::round() is the 2-dp money boundary and is the wrong
     * tool for a day count; this is the same floor-based construction without
     * the *100 / 100.
     */
    private static function jsRound(float $value): int
    {
        $floor = floor($value);

        return (int) (($value - $floor) >= 0.5 ? $floor + 1 : $floor);
    }
}
