<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Records\Support\Hris;
use App\Models\Holiday;
use App\Modules\Organization\Application\Commands\DeleteHoliday;
use App\Modules\Organization\Application\Commands\SaveHoliday;
use App\Modules\Organization\Application\HolidayDraft;
use App\Modules\Organization\Application\Queries\HolidayRecords;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Holiday Settings — the port of
 * src/app/(protected)/records/holidays/holidays-page.tsx.
 *
 * The holidays payroll pays for, and at what rate: regular and special
 * non-working days, each annual or one-time, each with the percent of the
 * daily rate the day pays when worked.
 *
 * Permissions, exactly as the reference:
 *  - the PAGE needs RECORDS — route middleware;
 *  - READS are open to any session (GET /api/records/holidays);
 *  - every WRITE needs all_data + RECORDS (api/records/holidays/route.ts),
 *    re-checked inside each action.
 */
#[Layout('layouts.app')]
#[Title('Holiday Settings')]
class HolidaysPage extends Component
{
    use WithSorting;
    use WithTableFilters;

    /** holidays-page.tsx:44-47. */
    public const array SCHEDULES = SaveHoliday::SCHEDULES;

    /** holidays-page.tsx:49-52. */
    public const array TYPES = SaveHoliday::TYPES;

    /** Type filter — 'ALL' or a holiday type. Plain filter state. */
    public string $type = 'ALL';

    /** Add/Edit dialog visibility. Plain UI state. */
    public bool $dialogOpen = false;

    /** The holiday being edited, or null when adding. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $editingId = null;

    public string $formName = '';

    public string $formDate = '';

    public string $formSchedule = 'ANNUAL';

    public string $formType = 'REGULAR';

    /**
     * The whole day's rate, not a premium on top of it: a regular holiday that
     * is worked pays double. 100 would pay an ordinary day's wage.
     */
    public string $formPercent = '200';

    /** The dialog's inline error (holidays-page.tsx:311-328). */
    public ?string $formError = null;

    /** Delete confirmation visibility. Plain UI state. */
    public bool $confirmOpen = false;

    /** The holiday queued for deletion. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $deletingId = null;

    public function mount(): void
    {
        // useSort<SortKey>("date") — holidays-page.tsx:92.
        $this->sortKey = 'date';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['id', 'name', 'date', 'type'];
    }

    /** The FilterSelect resets the page on change (holidays-page.tsx:194-199). */
    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function openAdd(): void
    {
        $this->editingId = null;
        $this->formName = '';
        $this->formDate = Hris::todayKey();
        $this->formSchedule = 'ANNUAL';
        $this->formType = 'REGULAR';
        $this->formPercent = '200';
        $this->formError = null;
        $this->dialogOpen = true;
    }

    public function startEdit(string $id): void
    {
        $row = app(HolidayRecords::class)->find(app(Access::class), $id);

        if ($row === null) {
            Flux::toast(variant: 'danger', text: 'That holiday no longer exists.');

            return;
        }

        $this->editingId = (string) $row->getKey();
        $this->formName = $row->name;
        $this->formDate = $row->date->format('Y-m-d');
        $this->formSchedule = $row->schedule;
        $this->formType = $row->type;
        $this->formPercent = (string) $row->percent;
        $this->formError = null;
        $this->dialogOpen = true;
    }

    /**
     * Save the dialog — HolidayDialog `submit()`'s checks
     * (holidays-page.tsx:308-329, verbatim messages) plus the API's own caps
     * (HolidayInput, schemas.ts:105-114).
     */
    public function save(): void
    {
        $name = trim($this->formName);

        if ($name === '') {
            $this->formError = 'Name is required.';

            return;
        }

        // An empty date would store "" — which reads back as an invalid date
        // and never matches in holidayOn(), so the holiday would silently
        // never apply.
        if ($this->formDate === '') {
            $this->formError = 'Date is required.';

            return;
        }

        $percent = is_numeric($this->formPercent) ? (float) $this->formPercent : NAN;

        if (! is_finite($percent) || $percent < 0) {
            $this->formError = 'Percent must be a positive number.';

            return;
        }

        $this->formError = null;
        $access = app(Access::class);
        try {
            $message = app(SaveHoliday::class)->handle($access, $this->editingId, new HolidayDraft($this->formName, $this->formDate, $this->formSchedule, $this->formType, $this->formPercent));
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
            $name = app(DeleteHoliday::class)->handle($access, $id);
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

    public function render(): View
    {
        $rows = $this->acceptPage(app(HolidayRecords::class)->page(app(Access::class), $this->type, $this->search, $this->sortKey, $this->sortDir, (int) $this->getPage(), $this->perPage));

        $editing = $this->editingId !== null ? app(HolidayRecords::class)->find(app(Access::class), $this->editingId) : null;
        $deleting = $this->deletingId !== null ? app(HolidayRecords::class)->find(app(Access::class), $this->deletingId) : null;

        return view('livewire.records.holidays-page', [
            'rows' => $rows,
            'editing' => $editing,
            'deleting' => $deleting,
        ]);
    }
}
