<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\AttendanceLog;
use App\Models\AttendanceRemark;
use App\Modules\Timekeeping\Domain\Attendance\CorrectionWindow;
use App\Modules\Timekeeping\Domain\Attendance\PunchSlots;
use App\Modules\Timekeeping\Domain\Roster\ResolvedLocation;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use App\Support\ManilaClock;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Port of `PUT /api/attendance/corrections`
 * (copems/src/app/api/attendance/corrections/route.ts) — makes one
 * employee's punches for one day read as the four slots the corrector typed.
 *
 * A punch stream cannot be amended in place — adding a second Time In to a day
 * that already has one would be counted twice — so a correction supersedes the
 * day. What it replaced goes into the audit trail, so the punches the station
 * actually recorded stay visible even once they no longer stand. The punches
 * a correction has to write are stamped source CORRECTION, which is what
 * tells a reader of `attendance_logs` that a person put them there.
 *
 * DELIBERATE DIVERGENCE: the reference deletes the whole day and re-inserts
 * every slot. Here a slot that still reads as a recorded punch — same type,
 * same Manila minute, seconds ignored exactly as the dialog's prefill
 * ignores them — KEEPS that row, with its source, coordinates, distance
 * and accuracy. Only the slots that changed or were cleared are
 * deleted and, where filled, inserted. The keep rule is
 * CorrectionWindow::reconcile, run only here; the dialog
 * (Queries\DayCorrections) shares CorrectionWindow::replaceable — the rows a
 * save supersedes — and does not run reconcile. The audit sentence lists
 * everything that stood before and everything that STANDS after — a kept
 * row with its real seconds, an inserted one at :00 — and says how many
 * were kept.
 *
 * DELIBERATE DIVERGENCE: the reference stamps every inserted row with the
 * employee's one worksite (`site_id: employee.work_site_id`,
 * corrections/route.ts:114). Here the inserted rows carry the PLACE of the
 * corrected date — its date exception, then the weekday's roster_locations
 * row, else the Assigned Worksite — through the same seam the station uses, so a
 * hand-entered Wednesday on a work-from-home Wednesday reads "Work from
 * home" in the feeds, not the office. Kept rows keep the kind they were
 * recorded with. The corrected date is the shift START date, so a night's
 * carried next-day slot gets the night's place.
 *
 * DELIBERATE DIVERGENCE: which day a slot is on is SAID, never guessed.
 * Each slot carries the dialog's "Next day" tick (the reference rolls a
 * time reading earlier than the one before it, and only on an overnight
 * shift), and a tick is refused outright on a fixed shift that ends the
 * same day — there, a next-day time would show on the next date's row.
 * What a save replaces is the punches the DTR reads as this date's row
 * (CorrectionWindow::replaceable, the engine's own grouping), never the
 * previous night's after-midnight Time Out on the same calendar day. The
 * rows include two previous days and the following day, so a preceding
 * flexible day cannot borrow a night twice, and before anything is written
 * the save is simulated (CorrectionWindow::misattribution): the date must
 * own exactly the punches just saved, so a correction never moves a punch
 * onto another date's row — nor the overtime decision keyed on that date
 * into OUTDATED.
 *
 * NOT source MANUAL, which means only one thing: an admin recording a
 * punch AT the station. Both are hand entered, but a station punch is
 * happening now and a correction is back-dated by definition, and
 * App\Livewire\Edtr\LiveFeed needs to tell them apart to keep the second
 * out of a feed of what is landing this minute.
 *
 * DIVERGENCE FROM THE REFERENCE: the source serialises concurrent writes per
 * employee with `pg_advisory_xact_lock`. MySQL has no transaction-scoped
 * advisory lock, so the existing-window SELECT takes `lockForUpdate()`
 * instead. Database-engine concurrency guarantees must be tested separately
 * from SQLite behavior.
 *
 * THE TRANSACTION IS RETRIED ON A DEADLOCK. Corrections of two neighbouring
 * dates lock overlapping attendance spans, and each inserts inside
 * its span: on MariaDB 11.4 the one that waited is chosen as the victim of a
 * 1213 when the other's insert lands next to its queued lock
 * (DatabaseConcurrencyTest, back-to-back nights). The closure touches nothing
 * but the database — the audit and the realtime knock run after it returns —
 * so it is simply run again against the committed rows, as InnoDB's own
 * guidance says; a refusal is never retried. A dedicated sentinel row taken
 * first would serialise them instead, but that is a new `locks` row and a
 * migration.
 */
final class CorrectPunches
{
    /** How many times the correction transaction runs before a deadlock is given up on. */
    private const TRANSACTION_ATTEMPTS = 3;

    private function __construct() {}

    /**
     * Apply a correction. Throws RuntimeException with the user-facing message
     * on any refusal — the caller shows it in the dialog, exactly where the
     * reference surfaced the API error.
     *
     * The CALLER must already have checked `mayReadAllData(EDTR)` — the port
     * of `requireAllDataModule("EDTR")` — and it is re-checked here so this
     * service cannot be reached around that gate.
     *
     * @param  array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string}  $slots
     * @param  array<array-key, mixed>  $nextDay  the "Next day" tick per slot key — untrusted: only a literal true beside a filled slot counts
     */
    public static function apply(
        Access $actor,
        string $employeeId,
        string $date,
        array $slots,
        array $nextDay,
        ?string $note,
    ): void {
        if (! $actor->mayReadAllData(ModuleCode::EDTR)) {
            throw new RuntimeException('You are not allowed to correct punches.');
        }
        if (! PayrollPeriod::isPayrollDate($date)) {
            throw new RuntimeException('Choose a valid date.');
        }

        $employee = app(EmployeeDirectory::class)->forTimekeeping($actor, $employeeId);
        if ($employee === null) {
            throw new RuntimeException('That employee was not found.');
        }

        // The full schedule decides which adjacent date owns each punch;
        // this date's scalar shift decides its available correction slots.
        $days = PayrollPeriod::attendanceDateRange(PayrollPeriod::addCalendarDays($date, -1), $date);
        $schedule = app(ShiftRoster::class)->scheduleForEmployee($employeeId, $days['from'], $days['to']);
        $rostered = $schedule?->forDate($date);

        // And where the day was worked: its date exception, weekly roster,
        // then Assigned Worksite — what the inserted rows
        // are stamped with, and what the dialog's "Location" line showed.
        $location = app(ShiftRoster::class)->locationForDate($employeeId, $date)
            ?? ResolvedLocation::assigned($employee->work_site_id);

        $flags = PunchSlots::nextDayFlags($nextDay, $slots);
        $shapeError = CorrectionWindow::nextDayError($date, $rostered, $flags)
            ?? PunchSlots::error($slots, $flags, CorrectionWindow::allowsNextDay($rostered));
        if ($shapeError !== null) {
            throw new RuntimeException($shapeError);
        }

        $punches = PunchSlots::punches($date, $slots, $flags);
        $slotKeys = PunchSlots::filledKeys($slots);
        // Two days before through the next day: a flexible previous day may
        // itself borrow an unfinished night from the date before it. The
        // engine needs that context to decide which punches this date owns.
        $span = PayrollPeriod::attendanceInstants($days['from'], $days['to']);

        ['replaced' => $replaced, 'kept' => $kept, 'standing' => $standing] = DB::transaction(static function () use (
            $employeeId,
            $location,
            $punches,
            $slotKeys,
            $schedule,
            $span,
            $note,
            $date,
            $actor,
            $days,
        ): array {
            // The employee row first, as every schedule change takes it, then
            // the schedule again under that lock: a correction judged by a
            // schedule that changed while its dialog was open is refused
            // rather than saved against hours the date no longer has.
            if (app(EmployeeDirectory::class)->forTimekeeping($actor, $employeeId, lock: true) === null) {
                throw new RuntimeException('That employee was not found.');
            }
            $current = app(ShiftRoster::class)->scheduleForEmployee($employeeId, $days['from'], $days['to']);
            $place = app(ShiftRoster::class)->locationForDate($employeeId, $date);
            if ($current?->toArray() !== $schedule?->toArray()
                || ($place !== null && ($place->kind !== $location->kind || $place->siteId !== $location->siteId))) {
                throw new RuntimeException('This employee’s schedule was changed just now. Close this dialog and open the day again before correcting it.');
            }
            $rows = AttendanceLog::query()
                ->where('employee_id', $employeeId)
                ->whereBetween('logged_at', [$span['gte'], $span['lte']])
                ->orderBy('logged_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'log_type', 'logged_at']);

            $logs = $rows->map(static fn (AttendanceLog $row): DomainLog => DomainLog::fromArray([
                'id' => $row->id, 'log_type' => $row->log_type, 'logged_at' => $row->logged_at,
            ]))->all();

            $logs = array_values($logs);
            $existing = CorrectionWindow::replaceable($date, $logs, $schedule);
            $plan = CorrectionWindow::reconcile($existing, $punches);

            // Nothing is written unless the DTR would read this date as
            // exactly what was saved — the rows above are locked, so the
            // answer still holds when the writes below land.
            $misread = CorrectionWindow::misattribution($date, $logs, $punches, $plan, $schedule, $slotKeys);
            if ($misread !== null) {
                throw new RuntimeException($misread);
            }

            if ($plan['delete'] !== []) {
                AttendanceLog::query()->whereKey($plan['delete'])->delete();
            }

            foreach ($plan['insert'] as $punch) {
                AttendanceLog::query()->create([
                    'employee_id' => $employeeId,
                    // The date's place: no site on a work-from-home day, the
                    // date's, weekday's or assigned site otherwise (null only when
                    // the employee has no worksite at all).
                    'site_id' => $location->isHome() ? null : $location->siteId,
                    'location_kind' => $location->kind,
                    'log_type' => $punch['log_type'],
                    'source' => AttendanceLog::SOURCE_CORRECTION,
                    'latitude' => null,
                    'longitude' => null,
                    'distance_meters' => null,
                    'accuracy_meters' => null,
                    // A hand-entered punch has no location to verify, so it is
                    // neither inside the geofence nor flagged for being outside one.
                    'is_geofenced' => true,
                    'is_anomaly' => false,
                    'anomaly_note' => null,
                    // The slots speak Manila wall clock; the column holds UTC.
                    'logged_at' => ManilaClock::instant($punch['logged_at'])->utc(),
                ]);
            }

            // The day's remark rides along with the correction, exactly as the
            // reference wrote it in the same request: emptied clears it, text
            // upserts it. Keyed on the DAY — a cleared day can still carry a note.
            if ($note !== null) {
                $trimmed = trim($note);
                if ($trimmed === '') {
                    AttendanceRemark::query()
                        ->where('employee_id', $employeeId)
                        ->whereDate('date', $date)
                        ->delete();
                } else {
                    $remark = AttendanceRemark::query()
                        ->where('employee_id', $employeeId)
                        ->whereDate('date', $date)
                        ->first() ?? new AttendanceRemark;
                    $remark->fill([
                        'employee_id' => $employeeId,
                        'date' => $date,
                        'note' => $trimmed,
                    ]);
                    // Actor column: from the session, never the request.
                    $remark->author_id = $actor->userId === null ? null : (int) $actor->userId;
                    $remark->save();
                }
            }

            return ['replaced' => $existing, 'kept' => count($plan['keep']), 'standing' => $plan['standing']];
        }, self::TRANSACTION_ATTEMPTS);

        /** @param list<array{log_type: string, logged_at: string}> $rows */
        $describe = static function (array $rows) use ($date): string {
            if ($rows === []) {
                return 'no punches';
            }
            $parts = [];
            foreach ($rows as $row) {
                // An audit keeps its seconds: this is the record of what stood
                // and of what stands — a kept row prints its real stamp, an
                // inserted one its :00. A punch after the corrected date says
                // so: the sentence carries only that one date.
                $parts[] = $row['log_type'].' '.ManilaClock::instant($row['logged_at'])->setTimezone(ManilaClock::TZ)->format('g:i:s A')
                    .(PunchSlots::isNextDay($row['logged_at'], $date) ? ' next day' : '');
            }

            return implode(', ', $parts);
        };

        $was = array_map(
            static fn (DomainLog $log): array => ['log_type' => $log->logType, 'logged_at' => $log->loggedAt],
            $replaced,
        );

        AuditService::record(
            $actor,
            AuditEvent::DTR_CORRECTED,
            'attendance_logs',
            $employeeId,
            EmployeeName::display($employee).' · '.$date.' · was '.$describe($was)
                .' → now '.$describe($standing)
                .($kept > 0 ? " · kept {$kept} as recorded" : ''),
        );

        // A correction deletes and inserts rows, so it moves the same feeds a
        // punch moves — and unlike a punch it can happen while the employee it
        // is about has their portal open. Announced here rather than at the
        // EdtrPage call site so that any future caller of apply() gets it.
        Realtime::announce([
            Realtime::allPunches(),
            Realtime::employeePunches($employeeId),
        ]);
    }
}
