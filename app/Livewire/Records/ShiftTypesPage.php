<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Records\Support\Hris;
use App\Livewire\Records\Support\ShiftTypePolicy;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Support\Access;
use App\Support\AuditService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Shifting Settings — the port of
 * src/app/(protected)/records/shifts/shift-types-page.tsx.
 *
 * The shift patterns that can be assigned to an employee — fixed hours, or
 * flexible ones that only owe a daily total — with the full editor: mode,
 * work week, required hours, grace and the break rules.
 *
 * Permissions, exactly as the reference:
 *  - the PAGE needs RECORDS — route middleware;
 *  - READS are open to any session (GET /api/records/shift-types);
 *  - every WRITE needs all_data + RECORDS and passes the shift-shape policy
 *    (api/records/shift-types/route.ts), re-checked inside each action;
 *  - the usage count reads the assigned-shift list through the same scoping
 *    GET /api/shifts applies (ROSTER, EDTR, PAYROLL).
 */
#[Layout('layouts.app')]
#[Title('Shifting Settings')]
class ShiftTypesPage extends Component
{
    use WithSorting;
    use WithTableFilters;

    /** Add/Edit dialog visibility. Plain UI state. */
    public bool $dialogOpen = false;

    /** The pattern being edited, or null when adding. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $editingId = null;

    public string $formName = '';

    public string $formMode = 'FIXED';

    /** @var list<int> */
    public array $formWorkDays = [1, 2, 3, 4, 5];

    public string $formStartTime = '08:00';

    public string $formEndTime = '17:00';

    public string $formGrace = '15';

    public string $formRequiredHours = '8';

    public bool $formHasBreak = true;

    public string $formBreakStart = '12:00';

    public string $formBreakEnd = '13:00';

    public string $formBreakMinutes = '60';

    public bool $formRequiresBreakPunch = true;

    /** The dialog's inline error (shift-types-page.tsx:404-414). */
    public ?string $formError = null;

    /** Delete confirmation visibility. Plain UI state. */
    public bool $confirmOpen = false;

    /** The pattern queued for deletion. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $deletingId = null;

    public function mount(): void
    {
        // useSort<SortKey>("name") — shift-types-page.tsx:129.
        $this->sortKey = 'name';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['id', 'name', 'mode'];
    }

    /** Storage `"HH:mm:ss"` → the `"HH:mm"` the form holds, with a fallback. */
    private static function hhmm(?string $value, string $fallback): string
    {
        return $value !== null && $value !== '' ? substr($value, 0, 5) : $fallback;
    }

    public function openAdd(): void
    {
        $this->editingId = null;
        $this->formName = '';
        $this->formMode = 'FIXED';
        $this->formWorkDays = [1, 2, 3, 4, 5];
        $this->formStartTime = '08:00';
        $this->formEndTime = '17:00';
        $this->formGrace = '15';
        $this->formRequiredHours = '8';
        $this->formHasBreak = true;
        $this->formBreakStart = '12:00';
        $this->formBreakEnd = '13:00';
        $this->formBreakMinutes = '60';
        $this->formRequiresBreakPunch = true;
        $this->formError = null;
        $this->dialogOpen = true;
    }

    public function startEdit(string $id): void
    {
        $row = ShiftType::query()->find($id);

        if ($row === null) {
            Flux::toast(variant: 'danger', text: 'That shift pattern no longer exists.');

            return;
        }

        // ShiftTypeDialog's initial state (shift-types-page.tsx:373-390).
        $this->editingId = (string) $row->getKey();
        $this->formName = $row->name;
        $this->formMode = $row->mode;
        $this->formWorkDays = Hris::workWeek($row->work_days);
        $this->formStartTime = self::hhmm($row->start_time, '08:00');
        $this->formEndTime = self::hhmm($row->end_time, '17:00');
        $this->formGrace = (string) $row->grace_minutes;
        $this->formRequiredHours = (string) ($row->required_hours ?? 8);
        $this->formHasBreak = ($row->break_start !== null && $row->break_start !== '') || $row->break_minutes > 0;
        $this->formBreakStart = self::hhmm($row->break_start, '12:00');
        $this->formBreakEnd = self::hhmm($row->break_end, '13:00');
        $this->formBreakMinutes = (string) $row->break_minutes;
        $this->formRequiresBreakPunch = $row->requires_break_punch;
        $this->formError = null;
        $this->dialogOpen = true;
    }

