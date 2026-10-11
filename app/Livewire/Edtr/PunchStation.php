<?php

declare(strict_types=1);

namespace App\Livewire\Edtr;

use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Livewire\Edtr\Support\AttendancePolicy;
use App\Livewire\Edtr\Support\Hris;
use App\Models\Shift;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Timekeeping\Application\Commands\RecordPunch;
use App\Modules\Timekeeping\Application\PunchInput;
use App\Modules\Timekeeping\Application\Queries\RosterLocations;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\ManilaClock;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

/**
 * Kiosk and self-service presentation, ported from PunchStation.tsx.
 * RecordPunch receives a fresh session actor and owns server-side geofencing,
 * pairing and persistence. Client-supplied derived results are never trusted.
 *
 * ── THE "TODAY" WINDOW — a deliberate divergence ────────────────────────────
 * The reference punch route (an earlier revision, punch/route.ts:65) bucketed
 * "today's punches" by mixing the host's LOCAL date with UTC midnight, which
 * put the day's cut eight hours into the working day and silently excluded
 * pre-08:00 punches from pairing. RecordPunch preserves the corrected window:
 * the Manila calendar day via ManilaClock::dayKey / PayrollPeriod::
 * attendanceInstants, reading one adjacent day back so an overnight shift's
 * open TIME_IN still pairs with its next-morning TIME_OUT.
 *
 * ── Locks ───────────────────────────────────────────────────────────────────
 * The reference serialised concurrent punches per employee with
 * `pg_advisory_xact_lock`; MySQL has no transaction-scoped advisory lock, so
 * RecordPunch's pairing-window SELECT takes `lockForUpdate()` instead.
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
     * browser's fix is the only location a punch there can carry.
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

    /**
     * Typed coordinate boxes (kiosk only). Free text — validated at punch
     * time. Filled, they win over the browser fix; left empty, the fix the
     * station reads as the punch is pressed is used instead.
     */
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

        if (app(OperationalDirectory::class)->find($actor, $id) !== null) {
            $this->employeeId = $id;
        }
    }

    /** Kiosk site select. The punch re-validates against the assigned site anyway. */
    public function selectSite(string $id): void
    {
        if ($this->selfService) {
            return;
        }
        if (app(OrganizationDirectory::class)->sites(app(Access::class))->contains('id', $id)) {
            $this->siteId = $id;
        }
    }

    /**
     * Record a punch. `$autoLat`/`$autoLng`/`$autoAccuracy` carry the fix the
     * browser handed the station as the button was pressed — in BOTH modes
     * (the reference reads one only in self-service, PunchStation.tsx:192).
     * Self-service: that fix is the only reading. Kiosk: RecordPunch lets the
     * typed boxes win when either is filled and falls back to the fix when
     * both are empty; the client always sends the fix. RecordPunch
     * recomputes everything derived from the reading.
     */
    public function punch(string $logType, ?float $autoLat = null, ?float $autoLng = null, ?float $autoAccuracy = null): void
    {
        $actor = Access::forActor(auth()->user());
        try {
            $message = app(RecordPunch::class)->handle($actor, new PunchInput($this->employeeId, $this->siteId, $this->source, $this->selfService, $this->lat, $this->lng), $logType, $autoLat, $autoLng, $autoAccuracy);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: 'The punch could not be recorded. Please try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        if ($message === null) {
            return;
        }
        Flux::toast(text: $message, variant: 'success');
        $this->dispatch('edtr-punch-recorded');
    }

    /**
     * The station is a PREVIEW keyed like a Time In: where a punch pressed
     * now would be judged — today's roster row for the employee (Work from
     * home, or that weekday's site) or, with no row, the Assigned Worksite
     * (`RosterLocations::forStation`). RecordPunch decides for real, under
     * lock, for the work date the punch itself belongs to; a Time Out that
     * closes yesterday's open Time In can land on yesterday's place, which
     * this preview does not read the window to know.
     */
    public function render(): View
    {
        $access = app(Access::class);
        $sites = app(OrganizationDirectory::class)->sites($access);

        $today = $this->employeeId === ''
            ? null
            : app(RosterLocations::class)->forStation($access, $this->employeeId);
        $wfhToday = $today?->kind === LocationKind::WFH;
        $todaySiteId = $today !== null && ! $wfhToday ? $today->site_id : null;
        $todaySite = $todaySiteId === null ? null : $sites->firstWhere('id', $todaySiteId);

        // The reference's three effects, replayed each render (idempotent):
        // the day's site pins the select; a Work from home day, and
        // self-service with no site, clear it; the kiosk defaults to the
        // first site. RecordPunch ignores the kiosk pick on a WFH day and
        // refuses a mismatch on an onsite one, so the pin is a courtesy.
        if ($wfhToday) {
            $this->siteId = '';
        } elseif ($todaySiteId !== null) {
            $this->siteId = $todaySiteId;
        } elseif ($this->selfService) {
            $this->siteId = '';
        } elseif ($this->siteId === '' && $sites->isNotEmpty()) {
            $this->siteId = (string) $sites->first()->id;
        }

        $site = $this->siteId === '' ? null : $sites->firstWhere('id', $this->siteId);

        // The geofence verdict preview (PunchStation.tsx:106-114) — recomputed
        // server-side from the typed boxes. A WFH day has no fence to preview:
        // the punch is accepted from anywhere, coordinates still recorded.
        $verdict = null;
        if ($wfhToday) {
            $verdict = ['inBounds' => true, 'distance' => 0.0, 'exempt' => true, 'wfh' => true];
        } elseif ($site !== null) {
            if ($site->geofence_exempt) {
                $verdict = ['inBounds' => true, 'distance' => 0.0, 'exempt' => true, 'wfh' => false];
            } else {
                $la = $this->coordinate($this->lat);
                $lo = $this->coordinate($this->lng);
                if (trim($this->lat) !== '' && trim($this->lng) !== '' && $la !== null && $lo !== null) {
                    $distance = AttendancePolicy::haversineMeters($la, $lo, $site->latitude, $site->longitude);
                    $verdict = [
                        'inBounds' => $distance <= $site->radius_meters,
                        'distance' => $distance,
                        'exempt' => false,
                        'wfh' => false,
                    ];
                }
            }
        }

        return view('livewire.edtr.punch-station', [
            'sites' => $sites,
            'todayLocation' => $today,
            'wfhToday' => $wfhToday,
            'todaySite' => $todaySite,
            'site' => $site,
            'verdict' => $verdict,
            // Nothing to punch against: no employee, one the actor may not
            // punch for, or an onsite day whose site does not resolve (no
            // Assigned Worksite, or one since deleted). A WFH day never disables.
            'punchDisabled' => $this->employeeId === '' || $today === null || (! $wfhToday && $todaySite === null),
            'lockedName' => $this->lockedEmployeeId === null
                ? ''
                : Hris::employeeName(app(OperationalDirectory::class)->find($access, $this->lockedEmployeeId)),
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
