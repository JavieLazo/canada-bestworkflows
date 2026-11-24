<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\Minutes;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftGeometry;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\AttendanceLog;
use App\Modules\Organization\Contracts\PunchSites;
use App\Modules\Timekeeping\Application\PunchInput;
use App\Modules\Timekeeping\Application\PunchPairing;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Modules\Timekeeping\Domain\Attendance\AttendancePolicy;
use App\Modules\Timekeeping\Domain\Attendance\CorrectionWindow;
use App\Modules\Timekeeping\Domain\Attendance\PunchWorkDate;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Modules\Timekeeping\Domain\Roster\ResolvedLocation;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Modules\Workforce\Contracts\PunchEmployees;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\HrisFormat;
use App\Support\LocalClock;
use App\Support\Money;
use App\Support\Realtime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns live punch authorization, geofencing, pairing and persistence.
 *
 * TWO LOCATION SOURCES. Both stations ask the browser for a fix as the
 * punch is pressed and pass it in as `$autoLat` / `$autoLng` /
 * `$autoAccuracy`. In self-service (My Portal) that fix is the only reading
 * there is — nobody types coordinates in the portal. At the kiosk the typed
 * boxes are there for a BIOMETRIC or MANUAL entry, and a typed pin WINS
 * whenever either box is filled; only when both are blank does the browser
 * fix stand in. A typed pin is a place, not a measurement, so it carries no
 * accuracy.
 *
 * DELIBERATE DIVERGENCE: PunchStation.tsx:193-201 gives the kiosk typed
 * boxes only, and a kiosk punch with blank boxes is a punch with no reading
 * at all.
 *
 * THREE PLACES A PUNCH IS JUDGED AT. A date override, then the weekday's
 * roster_locations row decides where the employee works on its work date:
 * ONSITE at that row's site (geofenced against it, and a kiosk may not pick
 * another), WFH (no site, no geofence — accepted from anywhere, the fix kept
 * as captured), or, with no row, the Assigned Worksite from Employee
 * Records. The place is resolved INSIDE the transaction, after the shift,
 * because the work date of a Time Out that closes an open Time In is that
 * Time In's date (Domain\Attendance\PunchWorkDate), and the open Time In is
 * in the locked window. DELIBERATE DIVERGENCE from punch/route.ts:89-117,
 * which knows one site per employee and nothing about working from home.
 *
 * NO SHIFT, NO TIME IN. A Time In is refused on a work date with nothing
 * rostered (`ShiftRoster::rosteredShift`: no default shift, no weekday
 * hours, no temporary schedule) — whether the employee was never assigned a
 * shift or had it cleared. A Time Out is still taken, because pairing
 * already refuses one with no open Time In: it can only close a Time In
 * recorded while a shift applied, which is never stranded. DELIBERATE
 * DIVERGENCE from punch/route.ts, which punched anyone against the default.
 *
 * A LATE DEVICE PUNCH NEVER LANDS ON A PAID DAY. A biometric device may send
 * a punch days after capturing it. One that arrives on a later local day
 * than it was captured is refused when a posted payslip already covers its
 * work date: the payslip is what payroll paid, and nothing would ever settle
 * the difference. The answer comes from PostedPayrollDates under payroll's
 * membership lock, the guard a punch correction takes, so every device punch
 * takes that lock FIRST, before any other read. A punch captured today is
 * judged like the live station's, which asks no such question.
 */
final class RecordPunch
{
    /** The largest accuracy DECIMAL(12,2) can hold; anything past it is no reading. */
    private const MAX_ACCURACY_METERS = 9_999_999_999.99;

    public function __construct(private readonly ShiftRoster $roster) {}

