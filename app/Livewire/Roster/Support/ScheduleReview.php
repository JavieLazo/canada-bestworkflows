<?php

declare(strict_types=1);

namespace App\Livewire\Roster\Support;

use App\Modules\Timekeeping\Application\ScheduleImpact;
use App\Support\HrisFormat;

/**
 * The review dialog's lines — what a schedule change affects, in the
 * person's words.
 *
 * Presentation only: every fact comes from the command's own
 * `ScheduleImpact`. Shift Roster and Shifting Settings both hold a save for
 * review, so the wording lives here once and the two dialogs cannot drift.
 */
final class ScheduleReview
{
    /** What the dialog holds before any save was held. */
    public const EMPTY = ['takes_effect' => '', 'moved' => '', 'conflicts' => [], 'rest_days' => [], 'drafts' => []];

    /**
     * @param  list<array{id: string, from: string, to: string, shift: string, place: string, overlap_from: string, overlap_to: string}>  $conflicts
     * @return array{takes_effect: string, moved: string, conflicts: list<string>, rest_days: list<string>, drafts: list<string>}
     */
    public static function lines(ScheduleImpact $impact, array $conflicts = []): array
    {
        $span = static fn (string $from, string $to): string => $from === $to ? HrisFormat::date($from) : HrisFormat::date($from).' to '.HrisFormat::date($to);
        $date = $impact->date();
        $reasons = array_values(array_unique($impact->deferred));

        return [
            'takes_effect' => $date === null
                ? (count($impact->effective) > 1 ? 'From '.HrisFormat::date(min($impact->effective)).' or '.HrisFormat::date(max($impact->effective)).', depending on the employee' : '')
                : HrisFormat::date($date),
            'moved' => $reasons === [] ? '' : 'Today is no longer possible'.(count($impact->effective) > 1 ? ' for '.count($impact->deferred).' of '.count($impact->effective).' employees' : '').': '.implode('; ', $reasons).'.',
            'conflicts' => array_map(static fn (array $c): string => $span($c['from'], $c['to']).' · '.$c['shift'].' · '.$c['place'].' — shares '.$span($c['overlap_from'], $c['overlap_to']), $conflicts),
            'rest_days' => array_map(static fn (array $d): string => HrisFormat::date($d['date']).' becomes a '.($d['works'] ? 'working day' : 'rest day'), $impact->restDays),
            'drafts' => array_values(array_unique(array_map(static fn (array $d): string => $d['code'].' · '.$d['name'].' · '.$span($d['period_start'], $d['period_end']), $impact->drafts))),
        ];
    }
}
