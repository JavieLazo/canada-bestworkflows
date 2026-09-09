<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\WorkSite;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;

/**
 * Two Manila weeks of punches, the port of seed.ts buildAttendance() with the
 * task-mandated DTR guarantees pinned to weekdays so they always land:
 *
 *  - in/out pairs sit around each employee's own shift bounds (a few minutes
 *    early in, a few minutes over on the way out);
 *  - exactly ONE late day per employee, staggered across the window;
 *  - one undertime day (Jose Rivera leaves 90 minutes early);
 *  - one absent day (Liza Domingo, no punches at all);
 *  - one missing time-out (Paolo Bautista never clocked out).
 *
 * Every punch is inside the fence, because one outside it is refused at the
 * station and could not have been recorded. Wall-clock strings go through
 * ManilaClock::instant() and are STORED AS UTC — never as Manila local.
 */
final class AttendanceSeeder extends PortSeeder
{
    public function run(): void
    {
        $employees = $this->employeeIdsByIndex();

        $sites = WorkSite::query()
            ->whereIn('id', ['site-1', 'site-2'])
            ->get()
            ->keyBy('id');

        if ($employees === [] || ! isset($sites['site-1'], $sites['site-2'])) {
            return;
        }

        $today = CarbonImmutable::now(ManilaClock::TZ);

        // The Manila weekdays inside the two-week window, most recent first,
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

                $site = $sites[$idx % 2 === 0 ? 'site-1' : 'site-2'];
                $source = $idx % 4 === 0 ? 'BIOMETRIC' : 'WEB_GPS';
                $seconds = ($day * 11 + $idx * 7) % 50;
                $n = $idx + 1;

                $this->punch(
                    "att-{$n}-{$day}-in", $employeeId, $site, 'TIME_IN', $source,
                    $dateStr, $inMin, $seconds, ($day * 7 + $idx * 13) % 61,
                );

                if ($day === $missingOutDay && $idx === 3) {
                    continue; // missing time-out
                }

                $this->punch(
                    "att-{$n}-{$day}-out", $employeeId, $site, 'TIME_OUT', $source,
                    $dateStr, $outMin, $seconds, ($day * 5 + $idx * 17) % 61,
                );
            }
        }
    }

    private function punch(
        string $id,
        string $employeeId,
        WorkSite $site,
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

        $this->upsert(AttendanceLog::class, ['id' => $id], [
            'employee_id' => $employeeId,
            'site_id' => (string) $site->getKey(),
            'log_type' => $type,
            'source' => $source,
            'latitude' => $site->latitude + 0.0002,
            'longitude' => $site->longitude + 0.0002,
            'distance_meters' => (float) $distance,
            'is_geofenced' => true,
            'is_anomaly' => false,
            'anomaly_note' => null,
            'logged_at' => ManilaClock::instant($wallClock)->utc(),
        ]);
    }
}
