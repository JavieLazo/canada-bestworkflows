<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Records\Support\Hris;
use App\Livewire\Records\Support\SiteRows;
use App\Modules\Organization\Application\Commands\DeleteSite;
use App\Modules\Organization\Application\Commands\SaveSite;
use App\Modules\Organization\Application\Commands\SetSiteStatus;
use App\Modules\Organization\Application\SiteDraft;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
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
 *    reference, with the API's own 409 wording (api/sites/[id]/route.ts) —
 *    and one that any employee's weekly roster location still names
 *    (`roster_locations`; Timekeeping's `SiteReferences`, checked after its
 *    punches, with ems's own sentence). The refusals come from the tagged
 *    retention sources behind `DeleteSite`, in that order;
 *  - MAKE INACTIVE / MAKE ACTIVE (no reference counterpart): a site history
 *    names cannot be deleted, so it is retired instead. `SetSiteStatus`
 *    flips the flag and nothing else — existing assignments keep the site;
 *    only new ones are refused it.
 */
#[Layout('layouts.app')]
#[Title('Site Settings')]
class SitesPage extends Component
{
    use WithSorting;
    use WithTableFilters;

    /** Geofence filter — 'ALL', 'ENFORCED' or 'EXEMPT'. Plain filter state. */
    public string $geofence = 'ALL';

    /** Status filter — 'ALL', 'ACTIVE' or 'INACTIVE'. Plain filter state. */
    public string $status = 'ALL';

    /** Make inactive / Make active confirmation visibility. Plain UI state. */
    public bool $statusOpen = false;

    /** The site queued for a status change. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $statusId = null;

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

    public function updatedStatus(): void
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
        $site = app(OrganizationDirectory::class)->sites(app(Access::class))->firstWhere('id', $id);

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
        $access = app(Access::class);
        try {
            $message = app(SaveSite::class)->handle($access, $this->editingId, new SiteDraft($this->formName, $this->formLatitude, $this->formLongitude, $this->formRadius, $this->formExempt));
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'A record with those details already exists.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: $message);
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
        try {
            $name = app(DeleteSite::class)->handle($access, $id);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The record could not be deleted. Please try again.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: "{$name} deleted.");
    }

    public function confirmStatus(string $id): void
    {
        $this->statusId = $id;
        $this->statusOpen = true;
    }

    /**
     * Flip the queued site between active and inactive. The direction is read
     * from the site as it stands now, never from the client; the command
     * re-checks all_data + RECORDS from a fresh Access.
     */
    public function statusConfirmed(): void
    {
        $id = $this->statusId;
        $this->statusOpen = false;
        $this->statusId = null;
        if ($id === null) {
            return;
        }
        $access = app(Access::class);
        $site = app(OrganizationDirectory::class)->sites($access)->firstWhere('id', $id);
        try {
            $message = app(SetSiteStatus::class)->handle($access, $id, $site === null || ! $site->is_active);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The site could not be changed. Please try again.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: $message);
    }

    public function render(): View
    {
        // Punch counts, filter and sort: one copy shared with the CSV export.
        ['sites' => $sites, 'counts' => $counts] = SiteRows::list(app(Access::class), $this->search, $this->geofence, $this->sortKey, $this->sortDir, $this->status);

        $rows = $this->paginateClamped($sites);

        $editing = $this->editingId !== null ? app(OrganizationDirectory::class)->sites(app(Access::class))->firstWhere('id', $this->editingId) : null;
        $deleting = $this->deletingId !== null ? app(OrganizationDirectory::class)->sites(app(Access::class))->firstWhere('id', $this->deletingId) : null;
        $changing = $this->statusId !== null ? app(OrganizationDirectory::class)->sites(app(Access::class))->firstWhere('id', $this->statusId) : null;

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
            'changing' => $changing,
            'drift' => $drift,
        ]);
    }
}
