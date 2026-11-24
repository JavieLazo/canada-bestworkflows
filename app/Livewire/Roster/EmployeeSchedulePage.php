<?php

declare(strict_types=1);

namespace App\Livewire\Roster;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Livewire\Roster\Support\RosterDayText;
use App\Livewire\Roster\Support\ScheduleReview;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Timekeeping\Application\Commands\CancelAssignment;
use App\Modules\Timekeeping\Application\Commands\CancelScheduleChange;
use App\Modules\Timekeeping\Application\Commands\FillUnscheduledDates;
use App\Modules\Timekeeping\Application\Commands\SaveRosterDays;
use App\Modules\Timekeeping\Application\Commands\SaveRosterWeek;
use App\Modules\Timekeeping\Application\Queries\ReferenceData;
use App\Modules\Timekeeping\Application\Queries\RosterCalendar;
use App\Modules\Timekeeping\Application\Queries\RosterHistory;
use App\Modules\Timekeeping\Application\Queries\RosterLocations;
use App\Modules\Timekeeping\Application\Queries\RosterShifts;
use App\Modules\Timekeeping\Application\ScheduleImpact;
use App\Modules\Timekeeping\Application\ScheduleImpactPending;
use App\Modules\Timekeeping\Contracts\Views\RosterDayView;
use App\Modules\Timekeeping\Contracts\Views\RosterLocationView;
use App\Modules\Timekeeping\Contracts\Views\RosterShiftView;
use App\Modules\Timekeeping\Domain\Roster\CustomHours;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Modules\Timekeeping\Domain\Roster\WorkWeek;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\HrisFormat;
use App\Support\LocalClock;
use Closure;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One employee's month on the Shift Roster — reached by a row of the week
 * table — and the one place a schedule is changed:
 *
 *   - THE CALENDAR shows each date's shift, hours and where it is worked,
 *     today marked, approved leave, and the short-rest warning
 *     (`RosterCalendar`).
 *   - PICKING DATES (any dates, not only a run) opens the side card: one
 *     shift and one place for all of them, saved by `SaveRosterDays` as
 *     temporary schedules over the weekly one. A date on whole-day leave
 *     cannot be picked, nor a past date — except a past date that had NO
 *     SHIFT while employed, which is picked on its own (never with today or
 *     later) and saved by `FillUnscheduledDates`: those dates hold payroll
 *     until HR says what they were.
 *   - A picked date inside a temporary schedule of several dates offers
 *     removing that whole schedule (`CancelAssignment`), and a Default
 *     schedule saved for a later date can be cancelled before it starts
 *     (`CancelScheduleChange`).
 *   - DEFAULT SCHEDULE is the employee's usual week, a shift and a place per
 *     weekday from an effective date (`SaveRosterWeek`). With no shift
 *     assigned before, every day needs a shift or Rest day.
 *   - Save refuses a picked date whose schedule changed after the page drew
 *     it (`$shownStamps`), so nobody saves over a change they did not see.
 *
 * Every change is dated and guarded by the commands: never the past, today
 * only until today's cutoff, the night before left as it was. A change they
 * hold — moved to the next date, reaching a payroll draft, flipping a rest
 * day inside a temporary schedule — opens the shared review dialog, and
 * `confirmReview()` saves with the token of exactly what was shown.
 *
 * Permissions: the PAGE needs ROSTER (route) and the employee in the
 * actor's operational directory (404 otherwise); the DAYS are read under
 * the roster's scope; every WRITE needs all_data + ROSTER, checked here
 * and again inside the command. Without it the calendar is read-only.
 */
#[Layout('layouts.app')]
#[Title('Shift Roster')]
class EmployeeSchedulePage extends Component
{
    /** The Default schedule's custom-hours slots: as many sets as one week can use, one per weekday. */
    public const CUSTOM_SETS = 7;

    /**
     * The employee whose month this is. Id-bearing → #[Locked]: mount()
     * sets it only after the actor-scoped directory resolves it, and
     * render() resolves it again on every request.
     */
    #[Locked]
    public string $employeeId = '';

    /**
     * The month shown, 'Y-m'; blank for this month. Plain state from the
     * URL — render() reads anything that is not a month as this month.
     */
    #[Url(as: 'month', except: '')]
    public string $month = '';

    /**
     * The dates picked on the calendar. NOT Locked on purpose: a draft the
     * day buttons edit through `pick()`, which admits only a date of the
     * month shown, today or later and not on whole-day leave, or a past
     * date with no shift. The commands check every date again for what they
     * enforce — a real date, today or later (or before today with no shift),
     * the safeguards — before anything is written; the month and the leave
     * are the screen's own limits.
     *
     * @var array<array-key, mixed>
     */
    public array $picked = [];

