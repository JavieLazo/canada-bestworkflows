<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Records\Support\Hris;
use App\Models\AttendanceLog;
use App\Models\EmployeeProfile;
use App\Models\Project;
use App\Models\WorkSite;
use App\Support\Access;
use App\Support\AuditService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Site Settings — the port of src/app/(protected)/records/sites/sites-page.tsx.
 *
 * Geofence administration: site coordinates, radius and exemptions that every
 * punch is validated against. Punch counts per site come from the same read
 * the source made (`useAttendance(1000)` — the most recent 1000 logs, scoped
 * as GET /api/attendance scopes them), so a radius change can be judged
 * against real traffic.
 *
 * Permissions, exactly as the reference:
 *  - the PAGE needs RECORDS — route middleware;
 *  - READS are open to any session (GET /api/sites);
 *  - every WRITE needs all_data + RECORDS (api/sites/route.ts), re-checked
 *    inside each action;
 *  - DELETE refuses a site that employees, punches or projects still
 *    reference, with the API's own 409 wording (api/sites/[id]/route.ts).
 */
#[Layout('layouts.app')]
#[Title('Site Settings')]
class SitesPage extends Component
{
    use WithSorting;
    use WithTableFilters;

    /** Geofence filter — 'ALL', 'ENFORCED' or 'EXEMPT'. Plain filter state. */
    public string $geofence = 'ALL';

    /** Add/Edit dialog visibility. Plain UI state. */
    public bool $dialogOpen = false;

    /** The site being edited, or null when adding. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $editingId = null;

    public string $formName = '';

    public string $formLatitude = '';

    public string $formLongitude = '';

    public string $formRadius = '100';

    public bool $formExempt = false;

    /** Delete confirmation visibility. Plain UI state. */
    public bool $confirmOpen = false;

    /** The site queued for deletion. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $deletingId = null;

    public function mount(): void
    {
        // useSort<SortKey>("name") — sites-page.tsx:77.
        $this->sortKey = 'name';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['name', 'radius', 'punches'];
    }

    /** The FilterSelect resets the page on change (sites-page.tsx:214-219). */
    public function updatedGeofence(): void
    {
        $this->resetPage();
    }

    public function openAdd(): void
    {
        $this->editingId = null;
        $this->formName = '';
        $this->formLatitude = '';
        $this->formLongitude = '';
        $this->formRadius = '100';
        $this->formExempt = false;
        $this->dialogOpen = true;
    }

    public function startEdit(string $id): void
    {
        $site = WorkSite::query()->find($id);

        if ($site === null) {
            Flux::toast(variant: 'danger', text: 'That work site no longer exists.');

            return;
        }

        $this->editingId = (string) $site->getKey();
        $this->formName = $site->name;
        $this->formLatitude = (string) $site->latitude;
        $this->formLongitude = (string) $site->longitude;
        $this->formRadius = (string) $site->radius_meters;
        $this->formExempt = $site->geofence_exempt;
        $this->dialogOpen = true;
    }

    /**
     * Save the dialog — the validations of SiteDialog `submit()`
     * (sites-page.tsx:407-446, verbatim toasts) followed by the API's own
     * write path.
     */
    public function save(): void
    {
        if (trim($this->formName) === '') {
            Flux::toast(variant: 'danger', text: 'Site name is required.');

            return;
        }

        if (! $this->formExempt) {
            // Blank boxes are checked before the numbers are: `Number("")` is 0,
            // not NaN, so an empty pair would otherwise save a site at 0, 0.
            if (trim($this->formLatitude) === '' || trim($this->formLongitude) === '') {
                Flux::toast(variant: 'danger', text: 'A site the geofence enforces needs both coordinates.');

                return;
            }

            $la = is_numeric($this->formLatitude) ? (float) $this->formLatitude : NAN;
            $lo = is_numeric($this->formLongitude) ? (float) $this->formLongitude : NAN;
            $radius = is_numeric($this->formRadius) ? (float) $this->formRadius : NAN;

            if (! is_finite($la) || $la < -90 || $la > 90) {
                Flux::toast(variant: 'danger', text: 'Latitude must be between -90 and 90.');

                return;
            }

            if (! is_finite($lo) || $lo < -180 || $lo > 180) {
                Flux::toast(variant: 'danger', text: 'Longitude must be between -180 and 180.');

                return;
            }

            if ($la === 0.0 && $lo === 0.0) {
                Flux::toast(variant: 'danger', text: "0, 0 is not a place. Capture the site's location or type its coordinates.");

                return;
            }

            if (! is_finite($radius) || floor($radius) !== $radius || $radius <= 0) {
                Flux::toast(variant: 'danger', text: 'Radius must be a whole number of metres, greater than 0.');

                return;
            }
        }

        if (mb_strlen(trim($this->formName)) > 160) {
            Flux::toast(variant: 'danger', text: 'name: must be at most 160 characters.');

            return;
        }

        $access = app(Access::class);

        // requireAllDataModule("RECORDS") — re-checked in the action itself.
        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }

        // Number(form.latitude) — an empty box on an exempt site saves as 0.
        $payload = [
            'name' => trim($this->formName),
            'latitude' => is_numeric($this->formLatitude) ? (float) $this->formLatitude : 0.0,
            'longitude' => is_numeric($this->formLongitude) ? (float) $this->formLongitude : 0.0,
            'radius_meters' => is_numeric($this->formRadius) ? (int) $this->formRadius : 0,
            'geofence_exempt' => $this->formExempt,
        ];

        $name = $payload['name'];

