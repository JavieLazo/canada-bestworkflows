<?php

declare(strict_types=1);

namespace App\Livewire\Edtr;

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\Minutes;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Domain\Payroll\PayrollRates;
use App\Livewire\Edtr\Support\AttendancePolicy;
use App\Livewire\Edtr\Support\Hris;
use App\Models\AttendanceLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollFormula;
use App\Models\Shift;
use App\Models\WorkSite;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\ManilaClock;
use App\Support\Money;
use App\Support\Realtime;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

/**
 * The kiosk — the port of copems/src/components/PunchStation.tsx merged with
 * the server half it called, POST /api/attendance/punch
 * (copems/src/app/api/attendance/punch/route.ts). In Livewire the two are one
 * place: every check the route re-ran server-side runs here, in the action,
 * against the session actor — nothing derived (distance, geofence verdict,
 * anomaly) is ever taken from the client.
 *
 * ── THE "TODAY" WINDOW — a deliberate divergence ────────────────────────────
 * The reference punch route (an earlier revision, punch/route.ts:65) bucketed
 * "today's punches" by mixing the host's LOCAL date with UTC midnight, which
 * put the day's cut eight hours into the working day and silently excluded
 * pre-08:00 punches from pairing. This port implements the CORRECT window:
 * the Manila calendar day via ManilaClock::dayKey / PayrollPeriod::
 * attendanceInstants, reading one adjacent day back so an overnight shift's
 * open TIME_IN still pairs with its next-morning TIME_OUT.
 *
 * ── Locks ───────────────────────────────────────────────────────────────────
 * The reference serialised concurrent punches per employee with
 * `pg_advisory_xact_lock`; MySQL has no transaction-scoped advisory lock, so
 * the pairing-window SELECT takes `lockForUpdate()` instead.
 */
class PunchStation extends Component
{
    /** When set, the punch station is locked to this employee (self-service). */
    #[Locked]
    public ?string $lockedEmployeeId = null;

    /** Off where the page already shows a clock of its own. */
    #[Locked]
    public bool $showClock = true;

    /**
     * My Portal. The work site and punch source are the admin's to set, so the
     * employee gets no controls for them and no coordinate boxes — the
     * location is read from the browser when they punch.
     */
    #[Locked]
    public bool $selfService = false;

    /** The selected employee — id-bearing, so #[Locked]; set via actions only. */
    #[Locked]
    public string $employeeId = '';

    /** The selected work site — id-bearing, so #[Locked]; set via selectSite(). */
    #[Locked]
    public string $siteId = '';

    /** Punch source select. Collapsed to the known three on every write. */
    public string $source = 'WEB_GPS';

    /** Typed coordinate boxes. Free text — validated at punch time. */
    public string $lat = '';

    public string $lng = '';

    public function mount(): void
    {
        $this->employeeId = $this->lockedEmployeeId ?? '';
    }

    public function updatedSource(string $value): void
    {
        $known = array_column(Hris::SOURCES, 'value');
        $this->source = in_array($value, $known, true) ? $value : 'WEB_GPS';
    }

    #[On('kit-employee-picked')]
    public function employeePicked(string $name, string $id): void
    {
        if ($name !== 'punch-employee') {
            return;
        }
        // A locked station never changes employee, whatever the client sends.
        if ($this->lockedEmployeeId !== null) {
            return;
        }

        // The same visibility the picker's own choose() enforced: the widened
        // directory tiers, or the actor's own row — a forged event must not
        // let a self-only viewer aim the station at somebody else.
        $actor = Access::forActor(auth()->user());
        $visible = $actor->mayReadAllData(ModuleCode::EMPLOYEES, ModuleCode::PAYROLL)
            || $actor->mayReadAllData(
                ModuleCode::EDTR,
                ModuleCode::ROSTER,
                ModuleCode::LEAVES,
                ModuleCode::PROJECTS,
                ModuleCode::TASKS,
            )
            || ($actor->hasEmployeeLink() && $actor->ownEmployeeId() === $id);
        if (! $visible) {
            return;
        }

        if (EmployeeProfile::query()->whereKey($id)->exists()) {
            $this->employeeId = $id;
        }
    }