    /**
     * `$at` is the instant a biometric device captured on the device. The
     * live station leaves it null and is judged at the server clock.
     * `$createdId` receives the new attendance row when one is written.
     */
    public function handle(Access $actor, PunchInput $input, string $logType, ?float $autoLat = null, ?float $autoLng = null, ?float $autoAccuracy = null, ?CarbonImmutable $at = null, ?string &$createdId = null): ?string
    {
        $createdId = null;
        if (! in_array($logType, [DomainLog::TIME_IN, DomainLog::TIME_OUT], true)) {
            return null;
        }
        if ($input->employeeId === '') {
            throw new RuntimeException('Select an employee first.');
        }
        // Punching for yourself needs nothing beyond a session. Punching for
        // someone else is the kiosk operation, and it needs both halves: the
        // eDTR module to be at the station at all, and a role that sees
        // everyone (punch/route.ts:57-66).
        $isSelf = $actor->employeeId !== null && $actor->employeeId === $input->employeeId;
        $isKiosk = $actor->mayReadAllData(ModuleCode::EDTR);
        if (! $isSelf && ! $isKiosk) {
            throw new RuntimeException('Forbidden.');
        }
        $source = $isSelf ? 'WEB_GPS' : $input->source;
        if (! in_array($source, ['WEB_GPS', 'BIOMETRIC', 'MANUAL'], true)) {
            $source = 'WEB_GPS';
        }
        $employee = app(PunchEmployees::class)->find($actor, $input->employeeId);
        if ($employee === null) {
            throw new RuntimeException('That employee was not found.');
        }
        if (! $employee->is_active) {
            throw new RuntimeException('That employee is inactive and cannot punch.');
        }
        // Where the reading comes from — see the class docblock. A typed box
        // that is not a number is no reading at all (PunchStation.tsx:193-201),
        // and a half-filled pair is still the typed path: it must not fall
        // back to a browser fix the operator did not mean to use.
        $typedLat = trim($input->lat) !== '';
        $typedLng = trim($input->lng) !== '';
        if ($input->selfService || (! $typedLat && ! $typedLng)) {
            $la = $autoLat;
            $lo = $autoLng;
            $accuracy = $this->accuracy($autoAccuracy);
        } else {
            $la = $this->coordinate($input->lat);
            $lo = $this->coordinate($input->lng);
            $accuracy = null;
            if ($typedLat && $la === null || $typedLng && $lo === null) {
                throw new RuntimeException("Those coordinates aren't numbers. Clear them or capture a location.");
            }
        }
        // The API schema's bounds: a reading outside them is no reading.
        if ($la !== null && ($la < -90.0 || $la > 90.0) || $lo !== null && ($lo < -180.0 || $lo > 180.0)) {
            throw new RuntimeException("Those coordinates aren't numbers. Clear them or capture a location.");
        }
        $hasFix = $la !== null && $lo !== null;
        if (! $hasFix) {
            // An accuracy without coordinates describes nothing.
            $accuracy = null;
        }
        // Bucket by the local day, not host-local dates mixed with UTC midnight.
        // Two previous dates distinguish an unfinished night's carried
        // Time In from a following flexible day's own punches. A device
        // punch is bucketed on the day it was captured, not the day the
        // device came back online.
        $now = ($at ?? CarbonImmutable::now())->setTimezone(LocalClock::zone());
        if ($at !== null && $now->greaterThan(CarbonImmutable::now(LocalClock::zone())->addMinutes(2))) {
            throw new RuntimeException('That punch time is ahead of the server clock.');
        }
        $today = LocalClock::dayKey($now);
        // Captured on an earlier day than the server's today: the only device
        // punch a posted payroll can already have paid.
        $late = $at !== null && $today < LocalClock::todayKey();
        $window = PayrollPeriod::attendanceInstants(PayrollPeriod::addCalendarDays($today, -2), $today);
        [$created, $site, $distance, $inBounds] = DB::transaction(function () use ($actor, $input, $logType, $source, $employee, $la, $lo, $accuracy, $window, $today, $now, $at, $late): array {
            // A device punch takes payroll's membership lock before any other
            // read, as PostedPayrollDates asks of a writer that may have to
            // answer whether a day is paid. The live station never asks.
            if ($at !== null) {
                app(PostedPayrollDates::class)->lock();
            }

            // The employee row next, as every schedule change takes it: a
            // punch waits for a change being saved for the same employee and
            // is then judged by the schedule as committed. The punch window
            // alone cannot do that while it holds no row yet.
            if (app(PunchEmployees::class)->find($actor, (string) $employee->id, lock: true) === null) {
                throw new RuntimeException('That employee was not found.');
            }
            $windowRows = AttendanceLog::query()->where('employee_id', (string) $employee->id)->whereBetween('logged_at', [$window['gte'], $window['lte']])->orderBy('logged_at')->orderBy('id')->lockForUpdate()->get(['id', 'log_type', 'logged_at']);
            $windowLogs = $windowRows->map(static fn (AttendanceLog $row): DomainLog => DomainLog::fromArray(['id' => $row->id, 'log_type' => $row->log_type, 'logged_at' => $row->logged_at]))->all();
            // Resolve the work date from the FULL dated/weekly schedule first:
            // this morning may still close yesterday's different shift.
            // An open yesterday-morning Time In may itself finish the
            // preceding date's night, so retain that date's override too.
            $schedule = $this->roster->scheduleForEmployee((string) $employee->id, PayrollPeriod::addCalendarDays($today, -2), $today);

            // WHERE the punch is judged — resolved AFTER the shift and INSIDE
            // the lock, because the work date of a Time Out that closes an
            // open Time In is that Time In's date (PunchWorkDate rule 2), and
            // the open Time In is in the window just read. So an after-midnight
            // Time Out of a 22:00–06:00 night is judged at the place the night
            // STARTED — the previous weekday's roster row — never the next
            // morning's. A date location overrides the weekday's place;
            // with neither, use Employee Records' Assigned Worksite.
            $workDate = PunchWorkDate::forScheduledPunch(array_values($windowLogs), $logType, $now, $schedule);
            if ($late && app(PostedPayrollDates::class)->lockAndCovers((string) $employee->id, $workDate)) {
                throw new RuntimeException(CorrectionWindow::PAID_DAY);
            }
            // Null on a date nobody rostered — never rostered, or the shift
            // cleared — so no punch window applies to it.
            $domainShift = $this->roster->rosteredShift((string) $employee->id, $workDate, $schedule);
            if ($domainShift === null && $logType === DomainLog::TIME_IN) {
                throw new RuntimeException(self::noShiftMessage($employee->name, $workDate));
            }
            $location = $this->roster->locationForDate((string) $employee->id, $workDate)
                ?? ResolvedLocation::assigned($employee->work_site_id);

            $site = null;
            $distance = null;
            $inBounds = true;
            if (! $location->isHome()) {
                if ($location->siteId === null) {
                    throw new RuntimeException($employee->name.' has no assigned worksite. Assign one in Employee Records before punching.');
                }
                $site = app(PunchSites::class)->find($location->siteId);
                if ($site === null) {
                    throw new RuntimeException('The assigned worksite is no longer available.');
                }
                // The kiosk may not punch somebody in at a site that is not
                // theirs today (punch/route.ts:89-117 for the assigned site;
                // the weekday sentence is ems's own).
                if (! $input->selfService && $input->siteId !== '' && $input->siteId !== $site->id) {
                    $when = $location->fromDateOverride
                        ? HrisFormat::date($workDate)
                        : HrisFormat::weekday(CalendarDays::dayOfWeek($workDate), short: false).'s';
                    throw new RuntimeException($location->fromRoster
                        ? $employee->name.' works at '.$site->name.' on '.$when.'.'
                        : $employee->name.' is assigned to '.$site->name.'.');
                }
                // Metres are recorded only when there is a pin to measure
                // against. An exempt site has none — the distance stays
                // unmeasured rather than becoming the thousands of kilometres
                // to 0, 0.
                $distance = $la !== null && $lo !== null && ! $site->geofence_exempt
                    ? AttendancePolicy::haversineMeters($la, $lo, $site->latitude, $site->longitude)
                    : null;
                $inBounds = $site->geofence_exempt ? true : $distance !== null && $distance <= $site->radius_meters;
                if (AttendancePolicy::blocksPunch($inBounds)) {
                    throw new RuntimeException(AttendancePolicy::geofenceBlockMessage($logType, $site->name, $distance, (int) $site->radius_meters));
                }
            }
            // A work-from-home day: nothing to measure against — no site, no
            // distance, no verdict. The coordinates and their accuracy are
            // kept exactly as captured, and the kiosk's site select is
            // ignored rather than refused: the place is a property of the
            // day, not of the station.

            $anomaly = PunchPairing::anomaly(array_values($windowLogs), $logType, $now, $schedule, $today);
            if ($anomaly !== null) {
                throw new RuntimeException($anomaly);
            }
            $windowError = $domainShift === null ? null : self::punchWindowError(array_values($windowLogs), $logType, $now, $domainShift, $workDate, $schedule);
            if ($windowError !== null) {
                throw new RuntimeException($windowError);
            }

            $attributes = [
                'employee_id' => (string) $employee->id,
                'site_id' => $site?->id,
                'location_kind' => $location->kind,
                'log_type' => $logType,
                'source' => $source,
                'latitude' => $la,
                'longitude' => $lo,
                // Math.round(distance * 100) / 100 — the two-decimal
                // boundary, which is Money::round here.
                'distance_meters' => $distance === null ? null : Money::round($distance),
                'accuracy_meters' => $accuracy,
                'is_geofenced' => $inBounds,
                'is_anomaly' => false,
                'anomaly_note' => null,
            ];
            // The live station keeps Eloquent's default of "now". A device
            // punch must keep the captured instant or an offline Time Out
            // would land when the device next saw a network.
            if ($at !== null) {
                $attributes['logged_at'] = $now->utc();
            }
            $created = AttendanceLog::query()->create($attributes);

            return [$created, $site, $distance, $inBounds];
        });
        $createdId = (string) $created->id;
        // $site is null only on a work-from-home day: every ONSITE path above
        // resolved one or threw. The ONSITE sentence names the site and its
        // geofence verdict; the WFH one has no verdict to print.
        $place = $site === null ? LocationKind::WFH_LABEL : $site->name;
        $verdict = $site === null ? '' : ' · '.($inBounds ? 'in-bounds' : 'OUT OF BOUNDS');
        AuditService::record($actor, AuditEvent::punch($logType), 'attendance_logs', (string) $created->id, $employee->name.' @ '.$place.' · '.$source.$verdict.self::readingLabel($distance, $accuracy));
        Realtime::announce([Realtime::allPunches(), Realtime::employeePunches($employee->id)]);
        $label = str_replace('_', ' ', $logType);

        if ($site === null) {
            return "{$label} recorded — working from home.";
        }

        return $site->geofence_exempt ? "{$label} recorded at {$site->name} — geofence-exempt." : "{$label} recorded — inside {$site->name}.";
    }