    /** The work-week day buttons toggle a day in and out (shift-types-page.tsx:465-489). */
    public function toggleDay(int $day): void
    {
        if ($day < 0 || $day > 6) {
            return;
        }

        if (in_array($day, $this->formWorkDays, true)) {
            $this->formWorkDays = array_values(array_filter(
                $this->formWorkDays,
                static fn (int $d): bool => $d !== $day,
            ));

            return;
        }

        $days = [...$this->formWorkDays, $day];
        sort($days);
        $this->formWorkDays = $days;
    }

    /**
     * Save the dialog — the client checks of ShiftTypeDialog `submit()`
     * (shift-types-page.tsx:400-414) plus the API's own shape policy and caps
     * (ShiftTypeInput, schemas.ts:118-134).
     */
    public function save(): void
    {
        if (trim($this->formName) === '') {
            $this->formError = 'Name is required.';

            return;
        }

        // An empty week has no scheduled day to measure against and nothing to
        // divide the monthly rate by.
        if ($this->formWorkDays === []) {
            $this->formError = 'Pick at least one working day.';

            return;
        }

        $this->formError = null;

        if (mb_strlen(trim($this->formName)) > 160) {
            Flux::toast(variant: 'danger', text: 'name: must be at most 160 characters.');

            return;
        }

        $flexible = $this->formMode === 'FLEXIBLE';

        // The client's payload mapping, verbatim (shift-types-page.tsx:170-186).
        // Bounds-checked because the property is client-writable: 0–6 only
        // (ShiftTypeInput, schemas.ts:124).
        $days = array_values(array_unique(array_filter(
            array_map(intval(...), $this->formWorkDays),
            static fn (int $d): bool => $d >= 0 && $d <= 6,
        )));
        sort($days);

        if ($days === []) {
            $this->formError = 'Pick at least one working day.';

            return;
        }

        $payload = [
            'name' => trim($this->formName),
            'mode' => $flexible ? 'FLEXIBLE' : 'FIXED',
            'work_days' => $days,
            'start_time' => $flexible ? null : $this->formStartTime.':00',
            'end_time' => $flexible ? null : $this->formEndTime.':00',
            'grace_minutes' => $flexible ? 0 : (is_numeric($this->formGrace) ? (int) $this->formGrace : 0),
            'required_hours' => $flexible ? (is_numeric($this->formRequiredHours) ? (float) $this->formRequiredHours : 0.0) : null,
            'break_start' => $this->formHasBreak && ! $flexible ? $this->formBreakStart.':00' : null,
            'break_end' => $this->formHasBreak && ! $flexible ? $this->formBreakEnd.':00' : null,
            'break_minutes' => $this->formHasBreak ? (is_numeric($this->formBreakMinutes) ? (int) $this->formBreakMinutes : 0) : 0,
            'requires_break_punch' => $this->formHasBreak && ! $flexible ? $this->formRequiresBreakPunch : false,
        ];

        // ShiftTypeInput's numeric caps (schemas.ts:118-134).
        if ($payload['grace_minutes'] < 0 || $payload['grace_minutes'] > 600) {
            Flux::toast(variant: 'danger', text: 'grace_minutes: must be between 0 and 600.');

            return;
        }

        if ($payload['break_minutes'] < 0 || $payload['break_minutes'] > 600) {
            Flux::toast(variant: 'danger', text: 'break_minutes: must be between 0 and 600.');

            return;
        }

        if ($payload['required_hours'] !== null
            && ($payload['required_hours'] < 0 || $payload['required_hours'] > 24)) {
            Flux::toast(variant: 'danger', text: 'required_hours: must be between 0 and 24.');

            return;
        }

        // The API's combination policy (api/records/shift-types/route.ts:19-25).
        $shapeError = ShiftTypePolicy::shapeError($payload);

        if ($shapeError !== null) {
            Flux::toast(variant: 'danger', text: $shapeError);

            return;
        }

        $access = app(Access::class);

        // requireAllDataModule("RECORDS") — re-checked in the action itself.
        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }

