<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\RosterLocation;

/**
 * A demo week of weekday locations — DEMO ONLY, never reached from
 * ProductionSeeder.
 *
 * The reference seed (`src/lib/seed.ts`) has nothing to port here: in the
 * reference every employee works at one Assigned Worksite on every day, and
 * there is no working from home. This seeder exists so the roster_locations
 * feature (2026_09_26_0001) can be walked through on the demo data without
 * first being configured by hand:
 *
 *  - emp-3 (Andrea Lim, Assigned Worksite site-1) has a full mixed week —
 *    Mon WFH, Tue Makati, Wed Quezon City, Thu WFH, Fri Makati — so the
 *    Shift Roster's Location column, the punch station and the correction
 *    dialog each show every kind on one employee;
 *  - emp-7 (Liza Domingo) has a single WFH Friday, the minimal case: one row,
 *    every other weekday still the Assigned Worksite.
 *
 * Every other employee has NO row, which is what "the Assigned Worksite,
 * geofenced" looks like in the table. AttendanceSeeder runs AFTER this one
 * and reads these rows, so the demo feed punches from home on a WFH weekday
 * instead of contradicting the roster.
 *
 * Rows are keyed on the natural (employee_id, weekday) pair of the BASELINE
 * (NULL `effective_from` and `change_id`, 2026_09_30_0006). New rows receive
 * BaseModel keys; existing rows keep their keys. After writing, every baseline weekday of a seeded employee that this
 * run did NOT write is deleted, the ProjectSeeder cleanup shape. A
 * walkthrough's dated changes are history and stay, so they still decide
 * the dates from which they were made. Kinds are the literal `ONSITE` | `WFH`
 * of `Modules\Timekeeping\Domain\Roster\LocationKind`, as every other seeder
 * spells its enum-like values.
 */
final class RosterLocationSeeder extends PortSeeder
{
    /**
     * 0-based reference index => weekday (0 Sunday … 6 Saturday) => 'WFH' or
     * a work_sites id. Weekdays absent here get no row: the Assigned Worksite.
     */
    private const WEEKS = [
        2 => [1 => 'WFH', 2 => 'site-1', 3 => 'site-2', 4 => 'WFH', 5 => 'site-1'],
        6 => [5 => 'WFH'],
    ];

    public function run(): void
    {
        $employees = $this->employeeIdsByIndex();

        // Missing demo sites are skipped rather than tripping a foreign key.
        $siteIds = $this->siteIdsBySeedKey();

        foreach (self::WEEKS as $index => $week) {
            $employeeId = $employees[$index] ?? null;

            if ($employeeId === null) {
                continue; // employeeIdsByIndex() already tolerates a missing employee
            }

            $seededDays = [];

            foreach ($week as $weekday => $place) {
                $home = $place === 'WFH';

                if (! $home && ! isset($siteIds[$place])) {
                    $this->command->warn(sprintf(
                        'Work site %s is missing: emp-%d was not given a location for %s.',
                        $place,
                        $index + 1,
                        ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][$weekday],
                    ));

                    continue;
                }

                $this->upsert(RosterLocation::class, ['employee_id' => $employeeId, 'weekday' => $weekday, 'effective_from' => null, 'change_id' => null], [
                    'id' => sprintf('rloc-%d-%d', $index + 1, $weekday),
                    'kind' => $home ? 'WFH' : 'ONSITE',
                    'site_id' => $home ? null : $siteIds[$place],
                ]);

                $seededDays[] = $weekday;
            }

            // Whatever baseline row a walkthrough left on the other weekdays
            // goes: the demo's baseline week is exactly the rows written
            // above. An empty list compiles to an unconditional delete of the
            // baseline, which is right. Dated changes are the walkthrough's
            // own history and stay.
            RosterLocation::query()
                ->where('employee_id', $employeeId)
                ->whereNull('effective_from')->whereNull('change_id')
                ->whereNotIn('weekday', $seededDays)
                ->delete();
        }
    }
}
