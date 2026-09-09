{{--
    My Portal — the port of copems/src/app/(protected)/portal/portal-page.tsx.
    See App\Livewire\Portal\PortalPage for the state and actions behind this.

    Fidelity notes where shadcn had no Flux twin:
      - Tabs are the same Tailwind approximation the eDTR page uses: a muted
        strip of role=tab buttons, with only the active tab's content mounted
        (Radix unmounts inactive TabsContent, and a server-side re-render
        matches that).
      - The payslip Dialog is <flux:modal>; DialogTitle/DialogDescription are
        plain markup carrying their classes, print-hidden exactly as the
        source's `print:hidden` header was. The print stylesheet keys on the
        `payslip-print` class, same as the reference.
--}}
@use('App\Livewire\Portal\Support\PortalLabels')

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">My Portal</h1>
            <p class="text-sm text-muted-foreground">
                {{ $employee !== null ? PortalLabels::employeeName($employee).' · '.($employee->position ?? 'Employee') : 'Self-service' }}
            </p>
        </div>
        <livewire:shell.live-clock class="hris-card shrink-0 px-5 py-3 text-right" wire:key="portal-clock" />
    </div>

    @unless ($hasEmployee)
        <p class="hris-card p-6 text-sm text-muted-foreground">
            Your login isn't linked to an employee record yet, so punches and requests are disabled.
            Ask HR to link your account.
        </p>
    @endunless

    <div>
        {{-- TabsList — shadcn's muted strip with the active trigger raised. --}}
        <div class="inline-flex h-9 items-center justify-center rounded-lg bg-muted p-1 text-muted-foreground" role="tablist">
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === 'punch' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'punch')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'punch' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
            >Punch station</button>
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === 'requests' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'requests')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'requests' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
            >Leave &amp; travel</button>
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === 'payslips' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'payslips')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'payslips' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
            >My payslips</button>
        </div>

        @if ($tab === 'punch')
            <div class="mt-4 space-y-4">
                @if ($hasEmployee)
                    <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,27rem)_minmax(0,1fr)]">
                        {{--
                            The station locked to the caller's own record, in
                            self-service mode: no site/source/coordinate
                            controls — the browser is asked for a fix as the
                            punch happens (PunchStation.tsx selfService).
                        --}}
                        <livewire:edtr.punch-station
                            :locked-employee-id="$ownEmployeeId"
                            :show-clock="false"
                            :self-service="true"
                            wire:key="portal-punch-station-{{ $ownEmployeeId }}"
                        />

                        <livewire:portal.my-punches wire:key="portal-my-punches" />
                    </div>
                @endif
            </div>
        @elseif ($tab === 'requests')
            <div class="mt-4 space-y-4">
                <form class="hris-card grid gap-4 p-6 sm:grid-cols-2" wire:submit="file">
                    <h2 class="text-base font-bold sm:col-span-2">File leave or business travel</h2>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-2">
                            <flux:label for="portal-request-type">Description</flux:label>
                            <flux:select id="portal-request-type" wire:model.live="type" placeholder="Select leave">
                                @foreach ($requestOptions as $t)
                                    <flux:select.option value="{{ $t['value'] }}">{{ $t['label'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                        <div class="space-y-2">
                            <flux:label for="portal-day-type">Day type</flux:label>
                            <flux:select id="portal-day-type" wire:model.live="dayType">
                                @foreach (PortalLabels::DAY_TYPES as $value => $label)
                                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-2">
                            <flux:label>From</flux:label>
                            <x-kit.date-field wire:model.live="start" aria-label="From date" />
                        </div>
                        <div class="space-y-2">
                            <flux:label>To</flux:label>
                            <x-kit.date-field wire:model.live="end" aria-label="To date" />
                        </div>
                    </div>

                    @if ($dateError !== null)
                        <p class="text-sm text-danger sm:col-span-2">{{ $dateError }}</p>
                    @endif

                    <div class="space-y-2 sm:col-span-2">
                        <flux:label for="portal-request-reason">Reason</flux:label>
                        <flux:textarea id="portal-request-reason" rows="3" wire:model="reason" />
                    </div>

                    <div class="sm:col-span-2">
                        <flux:button
                            type="submit"
                            variant="primary"
                            :disabled="$submitDisabled"
                            wire:loading.attr="disabled"
                            wire:target="file"
                        >
                            <span wire:loading wire:target="file" class="mr-2 inline-flex"><flux:icon.loading class="size-4" /></span>
                            <span wire:loading.remove wire:target="file" class="mr-2 inline-flex"><flux:icon.paper-airplane class="size-4" /></span>
                            Submit request
                        </flux:button>
                    </div>
                </form>

                <div class="hris-card overflow-hidden">
                    <div class="hris-table-wrap hris-table-scroll">
                        <table class="hris-table">
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th>Day type</th>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>Status</th>
                                    <th aria-label="Actions"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @if ($mine->isEmpty())
                                    <tr wire:key="requests-empty">
                                        <x-kit.empty-state :colspan="6" title="You haven't filed any requests." />
                                    </tr>
                                @endif
                                @foreach ($mine as $r)
                                    <tr wire:key="req-{{ $r->id }}">
                                        <td>{{ PortalLabels::describe($r->request_type, $leaveTypeNames) }}</td>
                                        <td class="whitespace-nowrap">{{ PortalLabels::dayTypeLabel($r->day_type) }}</td>
                                        <td class="num">{{ $r->start_date->format('M d, Y') }}</td>
                                        <td class="num">{{ $r->end_date->format('M d, Y') }}</td>
                                        <td>
                                            <span @class([
                                                'pill pill-success' => $r->status === 'APPROVED',
                                                'pill pill-danger' => $r->status === 'REJECTED',
                                                'pill pill-warning' => $r->status === 'PENDING',
                                                'pill pill-muted' => ! in_array($r->status, ['APPROVED', 'REJECTED', 'PENDING'], true),
                                            ])>{{ $r->status }}</span>
                                            @if ($r->status === 'APPROVED' && $r->request_type !== PortalLabels::OFFICIAL_BUSINESS && $r->is_paid !== null)
                                                <span class="ml-2 text-xs text-muted-foreground">
                                                    {{ $r->is_paid ? 'with pay' : 'without pay' }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            @if ($r->status === 'PENDING')
                                                {{--
                                                    Every Withdraw disables while one is in
                                                    flight (`withdrawingId !== null`); only the
                                                    clicked row spins.
                                                --}}
                                                <flux:button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    wire:click="withdraw('{{ $r->id }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="withdraw"
                                                >
                                                    <span wire:loading wire:target="withdraw('{{ $r->id }}')" class="mr-1 inline-flex"><flux:icon.loading class="size-4" /></span>
                                                    <span wire:loading.remove wire:target="withdraw('{{ $r->id }}')" class="mr-1 inline-flex"><flux:icon.x-mark class="size-4" /></span>
                                                    Withdraw
                                                </flux:button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @else
            <div class="mt-4">
                <section class="hris-card overflow-hidden">
                    <header class="border-b border-border px-5 py-4">
                        <h2 class="text-base font-bold">My payslips</h2>
                        <p class="text-sm text-muted-foreground">
                            Posted cut-offs only — open one to see the full breakdown or print it.
                        </p>
                    </header>
                    <div class="hris-table-wrap hris-table-scroll">
                        <table class="hris-table">
                            <thead>
                                <tr>
                                    <th>Period</th>
                                    <th class="text-right">Gross</th>
                                    <th class="text-right">Deductions</th>
                                    <th class="text-right">Net</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @if ($payslips->isEmpty())
                                    <tr wire:key="payslips-empty">
                                        <x-kit.empty-state :colspan="5" title="No payslip has been posted for you yet." />
                                    </tr>
                                @endif
                                @foreach ($payslips as $p)
                                    <tr wire:key="payslip-{{ $p->id }}">
                                        <td class="num whitespace-nowrap">
                                            {{ $p->period_start->format('M d, Y') }} – {{ $p->period_end->format('M d, Y') }}
                                        </td>
                                        <td class="num text-right">{{ PortalLabels::peso($p->gross_pay) }}</td>
                                        <td class="num text-right text-danger">{{ PortalLabels::peso($p->total_deductions) }}</td>
                                        <td class="num text-right font-bold">{{ PortalLabels::peso($p->net_pay) }}</td>
                                        <td class="text-right">
                                            <flux:button variant="ghost" size="sm" wire:click="viewPayslip('{{ $p->id }}')">
                                                <flux:icon.eye class="mr-2 size-4" />
                                                View
                                            </flux:button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        @endif
    </div>

    {{-- The payslip dialog, laid out as the document an employee expects to see. --}}
    <flux:modal wire:model.self="payslipOpen" class="max-h-[90vh] w-full max-w-3xl overflow-y-auto">
        @if ($viewing !== null)
            <div class="space-y-2 print:hidden">
                <flux:heading size="lg">Payslip</flux:heading>
                <flux:text>
                    {{ $viewing->period_start->format('M d, Y') }} – {{ $viewing->period_end->format('M d, Y') }}
                </flux:text>
            </div>

            <div class="payslip-print mt-4 space-y-5">
                <header class="flex flex-wrap items-start justify-between gap-4 border-b border-border pb-4">
                    <div>
                        <div class="flex items-center gap-2 font-bold">
                            <flux:icon.shield-check class="size-5 text-primary" />
                            BestWorkflows Employee Management System
                        </div>
                        <p class="mt-1 text-xs uppercase tracking-wide text-muted-foreground">
                            Payslip · Philippine Peso
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="num text-sm font-semibold">
                            {{ $viewing->period_start->format('M d, Y') }} – {{ $viewing->period_end->format('M d, Y') }}
                        </p>
                        <p class="text-xs text-muted-foreground">Cut-off period</p>
                    </div>
                </header>

                <section class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Employee</p>
                        <p class="mt-1 text-sm font-medium">{{ PortalLabels::employeeName($employee) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Employee code</p>
                        <p class="num mt-1 text-sm font-medium">{{ $employee?->employee_code ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Position</p>
                        <p class="mt-1 text-sm font-medium">{{ $employee?->position ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Monthly rate</p>
                        <p class="num mt-1 text-sm font-medium">{{ $employee !== null ? PortalLabels::peso($employee->monthly_rate) : '—' }}</p>
                    </div>
                </section>

                <section class="border-y border-border py-4">
                    <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        Attendance basis
                    </h2>
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                        @foreach ([
                            ['Work days', PortalLabels::dayCount($viewing->work_days)],
                            ['Holidays worked', PortalLabels::dayCount($viewing->holiday_days)],
                            ['Rest days worked', PortalLabels::dayCount($viewing->rest_days)],
                            ['Absences', PortalLabels::dayCount($viewing->absence_days)],
                            ['Unpaid leave', PortalLabels::dayCount($viewing->unpaid_leave_days)],
                            ['Daily rate', PortalLabels::peso($viewing->daily_rate)],
                        ] as [$basisLabel, $basisValue])
                            <div wire:key="basis-{{ $basisLabel }}">
                                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $basisLabel }}</p>
                                <p class="num mt-1 text-sm font-medium">{{ $basisValue }}</p>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-3 text-xs text-muted-foreground">
                        Every paid day is counted once: a worked holiday is paid at its own rate and appears under
                        Holidays, not Work days. Approved paid leave counts as a work day; unpaid leave and
                        absences earn nothing.
                    </p>
                </section>

                <section class="grid gap-4 sm:grid-cols-2">
                    @foreach ([
                        ['Earnings', $earnings, $viewing->gross_pay, 'Gross pay', false],
                        ['Deductions', $deductions, $viewing->total_deductions, 'Total deductions', true],
                    ] as [$columnTitle, $lines, $columnTotal, $totalLabel, $dangerTone])
                        <div class="rounded-md border border-border" wire:key="column-{{ $columnTitle }}">
                            <p class="border-b border-border px-4 py-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                {{ $columnTitle }}
                            </p>
                            <ul class="divide-y divide-border">
                                @if (count($lines) === 0)
                                    <li class="px-4 py-3 text-sm text-muted-foreground">Nothing on this cut-off.</li>
                                @endif
                                @foreach ($lines as $l)
                                    <li class="flex items-baseline justify-between gap-4 px-4 py-2" wire:key="line-{{ $columnTitle }}-{{ $l['label'] }}">
                                        <span class="text-sm">
                                            {{ $l['label'] }}
                                            @if ($l['hint'] !== null)
                                                <span class="num ml-2 text-xs text-muted-foreground">{{ $l['hint'] }}</span>
                                            @endif
                                        </span>
                                        <span class="num text-sm">{{ PortalLabels::peso($l['value']) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <div class="flex items-baseline justify-between gap-4 border-t border-border px-4 py-2">
                                <span class="text-sm font-semibold">{{ $totalLabel }}</span>
                                <span class="num text-sm font-bold {{ $dangerTone ? 'text-danger' : '' }}">{{ PortalLabels::peso($columnTotal) }}</span>
                            </div>
                        </div>
                    @endforeach
                </section>

                <section class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-success/30 bg-success-soft px-5 py-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-success">Net pay</p>
                        <p class="text-xs text-muted-foreground">Gross less all deductions</p>
                    </div>
                    <p class="num text-3xl font-bold text-success">{{ PortalLabels::peso($viewing->net_pay) }}</p>
                </section>

                <p class="text-xs text-muted-foreground">
                    Computed from posted attendance and the employee's payroll settings. Statutory amounts use
                    the contribution schedule effective from January 2025.
                </p>
            </div>

            <div class="mt-4 flex justify-end print:hidden">
                <flux:button type="button" variant="outline" x-on:click="window.print()">
                    <flux:icon.printer class="mr-2 size-4" />
                    Print
                </flux:button>
            </div>
        @endif
    </flux:modal>
</div>