    /**
     * Shift-slot guard for the live station. The DTR still flags imported or
     * corrected rows that violate these windows, but live punches should be
     * guided before a bad row lands.
     *
     * @param  list<DomainLog>  $windowLogs
     */
    private static function punchWindowError(
        array $windowLogs,
        string $logType,
        CarbonImmutable $now,
        DomainShift $shift,
        string $workDate,
        ?DomainShift $schedule,
    ): ?string {
        if ($shift->isFlexible()) {
            return null;
        }

        $geometry = ShiftResolver::geometry($shift);
        $nowMinute = self::timelineMinuteForInstant($now, $workDate);
        if (! is_finite($nowMinute)) {
            return null;
        }

        $workLogs = self::logsForWorkDate($windowLogs, $workDate, $geometry, $schedule);
        $completedOuts = count(array_filter(
            $workLogs,
            static fn (DomainLog $log): bool => $log->logType === DomainLog::TIME_OUT,
        ));
        $last = $workLogs === [] ? null : $workLogs[count($workLogs) - 1];

        if ($logType === DomainLog::TIME_IN) {
            $firstOpensAt = $geometry->start - ShiftResolver::EARLY_PUNCH_WINDOW_MINUTES;
            if ($nowMinute < $firstOpensAt) {
                return self::tooEarlyMessage('Time In', 'First Time In', $firstOpensAt);
            }

            if ($nowMinute >= $geometry->end) {
                return 'Time In is blocked: this shift already ended.';
            }
        }

        $breakWindow = ShiftResolver::scheduledBreakWindow($shift, $geometry);
        if ($breakWindow === null) {
            if ($logType === DomainLog::TIME_IN && $completedOuts > 0) {
                return 'Time In is blocked: this shift already has a Time Out.';
            }

            return null;
        }

        $firstBreakMinute = $breakWindow[0];
        $secondSlotStart = $breakWindow[0] + (($breakWindow[1] - $breakWindow[0]) / 2);

        if ($logType === DomainLog::TIME_IN) {
            if ($completedOuts >= 2) {
                return 'Time In is blocked: this shift already has a Final Time Out.';
            }

            $waitingForSecondTimeIn = $last !== null
                && $last->logType === DomainLog::TIME_OUT
                && $completedOuts === 1;
            $missedMorningAndInsideBreak = $last === null
                && $nowMinute >= $firstBreakMinute
                && $nowMinute < $secondSlotStart;

            if (($waitingForSecondTimeIn || $missedMorningAndInsideBreak) && $nowMinute < $secondSlotStart) {
                return self::tooEarlyMessage('Time In', 'Second Time In', $secondSlotStart);
            }

            return null;
        }

        $firstOutOpensAt = $firstBreakMinute - ShiftResolver::EARLY_PUNCH_WINDOW_MINUTES;
        if ($completedOuts === 0 && $nowMinute < $firstOutOpensAt) {
            return self::tooEarlyMessage('Time Out', 'First Time Out', $firstOutOpensAt);
        }

        return null;
    }

