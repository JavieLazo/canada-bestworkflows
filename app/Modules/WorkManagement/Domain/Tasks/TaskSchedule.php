<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Domain\Tasks;

use App\Domain\Projects\ProjectPolicy;
use App\Support\DateText;

/**
 * Where a task's planned start date may fall.
 *
 * DELIBERATE DIVERGENCE: the reference task has a due date and no start
 * (copems prisma/schema.prisma, model ProjectTask; projects.ts
 * taskDateError() only checks the due date). ems lets whoever hands out the
 * work also plan when it starts. The rule is the reference's window rule
 * turned round: a start must not fall before the project starts or after its
 * deadline, and a task must not be due before it starts. TaskPolicy::dateError
 * stays the literal port for the due date.
 *
 * A start date is OPTIONAL and only a plan: nothing here stops an assignee
 * starting early, and nothing stamps the date when they do.
 *
 * Pure: no Eloquent, no Carbon, no config(). Calendar days are 'Y-m-d'
 * strings compared with strcmp, for the reason ProjectPolicy::dateError()
 * gives.
 */
final class TaskSchedule
{
    private function __construct() {}

    /**
     * Whether a posted value is a real calendar day in storage form. The kit
     * date field only ever emits 'Y-m-d' or '', but the Livewire property
     * behind it is client-writable, so a command checks the shape itself
     * before the value reaches a comparison or the database.
     */
    public static function isDateKey(string $value): bool
    {
        return $value !== '' && DateText::toKey($value) === $value;
    }

    /**
     * Why a task cannot start on `$start`, or null when it can. A null or ''
     * start is always fine — the task simply has no planned start.
     *
     * The project window is checked first, then the task's own due date, so
     * the message names whichever edge the planner actually crossed.
     */
    public static function startDateError(?string $start, ?string $due, string $projectStart, ?string $projectDue): ?string
    {
        if ($start === null || $start === '') {
            return null;
        }

        if ($projectStart !== '' && strcmp($start, $projectStart) < 0) {
            return 'A task cannot start before the project starts.';
        }

        if ($projectDue !== null && $projectDue !== '' && strcmp($start, $projectDue) > 0) {
            return 'A task cannot start after the project deadline.';
        }

        return ProjectPolicy::dateError($start, $due);
    }

    /**
     * Whether a task's planned start and due date both still fit a project
     * window — what a project date change must not break. Null dates always
     * fit.
     */
    public static function fitsWindow(?string $start, ?string $due, string $projectStart, ?string $projectDue): bool
    {
        foreach ([$start, $due] as $day) {
            if ($day === null || $day === '') {
                continue;
            }
            if ($projectStart !== '' && strcmp($day, $projectStart) < 0) {
                return false;
            }
            if ($projectDue !== null && $projectDue !== '' && strcmp($day, $projectDue) > 0) {
                return false;
            }
        }

        return true;
    }
}
