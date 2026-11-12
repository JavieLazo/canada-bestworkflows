<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Records\Support\Hris;
use App\Livewire\Records\Support\ShiftTypeRows;
use App\Livewire\Roster\Support\ScheduleReview;
use App\Modules\Timekeeping\Application\Commands\DeleteShiftType;
use App\Modules\Timekeeping\Application\Commands\SaveShiftType;
use App\Modules\Timekeeping\Application\Queries\ReferenceData;
use App\Modules\Timekeeping\Application\ScheduleImpactPending;
use App\Modules\Timekeeping\Application\ShiftTypeDraft;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
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
 * Permissions:
 *  - the PAGE needs RECORDS — route middleware;
 *  - READS are open to any session (GET /api/records/shift-types);
 *  - every WRITE needs all_data + RECORDS and passes the shift-shape policy
 *    (api/records/shift-types/route.ts), re-checked inside the owning
 *    command; editing a pattern that employees are rostered on ALSO needs
 *    all_data + ROSTER, because `SaveShiftType` re-copies the edit onto
 *    their schedule from the effective date (the write `AssignShift` gates
 *    on ROSTER);
 *  - an edit that reaches rostered employees carries an EFFECTIVE FROM date
 *    (today or later) and, when it affects anything worth a second look,
 *    is HELD for review: nothing is saved until it is confirmed with the
 *    token of exactly what was shown;
 *  - the "in use by N" count is an unscoped count of employees a copy of
 *    each pattern still reaches from today (`ReferenceData::shiftTypeUsage`),
 *    so the badge, the Delete guard and the re-copy count agree. DELIBERATE DIVERGENCE from
 *    the reference, which read the actor-scoped shift list.
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

    /**
     * The date an edit applies to rostered employees from; blank is the
     * earliest date possible. A draft: `SaveShiftType` refuses a past date
     * and decides whether today is still possible.
     */
    public string $formEffectiveFrom = '';

    /** The dialog's inline error (shift-types-page.tsx:404-414). */
    public ?string $formError = null;

    /** The token of the impact shown in the review dialog; the save carries it back. */
    #[Locked]
    public string $reviewToken = '';

    /**
     * The review dialog's lines, as the command reported them.
     *
     * @var array{takes_effect: string, moved: string, conflicts: list<string>, rest_days: list<string>, drafts: list<string>}
     */
    #[Locked]
    public array $review = ScheduleReview::EMPTY;

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
        $this->formEffectiveFrom = '';
        $this->reviewToken = '';
        $this->formError = null;
        $this->dialogOpen = true;
    }

    public function startEdit(string $id): void
    {
        $row = app(ReferenceData::class)->shiftType(app(Access::class), $id);

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
        $this->formEffectiveFrom = '';
        $this->reviewToken = '';
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
     * (ShiftTypeInput, schemas.ts:118-134), all re-run by `SaveShiftType`.
     *
     * TAKES NO ARGUMENT ON PURPOSE: an action's arguments come from the
     * client, and a confirmation passed that way would skip the review.
     */
    public function save(): void
    {
        $this->reviewToken = '';
        $this->persist(null);
    }

    /**
     * The review dialog's Confirm: the held save runs again, carrying the
     * token of what was shown. Copied and cleared first, so a second click
     * carries nothing.
     */
    public function confirmReview(): void
    {
        $token = $this->reviewToken;
        $this->reviewToken = '';
        if ($token === '') {
            return;
        }
        $this->modal('schedule-review')->close();
        $this->persist($token);
    }

    /** The review dialog's Go back: the editor again, as it was left. */
    public function reviewBack(): void
    {
        $this->reviewToken = '';
        $this->modal('schedule-review')->close();
        $this->dialogOpen = true;
    }

    /**
     * An edit also re-copies the pattern onto its rostered rows inside one
     * transaction, so the catches are ordered narrowest first — a held
     * review saves nothing and shows what the edit affects, a unique clash
     * is the duplicate toast, any other QueryException (the roster update
     * failing, rolled back with the pattern) is the generic one, and a
     * RuntimeException is the command's own refusal. The last three each
     * extend the next, so the order is load-bearing.
     */
    private function persist(?string $confirmed): void
    {
        if (trim($this->formName) === '') {
            $this->formError = 'Name is required.';

            return;
        }

        $this->formError = null;
        $access = app(Access::class);
        $from = trim($this->formEffectiveFrom) === '' ? null : trim($this->formEffectiveFrom);
        try {
            $message = app(SaveShiftType::class)->handle($access, $this->editingId, new ShiftTypeDraft($this->formName, $this->formMode, $this->formWorkDays, $this->formStartTime, $this->formEndTime, $this->formGrace, $this->formRequiredHours, $this->formHasBreak, $this->formBreakStart, $this->formBreakEnd, $this->formBreakMinutes, $this->formRequiresBreakPunch), $from, $confirmed);
        } catch (ScheduleImpactPending $pending) {
            $this->reviewToken = $pending->impact->token();
            $this->review = ScheduleReview::lines($pending->impact);
            $this->dialogOpen = false;
            $this->modal('schedule-review')->show();

            return;
        } catch (UniqueConstraintViolationException) {
            Flux::toast(variant: 'danger', text: 'A record with those details already exists.');
            $this->dialogOpen = true;

            return;
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The shift pattern could not be saved. Nothing was changed. Please try again.');
            $this->dialogOpen = true;

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
            $this->dialogOpen = true;

            return;
        }
        Flux::toast(variant: 'success', text: $message);
        $this->dialogOpen = false;
        $this->editingId = null;
        $this->formEffectiveFrom = '';
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
            $name = app(DeleteShiftType::class)->handle($access, $id);
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
        // DELIBERATE DIVERGENCE from shift-types-page.tsx:138-143, which
        // counted a pattern as in use when a scoped shift row matched its
        // start and end — and so read 0 for every FLEXIBLE pattern. Here it is
        // the unscoped count of rows linked by shift_type_id, the same rows
        // SaveShiftType rewrites, so the badge, the Delete guard and the
        // save toast's count agree.
        $access = app(Access::class);

        $usage = app(ReferenceData::class)->shiftTypeUsage($access);

        // Search and sort: one copy shared with the CSV export.
        $rows = $this->paginateClamped(ShiftTypeRows::list($access, $this->search, $this->sortKey, $this->sortDir));

        $editing = $this->editingId !== null ? app(ReferenceData::class)->shiftType(app(Access::class), $this->editingId) : null;
        $deleting = $this->deletingId !== null ? app(ReferenceData::class)->shiftType(app(Access::class), $this->deletingId) : null;

        return view('livewire.records.shift-types-page', [
            'rows' => $rows,
            'usage' => $usage,
            'editing' => $editing,
            // How many rostered rows the save will rewrite; 0 when adding.
            'editingUsage' => $this->editingId !== null ? ($usage[$this->editingId] ?? 0) : 0,
            'deleting' => $deleting,
        ]);
    }
}
