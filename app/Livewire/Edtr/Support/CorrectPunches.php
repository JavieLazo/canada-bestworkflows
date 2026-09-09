<?php

declare(strict_types=1);

namespace App\Livewire\Edtr\Support;

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\AttendanceLog;
use App\Models\AttendanceRemark;
use App\Models\EmployeeProfile;
use App\Models\Shift;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\ManilaClock;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Port of `PUT /api/attendance/corrections`
 * (copems/src/app/api/attendance/corrections/route.ts) — replaces one
 * employee's punches for one day.
 *
 * A punch stream cannot be amended in place — adding a second Time In to a day
 * that already has one would be counted twice — so a correction supersedes the
 * day. What it replaced goes into the audit trail, so the punches the station
 * actually recorded stay visible even once they no longer stand. The corrected
 * punches are written with source CORRECTION, which is what tells a reader of
 * `attendance_logs` that a person put them there.
 *
 * NOT source MANUAL, which this used to write and which now means only one
 * thing: an admin recording a punch AT the station. Both are hand entered, but
 * a station punch is happening now and a correction is back-dated by
 * definition, and App\Livewire\Edtr\LiveFeed needs to tell them apart to
 * keep the second out of a feed of what is landing this minute.
 *
 * DIVERGENCE FROM THE REFERENCE: the source serialises concurrent writes per
 * employee with `pg_advisory_xact_lock`. MySQL has no transaction-scoped
 * advisory lock, so the existing-window SELECT takes `lockForUpdate()`
 * instead — the same punches end up serialised, through row locks rather than
 * an advisory one.
 */
final class CorrectPunches
{
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
     */
    public static function apply(
        Access $actor,
        string $employeeId,
        string $date,
        array $slots,
        ?string $note,
    ): void {
        if (! $actor->mayReadAllData(ModuleCode::EDTR)) {
            throw new RuntimeException('You are not allowed to correct punches.');
        }
        if (! PayrollPeriod::isPayrollDate($date)) {
            throw new RuntimeException('Choose a valid date.');
        }

        $employee = EmployeeProfile::query()->whereKey($employeeId)->first();
        if ($employee === null) {
            throw new RuntimeException('That employee was not found.');
        }

        // Whether the shift runs overnight decides if a time reading earlier
        // than the one before it is a night or a typo.
        $shiftRow = Shift::query()->where('employee_id', $employeeId)->first();
        $default = DomainShift::default();
        $start = ShiftResolver::clockMinutes($shiftRow->start_time ?? $default->startTime);
        $end = ShiftResolver::clockMinutes($shiftRow->end_time ?? $default->endTime);

        $shapeError = PunchSlots::error($slots, $end <= $start);
        if ($shapeError !== null) {
            throw new RuntimeException($shapeError);
        }

        $punches = PunchSlots::punches($date, $slots);
        $window = PayrollPeriod::attendanceInstants($date, $date);
        $nextDay = PayrollPeriod::addCalendarDays($date, 1);
        $nextWindow = PayrollPeriod::attendanceInstants($nextDay, $nextDay);

        $replaced = DB::transaction(static function () use (
            $employeeId,
            $employee,
            $punches,
            $window,
            $nextWindow,
        ): array {
            /*
             * A night shift's punch out lands on the next calendar date, so
             * clearing only this one would leave the old punch out behind and
             * correcting the same night twice would double it. The extra
             * stretch cleared is exactly what the DTR reads as belonging to
             * this day: the next morning up to that day's own first Time In,
             * and no further.
             */
            $nextDayLogs = AttendanceLog::query()
                ->where('employee_id', $employeeId)
                ->whereBetween('logged_at', [$nextWindow['gte'], $nextWindow['lte']])
                ->orderBy('logged_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'log_type', 'logged_at']);

            $opensAt = null;
            foreach ($nextDayLogs->values() as $index => $row) {
                if ($row->log_type === DomainLog::TIME_IN) {
                    $opensAt = $index;
                    break;
                }
            }
            $carried = $opensAt === null ? $nextDayLogs : $nextDayLogs->slice(0, $opensAt);

            $existingRows = AttendanceLog::query()
                ->where('employee_id', $employeeId)
                ->whereBetween('logged_at', [$window['gte'], $window['lte']])
                ->orderBy('logged_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'log_type', 'logged_at']);

            $existing = [];
            foreach ($existingRows as $row) {
                $existing[] = ['log_type' => $row->log_type, 'logged_at' => $row->logged_at];
            }
            foreach ($carried as $row) {
                $existing[] = ['log_type' => $row->log_type, 'logged_at' => $row->logged_at];
            }

            AttendanceLog::query()->whereKey($existingRows->modelKeys())->delete();
            if ($carried->isNotEmpty()) {
                AttendanceLog::query()->whereKey($carried->pluck('id')->all())->delete();
            }

            foreach ($punches as $punch) {
                AttendanceLog::query()->create([
                    'employee_id' => $employeeId,
                    'site_id' => $employee->work_site_id,
                    'log_type' => $punch['log_type'],
                    'source' => AttendanceLog::SOURCE_CORRECTION,
                    'latitude' => null,
                    'longitude' => null,
                    'distance_meters' => null,
                    // A hand-entered punch has no location to verify, so it is
                    // neither inside the geofence nor flagged for being outside one.
                    'is_geofenced' => true,
                    'is_anomaly' => false,
                    'anomaly_note' => null,
                    // The slots speak Manila wall clock; the column holds UTC.
                    'logged_at' => ManilaClock::instant($punch['logged_at'])->utc(),
                ]);
            }

            return $existing;
        });

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

        $describe = static function (array $rows): string {
            if ($rows === []) {
                return 'no punches';
            }
            $parts = [];
            foreach ($rows as $row) {
                $at = $row['logged_at'];
                $iso = $at instanceof \DateTimeInterface ? DomainLog::isoFromUtc($at) : (string) $at;
                $parts[] = $row['log_type'].' '.Hris::fmtTime($iso);
            }

            return implode(', ', $parts);
        };

        $now = array_map(
            static fn (array $p): array => ['log_type' => $p['log_type'], 'logged_at' => $p['logged_at']],
            $punches,
        );

        AuditService::record(
            $actor,
            AuditEvent::DTR_CORRECTED,
            'attendance_logs',
            $employeeId,
            Hris::employeeName($employee).' · '.$date.' · was '.$describe($replaced)
                .' → now '.$describe($now),
        );

        // A correction is a DELETE and an INSERT, so it moves the same feeds a
        // punch moves — and unlike a punch it can happen while the employee it
        // is about has their portal open. Announced here rather than at the
        // EdtrPage call site so that any future caller of apply() gets it.
        Realtime::announce([
            Realtime::allPunches(),
            Realtime::employeePunches($employeeId),
        ]);
    }
}
