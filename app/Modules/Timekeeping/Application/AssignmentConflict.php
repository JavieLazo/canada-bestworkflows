<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use RuntimeException;

/**
 * A temporary schedule that overlaps one already saved (D1): nothing is
 * ever replaced silently. Nothing was written; the screen lists
 * `$conflicts` — each existing assignment and the dates the two share — and
 * saves again naming the ones the person chose to replace.
 */
final class AssignmentConflict extends RuntimeException
{
    /** @param  list<array{id: string, from: string, to: string, shift: string, place: string, overlap_from: string, overlap_to: string}>  $conflicts */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct(count($conflicts) === 1
            ? 'These dates overlap a temporary schedule that is already saved. Review it, then choose what to replace.'
            : 'These dates overlap temporary schedules that are already saved. Review them, then choose what to replace.');
    }
}