    /** Kiosk site select. The punch re-validates against the assigned site anyway. */
    public function selectSite(string $id): void
    {
        if ($this->selfService) {
            return;
        }
        if (WorkSite::query()->whereKey($id)->exists()) {
            $this->siteId = $id;
        }
    }

    /**
     * Record a punch. `$autoLat`/`$autoLng` carry the browser reading in
     * self-service mode (PunchStation.tsx:192); the kiosk uses the typed
     * boxes. Everything derived from the reading is recomputed HERE.
     */
    public function punch(string $logType, ?float $autoLat = null, ?float $autoLng = null): void
    {
        if (! in_array($logType, [DomainLog::TIME_IN, DomainLog::TIME_OUT], true)) {
            return;
        }
        if ($this->employeeId === '') {
            Flux::toast(text: 'Select an employee first.', variant: 'danger');

            return;
        }

        $actor = Access::forActor(auth()->user());

        // Punching for yourself needs nothing beyond a session. Punching for
        // someone else is the kiosk operation, and it needs both halves: the
        // eDTR module to be at the station at all, and a role that sees
        // everyone (punch/route.ts:57-66).
        $isSelf = $actor->employeeId !== null && $actor->employeeId === $this->employeeId;
        $isKiosk = $actor->allData && $actor->hasModule(ModuleCode::EDTR);
        if (! $isSelf && ! $isKiosk) {
            Flux::toast(text: 'Forbidden.', variant: 'danger');

            return;
        }
        $source = $isSelf ? 'WEB_GPS' : $this->source;
        if (! in_array($source, array_column(Hris::SOURCES, 'value'), true)) {
            $source = 'WEB_GPS';
        }

        $employee = EmployeeProfile::query()->whereKey($this->employeeId)->first();
        if ($employee === null) {
            Flux::toast(text: 'That employee was not found.', variant: 'danger');

            return;
        }
        if (! $employee->is_active) {
            Flux::toast(text: 'That employee is inactive and cannot punch.', variant: 'danger');

            return;
        }
        if ($employee->work_site_id === null) {
            Flux::toast(
                text: Hris::employeeName($employee).' has no assigned worksite. Assign one in Employee Records before punching.',
                variant: 'danger',
            );

            return;
        }

        $assignedSite = WorkSite::query()->whereKey($employee->work_site_id)->first();
        if ($assignedSite === null) {
            Flux::toast(text: 'The assigned worksite is no longer available.', variant: 'danger');

            return;
        }
        if (! $this->selfService && $this->siteId !== '' && $this->siteId !== (string) $employee->work_site_id) {
            Flux::toast(
                text: Hris::employeeName($employee).' is assigned to '.$assignedSite->name.'.',
                variant: 'danger',
            );

            return;
        }
        $site = $assignedSite;

        // Nobody types coordinates in the portal — the browser reading arrives
        // as arguments there. A typed box that is not a number is no reading
        // at all (PunchStation.tsx:193-201).
        if ($this->selfService) {
            $la = $autoLat;
            $lo = $autoLng;
        } else {
            $la = $this->coordinate($this->lat);
            $lo = $this->coordinate($this->lng);
            if ((trim($this->lat) !== '' && $la === null) || (trim($this->lng) !== '' && $lo === null)) {
                Flux::toast(text: "Those coordinates aren't numbers. Clear them or capture a location.", variant: 'danger');

                return;
            }
        }
        // The API schema's bounds: a reading outside them is no reading.
        if (($la !== null && ($la < -90.0 || $la > 90.0)) || ($lo !== null && ($lo < -180.0 || $lo > 180.0))) {
            Flux::toast(text: "Those coordinates aren't numbers. Clear them or capture a location.", variant: 'danger');

            return;
        }

        // Metres are recorded only when there is a pin to measure against. An
        // exempt site has none — the distance stays unmeasured rather than
        // becoming the thousands of kilometres to 0, 0.
        $hasFix = $la !== null && $lo !== null;
        $distance = $hasFix && ! $site->geofence_exempt
            ? AttendancePolicy::haversineMeters($la, $lo, $site->latitude, $site->longitude)
            : null;
        $inBounds = $site->geofence_exempt
            ? true
            : ($distance !== null && $distance <= $site->radius_meters);

        if (AttendancePolicy::blocksPunch($inBounds)) {
            Flux::toast(
                text: AttendancePolicy::geofenceBlockMessage($logType, $site->name, $distance, (int) $site->radius_meters),
                variant: 'danger',
            );

            return;
        }

        // The CORRECT Manila window — see the class docblock for the reference
        // bug this diverges from. One adjacent day back so an overnight
        // shift's open TIME_IN still pairs.
        $today = Hris::todayKey();
        $window = PayrollPeriod::attendanceInstants(PayrollPeriod::addCalendarDays($today, -1), $today);

        try {
            $created = DB::transaction(function () use (
                $logType,
                $source,
                $employee,
                $site,
                $la,
                $lo,
                $distance,
                $inBounds,
                $window,
                $today,
            ): AttendanceLog {
                $windowRows = AttendanceLog::query()
                    ->where('employee_id', (string) $employee->id)
                    ->whereBetween('logged_at', [$window['gte'], $window['lte']])
                    ->orderBy('logged_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id', 'log_type', 'logged_at']);

                $windowLogs = $windowRows
                    ->map(static fn (AttendanceLog $row): DomainLog => DomainLog::fromArray([
                        'id' => $row->id,
                        'log_type' => $row->log_type,
                        'logged_at' => $row->logged_at,
                    ]))
                    ->all();

                $shiftRow = Shift::query()->where('employee_id', (string) $employee->id)->first();
                $domainShift = $shiftRow === null ? null : DomainShift::fromArray($shiftRow->toArray());

                // A Time In left open past the shift plus its overtime ceiling
                // is somebody who forgot to clock out, not somebody still
                // working — so it stops blocking the next shift and the day
                // waits for a correction.
                $rates = PayrollRates::fromFormulas(
                    array_values(PayrollFormula::query()->get(['key', 'params'])->map->toArray()->all()),
                );
                $nowIso = DomainLog::isoFromUtc(CarbonImmutable::now()->utc());

                $anomaly = AttendancePolicy::pairingAnomaly(
                    array_values($windowLogs),
                    $logType,
                    $nowIso,
                    ShiftResolver::openPunchExpiryMinutes($domainShift, $rates->maxOtHours),
                );
                if ($anomaly !== null) {
                    throw new RuntimeException($anomaly);
                }

                if ($shiftRow !== null && (bool) $shiftRow->requires_break_punch) {
                    $completedPairs = 0;
                    foreach ($windowRows as $row) {
                        if ($row->log_type === DomainLog::TIME_OUT
                            && ManilaClock::dayKey($row->logged_at) === $today) {
                            $completedPairs++;
                        }
                    }

                    if (AttendancePolicy::blocksTimeOutWithoutBreak(
                        $logType,
                        true,
                        $completedPairs,
                        ManilaClock::minutesOfDay(CarbonImmutable::now()),
                        ShiftResolver::clockMinutes($shiftRow->end_time),
                    )) {
                        throw new RuntimeException(
                            AttendancePolicy::breakPunchRequiredMessage($shiftRow->break_start),
                        );
                    }
                }

                return AttendanceLog::query()->create([
                    'employee_id' => (string) $employee->id,
                    'site_id' => (string) $site->id,
                    'log_type' => $logType,
                    'source' => $source,
                    'latitude' => $la,
                    'longitude' => $lo,
                    // Math.round(distance * 100) / 100 — the two-decimal
                    // boundary, which is Money::round here.
                    'distance_meters' => $distance === null ? null : Money::round($distance),
                    'is_geofenced' => $inBounds,
                    'is_anomaly' => false,
                    'anomaly_note' => null,
                ]);
            });
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        AuditService::record(
            $actor,
            AuditEvent::punch($logType),
            'attendance_logs',
            (string) $created->id,
            Hris::employeeName($employee).' @ '.$site->name.' · '.$source.' · '
                .($inBounds ? 'in-bounds' : 'OUT OF BOUNDS')
                .($distance !== null ? ' ('.((int) Minutes::round($distance)).' m)' : ''),
        );

        $label = str_replace('_', ' ', $logType);
        if ($site->geofence_exempt) {
            Flux::toast(text: "{$label} recorded at {$site->name} — geofence-exempt.", variant: 'success');
        } else {
            Flux::toast(text: "{$label} recorded — inside {$site->name}.", variant: 'success');
        }

        // The live feed refreshes without waiting for its next poll.
        $this->dispatch('edtr-punch-recorded');

        // ...and so does every OTHER browser watching this punch: the station
        // beside it, the supervisor's eDTR page, this employee's own portal.
        // A knock only — Realtime sends it after the transaction commits and
        // after this response is flushed, and swallows a failure, so the
        // punch above is never at the mercy of a socket vendor.
        Realtime::announce([
            Realtime::allPunches(),
            Realtime::employeePunches((string) $employee->id),
        ]);
    }

