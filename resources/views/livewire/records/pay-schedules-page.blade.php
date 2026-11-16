{{--
    Pay Schedules — how a month is cut into salary periods and when each is
    paid: the list (periods, pay days, the weekend and holiday rule, the
    deadlines, which is the default), an Add/Edit dialog with a preview of
    the next months' pay dates, and a delete confirmation. Writes need
    Payroll access to all data; the commands check again. Nothing here
    prices a payroll.
--}}
@php
    use App\Livewire\Records\PaySchedulesPage;
    use App\Livewire\Records\Support\PayScheduleText;
    use App\Modules\Payroll\Application\PayrollPeriodText;
    use App\Modules\Payroll\Domain\Schedules\PaySchedule;
@endphp
<div class="space-y-6">
    @include('livewire.payroll.partials.settings-header', [
        'activeView' => 'schedules',
        'title' => 'Pay Schedules',
        'description' => 'When each salary period is paid.',
    ])

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            @unless ($ready)
                <p class="w-full text-xs text-muted-foreground">Pay schedules can be set up once this installation's upgrade finishes; every payroll is paid on its period's last day until then.</p>
            @endunless
            @unless ($canEdit)
                <p class="w-full text-xs text-muted-foreground">Your role can view the schedules. Adding, editing and deleting require Payroll access to all data.</p>
            @endunless
            <flux:input
                aria-label="Search pay schedules"
                placeholder="Search name"
                class="w-full min-w-[12rem] max-w-xs flex-1"
                wire:model.live.debounce.250ms="search"
            />
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <x-kit.export-button :href="route('records.pay-schedules.export', array_filter(['q' => $search]))" />
                @if ($canEdit && $ready)
                    <flux:button variant="primary" icon="plus" wire:click="openAdd">Add Pay Schedule</flux:button>
                @endif
            </div>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Salary Periods</th>
                        <th>Pay Days</th>
                        <th>Weekend or Holiday</th>
                        <th>Deadlines</th>
                        <th>Payrolls</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($rows->isEmpty())
                        <tr wire:key="empty">
                            <x-kit.empty-state :colspan="7" title="No pay schedule yet." hint="Add one to set the pay days and the deadlines before them." />
                        </tr>
                    @endif
                    @foreach ($rows as $row)
                        @php $schedule = PayScheduleText::schedule($row); @endphp
                        <tr wire:key="schedule-{{ $row->id }}">
                            <td>
                                <p class="font-medium">{{ $row->name }}</p>
                                @if ($row->is_default)
                                    <span class="pill pill-success mt-1">Default</span>
                                @endif
                            </td>
                            @if ($schedule === null)
                                <td colspan="4" class="text-danger">This schedule holds figures payroll cannot read.</td>
                            @else
                                <td class="whitespace-nowrap">{{ PayScheduleText::periods($schedule) }}</td>
                                <td>
                                    {{ PayScheduleText::payDays($schedule) }}
                                    <span class="block text-xs text-muted-foreground">Contributions: {{ mb_strtolower(PayScheduleText::contributionMonth($schedule)) }}</span>
                                </td>
                                <td class="whitespace-nowrap">{{ PayScheduleText::holidayRule($schedule) }}</td>
                                <td>{{ PayScheduleText::deadlines($schedule) }}</td>
                            @endif
                            <td class="num">{{ $row->runs_count }}</td>
                            <td class="text-right">
                                @if ($canEdit && $ready)
                                    <x-kit.row-actions :label="$row->name">
                                        <flux:menu.item icon="pencil-square" wire:click="startEdit('{{ $row->id }}')">Edit</flux:menu.item>
                                        @unless ($row->is_default)
                                            <flux:menu.item icon="star" wire:click="makeDefault('{{ $row->id }}')">Make the Default</flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete('{{ $row->id }}')" :disabled="$row->runs_count > 0">
                                                {{ $row->runs_count > 0 ? 'Delete · used by '.$row->runs_count.' '.($row->runs_count === 1 ? 'payroll' : 'payrolls') : 'Delete' }}
                                            </flux:menu.item>
                                        @endunless
                                    </x-kit.row-actions>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <flux:modal
        wire:model.self="dialogOpen"
        class="w-full max-w-2xl"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'schedule-heading')"
    >
        <form class="grid gap-5" wire:submit="save">
            <flux:heading size="lg" id="schedule-heading">{{ $editingId === null ? 'Add Pay Schedule' : 'Edit Pay Schedule' }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <flux:label for="schedule-name">Name</flux:label>
                    <flux:input id="schedule-name" wire:model="formName" maxlength="120" placeholder="e.g. Semi-monthly, paid on the 15th and 30th" />
                </div>
                <x-kit.select label="Salary Periods" wire:model.live="formPeriods">
                    <flux:select.option value="{{ PaySchedulesPage::PERIODS_SEMI_MONTHLY }}">Semi-monthly: 1st–15th and 16th–end</flux:select.option>
                    <flux:select.option value="{{ PaySchedulesPage::PERIODS_MONTHLY }}">Monthly: 1st–end</flux:select.option>
                </x-kit.select>
            </div>

            <fieldset class="rounded-md border border-border p-4">
                <legend class="px-1 text-sm font-semibold">Pay Days</legend>
                <div class="grid gap-3">
                    @foreach ($formPayDays as $index => $payDay)
                        @php $label = $formPeriods === PaySchedulesPage::PERIODS_MONTHLY ? 'The month' : ($index === 0 ? 'The 1st–15th' : 'The 16th–end'); @endphp
                        <div class="grid gap-3 sm:grid-cols-[10rem_1fr_7rem_1fr] sm:items-end" wire:key="pay-day-{{ $index }}">
                            <p class="text-sm font-medium sm:pb-2">{{ $label }}</p>
                            <x-kit.select label="Paid On" wire:model.live="formPayDays.{{ $index }}.kind">
                                <flux:select.option value="{{ PaySchedule::PAY_PERIOD_END }}">The period's last day</flux:select.option>
                                <flux:select.option value="{{ PaySchedule::PAY_DAY }}">A day of the month</flux:select.option>
                            </x-kit.select>
                            <div class="space-y-2">
                                <flux:label for="pay-day-{{ $index }}">Day</flux:label>
                                <flux:input id="pay-day-{{ $index }}" type="number" min="1" max="31" step="1" class:input="num" wire:model="formPayDays.{{ $index }}.day" aria-label="{{ $label }} pay day" :disabled="($payDay['kind'] ?? '') !== PaySchedule::PAY_DAY" />
                            </div>
                            <div class="flex items-center gap-3 sm:pb-2">
                                <flux:switch id="pay-next-{{ $index }}" wire:model="formPayDays.{{ $index }}.next_month" :disabled="($payDay['kind'] ?? '') !== PaySchedule::PAY_DAY" />
                                <flux:label for="pay-next-{{ $index }}">Of the following month</flux:label>
                            </div>
                        </div>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-muted-foreground">A day the month lacks (the 30th in February) becomes the month's last day.</p>
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-kit.select label="A pay date on a Saturday, Sunday or holiday" wire:model.live="formHolidayRule">
                    <flux:select.option value="{{ PaySchedule::MOVE_BEFORE }}">Moves to the working day before</flux:select.option>
                    <flux:select.option value="{{ PaySchedule::MOVE_AFTER }}">Moves to the working day after</flux:select.option>
                    <flux:select.option value="{{ PaySchedule::MOVE_KEEP }}">Stays on that day</flux:select.option>
                </x-kit.select>
                <x-kit.select label="A payroll carries the contributions of" wire:model.live="formContributionMonth">
                    <flux:select.option value="{{ PaySchedule::MONTH_PERIOD }}">The salary period's month</flux:select.option>
                    <flux:select.option value="{{ PaySchedule::MONTH_PAY_DATE }}">The pay date's month</flux:select.option>
                </x-kit.select>
            </div>

            <fieldset class="rounded-md border border-border p-4">
                <legend class="px-1 text-sm font-semibold">Deadlines Before the Pay Date</legend>
                <p class="text-xs text-muted-foreground">Each one reminds payroll officers ahead of the pay date while no finalized payroll covers the period.</p>
                <div class="mt-3 grid gap-3">
                    @foreach ($formDeadlines as $index => $deadline)
                        <div class="grid gap-3 sm:grid-cols-[1fr_9rem_auto] sm:items-end" wire:key="deadline-{{ $index }}">
                            <div class="space-y-2">
                                <flux:label for="deadline-label-{{ $index }}">What Is Due</flux:label>
                                <flux:input id="deadline-label-{{ $index }}" wire:model="formDeadlines.{{ $index }}.label" maxlength="60" placeholder="e.g. Draft complete" />
                            </div>
                            <div class="space-y-2">
                                <flux:label for="deadline-days-{{ $index }}">Days Before</flux:label>
                                <flux:input id="deadline-days-{{ $index }}" type="number" min="0" max="60" step="1" class:input="num" wire:model="formDeadlines.{{ $index }}.days" aria-label="Days before the pay date" />
                            </div>
                            <flux:button variant="ghost" icon="x-mark" wire:click="removeDeadline({{ $index }})" aria-label="Remove deadline" />
                        </div>
                    @endforeach
                </div>
                @if (count($formDeadlines) < PaySchedule::MAX_DEADLINES)
                    <flux:button variant="outline" icon="plus" size="sm" class="mt-3" wire:click="addDeadline">Add Deadline</flux:button>
                @endif
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <flux:label for="schedule-lag">Attendance window ends this many days before the period</flux:label>
                    <flux:input id="schedule-lag" type="number" min="0" max="31" step="1" class:input="num" wire:model.live="formLag" />
                    <p class="text-xs text-muted-foreground">0 reads the period's own attendance. A later window is recorded now and priced in a later upgrade.</p>
                </div>
                <div class="flex items-center gap-3 sm:pt-7">
                    <flux:switch id="schedule-default" wire:model="formIsDefault" />
                    <flux:label for="schedule-default">The default: new payrolls start from it</flux:label>
                </div>
            </div>

            @if ($preview !== [])
                <div class="rounded-md border border-border bg-muted px-4 py-3 text-sm" data-schedule-preview>
                    <p class="font-medium">The Coming Pay Dates</p>
                    <ul class="mt-1 space-y-1 text-xs text-muted-foreground">
                        @foreach ($preview as $period)
                            <li wire:key="preview-{{ $period['start'] }}">
                                <span class="num">{{ PayrollPeriodText::day($period['start']) }} – {{ PayrollPeriodText::day($period['end']) }}</span>:
                                paid <span class="num">{{ PayrollPeriodText::day($period['pay_date']) }}</span>
                                @foreach ($period['deadlines'] as $deadline)
                                    · {{ $deadline['label'] }} by <span class="num">{{ PayrollPeriodText::day($deadline['date']) }}</span>
                                @endforeach
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($formError !== null)
                <p role="alert" class="text-sm text-danger">{{ $formError }}</p>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ $editingId === null ? 'Add' : 'Save Changes' }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal
        wire:model.self="confirmOpen"
        class="w-full max-w-md"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'schedule-delete-heading')"
    >
        <div class="space-y-4">
            <flux:heading size="lg" id="schedule-delete-heading">Delete this pay schedule?</flux:heading>
            <flux:text>{{ $deleting?->name ?? 'This schedule' }} is removed for good. No payroll took its dates from it.</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="outline">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteConfirmed">Delete</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
