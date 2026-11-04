{{--
    My Portal — the port of copems/src/app/(protected)/portal/portal-page.tsx.
    See App\Livewire\Portal\PortalPage for the state and actions behind this.

    Fidelity notes where shadcn had no Flux twin:
      - Tabs are the same Tailwind approximation the eDTR page uses: a muted
        strip of role=tab buttons, with only the active tab's content mounted
        (Radix unmounts inactive TabsContent, and a server-side re-render
        matches that).
      - The payslip Dialog is a BARE <flux:modal> that shows the payslip alone:
        there is no DialogTitle / DialogDescription header (the source has
        one), because the payslip names itself and its period. The print
        stylesheet keys on the `payslip-print` class, same as the reference.
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
            Your login isn't linked to an employee record yet, so punches, attendance, requests and leave credits are unavailable.
            Ask HR to link your account.
        </p>
    @endunless

    <div>
        {{-- TabsList — shadcn's muted strip with the active trigger raised. --}}
        <div class="inline-flex h-auto flex-wrap items-center justify-start rounded-lg bg-muted p-1 text-muted-foreground" role="tablist">
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
                aria-selected="{{ $tab === 'attendance' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'attendance')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'attendance' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
            >Attendance</button>
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
            <button type="button" role="tab" aria-selected="{{ $tab === 'inventory' ? 'true' : 'false' }}" wire:click="$set('tab', 'inventory')" class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'inventory' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}">My issued items</button>
        </div>

        @if ($tab === 'inventory')
            <div class="mt-4"><livewire:inventory.employee-items :own="true" key="portal-inventory" /></div>
        @elseif ($tab === 'attendance')
            <div class="mt-4"><livewire:portal.my-attendance /></div>
        @elseif ($tab === 'punch')
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
                @if ($hasEmployee)
                    <details class="hris-card p-4">
                        <summary class="cursor-pointer text-sm font-semibold">My leave ledger · opening, earned and carried credits</summary>
                        <div class="mt-4"><livewire:leaves.leave-ledger :own="true" wire:key="portal-leave-ledger" /></div>
                    </details>
                @endif
                <div @class(['grid items-start gap-4', 'lg:grid-cols-2' => $hasEmployee])>
                    {{-- Not in the reference: MY leave credits for the year, per
                         type — what Leave Management entered, what approved leave
                         with pay has used, and what is left. Own-pinned in
                         LeaveCreditRecords::own(); nothing for an unlinked account,
                         whose notice above already says so. --}}
                    @if ($hasEmployee)
                        <div class="hris-card min-w-0 overflow-hidden lg:order-2" data-leave-credits>
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                                <h2 class="text-base font-bold">Leave credits · {{ $creditYear }}</h2>
                                <div class="flex flex-wrap items-center gap-3">
                                    <p class="text-xs text-muted-foreground">Used days come from approved leave with pay.</p>
                                    @if ($cashOutTypes !== [])
                                        <flux:button size="sm" variant="outline" icon="banknotes" wire:click="$set('cashOutOpen', true)" data-cash-out-button>Cash out credits</flux:button>
                                    @endif
                                </div>
                            </div>
                            @if ($balances->isEmpty())
                                <p class="px-5 py-6 text-sm text-muted-foreground">No leave credits have been entered for {{ $creditYear }} yet.</p>
                            @else
                                <div class="hris-table-wrap">
                                    <table class="hris-table">
                                        <thead>
                                            <tr>
                                                <th>Type</th>
                                                <th><span class="block text-right">Credited</span></th>
                                                <th><span class="block text-right">Used</span></th>
                                                <th><span class="block text-right">Left</span></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($balances as $b)
                                                <tr wire:key="credit-{{ $b->request_type }}">
                                                    <td>{{ $b->label }}</td>
                                                    <td class="num text-right">{{ PortalLabels::credits($b->credited) }}</td>
                                                    <td class="num text-right">{{ PortalLabels::credits($b->used) }}</td>
                                                    <td class="num text-right">
                                                        <span @class(['pill pill-caution' => $b->remaining <= 0])>{{ PortalLabels::credits($b->remaining) }}</span>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                @php $setAside = $balances->sum(static fn ($b) => $b->spoken_for); @endphp
                                <p class="px-5 pb-3 text-xs text-muted-foreground" data-credit-note>
                                    Lateness and absences are charged to these credits first, so a balance can go below zero.
                                    @if ($setAside > 0)
                                        <span class="num">{{ PortalLabels::credits($setAside) }}</span> {{ $setAside === 1.0 ? 'day is' : 'days are' }} set aside for cash-out requests and cannot be filed.
                                    @endif
                                </p>
                            @endif
                            {{-- MY cash-out requests (LeaveCashOutRecords::own): what was asked, decided and paid. --}}
                            @if ($cashOuts->isNotEmpty())
                                <div class="border-t border-border px-5 py-3" data-cash-outs>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Cash-out requests</p>
                                    <ul class="mt-2 space-y-1 text-sm">
                                        @foreach ($cashOuts as $c)
                                            <li class="flex flex-wrap items-center justify-between gap-2" wire:key="cash-out-{{ $c->id }}" data-cash-out="{{ $c->status }}">
                                                <span>{{ $c->type_name }} · <span class="num">{{ PortalLabels::credits($c->days) }}</span> {{ $c->days === 1.0 ? 'day' : 'days' }} · {{ $c->requested_at->setTimezone(\App\Support\ManilaClock::TZ)->format('M d, Y') }}@if ($c->amount !== null) · <span class="num">₱{{ number_format($c->amount, 2) }}</span>@endif</span>
                                                <span class="flex items-center gap-2">
                                                    <span class="pill {{ match ($c->status) { 'PENDING' => 'pill-caution', 'APPROVED' => 'pill-primary', 'PAID' => 'pill-success-strong', default => 'pill-muted' } }}">{{ ucfirst(strtolower($c->status)) }}</span>
                                                    @if ($c->status === 'PENDING')
                                                        <flux:button size="xs" variant="ghost" wire:click="withdrawCashOut('{{ $c->id }}')">Withdraw</flux:button>
                                                    @endif
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            <flux:modal wire:model.self="cashOutOpen" class="w-full max-w-md" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'cash-out-heading')">
                                <div class="space-y-4" data-cash-out-dialog>
                                    <flux:heading size="lg" id="cash-out-heading">Cash out leave credits</flux:heading>
                                    <flux:text class="text-sm text-muted-foreground">Turn part of your credits into pay, in whole or half days. A leave manager approves it, and it is paid on your next regular payroll at your daily rate.</flux:text>
                                    <x-kit.select label="Leave type" wire:model="cashOutType" placeholder="Choose a leave type">
                                        @foreach ($cashOutTypes as $option)<flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>@endforeach
                                    </x-kit.select>
                                    <flux:input label="Days" type="number" min="0.5" step="0.5" wire:model="cashOutDays" />
                                    <flux:textarea label="Note (optional)" wire:model="cashOutNote" rows="2" maxlength="500" />
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <flux:button variant="ghost" wire:click="$set('cashOutOpen', false)">Cancel</flux:button>
                                        <flux:button variant="primary" icon="check" wire:click="requestCashOut">Send request</flux:button>
                                    </div>
                                </div>
                            </flux:modal>
                        </div>
                    @endif

                    <form class="hris-card grid min-w-0 gap-4 p-5" wire:submit="file">
                        <h2 class="text-base font-bold">File leave or business travel</h2>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-2">
                                <flux:label for="portal-request-type">Description</flux:label>
                                <x-kit.select id="portal-request-type" wire:model.live="type" placeholder="Select leave">
                                    @foreach ($requestOptions as $t)
                                        <flux:select.option value="{{ $t['value'] }}">{{ $t['label'] }}</flux:select.option>
                                    @endforeach
                                </x-kit.select>
                            </div>
                            <div class="space-y-2">
                                <flux:label for="portal-day-type">Day type</flux:label>
                                <x-kit.select id="portal-day-type" wire:model.live="dayType">
                                    @foreach (PortalLabels::DAY_TYPES as $value => $label)
                                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                    @endforeach
                                </x-kit.select>
                            </div>
                            @if ($dayType === 'HALF_DAY' && $halvesReady)
                                <div class="space-y-2">
                                    <flux:label for="portal-half">Which half</flux:label>
                                    <x-kit.select id="portal-half" wire:model.live="half">
                                        <flux:select.option value="">Either half</flux:select.option>
                                        @foreach (PortalLabels::HALVES as $value => $label)
                                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                        @endforeach
                                    </x-kit.select>
                                </div>
                            @endif
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
                            <p class="text-sm text-danger">{{ $dateError }}</p>
                        @endif

                        {{-- The selected type's credits in the request's start
                             year gate Submit; FileAbsence checks again on save. --}}
                        @if ($creditHint !== null)
                            @php
                                $hintBalance = $creditHint['balance'];
                                $hintNeeds = PortalLabels::dayCount($creditHint['needs']);
                            @endphp
                            @if ($creditHint['year'] !== $creditYear)
                                <p class="text-sm text-muted-foreground" data-credit-year>
                                    This request falls in {{ $creditHint['year'] }}; the credits shown are for {{ $creditYear }}.
                                </p>
                            @endif
                            @if ($creditHint['error'] !== null)
                                <p class="text-sm text-danger" role="alert" data-credit-error>{{ $creditHint['error'] }}</p>
                            @elseif ($hintBalance !== null)
                                <p class="text-sm text-muted-foreground" data-credit-hint>
                                    This request needs
                                    <span @class(['num', 'pill pill-caution' => $creditHint['needs'] > $hintBalance->available])>{{ $hintNeeds }} {{ $creditHint['needs'] === 1.0 ? 'day' : 'days' }}</span>
                                    (rest days and holidays cost nothing);
                                    you have <span class="num">{{ PortalLabels::credits((float) $hintBalance->available) }}</span>
                                    {{ $hintBalance->label }} {{ $hintBalance->available === 1.0 ? 'credit' : 'credits' }} available{{ $hintBalance->spoken_for > 0 ? ', with '.PortalLabels::credits($hintBalance->spoken_for).' set aside for cash-out' : '' }}.
                                </p>
                            @endif
                        @endif

                        <div class="space-y-2">
                            <flux:label for="portal-request-reason">Reason</flux:label>
                            <flux:textarea id="portal-request-reason" rows="3" wire:model="reason" />
                        </div>

                        <div>
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
                </div>

                <div class="hris-card overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3">
                        <h2 class="text-base font-bold">My requests</h2>
                    </div>
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
                                        <td class="whitespace-nowrap">{{ PortalLabels::dayTypeLabel($r->day_type, $r->half ?? null) }}</td>
                                        <td class="num">{{ $r->start_date->format('M d, Y') }}</td>
                                        <td class="num">{{ $r->end_date->format('M d, Y') }}</td>
                                        <td>
                                            <span @class([
                                                'pill pill-success' => $r->status === 'APPROVED',
                                                'pill pill-danger' => $r->status === 'REJECTED',
                                                'pill pill-warning' => $r->status === 'PENDING',
                                                'pill pill-muted' => ! in_array($r->status, ['APPROVED', 'REJECTED', 'PENDING'], true),
                                            ])>{{ $r->status }}</span>
                                            @if ($r->cancelled_dates !== [])
                                                <details class="mt-2 text-xs" wire:ignore.self wire:key="portal-cancellations-{{ $r->id }}">
                                                    <summary class="cursor-pointer text-muted-foreground">{{ count($r->cancelled_dates) }} cancelled {{ count($r->cancelled_dates) === 1 ? 'date' : 'dates' }}</summary>
                                                    <ul class="mt-2 space-y-2">
                                                        @foreach ($r->cancellations as $cancellation)
                                                            <li class="max-w-sm break-words">
                                                                <span class="num font-medium">{{ $cancellation->date->format('M d, Y') }}</span>
                                                                · {{ $cancellation->reason }}
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </details>
                                            @endif
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
                    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                        <div>
                            <h2 class="text-base font-bold">My payslips</h2>
                            <p class="text-sm text-muted-foreground">
                                Posted cut-offs only — select one to see the full breakdown or print it.
                            </p>
                        </div>
                    </header>
                    <div class="hris-table-wrap hris-table-scroll">
                        <table class="hris-table">
                            <thead>
                                <tr>
                                    <th>Period</th>
                                    {{-- `!`: hris-table's own `thead th { text-align: left }` outranks a plain
                                         text-right, which would leave each heading at the far side of its amounts. --}}
                                    <th class="!text-right">Gross</th>
                                    <th class="!text-right">Deductions</th>
                                    <th class="!text-right">Net</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if ($payslips->isEmpty())
                                    <tr wire:key="payslips-empty">
                                        <x-kit.empty-state :colspan="4" title="No payslip has been posted for you yet." />
                                    </tr>
                                @endif
                                @foreach ($payslips as $p)
                                    {{-- The row is the control: no View button. viewPayslip()
                                         re-checks that the payslip is the caller's own. --}}
                                    <tr
                                        wire:key="payslip-{{ $p->id }}"
                                        tabindex="0"
                                        aria-label="View payslip for {{ $p->period_start->format('M d, Y') }} to {{ $p->period_end->format('M d, Y') }}"
                                        class="cursor-pointer focus:outline-none focus-visible:bg-accent"
                                        wire:click="viewPayslip('{{ $p->id }}')"
                                        x-on:keydown.enter.prevent="$wire.viewPayslip('{{ $p->id }}')"
                                        x-on:keydown.space.prevent="$wire.viewPayslip('{{ $p->id }}')"
                                    >
                                        <td class="num whitespace-nowrap">
                                            {{ $p->period_start->format('M d, Y') }} – {{ $p->period_end->format('M d, Y') }}
                                        </td>
                                        <td class="num text-right">{{ PortalLabels::peso($p->gross_pay) }}</td>
                                        <td class="num text-right text-danger">{{ PortalLabels::peso($p->total_deductions) }}</td>
                                        <td class="num text-right font-bold">{{ PortalLabels::peso($p->net_pay) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        @endif
    </div>

    {{-- The payslip dialog shows THE PAYSLIP AND NOTHING ROUND IT: Flux's bare variant
         draws no panel — no background, padding, shadow or close button — so the sheet
         itself is what opens over the page. No heading or period line either: the
         payslip carries both. Only the two controls stay, above the sheet where they are
         reachable without scrolling a long payslip on a phone.

         The dialog is as wide as the sheet at its largest on-screen size (payslip.css grows
         it to 1,120px; the 1px of padding is room for the sheet's own outline), so there is
         no invisible strip beside it that looks like the backdrop but would not close it.
         With no panel to keep clear of the window's edge it may use nearly the full height,
         which shows the whole sheet on an ordinary desktop without scrolling.
         Flux forwards no aria attribute to the <dialog>, hence the x-init. --}}
    <flux:modal
        wire:model.self="payslipOpen"
        variant="bare"
        class="max-h-[calc(100dvh-2rem)] w-full max-w-[1122px] overflow-y-auto p-px"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-label', 'Payslip')"
    >
        @if ($viewing !== null)
            <div class="mb-2 flex justify-end gap-2 print:hidden">
                <flux:button type="button" variant="primary" size="sm" icon="printer" x-on:click="window.print()">Print</flux:button>
                <flux:modal.close>
                    <flux:button type="button" variant="outline" size="sm" icon="x-mark">Close</flux:button>
                </flux:modal.close>
            </div>

            {{-- The employee's copy is the payslip itself. The "Additional payroll details"
                 fold (position, payroll name, incentive tax treatment, the employer's
                 contributions) is NOT shown in My Portal; Payroll and Reports show it. --}}
            <div class="payslip-print">
                <x-payroll.document :document="$payslipDocument" :details="false" />
            </div>
        @endif
    </flux:modal>
</div>