        $name = $payload['name'];

        if ($this->editingId !== null) {
            $row = ShiftType::query()->find($this->editingId);

            if ($row === null) {
                Flux::toast(variant: 'danger', text: 'That shift pattern no longer exists.');

                return;
            }

            $row->fill($payload)->save();

            AuditService::record(
                $access,
                AuditEvent::SHIFT_TYPE_UPDATED,
                'shift_types',
                (string) $row->getKey(),
                "{$row->code} updated — {$name}",
            );

            Flux::toast(variant: 'success', text: "{$name} updated.");
        } else {
            $code = $this->nextCode();
            $row = ShiftType::query()->create($payload + ['code' => $code]);

            AuditService::record(
                $access,
                AuditEvent::SHIFT_TYPE_CREATED,
                'shift_types',
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

        $row = ShiftType::query()->find($id);

        if ($row === null) {
            Flux::toast(variant: 'danger', text: 'That shift pattern no longer exists.');

            return;
        }

        $code = $row->code;
        $name = $row->name;
        $row->delete();

        AuditService::record(
            $access,
            AuditEvent::SHIFT_TYPE_DELETED,
            'shift_types',
            $id,
            "{$code} \"{$name}\" deleted",
        );

        Flux::toast(variant: 'success', text: "{$name} deleted.");
    }

    /** shift-types-page.tsx:110-116 — `SFT-000` codes, read from the rows. */
    private function nextCode(): string
    {
        $highest = 0;

        foreach (ShiftType::query()->pluck('code') as $code) {
            if (preg_match('/^SFT-(\d+)$/i', (string) $code, $m) === 1) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return sprintf('SFT-%03d', $highest + 1);
    }

    public function render(): View
    {
        // A pattern counts as in use when someone is rostered on those exact
        // hours (shift-types-page.tsx:134-139), read through the same scoping
        // GET /api/shifts applies.
        $access = app(Access::class);

        $assigned = Shift::query()
            ->toEmployee($access, 'employee_id', ModuleCode::ROSTER, ModuleCode::EDTR, ModuleCode::PAYROLL)
            ->get(['start_time', 'end_time']);

        $usageOf = static function (ShiftType $row) use ($assigned): int {
            if ($row->mode === 'FLEXIBLE') {
                return 0;
            }

            return $assigned
                ->filter(static fn (Shift $s): bool => $s->start_time === $row->start_time && $s->end_time === $row->end_time)
                ->count();
        };

        $needle = strtolower(trim($this->search));

        $items = ShiftType::query()->get()
            ->filter(static function (ShiftType $r) use ($needle): bool {
                return $needle === '' || str_contains(strtolower("{$r->code} {$r->name}"), $needle);
            })
            ->values()
            ->all();

        usort($items, function (ShiftType $a, ShiftType $b): int {
            return match ($this->sortKey) {
                'id' => Hris::compareText($a->code, $b->code),
                'mode' => Hris::compareText($a->mode, $b->mode) ?: Hris::compareText($a->name, $b->name),
                default => Hris::compareText($a->name, $b->name),
            };
        });

        if ($this->sortDir === 'desc') {
            $items = array_reverse($items);
        }

        $rows = $this->paginateClamped($items);

        $usage = [];

        foreach ($rows as $row) {
            $usage[$row->getKey()] = $usageOf($row);
        }

        $editing = $this->editingId !== null ? ShiftType::query()->find($this->editingId) : null;
        $deleting = $this->deletingId !== null ? ShiftType::query()->find($this->deletingId) : null;

        return view('livewire.records.shift-types-page', [
            'rows' => $rows,
            'usage' => $usage,
            'editing' => $editing,
            'deleting' => $deleting,
        ]);
    }
}
