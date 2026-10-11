{{-- Attendance is read through Timekeeping with the account's own employee resolved on every request. --}}
@use('App\Domain\Attendance\DayRecord')
@use('App\Livewire\Edtr\Support\Hris')
@use('App\Modules\Timekeeping\Domain\Attendance\OvertimeApprovalPolicy')

<div class="min-w-0 space-y-4">
    @if (! $linked)
        <section class="hris-card space-y-2 p-6">
            <h2 class="text-base font-bold">Employee record not linked</h2>
            <p class="text-sm text-muted-foreground">Your attendance will appear here once management links this account to your employee record.</p>
        </section>
    @else
        <section class="hris-card space-y-4 p-4 sm:p-5" aria-labelledby="my-attendance-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-bold" id="my-attendance-heading">My attendance</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Your daily time record. Choose up to 31 days to review.</p>
                </div>
                <flux:button variant="outline" icon="arrow-path" wire:click="refreshAttendance" wire:loading.attr="disabled">Refresh</flux:button>
            </div>
            <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                <div class="min-w-0 space-y-2">
                    <flux:label for="my-attendance-start">Period start</flux:label>
                    <x-kit.date-field id="my-attendance-start" wire:model.live="start" aria-label="Attendance period start" />
                </div>
                <div class="min-w-0 space-y-2">
                    <flux:label for="my-attendance-end">Period end</flux:label>
                    <x-kit.date-field id="my-attendance-end" wire:model.live="end" aria-label="Attendance period end" />
                </div>
                <div class="flex flex-wrap items-center gap-2 sm:col-span-2 lg:col-span-1">
                    <flux:button variant="outline" icon="chevron-left" wire:click="previousCutoff" aria-label="Previous cutoff" title="Previous cutoff" wire:loading.attr="disabled" />
                    <flux:button variant="outline" wire:click="thisCutoff" wire:loading.attr="disabled">This cutoff</flux:button>
                    <flux:button variant="outline" icon="chevron-right" wire:click="nextCutoff" aria-label="Next cutoff" title="Next cutoff" wire:loading.attr="disabled" />
                </div>
            </div>
            <flux:error name="period" />
            <p class="text-xs text-muted-foreground" role="status" wire:loading>Loading attendance…</p>
        </section>

        @if ($attendance !== null)
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                @foreach ([
                    ['Days present', (string) $attendance->totals->present, null],
                    ['Worked', Hris::fmtDuration($attendance->totals->regularMinutes()), 'Excludes overtime'],
                    ['Late', Hris::fmtDuration($attendance->totals->lateMinutes), $attendance->unscheduled['late'] > 0 ? Hris::fmtDuration($attendance->unscheduled['late']).' on unscheduled days' : null],
                    ['Undertime', Hris::fmtDuration($attendance->totals->undertimeMinutes), $attendance->unscheduled['undertime'] > 0 ? Hris::fmtDuration($attendance->unscheduled['undertime']).' on unscheduled days' : null],
                    ['Absences', (string) $attendance->absences, $attendance->unscheduled['absences'] > 0 ? $attendance->unscheduled['absences'].' on unscheduled days' : null],
                ] as [$label, $value, $note])
                    <div class="hris-card min-w-0 p-4" wire:key="attendance-stat-{{ $label }}">
                        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $label }}</p>
                        <p class="num mt-1 break-words text-2xl font-bold">{{ $value }}</p>
                        @if ($note !== null)
                            <p class="mt-1 text-xs text-muted-foreground">{{ $note }}</p>
                        @endif
                    </div>
                @endforeach
            </div>

            @if ($attendance->unscheduled['days'] > 0)
                <div class="hris-card space-y-1 px-4 py-3" data-unscheduled-days="{{ $attendance->unscheduled['days'] }}">
                    <p class="text-sm font-medium">{{ $attendance->unscheduled['days'] }} {{ $attendance->unscheduled['days'] === 1 ? 'day has' : 'days have' }} no assigned schedule.</p>
                    <p class="text-sm text-muted-foreground">Attendance on those days uses the system default of 8:00 AM–5:00 PM, Monday to Friday. Ask management to review your schedule if these figures look incorrect.</p>
                </div>
            @endif

            <section class="hris-card min-w-0 overflow-hidden" aria-label="Daily attendance">
                <header class="space-y-1 border-b border-border px-4 py-3">
                    <h3 class="text-sm font-semibold">{{ Hris::fmtDate($start) }} – {{ Hris::fmtDate($end) }}</h3>
                </header>
                <div class="hris-table-wrap" tabindex="0" role="region" aria-label="Scrollable daily attendance table">
                    <table class="hris-table">
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Status</th>
                                <th scope="col">First Time In</th>
                                <th scope="col">First Time Out</th>
                                <th scope="col">Second Time In</th>
                                <th scope="col">Final Time Out</th>
                                <th scope="col" class="!text-right">Worked</th>
                                <th scope="col" class="!text-right">Late</th>
                                <th scope="col" class="!text-right">Undertime</th>
                                <th scope="col" class="!text-right">Overtime</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($attendance->days as $day)
                                @php
                                    [$status, $tone] = match ($day->status) {
                                        DayRecord::PRESENT => ['Present', 'pill-success'],
                                        DayRecord::LATE => ['Late', 'pill-caution'],
                                        DayRecord::ABSENT => ['Absent', 'pill-danger'],
                                        DayRecord::INCOMPLETE => ['Incomplete', 'pill-caution'],
                                        DayRecord::OFFICIAL_BUSINESS => ['Official business', 'pill-primary'],
                                        default => ['Excused', 'pill-muted'],
                                    };
                                    $overtime = $attendance->overtimeByDate[$day->date] ?? null;
                                    $dayOff = $day->status === DayRecord::EXCUSED
                                        && $day->excuseType === null
                                        && $day->timeIn === null && $day->breakOut === null
                                        && $day->breakIn === null && $day->timeOut === null;
                                @endphp
                                <tr wire:key="own-attendance-{{ $day->date }}">
                                    <td class="num whitespace-nowrap">{{ Hris::fmtDate($day->date) }}</td>
                                    @if ($dayOff)
                                        @for ($column = 0; $column < 9; $column++)
                                            <td class="num whitespace-nowrap {{ $column >= 5 ? 'text-right' : '' }}">—</td>
                                        @endfor
                                    @else
                                        <td><span class="pill {{ $tone }}">{{ $status }}</span></td>
                                        @foreach ([$day->timeIn, $day->breakOut, $day->breakIn, $day->timeOut] as $time)
                                            <td class="num whitespace-nowrap">
                                                {{ $time !== null ? Hris::fmtTime($time) : '—' }}
                                                @if (Hris::isNextDay($time, $day->date))
                                                    <span class="block text-xs text-muted-foreground">next day</span>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="num whitespace-nowrap text-right">{{ Hris::fmtDuration($day->regularMinutes()) }}</td>
                                        <td class="num whitespace-nowrap text-right {{ Hris::durationTone($day->lateMinutes, 'text-danger') }}">{{ Hris::fmtDuration($day->lateMinutes) }}</td>
                                        <td class="num whitespace-nowrap text-right {{ Hris::durationTone($day->undertimeMinutes, 'text-warning') }}">{{ Hris::fmtDuration($day->undertimeMinutes) }}</td>
                                        <td class="text-right">
                                            <span class="num whitespace-nowrap">{{ Hris::fmtDuration($day->overtimeMinutes) }}</span>
                                            @if ($overtime !== null && $overtime->status !== OvertimeApprovalPolicy::PENDING)
                                                @php
                                                    [$decision, $decisionTone] = match ($overtime->status) {
                                                        OvertimeApprovalPolicy::APPROVED => ['Approved', 'pill-success'],
                                                        OvertimeApprovalPolicy::PARTIAL => ['Partially approved', 'pill-primary'],
                                                        OvertimeApprovalPolicy::REJECTED => ['Rejected', 'pill-danger'],
                                                        default => ['Needs review', 'pill-caution'],
                                                    };
                                                @endphp
                                                <div class="mt-1"><span class="pill {{ $decisionTone }}">{{ $decision }}</span></div>
                                                @if ($overtime->decision === OvertimeApprovalPolicy::APPROVED)
                                                    <span class="mt-1 block whitespace-nowrap text-xs text-muted-foreground">{{ Hris::fmtDuration((float) $overtime->approved_minutes) }} approved</span>
                                                @endif
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr><x-kit.empty-state :colspan="10" title="No attendance dates to show yet." :hint="'Attendance is available through '.Hris::fmtDate($today).'.'" /></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="border-t border-border px-4 py-3 text-xs text-muted-foreground">Overtime shows computed time and its approval status. Contact management if a punch or attendance record needs correction.</p>
            </section>
        @endif
    @endif
</div>
