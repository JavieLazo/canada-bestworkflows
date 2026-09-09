<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Records\Support\Hris;
use App\Models\Holiday;
use App\Support\Access;
use App\Support\AuditService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
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
    public const array SCHEDULES = ['ANNUAL' => 'Every year', 'ONE_TIME' => 'One time'];

    /** holidays-page.tsx:49-52. */
    public const array TYPES = ['REGULAR' => 'Regular Holiday', 'SPECIAL_NON_WORKING' => 'Special Non-Working Holiday'];

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
        $row = Holiday::query()->find($id);

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

        if (mb_strlen($name) > 160) {
            Flux::toast(variant: 'danger', text: 'name: must be at most 160 characters.');

            return;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->formDate) !== 1) {
            Flux::toast(variant: 'danger', text: 'date: must be a real YYYY-MM-DD calendar date.');

            return;
        }

        if ($percent > 999) {
            Flux::toast(variant: 'danger', text: 'percent: must be at most 999.');

            return;
        }

        if (! isset(self::SCHEDULES[$this->formSchedule]) || ! isset(self::TYPES[$this->formType])) {
            // The selects only offer these; anything else is a tampered payload.
            Flux::toast(variant: 'danger', text: 'That schedule or type is not recognised.');

            return;
        }

        $access = app(Access::class);

        // requireAllDataModule("RECORDS") — re-checked in the action itself.
        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }

        $payload = [
            'name' => $name,
            'date' => $this->formDate,
            'schedule' => $this->formSchedule,
            'type' => $this->formType,
            'percent' => $percent,
        ];

        if ($this->editingId !== null) {
            $row = Holiday::query()->find($this->editingId);

            if ($row === null) {
                Flux::toast(variant: 'danger', text: 'That holiday no longer exists.');

                return;
            }

            $row->fill($payload)->save();

            AuditService::record(
                $access,
                AuditEvent::HOLIDAY_UPDATED,
                'holidays',
                (string) $row->getKey(),
                "{$row->code} updated — {$name}",
            );

            Flux::toast(variant: 'success', text: "{$name} updated.");
        } else {
            $code = $this->nextCode();
            $row = Holiday::query()->create($payload + ['code' => $code]);

            AuditService::record(
                $access,
                AuditEvent::HOLIDAY_CREATED,
                'holidays',
                (string) $row->getKey(),
                "{$name} added as {$code}",
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

        $row = Holiday::query()->find($id);

        if ($row === null) {
            Flux::toast(variant: 'danger', text: 'That holiday no longer exists.');

            return;
        }

        $code = $row->code;
        $name = $row->name;
        $row->delete();

        AuditService::record(
            $access,
            AuditEvent::HOLIDAY_DELETED,
            'holidays',
            $id,
            "{$code} \"{$name}\" deleted",
        );

        Flux::toast(variant: 'success', text: "{$name} deleted.");
    }

    /** holidays-page.tsx:79-85 — `HOL-000` codes, read from the rows. */
    private function nextCode(): string
    {
        $highest = 0;

        foreach (Holiday::query()->pluck('code') as $code) {
            if (preg_match('/^HOL-(\d+)$/i', (string) $code, $m) === 1) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return sprintf('HOL-%03d', $highest + 1);
    }

    public function render(): View
    {
        $query = Holiday::query();

        if ($this->type !== 'ALL') {
            $query->where('type', $this->type);
        }

        $needle = trim($this->search);

        if ($needle !== '') {
            // `${r.code} ${r.name}`.includes(needle) — holidays-page.tsx:98-101.
            $query->where(function ($q) use ($needle): void {
                $like = '%'.addcslashes($needle, '%_\\').'%';
                $q->where('code', 'like', $like)->orWhere('name', 'like', $like);
            });
        }

        $this->applySort($query, [
            'id' => 'code',
            'name' => 'name',
            'date' => 'date',
            // compareText(a.type, b.type) || compareText(a.name, b.name), the
            // whole list reversed for desc — so the tiebreak reverses too.
            'type' => static function ($q, string $dir): void {
                $q->orderBy('type', $dir)->orderBy('name', $dir);
            },
        ]);

        $rows = $this->paginateClamped($query);

        $editing = $this->editingId !== null ? Holiday::query()->find($this->editingId) : null;
        $deleting = $this->deletingId !== null ? Holiday::query()->find($this->deletingId) : null;

        return view('livewire.records.holidays-page', [
            'rows' => $rows,
            'editing' => $editing,
            'deleting' => $deleting,
        ]);
    }
}