        if ($this->editingId !== null) {
            $site = WorkSite::query()->find($this->editingId);

            if ($site === null) {
                Flux::toast(variant: 'danger', text: 'That work site no longer exists.');

                return;
            }

            $site->fill($payload)->save();

            AuditService::record(
                $access,
                AuditEvent::SITE_UPDATED,
                'work_sites',
                (string) $site->getKey(),
                "{$name} updated",
            );

            Flux::toast(variant: 'success', text: 'Work site updated.');
        } else {
            $site = WorkSite::query()->create($payload);

            AuditService::record(
                $access,
                AuditEvent::SITE_CREATED,
                'work_sites',
                (string) $site->getKey(),
                "{$name} added",
            );

            Flux::toast(variant: 'success', text: "{$name} added.");
        }

        $this->dialogOpen = false;
        $this->editingId = null;
    }

    public function confirmDelete(string $id): void
    {
        $this->deletingId = $id;
        $this->confirmOpen = true;
    }

    /**
     * Delete a site — with the API's refusals for a site still referenced by
     * employees, attendance or projects (api/sites/[id]/route.ts:40-63).
     */
    public function deleteConfirmed(): void
    {
        $id = $this->deletingId;
        $this->confirmOpen = false;
        $this->deletingId = null;

        if ($id === null) {
            return;
        }

        $access = app(Access::class);

        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }

        $site = WorkSite::query()->find($id);

        if ($site === null) {
            Flux::toast(variant: 'danger', text: 'That work site no longer exists.');

            return;
        }

        $assignedEmployees = EmployeeProfile::query()->where('work_site_id', $id)->count();

        if ($assignedEmployees > 0) {
            Flux::toast(variant: 'danger', text: sprintf(
                'Reassign %d employee%s before deleting this work site.',
                $assignedEmployees,
                $assignedEmployees === 1 ? '' : 's',
            ));

            return;
        }

        $recordedPunches = AttendanceLog::query()->where('site_id', $id)->count();

        if ($recordedPunches > 0) {
            Flux::toast(variant: 'danger', text: 'This work site has recorded punches and must remain in attendance history.');

            return;
        }

        $linkedProjects = Project::query()->where('site_id', $id)->count();

        if ($linkedProjects > 0) {
            Flux::toast(variant: 'danger', text: sprintf(
                'This work site belongs to %d project%s and must remain in project history.',
                $linkedProjects,
                $linkedProjects === 1 ? '' : 's',
            ));

            return;
        }

        $name = $site->name;
        $site->delete();

        AuditService::record($access, AuditEvent::SITE_DELETED, 'work_sites', $id, "{$name} deleted");

        Flux::toast(variant: 'success', text: "{$name} deleted.");
    }

    /**
     * Punch counts per site — the port of the `stats` memo
     * (sites-page.tsx:80-91) over `useAttendance(1000)`: the most recent 1000
     * logs, scoped exactly as GET /api/attendance scopes them (EDTR, PAYROLL).
     *
     * @return array<string, int>
     */
    private function punchCounts(): array
    {
        $access = app(Access::class);

        $siteIds = AttendanceLog::query()
            ->toEmployee($access, 'employee_id', ModuleCode::EDTR, ModuleCode::PAYROLL)
            ->orderByDesc('logged_at')
            ->limit(1000)
            ->pluck('site_id');

        $counts = [];

        foreach ($siteIds as $siteId) {
            if ($siteId === null || $siteId === '') {
                continue;
            }

            $counts[(string) $siteId] = ($counts[(string) $siteId] ?? 0) + 1;
        }

        return $counts;
    }

    public function render(): View
    {
        $counts = $this->punchCounts();

        $needle = strtolower(trim($this->search));

        // Filter and sort in PHP, exactly as the source's memo does
        // (sites-page.tsx:96-116): filter, comparator by key, then reverse the
        // whole list for descending order.
        $sites = WorkSite::query()->get()
            ->filter(function (WorkSite $s) use ($needle): bool {
                if ($this->geofence === 'ENFORCED' && $s->geofence_exempt) {
                    return false;
                }

                if ($this->geofence === 'EXEMPT' && ! $s->geofence_exempt) {
                    return false;
                }

                if ($needle === '') {
                    return true;
                }

                return str_contains(strtolower($s->name), $needle);
            })
            ->values()
            ->all();

        $punchesAt = static fn (WorkSite $s): int => $counts[(string) $s->getKey()] ?? 0;

        usort($sites, function (WorkSite $a, WorkSite $b) use ($punchesAt): int {
            return match ($this->sortKey) {
                'radius' => ($a->radius_meters <=> $b->radius_meters) ?: Hris::compareText($a->name, $b->name),
                'punches' => ($punchesAt($b) <=> $punchesAt($a)) ?: Hris::compareText($a->name, $b->name),
                default => Hris::compareText($a->name, $b->name),
            };
        });

        if ($this->sortDir === 'desc') {
            $sites = array_reverse($sites);
        }

        $rows = $this->paginateClamped($sites);

        $editing = $this->editingId !== null ? WorkSite::query()->find($this->editingId) : null;
        $deleting = $this->deletingId !== null ? WorkSite::query()->find($this->deletingId) : null;

        // How far the saved coordinates sit from the edited ones
        // (sites-page.tsx:359-367) — shown while editing only.
        $drift = null;

        if ($editing !== null
            && is_numeric($this->formLatitude) && is_numeric($this->formLongitude)
            && ((float) $this->formLatitude !== $editing->latitude || (float) $this->formLongitude !== $editing->longitude)) {
            $drift = (int) round(Hris::haversineMeters(
                $editing->latitude,
                $editing->longitude,
                (float) $this->formLatitude,
                (float) $this->formLongitude,
            ));
        }

        return view('livewire.records.sites-page', [
            'rows' => $rows,
            'counts' => $counts,
            'editing' => $editing,
            'deleting' => $deleting,
            'drift' => $drift,
        ]);
    }
}