    /**
     * What each pickable date read when the page last drew it ('Y-m-d' =>
     * `stamp()`), so Save can tell a date changed by someone else since.
     * Locked: only render() sets it.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $shownStamps = [];

    /** The side card's shift: DEFAULT (the weekly schedule), REST, a pattern id, CUSTOM, or KEEP (a deleted pattern's saved hours). A draft the command validates. */
    public string $dayShift = 'DEFAULT';

    /**
     * NOT Locked: the side card's custom hours, read when its shift is
     * CUSTOM; the command validates them.
     *
     * @var array<array-key, mixed>
     */
    public array $dayCustom = CustomHours::BLANK;

    /** The side card's place: DEFAULT (the weekly location), WFH or a site id. A draft the command validates. */
    public string $dayLocation = 'DEFAULT';

    /**
     * The Default schedule dialog's seven location selects: weekday 0
     * Sunday … 6 Saturday => 'DEFAULT' (the Assigned Worksite), 'WFH', or a
     * work_sites id. NOT Locked on purpose — the wire:model draft the
     * selects edit: the save shapes it to seven strings and `SaveRosterWeek`
     * re-validates every key and value before anything is written.
     *
     * @var array<array-key, mixed>
     */
    public array $locationDays = [];

    /**
     * NOT Locked: the Default schedule dialog's weekday shift selects, a
     * wire:model draft. The command re-validates every weekday and pattern
     * id. KEEP retains a saved pattern whose catalog entry was deleted;
     * DEFAULT and REST are explicit choices.
     *
     * @var array<array-key, mixed>
     */
    public array $shiftDays = [];

    /**
     * NOT Locked: hours typed in the Default schedule dialog instead of
     * picked from Shifting Settings, a draft each day's 'CUSTOM:n' choice
     * points into. While the dialog holds a draft it is always CUSTOM_SETS
     * slots, drawn on the page, so the browser adds and removes a set with
     * no request (`$customSlots` says which are in use). The command
     * validates every set it is sent.
     *
     * @var array<array-key, mixed>
     */
    public array $customHours = [];

    /**
     * NOT Locked: which custom-hours slots are in use, a draft the browser
     * edits (Add hours, remove). A day's 'CUSTOM:n' is read only from a slot
     * listed here, so a removed set is never saved.
     *
     * @var array<array-key, mixed>
     */
    public array $customSlots = [];

    /** The Default schedule's Effective from: blank for the earliest it can start. A draft the command judges. */
    public string $weekFrom = '';

    /** Which save the review dialog is holding: days or week. */
    #[Locked]
    public string $reviewAction = '';

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

