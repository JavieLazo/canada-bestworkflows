<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\RosterLocation;
use App\Models\WorkSite;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;

/**
 * Two local weeks of punches, the port of seed.ts buildAttendance() with the
 * task-mandated DTR guarantees pinned to weekdays so they always land:
 *
 *  - in/out pairs sit around each employee's own shift bounds (a few minutes
 *    early in, a few minutes over on the way out);
 *  - exactly ONE late day per employee, staggered across the window;
 *  - one undertime day (Jose Rivera leaves 90 minutes early);
 *  - one absent day (Liza Domingo, no punches at all);
 *  - one missing time-out (Paolo Bautista never clocked out).
 *
 * WHERE a pair is punched follows RosterLocationSeeder, which runs first and
 * whose rows are read back here rather than restated: on a weekday whose
 * roster_locations row says WFH the pair comes from a home address a few
 * kilometres from the office — no site, no distance, `location_kind` WFH,
 * the browser's accuracy recorded — and on a weekday whose row names a site
 * it is punched at THAT site, so the demo feed never contradicts the roster.
 * A weekday with no row punches at the employee's usual site, exactly as
 * every punch did before the table existed. Every ONSITE punch is inside its
 * fence, because one outside it is refused at the station and could not have
 * been recorded. Wall-clock strings go through LocalClock::instant() and are
 * STORED AS UTC — never as local wall clock.
 */
final class AttendanceSeeder extends PortSeeder
{
    /**
     * Where a WFH pair is punched from: a home a few kilometres east of the
     * Makati office, nudged per employee so two homes never share a pin.
     * Plausible, not real.
     */
    private const HOME_LATITUDE = 14.5869;

    private const HOME_LONGITUDE = 121.0614;

    public function run(): void
    {
        $employees = $this->employeeIdsByIndex();
        $siteIds = $this->siteIdsBySeedKey();

        $sites = WorkSite::query()
            ->whereIn('id', array_values($siteIds))
            ->get()
            ->keyBy('id');

        if ($employees === [] || ! isset($siteIds['site-1'], $siteIds['site-2'])) {
            return;
        }

        // The seeded weekly locations, employee => weekday => row, read from
        // the baseline RosterLocationSeeder just wrote so the two never drift.
        /** @var array<string, array<int, RosterLocation>> $locations */
        $locations = [];

        foreach (RosterLocation::query()->whereIn('employee_id', array_values($employees))->whereNull('effective_from')->whereNull('change_id')->whereNotNull('kind')->get() as $location) {
            $locations[$location->employee_id][$location->weekday] = $location;
        }

        $today = CarbonImmutable::now(LocalClock::zone());

        // The local weekdays inside the two-week window, most recent first,
        // as days-ago offsets.
        $weekdays = [];

        for ($day = 0; $day <= 13; $day++) {
            if (! $today->subDays($day)->isWeekend()) {
                $weekdays[] = $day;
            }
        }

        $missingOutDay = $weekdays[1]; // emp-4 never clocked out
        $undertimeDay = $weekdays[2];  // emp-2 left 90 minutes early
        $absentDay = $weekdays[3];     // emp-7 absent

        for ($day = 13; $day >= 0; $day--) {
            $date = $today->subDays($day);

            if ($date->isWeekend()) {
                continue;
            }

            $dateStr = $date->format('Y-m-d');

            foreach ($employees as $idx => $employeeId) {
                if ($day === $absentDay && $idx === 6) {
                    continue; // absence
                }

                // Mirrors ShiftSeeder: every third employee is on Morning.
                $morning = $idx % 3 === 0;
                $startMin = $morning ? 7 * 60 : 8 * 60;
                $endMin = $morning ? 16 * 60 : 17 * 60;
                $grace = $morning ? 10 : 15;

                // Exactly one late day per employee, staggered across the week.
                $late = $day === $weekdays[$idx % count($weekdays)];

                $inMin = $late
                    ? $startMin + $grace + 20 + $idx
                    : $startMin - (5 + $idx % 15);

                $outMin = ($day === $undertimeDay && $idx === 1)
                    ? $endMin - 90
                    : $endMin + 5 + ($idx % 25);

                // The weekday's roster row decides the place; no row is the
                // usual site. A null site here means "from home".
                $usual = $sites[$siteIds[$idx % 2 === 0 ? 'site-1' : 'site-2']];
                $location = $locations[$employeeId][$date->dayOfWeek] ?? null;

                $site = match (true) {
                    $location === null => $usual,
                    $location->kind === 'WFH' => null,
                    default => $sites->get((string) $location->site_id) ?? $usual,
                };

                // A biometric device sits at the office: a home punch is
                // always the browser.
                $source = $site !== null && $idx % 4 === 0 ? 'BIOMETRIC' : 'WEB_GPS';
                $seconds = ($day * 11 + $idx * 7) % 50;
                $n = $idx + 1;

                $this->punch(
                    "att-{$n}-{$day}-in", $employeeId, $site, $idx, 'TIME_IN', $source,
                    $dateStr, $inMin, $seconds, ($day * 7 + $idx * 13) % 61,
                );

                if ($day === $missingOutDay && $idx === 3) {
                    continue; // missing time-out
                }

                $this->punch(
                    "att-{$n}-{$day}-out", $employeeId, $site, $idx, 'TIME_OUT', $source,
                    $dateStr, $outMin, $seconds, ($day * 5 + $idx * 17) % 61,
                );
            }
        }
    }

    /**
     * One punch, inside the site's fence — or, when $site is null, from the
     * employee's home: the shape RecordPunch writes on a WFH day (no site, no
     * distance, `location_kind` WFH, coordinates and accuracy kept, and
     * `is_geofenced` true because a verdict with nothing to judge is true,
     * as AttendanceLog's docblock says). Every column a place decides is
     * written on both branches so a re-run restores it.
     */
    private function punch(
        string $id,
        string $employeeId,
        ?WorkSite $site,
        int $idx,
        string $type,
        string $source,
        string $date,
        int $minuteOfDay,
        int $seconds,
        int $distance,
    ): void {
        $wallClock = sprintf(
            '%s %02d:%02d:%02d',
            $date,
            intdiv($minuteOfDay, 60),
            $minuteOfDay % 60,
            $seconds,
        );

        $place = $site === null
            ? [
                'site_id' => null,
                'location_kind' => 'WFH',
                'latitude' => self::HOME_LATITUDE + $idx * 0.003,
                'longitude' => self::HOME_LONGITUDE - $idx * 0.002,
                'distance_meters' => null,
                'accuracy_meters' => 12.0,
            ]
            : [
                'site_id' => (string) $site->getKey(),
                'location_kind' => 'ONSITE',
                'latitude' => $site->latitude + 0.0002,
                'longitude' => $site->longitude + 0.0002,
                'distance_meters' => (float) $distance,
                'accuracy_meters' => null,
            ];

        $this->upsert(AttendanceLog::class, ['id' => $id], $place + [
            'employee_id' => $employeeId,
            'log_type' => $type,
            'source' => $source,
            'is_geofenced' => true,
            'is_anomaly' => false,
            'anomaly_note' => null,
            'logged_at' => LocalClock::instant($wallClock)->utc(),
        ]);
    }
}