    /**
     * @param  list<DomainLog>  $windowLogs
     * @return list<DomainLog>
     */
    private static function logsForWorkDate(array $windowLogs, string $workDate, ShiftGeometry $geometry, ?DomainShift $schedule): array
    {
        $from = $geometry->start - ShiftResolver::EARLY_PUNCH_WINDOW_MINUTES;
        $to = $geometry->end + (12 * 60);

        $logs = [];
        // The previous night's carried punches are not completed slots of
        // today's day shift, even when its early window overlaps their time.
        foreach (DtrCalculator::rowPunches($windowLogs, $schedule, $workDate) as $log) {
            $minute = self::timelineMinuteForInstant(LocalClock::instant($log->loggedAt), $workDate);
            if ($minute >= $from && $minute <= $to) {
                $logs[] = $log;
            }
        }

        usort(
            $logs,
            static fn (DomainLog $a, DomainLog $b): int => LocalClock::ms(LocalClock::instant($a->loggedAt))
                <=> LocalClock::ms(LocalClock::instant($b->loggedAt)),
        );

        return $logs;
    }

    private static function timelineMinuteForInstant(CarbonImmutable $instant, string $workDate): float
    {
        $base = LocalClock::ms(LocalClock::instant($workDate.'T00:00:00'));

        return (LocalClock::ms($instant) - $base) / 60000;
    }