    /**
     * What the held save sends — the dates and choices, or the week —
     * exactly as the review was computed from, so Confirm saves what was
     * shown whatever the drafts became after (the Default schedule's draft
     * is cleared when its dialog closes). Locked: only hold() sets it.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $reviewInput = [];

    public function mount(string $employee): void
    {
        $found = app(OperationalDirectory::class)->find(app(Access::class), $employee);
        abort_if($found === null, 404);

        $this->employeeId = (string) $found->id;
    }

    public function previousMonth(): void
    {
        $this->month = substr(PayrollPeriod::addCalendarDays(RosterDayText::monthStart($this->month), -1), 0, 7);
        $this->clearPicks();
    }

    public function nextMonth(): void
    {
        $first = RosterDayText::monthStart($this->month);
        $this->month = substr(PayrollPeriod::addCalendarDays(PayrollPeriod::calendarMonthRange($first)['to'], 1), 0, 7);
        $this->clearPicks();
    }

    /**
     * Pick a date, or unpick a picked one. The date is a client argument, so
     * it is admitted only when the calendar would offer it: in the month
     * shown, today or later and not on whole-day leave, or a past date with
     * no shift while employed. A past date and a later one are never picked
     * together: picking one kind starts a new selection. The first date
     * picked fills the side card with what it has now.
     */
    public function pick(string $date): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::ROSTER) || ! PayrollPeriod::isPayrollDate($date)
            || substr($date, 0, 7) !== substr(RosterDayText::monthStart($this->month), 0, 7)) {
            return;
        }
        $day = $this->day($access, $date);
        $employee = app(OperationalDirectory::class)->find($access, $this->employeeId);
        if ($day === null || $employee === null || ! self::pickable($date, $day, LocalClock::todayKey(), $employee)) {
            return;
        }

        $today = LocalClock::todayKey();
        $picked = $this->pickedDates();
        if ($picked !== [] && ($picked[0] < $today) !== ($date < $today)) {
            $picked = [];
        }
        $picked = in_array($date, $picked, true)
            ? array_values(array_diff($picked, [$date]))
            : [...$picked, $date];
        sort($picked);
        $this->picked = $picked;

        if ($picked === []) {
            $this->resetDayDraft();
        } elseif (count($picked) === 1) {
            $this->startCardFrom($picked[0] === $date ? $day : $this->day($access, $picked[0]));
        }
    }

    /** The side card's Cancel: nothing picked, the card closed. */
    public function clearPicks(): void
    {
        $this->picked = [];
        $this->resetDayDraft();
    }

    /** The side card's Save, behind the all_data + ROSTER gate the command enforces. */
    public function saveDays(): void
    {
        if ($this->pickedDates() === []) {
            return;
        }

        $this->saveDaysWith(null, null);
    }

    /**
     * Open the Default schedule dialog seeded from the stored week: each
     * weekday's place and shift — DEFAULT where the weekday follows the
     * employee's standing default shift, or blank (to be chosen) where it
     * has no shift at all — and the Assigned Worksite.
     */
    public function openDefaultSchedule(): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::ROSTER)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }
        $unset = $this->hasStandingShift($access) ? 'DEFAULT' : '';

        $days = array_fill(0, 7, LocationKind::DEFAULT);
        foreach (app(RosterLocations::class)->weekly($access, $this->employeeId) as $weekday => $view) {
            $days[$weekday] = $view->kind === LocationKind::WFH
                ? LocationKind::WFH
                : ($view->site_id ?? LocationKind::DEFAULT);
        }

        $this->weekFrom = '';
        $this->locationDays = $days;
        $this->customHours = array_fill(0, self::CUSTOM_SETS, CustomHours::BLANK);
        $this->customSlots = [];
        $patternIds = app(ReferenceData::class)->shiftTypes($access)->pluck('id')->all();
        $this->shiftDays = array_fill(0, 7, $unset);
        foreach (app(RosterShifts::class)->weekly($access, $this->employeeId) as $weekday => $view) {
            $this->shiftDays[$weekday] = $this->savedChoice($view, $patternIds);
        }

        $this->modal('default-schedule')->show();
    }

    /**
     * One more set of custom hours for the Default schedule's days to pick:
     * the first slot not in use, blank. The dialog does the same in the
     * browser; this is its server twin.
     */
    public function addCustomHours(): void
    {
        $slots = $this->usedSlots();
        for ($slot = 0; $slot < self::CUSTOM_SETS; $slot++) {
            if (! in_array($slot, $slots, true)) {
                $this->customHours = array_replace(array_fill(0, self::CUSTOM_SETS, CustomHours::BLANK), $this->customHours, [$slot => CustomHours::BLANK]);
                $slots[] = $slot;
                sort($slots);
                $this->customSlots = $slots;

                return;
            }
        }
    }

    /**
     * Remove one set of custom hours: its slot is freed and blanked, and a
     * day that picked it goes back to the standing default shift, or to
     * blank (to be chosen) when there is none. The other sets keep their
     * slots, so no other day's pick moves. The dialog does the same in the
     * browser; this is its server twin.
     */
    public function removeCustomHours(int $index): void
    {
        $slots = $this->usedSlots();
        if (! in_array($index, $slots, true)) {
            return;
        }
        $this->customSlots = array_values(array_diff($slots, [$index]));
        $this->customHours[$index] = CustomHours::BLANK;
        $unset = $this->hasStandingShift(app(Access::class)) ? 'DEFAULT' : '';
        $this->shiftDays = array_map(static fn (mixed $choice): mixed => $choice === 'CUSTOM:'.$index ? $unset : $choice, $this->shiftDays);
    }

    /**
     * The Default schedule dialog's Save, behind the all_data + ROSTER gate
     * the command enforces. A day still blank is named first: the command
     * would refuse it anyway, one day at a time.
     */
    public function saveDefaultSchedule(): void
    {
        if ($this->shiftDays === [] && $this->locationDays === []) {
            return;
        }
        $blank = [];
        foreach (WorkWeek::weekdays() as $weekday) {
            if (($this->shiftDays[$weekday] ?? '') === '') {
                $blank[] = HrisFormat::weekday($weekday, short: false);
            }
        }
        if ($blank !== []) {
            $last = array_pop($blank);
            Flux::toast(variant: 'danger', text: 'Choose a shift or Rest day for '.($blank === [] ? $last : implode(', ', $blank).' and '.$last).'. A day with no shift records no Time In and reads absent.');

            return;
        }

        $this->saveWeekWith(null, null);
    }

    /**
     * The review dialog's Go back: back to the editor that raised it. For
     * the Default schedule that is its dialog again with the week exactly
     * as it was sent — closing the dialog for the review dropped the
     * drafts, so they are rebuilt from the held input. Picked dates were
     * never dropped; every other review just closes.
     */
    public function backToEditor(): void
    {
        $action = $this->reviewAction;
        $input = $this->reviewInput;
        $this->reviewAction = '';
        $this->reviewToken = '';
        $this->reviewInput = [];
        $this->modal('schedule-review')->close();
        if ($action !== 'week' || $input === [] || ! app(Access::class)->mayReadAllData(ModuleCode::ROSTER)) {
            return;
        }

        $this->customHours = array_fill(0, self::CUSTOM_SETS, CustomHours::BLANK);
        $this->customSlots = [];
        $shifts = [];
        foreach ((array) ($input['shifts'] ?? []) as $weekday => $choice) {
            if (is_array($choice)) {
                $shifts[$weekday] = 'CUSTOM:'.$this->slotFor($choice);
            } else {
                $shifts[$weekday] = is_string($choice) ? $choice : '';
            }
        }
        $this->shiftDays = $shifts;
        $this->locationDays = (array) ($input['places'] ?? []);
        $this->weekFrom = is_string($input['from'] ?? null) ? $input['from'] : '';
        $this->modal('default-schedule')->show();
    }

    /**
     * Remove a whole temporary schedule from one of its picked dates: before
     * it begins entirely, once begun from the next date it can still change
     * (`CancelAssignment`). The id is a client argument, so it must be the
     * temporary schedule of a date of the month shown.
     */
    public function removeTemporary(string $assignmentId): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::ROSTER)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }
        if (! in_array($assignmentId, $this->monthTemporaries($access), true)) {
            Flux::toast(variant: 'danger', text: 'That temporary schedule is no longer on this month. Pick the date again.');

            return;
        }

        $this->removeTemporaryWith(null, ['id' => $assignmentId]);
    }

    /**
     * Cancel a Default schedule saved for a later date, before it starts
     * (`CancelScheduleChange`). The id is a client argument, so it must be
     * one of this employee's changes still to come.
     */
    public function cancelChange(string $changeId): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::ROSTER)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }
        if (! array_key_exists($changeId, $this->upcomingChanges($access))) {
            Flux::toast(variant: 'danger', text: 'That change has already started or was cancelled.');

            return;
        }

        $this->cancelChangeWith(null, ['id' => $changeId]);
    }

    /**
     * The review dialog's Confirm: the held save runs again, carrying the
     * token of what was shown. Copied and cleared first, so a second click
     * carries nothing.
     */
    public function confirmReview(): void
    {
        $action = $this->reviewAction;
        $token = $this->reviewToken;
        $input = $this->reviewInput;
        $this->reviewAction = '';
        $this->reviewToken = '';
        $this->reviewInput = [];
        if ($action === '' || $token === '' || $input === []) {
            return;
        }

        $this->modal('schedule-review')->close();
        match ($action) {
            'days' => $this->saveDaysWith($token, $input),
            'week' => $this->saveWeekWith($token, $input),
            'remove-temporary' => $this->removeTemporaryWith($token, $input),
            'cancel-change' => $this->cancelChangeWith($token, $input),
            default => null,
        };
    }

    public function render(): View
    {
        $access = app(Access::class);
        $directory = app(OperationalDirectory::class);
        $employee = $directory->find($access, $this->employeeId);
        abort_if($employee === null, 404);

        $today = LocalClock::todayKey();
        $first = RosterDayText::monthStart($this->month);
        $last = PayrollPeriod::calendarMonthRange($first)['to'];
        $calendar = app(RosterCalendar::class);
        // The day before the 1st too: the note behind the 1st's short-rest
        // warning names the shift it follows.
        $days = $calendar->forEmployees($access, [$this->employeeId], CalendarDays::previous($first), $last)[$this->employeeId] ?? [];
        $thisWeek = RosterDayText::week(RosterDayText::weekStart($today));
        // This week from the month already read when it lies inside it.
        $usualWeek = array_intersect_key($days, array_flip($thisWeek));
        if (count($usualWeek) !== 7) {
            $usualWeek = $calendar->forEmployees($access, [$this->employeeId], $thisWeek[0], $thisWeek[6])[$this->employeeId] ?? [];
        }
        $sites = app(OrganizationDirectory::class)->sites($access)->keyBy('id');
        $reference = app(ReferenceData::class);
        $patterns = $reference->shiftTypes($access, ordered: true);
        $canEdit = $access->mayReadAllData(ModuleCode::ROSTER);

        $picked = array_values(array_filter($this->pickedDates(), static fn (string $date): bool => isset($days[$date])));

        // What the side card starts on for each date that can be picked, so
        // the browser fills it the moment a date is picked, with no request.
        // A past date with no shift starts on nothing chosen: there is no
        // usual schedule for it to go back to.
        $card = [];
        $stamps = [];
        foreach ($days as $date => $day) {
            if (! $canEdit || $date < $first || ! self::pickable($date, $day, $today, $employee)) {
                continue;
            }
            $past = $date < $today;
            $temporary = ! $past && $day->temporary_id !== null && $day->temporary_from !== $day->temporary_to && $day->temporary_to >= $today
                ? ['id' => $day->temporary_id, 'span' => RosterDayText::short((string) $day->temporary_from).' – '.RosterDayText::short((string) $day->temporary_to).', '.substr((string) $day->temporary_to, 0, 4)]
                : null;
            $card[$date] = [
                'heading' => RosterDayText::longDay($date),
                'past' => $past,
                'shift' => $past ? '' : $day->shift_choice,
                'custom' => $day->custom_hours ?? CustomHours::BLANK,
                'location' => $day->location_choice,
                'usual' => $day->usual,
                'place' => RosterDayText::place($day, $sites, $employee->work_site_id),
                'keep' => $day->shift_choice === 'KEEP' ? $day->hours : null,
                'temporary' => $temporary,
            ];
            $stamps[$date] = self::stamp($day);
        }
        $this->shownStamps = $stamps;

        // The Default schedule dialog's own reads, only while it holds a draft.
        $standing = null;
        $savedShifts = [];
        $savedPlaces = [];
        $restDays = [];
        $customOptions = [];
        $standingWeek = [];
        if ($this->shiftDays !== []) {
            $standing = $reference->shiftsFor($access, [$this->employeeId])->keyBy('employee_id')->get($this->employeeId);
            $savedShifts = app(RosterShifts::class)->weekly($access, $this->employeeId);
            $savedPlaces = array_map(static fn (RosterLocationView $row): ?string => $row->site_id, app(RosterLocations::class)->weekly($access, $this->employeeId));
            $draftWorks = [];
            foreach ($this->shiftDays as $weekday => $choice) {
                if ($choice === 'REST') {
                    $draftWorks[(int) $weekday] = false;
                } elseif (is_string($choice) && $choice !== 'DEFAULT') {
                    $draftWorks[(int) $weekday] = true;
                }
            }
            // With no assigned shift a day left on it has no shift, not a
            // rest day: only a Rest day choice rests.
            $restDays = $standing === null
                ? array_keys(array_filter($draftWorks, static fn (bool $works): bool => ! $works))
                : array_values(array_diff([0, 1, 2, 3, 4, 5, 6], Hris::workingWeekdays($standing->work_days, $draftWorks)));
            // Every slot, used or not: the browser shows a set it adds without
            // asking for the options again.
            for ($slot = 0; $slot < self::CUSTOM_SETS; $slot++) {
                $customOptions['CUSTOM:'.$slot] = Hris::customHoursLabel($this->customHours[$slot] ?? CustomHours::BLANK);
            }
            $standingWeek = $standing === null ? [] : Hris::workingWeekdays($standing->work_days);
        }

        return view('livewire.roster.employee-schedule-page', [
            'employee' => $employee,
            'name' => Hris::employeeName($employee),
            'place' => $directory->placeLabels($access, [$this->employeeId])[$this->employeeId] ?? '',
            'usualWeek' => RosterDayText::usualWeek($usualWeek),
            'first' => $first,
            'last' => $last,
            'days' => $days,
            'today' => $today,
            'canEdit' => $canEdit,
            'picked' => $picked,
            'card' => $card,
            'cardKeeps' => array_any($card, static fn (array $day): bool => $day['keep'] !== null),
            'cardSites' => array_values(array_unique(array_filter(array_column($card, 'location'), static fn (string $choice): bool => ! in_array($choice, ['DEFAULT', LocationKind::WFH], true)))),
            'patterns' => $patterns,
            'sites' => $sites,
            'assignedSite' => $employee->work_site_id === null ? null : $sites->get($employee->work_site_id),
            'standing' => $standing,
            'savedShifts' => $savedShifts,
            'savedPlaces' => $savedPlaces,
            'restDays' => $restDays,
            'customOptions' => $customOptions,
            'customSlots' => $this->usedSlots(),
            'standingWeek' => $standingWeek,
            'upcoming' => $canEdit ? $this->upcomingChanges($access) : [],
        ]);
    }

    /**
     * The picked dates' save: the drafts, or the input a confirmed review
     * held, with the token of that review.
     *
     * @param  array<string, mixed>|null  $held
     */
    private function saveDaysWith(?string $confirmed, ?array $held): void
    {
        $this->guarded(
            'days',
            $held ?? fn (Access $access): array => [
                ...$this->admissibleDates($access),
                'shift' => $this->dayShift === 'CUSTOM' ? $this->dayCustom : $this->dayShift,
                'location' => $this->dayLocation,
            ],
            // Past dates with no shift are filled; today and later dates
            // change under the usual safeguards and may be held for review.
            fn (Access $access, array $in): string => ($in['past'] ?? false) === true
                ? app(FillUnscheduledDates::class)->handle($access, $this->employeeId, $in['dates'], $in['shift'], $in['location'])
                : app(SaveRosterDays::class)->handle($access, $this->employeeId, $in['dates'], $in['shift'], $in['location'], $confirmed),
        );
    }

    /** @param  array<string, mixed>  $input */
    private function removeTemporaryWith(?string $confirmed, array $input): void
    {
        $this->guarded('remove-temporary', $input, static fn (Access $access, array $in): string => app(CancelAssignment::class)->handle($access, (string) $in['id'], $confirmed));
    }

    /** @param  array<string, mixed>  $input */
    private function cancelChangeWith(?string $confirmed, array $input): void
    {
        $this->guarded('cancel-change', $input, static fn (Access $access, array $in): string => app(CancelScheduleChange::class)->handle($access, (string) $in['id'], $confirmed));
    }

    /**
     * The Default schedule's save: the drafts, or the input a confirmed
     * review held, with the token of that review.
     *
     * @param  array<string, mixed>|null  $held
     */
    private function saveWeekWith(?string $confirmed, ?array $held): void
    {
        $this->guarded(
            'week',
            $held ?? function (): array {
                // Shaped to exactly seven strings: a missing, blank or
                // non-string entry reads DEFAULT, and everything else passes
                // through for the command to judge.
                $places = [];
                foreach (range(0, 6) as $weekday) {
                    $value = $this->locationDays[$weekday] ?? LocationKind::DEFAULT;
                    $value = is_string($value) ? trim($value) : '';
                    $places[$weekday] = $value === '' ? LocationKind::DEFAULT : $value;
                }

                // Every custom-hours pick replaced by the hours it names.
                return [
                    'shifts' => array_map($this->commandChoice(...), $this->shiftDays),
                    'places' => $places,
                    'from' => trim($this->weekFrom) === '' ? null : trim($this->weekFrom),
                ];
            },
            fn (Access $access, array $in): string => app(SaveRosterWeek::class)->handle($access, $this->employeeId, $in['shifts'], $in['places'], $in['from'], $confirmed),
        );
    }

    /**
     * Run one of this screen's saves behind a fresh Access. A refusal toasts
     * the command's own sentence. A change that needs reviewing opens the
     * review dialog and holds the input it was computed from.
     *
     * @param  array<string, mixed>|Closure(Access): array<string, mixed>  $input  what the save sends, or how to read it from the drafts
     * @param  Closure(Access, array<string, mixed>): string  $save  the command call, answering its message
     */
    private function guarded(string $action, array|Closure $input, Closure $save): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::ROSTER)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }

        try {
            $in = is_array($input) ? $input : $input($access);
            try {
                $message = $save($access, $in);
            } catch (ScheduleImpactPending $pending) {
                $this->hold($action, $pending->impact, $in);

                return;
            }
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The schedule could not be saved. Please try again.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->settle($action);
        Flux::toast(variant: 'success', text: $message);
    }

    /**
     * Hold a save for review: what it affects, in the person's words, and
     * the input it was computed from.
     *
     * @param  array<string, mixed>  $input
     */
    private function hold(string $action, ScheduleImpact $impact, array $input): void
    {
        $this->reviewAction = $action;
        $this->reviewToken = $impact->token();
        $this->reviewInput = $input;
        $this->review = ScheduleReview::lines($impact, []);

        $this->modal('default-schedule')->close();
        $this->modal('schedule-review')->show();
    }

    /** Clear what a saved change staged, and close its dialog. */
    private function settle(string $action): void
    {
        if ($action === 'days' || $action === 'remove-temporary') {
            $this->clearPicks();

            return;
        }
        if ($action !== 'week') {
            return;
        }
        $this->locationDays = [];
        $this->shiftDays = [];
        $this->customHours = [];
        $this->customSlots = [];
        $this->weekFrom = '';
        $this->modal('default-schedule')->close();
    }

    /**
     * The side card starts on what the date has now: its own temporary shift
     * and place, else the weekly schedule's — and a past date with no shift
     * on no shift chosen, as there is no usual schedule to go back to.
     */
    private function startCardFrom(?RosterDayView $day): void
    {
        if ($day === null) {
            $this->resetDayDraft();

            return;
        }
        $this->dayShift = $day->date < LocalClock::todayKey() ? '' : $day->shift_choice;
        $this->dayCustom = $day->custom_hours ?? CustomHours::BLANK;
        $this->dayLocation = $day->location_choice;
    }

    private function resetDayDraft(): void
    {
        $this->dayShift = 'DEFAULT';
        $this->dayCustom = CustomHours::BLANK;
        $this->dayLocation = LocationKind::DEFAULT;
    }

    /**
     * The picked dates, held to what the calendar offers — the month shown,
     * today or later and not on whole-day leave, or past with no shift while
     * employed, never both kinds at once — because the browser picks them
     * without asking the server; a date it would not offer refuses the save
     * rather than being dropped from it unseen. A date whose schedule
     * changed since the page drew it refuses the save too: the page now
     * shows the change, and saving again saves over what was seen.
     *
     * @return array{dates: list<string>, past: bool}
     */
    private function admissibleDates(Access $access): array
    {
        $dates = $this->pickedDates();
        $first = RosterDayText::monthStart($this->month);
        $days = $dates === [] ? [] : app(RosterCalendar::class)->forEmployees($access, [$this->employeeId], $first, PayrollPeriod::calendarMonthRange($first)['to'])[$this->employeeId] ?? [];
        $employee = app(OperationalDirectory::class)->find($access, $this->employeeId);
        $today = LocalClock::todayKey();
        $past = $dates !== [] && $dates[0] < $today;
        $changed = [];
        foreach ($dates as $date) {
            $day = $days[$date] ?? null;
            if ($day === null || $employee === null || ! self::pickable($date, $day, $today, $employee)) {
                throw new \RuntimeException('Some of the picked dates can no longer be changed. Pick the dates again.');
            }
            if (($date < $today) !== $past) {
                throw new \RuntimeException('Past dates with no shift are saved on their own. Pick only past dates, or only today and later.');
            }
            if (($this->shownStamps[$date] ?? null) !== self::stamp($day)) {
                $changed[] = $date;
            }
        }
        if ($changed !== []) {
            throw new \RuntimeException('Someone changed '.RosterDayText::compact($changed).' after this page was loaded. The calendar now shows that change; check it, then save again.');
        }

        return ['dates' => $dates, 'past' => $past];
    }

    /**
     * Whether the calendar offers a date for picking: today or later and not
     * on whole-day leave; or before today, with no shift, inside the
     * employee's employment — the dates that hold payroll until filled.
     */
    private static function pickable(string $date, RosterDayView $day, string $today, DirectoryEmployee $employee): bool
    {
        if ($date >= $today) {
            return $day->leave === null || $day->half_day;
        }
        $hired = $employee->date_hired?->format('Y-m-d');
        $resigned = $employee->date_resigned?->format('Y-m-d');

        return ! $day->rostered && ($hired === null || $date >= $hired) && ($resigned === null || $date <= $resigned);
    }

    /** What a date reads, in short: changes whenever its shift, hours, place or leave do. */
    private static function stamp(RosterDayView $day): string
    {
        return substr(md5(serialize([
            $day->rostered, $day->works, $day->label, $day->hours, $day->shift_choice, $day->custom_hours,
            $day->location_choice, $day->place_kind, $day->place_site_id, $day->leave, $day->half_day, $day->temporary_id,
        ])), 0, 12);
    }

    /** Whether the employee has a standing default shift, which a weekday left to it follows. */
    private function hasStandingShift(Access $access): bool
    {
        return app(ReferenceData::class)->shiftsFor($access, [$this->employeeId])->keyBy('employee_id')->get($this->employeeId) !== null;
    }

    /**
     * The temporary schedules deciding a date of the month shown.
     *
     * @return list<string>
     */
    private function monthTemporaries(Access $access): array
    {
        $first = RosterDayText::monthStart($this->month);
        $days = app(RosterCalendar::class)->forEmployees($access, [$this->employeeId], $first, PayrollPeriod::calendarMonthRange($first)['to'])[$this->employeeId] ?? [];

        return array_values(array_unique(array_filter(array_map(static fn (RosterDayView $day): ?string => $day->temporary_id, $days))));
    }

    /**
     * The employee's Default schedule changes saved for a later date and
     * still cancellable, earliest first: id => when and what they set.
     *
     * @return array<string, array{from: string, sets: list<string>}>
     */
    private function upcomingChanges(Access $access): array
    {
        $out = [];
        foreach (app(RosterHistory::class)->forEmployee($access, $this->employeeId)['changes'] as $change) {
            if ($change->state === 'UPCOMING' && $change->can_cancel) {
                $out[$change->id] = ['from' => $change->effective_from, 'sets' => $change->sets];
            }
        }
        uasort($out, static fn (array $a, array $b): int => strcmp($a['from'], $b['from']));

        return $out;
    }

    private function day(Access $access, string $date): ?RosterDayView
    {
        return app(RosterCalendar::class)->forEmployees($access, [$this->employeeId], $date, $date)[$this->employeeId][$date] ?? null;
    }

    /**
     * The picked dates as strings, sorted, each once.
     *
     * @return list<string>
     */
    private function pickedDates(): array
    {
        $dates = array_values(array_unique(array_filter($this->picked, static fn (mixed $date): bool => is_string($date) && PayrollPeriod::isPayrollDate($date))));
        sort($dates);

        return $dates;
    }

    /**
     * The select value that reproduces a saved day: REST, custom hours (in
     * one slot, however many days use them), the pattern while it exists,
     * else KEEP for the copy a deleted pattern left.
     *
     * @param  array<array-key, mixed>  $patternIds
     */
    private function savedChoice(RosterShiftView $view, array $patternIds): string
    {
        if ($view->is_rest_day) {
            return 'REST';
        }
        if (CustomHours::isCustom($view->shift_type_id, $view->shift_name, $view->shift_data)) {
            return 'CUSTOM:'.$this->slotFor(CustomHours::input($view->shift_data));
        }

        return $view->shift_type_id !== null && in_array($view->shift_type_id, $patternIds, true) ? $view->shift_type_id : 'KEEP';
    }

    /**
     * The slot holding these custom hours: one already in use with exactly
     * them, else the next free one, filled. A week has at most seven days,
     * so it never runs out.
     *
     * @param  array<array-key, mixed>  $hours
     */
    private function slotFor(array $hours): int
    {
        $slots = $this->usedSlots();
        foreach ($slots as $slot) {
            if (($this->customHours[$slot] ?? null) === $hours) {
                return $slot;
            }
        }
        $slot = 0;
        while (in_array($slot, $slots, true)) {
            $slot++;
        }
        $this->customHours[$slot] = $hours;
        $slots[] = $slot;
        sort($slots);
        $this->customSlots = $slots;

        return $slot;
    }

    /**
     * The custom-hours slots in use, as the draft says: whole numbers within
     * CUSTOM_SETS, each once, in order.
     *
     * @return list<int>
     */
    private function usedSlots(): array
    {
        $slots = [];
        foreach ($this->customSlots as $slot) {
            if (is_numeric($slot) && (int) $slot >= 0 && (int) $slot < self::CUSTOM_SETS) {
                $slots[(int) $slot] = (int) $slot;
            }
        }
        ksort($slots);

        return array_values($slots);
    }

    /**
     * One day's select value as the command reads it: a 'CUSTOM:n' pick
     * becomes the hours of slot n, which must be in use.
     *
     * @return string|array<array-key, mixed>
     */
    private function commandChoice(mixed $choice): string|array
    {
        if (is_string($choice) && str_starts_with($choice, 'CUSTOM:')) {
            $slot = (int) substr($choice, 7);
            $hours = in_array($slot, $this->usedSlots(), true) ? ($this->customHours[$slot] ?? null) : null;
            if (! is_array($hours)) {
                throw new \RuntimeException('Those custom hours were removed. Pick a shift again.');
            }

            return $hours;
        }

        return is_string($choice) ? $choice : '';
    }
}