    public function render(): View
    {
        $sites = WorkSite::query()->orderBy('name')->get();

        $selectedEmployee = $this->employeeId === ''
            ? null
            : EmployeeProfile::query()->whereKey($this->employeeId)->first();
        $assignedSiteId = $selectedEmployee !== null && $selectedEmployee->work_site_id !== null
            ? (string) $selectedEmployee->work_site_id
            : null;

        // The reference's three effects, replayed each render (idempotent):
        // the assigned site pins the select; self-service without an
        // assignment clears it; the kiosk defaults to the first site.
        if ($assignedSiteId !== null) {
            $this->siteId = $assignedSiteId;
        } elseif ($this->selfService) {
            $this->siteId = '';
        } elseif ($this->siteId === '' && $sites->isNotEmpty()) {
            $this->siteId = (string) $sites->first()->id;
        }

        $site = $this->siteId === '' ? null : $sites->firstWhere('id', $this->siteId);
        $assignedSite = $assignedSiteId === null ? null : $sites->firstWhere('id', $assignedSiteId);

        // The geofence verdict preview (PunchStation.tsx:106-114) — recomputed
        // server-side from the typed boxes.
        $verdict = null;
        if ($site !== null) {
            if ($site->geofence_exempt) {
                $verdict = ['inBounds' => true, 'distance' => 0.0, 'exempt' => true];
            } else {
                $la = $this->coordinate($this->lat);
                $lo = $this->coordinate($this->lng);
                if (trim($this->lat) !== '' && trim($this->lng) !== '' && $la !== null && $lo !== null) {
                    $distance = AttendancePolicy::haversineMeters($la, $lo, $site->latitude, $site->longitude);
                    $verdict = [
                        'inBounds' => $distance <= $site->radius_meters,
                        'distance' => $distance,
                        'exempt' => false,
                    ];
                }
            }
        }

        return view('livewire.edtr.punch-station', [
            'sites' => $sites,
            'selectedEmployee' => $selectedEmployee,
            'assignedSiteId' => $assignedSiteId,
            'assignedSite' => $assignedSite,
            'site' => $site,
            'verdict' => $verdict,
            'punchDisabled' => $this->employeeId === '' || $assignedSiteId === null || $assignedSite === null,
            'lockedName' => $this->lockedEmployeeId === null
                ? ''
                : Hris::employeeName(EmployeeProfile::query()->whereKey($this->lockedEmployeeId)->first()),
        ]);
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
