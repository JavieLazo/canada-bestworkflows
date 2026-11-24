{{--
    Pay Schedule — how the year is divided into pay periods and when each is
    paid. The page reads first: the company's schedule as six figures, each
    with one plain line under it, and the pay dates it gives for the coming
    periods. Edit opens a dialog on the same figures with the coming pay
    dates worked out from what is typed. Writes need Payroll access to all
    data; the command checks again. Nothing here prices a payroll.
--}}
@php
    use App\Livewire\Records\PaySchedulesPage;
    use App\Livewire\Records\Support\PayScheduleText;
    use App\Modules\Payroll\Domain\Schedules\PaySchedule;

    $dt = 'text-xs font-medium uppercase tracking-wide text-muted-foreground';
    $thisYear = (int) substr(\App\Support\LocalClock::todayKey(), 0, 4);
    $byWeeks = $schedule?->isByWeeks() ?? false;
    $formByWeeks = in_array($formFrequency, [PaySchedulesPage::EVERY_WEEK, PaySchedulesPage::EVERY_TWO_WEEKS], true);
@endphp
<div class="space-y-6">
    @include('livewire.payroll.partials.settings-header', [
        'activeView' => 'schedules',
        'title' => 'Pay Schedule',
        'description' => 'How the year is divided into pay periods, and when each period is paid.',
    ])

    <section class="hris-card overflow-hidden" aria-labelledby="schedule-card-heading" data-pay-schedule>
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
            <div class="min-w-0">
                <h2 id="schedule-card-heading" class="flex flex-wrap items-center gap-2 text-base font-bold">
                    Company Pay Schedule
                    @if ($schedule !== null)
                        <span class="pill pill-success">Set</span>
                    @else
                        <span class="pill pill-caution">Not Set Up</span>
                    @endif
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">Every new payroll takes its period and its pay date from this schedule.</p>
            </div>
            @if ($canEdit && $ready)
                <flux:button variant="outline" icon="pencil" wire:click="startEdit" class="print:hidden">{{ $schedule === null ? 'Set Up' : 'Edit' }}</flux:button>
            @endif
        </header>

        @unless ($ready)
            <p class="border-b border-border px-5 py-3 text-xs text-muted-foreground">The pay schedule can be set up once this installation's upgrade finishes.</p>
        @endunless
        @unless ($canEdit)
            <p class="border-b border-border px-5 py-3 text-xs text-muted-foreground">Your role can view the schedule. Changing it requires Payroll access to all data.</p>
        @endunless

        @if ($row !== null && $schedule === null)
            <p class="px-5 py-4 text-sm text-danger">This schedule holds figures payroll cannot read. Edit it to set it again.</p>
        @elseif ($schedule === null)
            <p class="px-5 py-6 text-sm text-muted-foreground">No pay schedule is set yet. Until one is, a payroll's dates are typed by hand.</p>
        @else
            <div class="grid gap-6 p-5 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <dl class="grid content-start gap-x-6 gap-y-5 sm:grid-cols-2" data-schedule-figures>
                    <div class="min-w-0">
                        <dt class="{{ $dt }}">Pay Periods</dt>
                        <dd class="mt-1">
                            <span class="text-lg font-semibold" data-schedule-frequency>{{ PayScheduleText::frequency($schedule) }}</span>
                            <span class="mt-1 block text-xs text-muted-foreground">{{ $schedule->usualPaysPerYear() }} pays in most years. Other choices: {{ implode(', ', array_map('mb_strtolower', array_values(array_diff(PaySchedulesPage::FREQUENCIES, [PayScheduleText::frequency($schedule)])))) }}.</span>
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="{{ $dt }}">A Period Runs</dt>
                        <dd class="mt-1">
                            <span class="text-lg font-semibold" data-schedule-runs>{{ PayScheduleText::runs($schedule) }}</span>
                            <span class="mt-1 block text-xs text-muted-foreground">
                                @if ($byWeeks)
                                    The first period started on <span class="num">{{ PayScheduleText::longDay($schedule->cycle['anchor']) }}</span>. Every period follows from that date.
                                @else
                                    Of every month.
                                @endif
                            </span>
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="{{ $dt }}">Pay Day</dt>
                        <dd class="mt-1">
                            <span class="text-lg font-semibold" data-schedule-pay-day>{{ PayScheduleText::payDay($schedule) }}</span>
                            <span class="mt-1 block text-xs text-muted-foreground">
                                @if ($byWeeks)
                                    {{ $schedule->cycle['pay_after_days'] === 1 ? '1 day' : $schedule->cycle['pay_after_days'].' days' }} after the last day of the period. Alberta allows up to 10 days.
                                @else
                                    A day the month does not have becomes the month's last day.
                                @endif
                            </span>
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="{{ $dt }}">If a Pay Day Is a Holiday</dt>
                        <dd class="mt-1">
                            <span class="text-lg font-semibold" data-schedule-holiday>{{ PayScheduleText::holidayMove($schedule) }}</span>
                            <span class="mt-1 block text-xs text-muted-foreground">The same goes for a Saturday or a Sunday.</span>
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="{{ $dt }}">Which Year a Pay Counts In</dt>
                        <dd class="mt-1">
                            <span class="text-lg font-semibold">The Year of Its Pay Day</span>
                            <span class="mt-1 block text-xs text-muted-foreground">A period that ends in December but is paid in January uses the new year's figures.</span>
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="{{ $dt }}">Reminders (Optional)</dt>
                        <dd class="mt-1">
                            <span class="text-lg font-semibold" data-schedule-reminders>{{ $schedule->deadlines === [] ? 'None' : PayScheduleText::deadlines($schedule) }}</span>
                            <span class="mt-1 block text-xs text-muted-foreground">A reminder tells payroll officers what is due some days before a pay day.</span>
                        </dd>
                    </div>
                </dl>

                <section class="min-w-0 rounded-md border border-border" aria-labelledby="next-pay-heading" data-next-pay-dates>
                    <div class="border-b border-border px-4 py-3">
                        <h3 id="next-pay-heading" class="text-sm font-bold">Next Pay Dates</h3>
                        <p class="mt-0.5 text-xs text-muted-foreground">What this schedule gives for the coming periods.</p>
                    </div>
                    <div class="hris-table-wrap">
                        <table class="hris-table">
                            <thead>
                                <tr>
                                    <th scope="col">Pay Period</th>
                                    <th scope="col">Paid On</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($coming as $period)
                                    <tr wire:key="coming-{{ $period['start'] }}">
                                        <td class="num whitespace-nowrap">{{ PayScheduleText::range($period['start'], $period['end']) }}</td>
                                        <td>
                                            <span class="num whitespace-nowrap font-medium">{{ PayScheduleText::payDate($period['pay_date'], $thisYear) }}</span>
                                            @if ($period['moved_from'] !== null)
                                                <span class="block text-xs text-muted-foreground">Moved from {{ $period['moved_from'] }}</span>
                                            @endif
                                            @if ($period['new_year'] !== null)
                                                <span class="block text-xs text-muted-foreground">First pay on the {{ $period['new_year'] }} figures</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
            <p class="border-t border-border px-5 py-3 text-xs text-muted-foreground">A change here is used by payrolls created from now on. Payrolls that already exist keep their dates.</p>
        @endif
    </section>

    @if ($schedule !== null && $byWeeks && $schedule->periodDays() === 14)
        <div class="flex items-start gap-3 rounded-md border border-border bg-muted px-4 py-3 text-sm" data-extra-pay-notice>
            <flux:icon.information-circle class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
            <p>
                @if ($extraPayYears !== [])
                    @foreach ($extraPayYears as $extraYear => $extraCount)
                        <b>{{ $extraYear }} has {{ $extraCount }} pay days.</b>
                    @endforeach
                    That is one more than usual. The system counts the pays itself, so the tax for each pay is worked out from the right number.
                @else
                    <b>A year with 27 pay days.</b>
                    About once every eleven years a two-week schedule has 27 pay days in one calendar year. The system counts the pays itself and tells you here at the start of such a year, because the tax for each pay is worked out from the number of pays in the year.
                @endif
            </p>
        </div>
    @endif

    <flux:modal
        wire:model.self="dialogOpen"
        class="w-full max-w-2xl"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'schedule-heading')"
    >
        {{-- The form and its preview render only while the dialog is open. --}}
        @if ($dialogOpen)
        <form class="grid gap-5" wire:submit="save" data-schedule-form>
            <div class="space-y-1">
                <flux:heading size="lg" id="schedule-heading">{{ $schedule === null ? 'Set Up Pay Schedule' : 'Edit Pay Schedule' }}</flux:heading>
                <p class="text-sm text-muted-foreground">Payrolls created after saving use these dates. Payrolls that already exist keep theirs.</p>
            </div>

            <div class="space-y-2">
                <x-kit.select label="Pay Periods" wire:model.live="formFrequency">
                    @foreach (PaySchedulesPage::FREQUENCIES as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </x-kit.select>
                <p class="text-xs text-muted-foreground">How often employees are paid. Every two weeks is the usual choice.</p>
            </div>

            @if ($formByWeeks)
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <flux:label for="schedule-anchor">First Day of a Period</flux:label>
                        <x-kit.date-field id="schedule-anchor" wire:model.live="formAnchor" aria-label="First day of a period" />
                        <p class="text-xs text-muted-foreground">Pick the first day of any one period. Every other period follows from it. Start on the first day of your work week so overtime is counted by whole weeks.</p>
                    </div>
                    <div class="space-y-2">
                        <flux:label for="schedule-pay-after">Pay Day, in Days After the Period Ends</flux:label>
                        <flux:input id="schedule-pay-after" type="number" min="0" max="{{ PaySchedule::MAX_PAY_AFTER_DAYS }}" step="1" class:input="num" wire:model.live.debounce.300ms="formPayAfter" />
                        <p class="text-xs text-muted-foreground">0 pays on the period's last day. 6 pays on the Friday after a period that ends on a Saturday. Alberta allows up to 10 days.</p>
                    </div>
                </div>
            @else
                <fieldset class="rounded-md border border-border p-4">
                    <legend class="px-1 text-sm font-semibold">Pay Days</legend>
                    <div class="grid gap-3">
                        @foreach ($formPayDays as $index => $payDay)
                            @php $label = $formFrequency === PaySchedulesPage::ONCE_A_MONTH ? 'The month' : ($index === 0 ? 'The 1st–15th' : 'The 16th–end'); @endphp
                            <div class="grid gap-3 sm:grid-cols-[10rem_1fr_7rem_1fr] sm:items-end" wire:key="pay-day-{{ $index }}">
                                <p class="text-sm font-medium sm:pb-2">{{ $label }}</p>
                                <x-kit.select label="Paid On" wire:model.live="formPayDays.{{ $index }}.kind">
                                    <flux:select.option value="{{ PaySchedule::PAY_PERIOD_END }}">The period's last day</flux:select.option>
                                    <flux:select.option value="{{ PaySchedule::PAY_DAY }}">A day of the month</flux:select.option>
                                </x-kit.select>
                                <div class="space-y-2">
                                    <flux:label for="pay-day-{{ $index }}">Day</flux:label>
                                    <flux:input id="pay-day-{{ $index }}" type="number" min="1" max="31" step="1" class:input="num" wire:model.live.debounce.300ms="formPayDays.{{ $index }}.day" aria-label="{{ $label }} pay day" :disabled="($payDay['kind'] ?? '') !== PaySchedule::PAY_DAY" />
                                </div>
                                <div class="flex items-center gap-3 sm:pb-2">
                                    <flux:switch id="pay-next-{{ $index }}" wire:model.live="formPayDays.{{ $index }}.next_month" :disabled="($payDay['kind'] ?? '') !== PaySchedule::PAY_DAY" />
                                    <flux:label for="pay-next-{{ $index }}">Of the following month</flux:label>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-muted-foreground">A day the month does not have (the 30th in February) becomes the month's last day.</p>
                </fieldset>
            @endif

            <div class="space-y-2">
                <x-kit.select label="If a Pay Day Is a Holiday or on a Weekend" wire:model.live="formHolidayRule">
                    <flux:select.option value="{{ PaySchedule::MOVE_BEFORE }}">It moves to the working day before</flux:select.option>
                    <flux:select.option value="{{ PaySchedule::MOVE_AFTER }}">It moves to the working day after</flux:select.option>
                    <flux:select.option value="{{ PaySchedule::MOVE_KEEP }}">It stays on that day</flux:select.option>
                </x-kit.select>
            </div>

            <fieldset class="rounded-md border border-border p-4">
                <legend class="px-1 text-sm font-semibold">Reminders (Optional)</legend>
                <p class="text-xs text-muted-foreground">Each one reminds payroll officers some days before a pay day, while the payroll for that period is not finalized yet.</p>
                <div class="mt-3 grid gap-3">
                    @foreach ($formDeadlines as $index => $deadline)
                        <div class="grid gap-3 sm:grid-cols-[1fr_9rem_auto] sm:items-end" wire:key="deadline-{{ $index }}">
                            <div class="space-y-2">
                                <flux:label for="deadline-label-{{ $index }}">What Is Due</flux:label>
                                <flux:input id="deadline-label-{{ $index }}" wire:model="formDeadlines.{{ $index }}.label" maxlength="60" placeholder="e.g. Timesheets checked" />
                            </div>
                            <div class="space-y-2">
                                <flux:label for="deadline-days-{{ $index }}">Days Before</flux:label>
                                <flux:input id="deadline-days-{{ $index }}" type="number" min="0" max="60" step="1" class:input="num" wire:model="formDeadlines.{{ $index }}.days" aria-label="Days before the pay day" />
                            </div>
                            <flux:button variant="ghost" icon="x-mark" wire:click="removeDeadline({{ $index }})" aria-label="Remove reminder" />
                        </div>
                    @endforeach
                </div>
                @if (count($formDeadlines) < PaySchedule::MAX_DEADLINES)
                    <flux:button variant="outline" icon="plus" size="sm" class="mt-3" wire:click="addDeadline">Add Reminder</flux:button>
                @endif
            </fieldset>

            <div class="rounded-md border border-border bg-muted px-4 py-3 text-sm" data-schedule-preview>
                <p class="font-medium">The Coming Pay Dates</p>
                @if ($preview === [])
                    <p class="mt-1 text-xs text-muted-foreground">Fill in the fields above to see the pay dates.</p>
                @else
                    <ul class="mt-1 space-y-1 text-xs text-muted-foreground">
                        @foreach ($preview as $period)
                            <li wire:key="preview-{{ $period['start'] }}">
                                <span class="num">{{ PayScheduleText::range($period['start'], $period['end']) }}</span>:
                                paid <span class="num">{{ PayScheduleText::payDate($period['pay_date'], $thisYear) }}</span>
                                @if ($period['moved_from'] !== null)
                                    (moved from {{ $period['moved_from'] }})
                                @endif
                                @foreach ($period['deadlines'] as $deadline)
                                    · {{ $deadline['label'] }} by <span class="num">{{ PayScheduleText::payDate($deadline['date'], $thisYear) }}</span>
                                @endforeach
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($formError !== null)
                <p role="alert" class="text-sm text-danger">{{ $formError }}</p>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="outline">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
        @endif
    </flux:modal>
</div>
