<?php

declare(strict_types=1);

use App\Livewire\Roster\Hris as RosterHris;
use App\Modules\Timekeeping\Contracts\Views\RosterLocationView;

/**
 * The roster's Location cell reads a week the way people do:
 * the place the employee works most working days first, then each other
 * place with its days — in the week's order (`WorkWeek`: Sunday first,
 * Saturday last), consecutive days merged.
 * A working weekday with no row is the Assigned Worksite; rest days are left
 * out, so a place nobody is scheduled at never shows. Which weekdays work
 * comes from `workingWeekdays()`, which the Weekly schedule dialog's rest-day
 * hints read too.
 *
 * Pure PHP over detached views; no container, so this runs under tests/Unit.
 */

/**
 * @param  array<int, array{kind: string, site_id?: string|null, site_name?: string|null}>  $days  weekday => the row's facts
 * @return array<int, RosterLocationView>
 */
function rlocSummaryWeek(array $days): array
{
    $week = [];
    foreach ($days as $weekday => $facts) {
        $week[$weekday] = new RosterLocationView([
            'id' => 'rloc-'.$weekday,
            'employee_id' => 'emp-1',
            'weekday' => $weekday,
            'kind' => $facts['kind'],
            'site_id' => $facts['site_id'] ?? null,
            'site_name' => $facts['site_name'] ?? null,
        ]);
    }

    return $week;
}

/** @return list<int> */
function rlocMonFri(): array
{
    return [1, 2, 3, 4, 5];
}

it('leads with the place worked most days and lists the other places by day', function () {
    $week = rlocSummaryWeek([
        1 => ['kind' => 'WFH'],
        2 => ['kind' => 'ONSITE', 'site_id' => 'site-1', 'site_name' => 'Makati HQ'],
        3 => ['kind' => 'ONSITE', 'site_id' => 'site-2', 'site_name' => 'Quezon Depot'],
        4 => ['kind' => 'WFH'],
        5 => ['kind' => 'ONSITE', 'site_id' => 'site-1', 'site_name' => 'Makati HQ'],
    ]);

    // Makati and WFH tie on two days: the rostered Makati days are the
    // Assigned Worksite itself, and the Assigned Worksite wins the tie.
    expect(RosterHris::placeSummary($week, rlocMonFri(), 'site-1', 'Makati HQ'))->toBe([
        'main' => 'Makati HQ',
        'others' => [
            ['days' => 'Mon, Thu', 'label' => 'WFH'],
            ['days' => 'Wed', 'label' => 'Quezon Depot'],
        ],
    ]);
});

it('counts a working weekday with no row as the Assigned Worksite', function () {
    $week = rlocSummaryWeek([5 => ['kind' => 'WFH']]);

    expect(RosterHris::placeSummary($week, rlocMonFri(), 'site-1', 'Makati HQ'))->toBe([
        'main' => 'Makati HQ',
        'others' => [['days' => 'Fri', 'label' => 'WFH']],
    ]);
});

it('never mentions the Assigned Worksite when every working day has a place of its own', function () {
    $week = rlocSummaryWeek(array_fill_keys(rlocMonFri(), ['kind' => 'WFH']));

    expect(RosterHris::placeSummary($week, rlocMonFri(), 'site-9', 'Remote / Field Work'))->toBe([
        'main' => 'WFH',
        'others' => [],
    ]);
});

it('breaks a tie between roster places by the place seen first in the week', function () {
    $week = rlocSummaryWeek([
        1 => ['kind' => 'ONSITE', 'site_id' => 'site-2', 'site_name' => 'Cebu Branch'],
        2 => ['kind' => 'WFH'],
        3 => ['kind' => 'ONSITE', 'site_id' => 'site-2', 'site_name' => 'Cebu Branch'],
        4 => ['kind' => 'WFH'],
    ]);

    expect(RosterHris::placeSummary($week, [1, 2, 3, 4], 'site-1', 'Makati HQ'))->toBe([
        'main' => 'Cebu Branch',
        'others' => [['days' => 'Tue, Thu', 'label' => 'WFH']],
    ]);
});

