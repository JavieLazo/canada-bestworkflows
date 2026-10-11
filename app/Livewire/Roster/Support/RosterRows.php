<?php

declare(strict_types=1);

namespace App\Livewire\Roster\Support;

use App\Domain\Attendance\Shift as DomainShift;
use App\Livewire\Roster\Hris;
use App\Modules\Timekeeping\Contracts\Views\RosterLocationView;
use App\Modules\Timekeeping\Contracts\Views\RosterShiftView;
use App\Modules\Timekeeping\Contracts\Views\ShiftView as Shift;
use App\Modules\Workforce\Contracts\DirectoryEmployee as EmployeeProfile;
use App\Support\CodeSort;
use App\Support\EmployeeName;
use Illuminate\Support\Collection;

/**
 * The Shift Roster rows: filter, join and sort — the in-memory pipeline of
 * roster-page.tsx:86-128, kept in PHP so blanks-last sorting and the desc
 * `reverse()` behave exactly as the source's array pipeline did. One copy,
 * kept out of the component so it stays a pure function of its inputs.
 */
final class RosterRows
{
    /**
     * @param  Collection<int, EmployeeProfile>  $employees
     * @param  Collection<array-key, Shift>  $shifts  keyed by employee id
     * @param  array<string, array<int, RosterLocationView>>  $weekly  employee id => weekday => view; no entry means no row
     * @param  array<string, array<int, RosterShiftView>>  $weeklyShifts
     * @return list<array{employee: EmployeeProfile, shift: Shift|null, locations: array<int, RosterLocationView>, weeklyShifts: array<int, RosterShiftView>}>
     */
    public static function build(
        Collection $employees,
        Collection $shifts,
        array $weekly,
        array $weeklyShifts,
        string $search,
        string $status,
        string $position,
        string $pattern,
        string $sortKey,
        string $sortDir,
    ): array {
        $needle = mb_strtolower(trim($search));

        $withShift = $employees
            ->filter(static function (EmployeeProfile $e) use ($needle, $status, $position): bool {
                // "INACTIVE" keeps only inactive people; a status name keeps
                // matching statusKeys, active or not (roster-page.tsx:88-94).
                $excluded = $status === 'INACTIVE'
                    ? $e->is_active
                    : ($status !== 'ALL'
                        && Hris::statusKey($e->employment_status) !== Hris::statusKey($status));

                if ($excluded) {
                    return false;
                }

                if ($position !== 'ALL' && ($e->position ?? '') !== $position) {
                    return false;
                }

                if ($needle === '') {
                    return true;
                }

                $haystack = mb_strtolower(
                    "{$e->employee_code} ".EmployeeName::display($e, '').' '.($e->position ?? '')
                );

                return str_contains($haystack, $needle);
            })
            ->map(static fn (EmployeeProfile $e): array => [
                'employee' => $e,
                'shift' => $shifts->get($e->id),
                'locations' => $weekly[$e->id] ?? [],
                'weeklyShifts' => $weeklyShifts[$e->id] ?? [],
            ])
            ->filter(static function (array $row) use ($pattern): bool {
                if ($pattern === 'ALL') {
                    return true;
                }

                /** @var Shift|null $shift */
                $shift = $row['shift'];

                if ($pattern === 'UNASSIGNED') {
                    return ! ($shift?->shift_type_id) && $row['weeklyShifts'] === [];
                }

                return $shift?->shift_type_id === $pattern
                    || array_any($row['weeklyShifts'], static fn (RosterShiftView $day): bool => $day->shift_type_id === $pattern);
            })
            ->values();

        $sorted = $withShift
            ->sort(static function (array $a, array $b) use ($sortKey): int {
                /** @var EmployeeProfile $ea */
                $ea = $a['employee'];
                /** @var EmployeeProfile $eb */
                $eb = $b['employee'];

                return match ($sortKey) {
                    // The id column, so CodeSort — see App\Support\CodeSort.
                    'id' => CodeSort::compare($ea->employee_code, $eb->employee_code),
                    'shift' => self::compareHours($a['shift'], $b['shift']),
                    default => Hris::compareText($ea->last_name, $eb->last_name)
                        ?: Hris::compareText($ea->first_name, $eb->first_name),
                };
            })
            ->values();

        // sort.dir === "asc" ? withShift : withShift.reverse() — roster-page.tsx:127.
        $rows = $sortDir === 'desc' ? $sorted->reverse() : $sorted;

        return array_values($rows->all());
    }

    /**
     * The Hours column's ascending order.
     *
     * DELIBERATE DIVERGENCE from roster-page.tsx:118-119, which compares
     * `start_time` for every row: a FLEXIBLE row stores the filler 08:00
     * clock, so that order files it among the 8:00 AM rows while its cell
     * reads "Flexible · 8 h per day". Fixed rows come first, by start time
     * exactly as the reference orders them; flexible rows follow, by their
     * daily hours with a missing count last; rows with no shift stay last,
     * where the blank start time puts them. The desc order is the whole list
     * reversed, as in the reference.
     */
    private static function compareHours(?Shift $a, ?Shift $b): int
    {
        $rank = static fn (?Shift $shift): int => match (true) {
            $shift === null => 2,
            $shift->mode === DomainShift::MODE_FLEXIBLE => 1,
            default => 0,
        };

        if ($a === null || $b === null || $rank($a) !== $rank($b)) {
            return $rank($a) <=> $rank($b);
        }

        if ($rank($a) === 0) {
            return Hris::compareText($a->start_time, $b->start_time);
        }

        if ($a->required_hours === null || $b->required_hours === null) {
            return ($a->required_hours === null) <=> ($b->required_hours === null);
        }

        return $a->required_hours <=> $b->required_hours;
    }
}