    /** "Time In is blocked: Sol Santos has no shift on Oct 08, 2026. Assign one in Shift Roster first." */
    private static function noShiftMessage(string $name, string $workDate): string
    {
        return "Time In is blocked: {$name} has no shift on ".HrisFormat::date($workDate).'. Assign one in Shift Roster first.';
    }

    private static function tooEarlyMessage(string $action, string $slot, float $opensAt): string
    {
        return "{$action} is blocked: too early for {$slot}. Earliest allowed is ".self::slotMinuteLabel($opensAt).'.';
    }

    private static function slotMinuteLabel(float $minute): string
    {
        $whole = (int) Minutes::round($minute);
        $dayOffset = (int) floor($whole / (24 * 60));
        $within = (($whole % (24 * 60)) + (24 * 60)) % (24 * 60);
        $hour24 = intdiv($within, 60);
        $minutePart = $within % 60;
        $hour12 = $hour24 % 12 === 0 ? 12 : $hour24 % 12;
        $suffix = $hour24 < 12 ? 'AM' : 'PM';

        $label = sprintf('%d:%02d %s', $hour12, $minutePart, $suffix);
        if ($dayOffset < 0) {
            return $label.' previous day';
        }
        if ($dayOffset > 0) {
            return $label.' next day';
        }

        return $label;
    }

    /**
     * The distance and accuracy as the audit sentence prints them — ` (12 m
     * ±8 m)`, ` (12 m)`, ` (±8 m)` on an exempt site with a fix, or nothing.
     */
    private static function readingLabel(?float $distance, ?float $accuracy): string
    {
        $parts = [];
        if ($distance !== null) {
            $parts[] = ((int) Minutes::round($distance)).' m';
        }
        if ($accuracy !== null) {
            $parts[] = '±'.((int) Minutes::round($accuracy)).' m';
        }

        return $parts === [] ? '' : ' ('.implode(' ', $parts).')';
    }

    /**
     * The browser's accuracy as a stored value: metres at the two-decimal
     * boundary, or null for anything that is not a usable reading — absent,
     * NaN or infinite, negative, or past what DECIMAL(12,2) can hold (which
     * MariaDB in strict mode would refuse outright).
     */
    private function accuracy(?float $reading): ?float
    {
        if ($reading === null || ! is_finite($reading) || $reading < 0.0 || $reading > self::MAX_ACCURACY_METERS) {
            return null;
        }

        return Money::round($reading);
    }

    /** TS: `coordinate(text)` — a typed box as a number, or null for anything else. */
    private function coordinate(string $text): ?float
    {
        $text = trim($text);
        if ($text === '' || ! is_numeric($text)) {
            return null;
        }

        return (float) $text;
    }
}