it('leaves rest days out, so a place scheduled on no working day never shows', function () {
    $week = rlocSummaryWeek([
        0 => ['kind' => 'WFH'],
        6 => ['kind' => 'WFH'],
        3 => ['kind' => 'ONSITE', 'site_id' => 'site-2', 'site_name' => 'Cebu Branch'],
    ]);

    expect(RosterHris::placeSummary($week, rlocMonFri(), 'site-1', 'Makati HQ'))->toBe([
        'main' => 'Makati HQ',
        'others' => [['days' => 'Wed', 'label' => 'Cebu Branch']],
    ]);
});

it('merges consecutive days into a range, Sunday first and Saturday last, and never joins Saturday to Sunday', function () {
    $week = rlocSummaryWeek([
        0 => ['kind' => 'WFH'],
        6 => ['kind' => 'WFH'],
        1 => ['kind' => 'WFH'],
        2 => ['kind' => 'WFH'],
        3 => ['kind' => 'WFH'],
        5 => ['kind' => 'WFH'],
    ]);

    // Six WFH rows outnumber the one bare Thursday, so WFH leads and the
    // Assigned Worksite is the odd day out.
    expect(RosterHris::placeSummary($week, [0, 1, 2, 3, 4, 5, 6], 'site-1', 'Makati HQ'))->toBe([
        'main' => 'WFH',
        'others' => [['days' => 'Thu', 'label' => 'Makati HQ']],
    ]);

    $week = rlocSummaryWeek([
        0 => ['kind' => 'WFH'],
        6 => ['kind' => 'WFH'],
        1 => ['kind' => 'WFH'],
    ]);

    // Four bare weekdays now outnumber the three WFH days, so the range
    // spelling shows on the second line: the week opens on Sunday, which
    // joins Monday, and Saturday closes it on its own.
    expect(RosterHris::placeSummary($week, [0, 1, 2, 3, 4, 5, 6], 'site-1', 'Makati HQ'))->toBe([
        'main' => 'Makati HQ',
        'others' => [['days' => 'Sun–Mon, Sat', 'label' => 'WFH']],
    ]);
});

it('labels an onsite row whose site no longer resolves', function () {
    $week = rlocSummaryWeek([
        2 => ['kind' => 'ONSITE', 'site_id' => 'site-gone', 'site_name' => null],
    ]);

    expect(RosterHris::placeSummary($week, rlocMonFri(), 'site-1', 'Makati HQ'))->toBe([
        'main' => 'Makati HQ',
        'others' => [['days' => 'Tue', 'label' => 'Site unavailable']],
    ]);
});

it('answers a null main place when the days fall back to an Assigned Worksite nobody set', function () {
    expect(RosterHris::placeSummary([], rlocMonFri(), null, null))->toBe(['main' => null, 'others' => []]);

    $week = rlocSummaryWeek(array_fill_keys([1, 2, 3], ['kind' => 'WFH']));

    expect(RosterHris::placeSummary($week, rlocMonFri(), null, null))->toBe([
        'main' => 'WFH',
        'others' => [['days' => 'Thu–Fri', 'label' => 'No worksite set']],
    ]);
});

it('works Monday to Friday when the standing row is missing or predates work_days, then applies each weekday choice', function () {
    expect(RosterHris::workingWeekdays(null))->toBe([1, 2, 3, 4, 5])
        ->and(RosterHris::workingWeekdays([]))->toBe([1, 2, 3, 4, 5])
        ->and(RosterHris::workingWeekdays([1, 2, 3, 4, 5, 6]))->toBe([1, 2, 3, 4, 5, 6])
        // A REST pick rests the day; a pattern pick works it whatever the pattern's own week says.
        ->and(RosterHris::workingWeekdays([1, 2, 3, 4, 5], [3 => false, 6 => true]))->toBe([1, 2, 4, 5, 6])
        ->and(RosterHris::workingWeekdays([1, 2, 3, 4, 5], [1 => false, 2 => false, 3 => false, 4 => false, 5 => false]))->toBe([]);
});
