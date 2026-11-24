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
                Punch in and out, check your attendance and schedule, file leave and travel, and view your payslips and issued items.
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
            >Punch Station</button>
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
                aria-selected="{{ $tab === 'schedule' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'schedule')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'schedule' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
            >My Schedule</button>
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === 'requests' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'requests')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'requests' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
            >Leave &amp; Travel</button>
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === 'payslips' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'payslips')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'payslips' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
            >My Payslips</button>
            <button type="button" role="tab" aria-selected="{{ $tab === 'inventory' ? 'true' : 'false' }}" wire:click="$set('tab', 'inventory')" class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'inventory' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}">My Issued Items</button>
        </div>

        @if ($tab === 'inventory')
            <div class="mt-4"><livewire:inventory.employee-items :own="true" key="portal-inventory" /></div>
        @elseif ($tab === 'attendance')
            <div class="mt-4"><livewire:portal.my-attendance /></div>
        @elseif ($tab === 'schedule')
            <div class="mt-4"><livewire:portal.my-schedule key="portal-schedule" /></div>
        @elseif ($tab === 'punch')
            <div class="mt-4 space-y-4">
                @if ($hasEmployee)
                    <div class="grid items-start gap-4 xl:min-h-[max(28rem,calc(100dvh_-_18.5rem))] xl:grid-cols-[minmax(0,27rem)_minmax(0,1fr)] xl:items-stretch" data-punch-row>
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

                        {{--
                            Side by side, the two cards are ONE height, and that
                            height is the rest of the screen: the row is at
                            least the viewport less the header, title, tabs and
                            spacing above and below it (18.5rem, measured so
                            the page itself does not scroll), never under
                            28rem. The station stretches to it, its punch
                            buttons at its foot; My Punches — taken out of the
                            flow, so its rows never make the row taller — fills
                            it and scrolls its own table, so as many punches as
                            fit are read without scrolling the page. Stacked
                            (below xl) it keeps its own height cap.
                        --}}
                        <div class="min-w-0 xl:relative xl:*:absolute xl:*:inset-0" data-my-punches-slot>
                            <livewire:portal.my-punches wire:key="portal-my-punches" />
                        </div>
                    </div>
                @endif
            </div>
        @elseif ($tab === 'requests')
            <div class="mt-4 space-y-4">
                @if ($hasEmployee)
                    <div class="hris-card flex flex-wrap items-center justify-between gap-3 px-5 py-4" data-apply-card>
                        <div class="min-w-0">
                            <h2 class="text-base font-bold">File Leave or Business Travel</h2>
                            <p class="text-sm text-muted-foreground">Complete the Application for Leave. Your name, office, position and salary come from your record. Official business is on the same form, under Others.</p>
                        </div>
                        <flux:button variant="primary" icon="document-text" wire:click="$set('applyOpen', true)" data-apply-button>Apply for Leave</flux:button>
                    </div>
                @endif
                <div>
                    {{-- Not in the reference: MY leave credits for the year, per
                         type — what Leave Management entered, what approved leave
                         with pay has used, and what is left. Own-pinned in
                         LeaveCreditRecords::own(); nothing for an unlinked account,
                         whose notice above already says so. --}}
                    @if ($hasEmployee)
                        <div class="hris-card min-w-0 overflow-hidden" data-leave-credits>
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                                <h2 class="text-base font-bold">Leave Credits · {{ $creditYear }}</h2>
                                <div class="flex flex-wrap items-center gap-3">
                                    <p class="text-xs text-muted-foreground">Credits used come from approved paid leave.</p>
                                    @if ($cashOutTypes !== [])
                                        <flux:button size="sm" variant="outline" icon="banknotes" wire:click="$set('cashOutOpen', true)" data-cash-out-button>Convert Credits to Cash</flux:button>
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
                                                <th><span class="block text-right">Available</span></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {{-- Every leave type that uses credits; one with none entered reads 0. --}}
                                            @foreach ($balances as $b)
                                                @php $left = $b->remaining ?? 0.0 - $b->used; @endphp
                                                <tr wire:key="credit-{{ $b->request_type }}" @if (! $b->entered()) data-no-credits @endif>
                                                    <td>{{ $b->label }}</td>
                                                    <td class="num text-right">{{ PortalLabels::credits($b->credited ?? 0.0) }}</td>
                                                    <td class="num text-right">{{ PortalLabels::credits($b->used) }}</td>
                                                    <td class="num text-right">
                                                        <span @class(['pill pill-caution' => $left <= 0])>{{ PortalLabels::credits($left) }}</span>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                @php $setAside = $balances->sum(static fn ($b) => $b->spoken_for); @endphp
                                <p class="px-5 pb-3 text-xs text-muted-foreground" data-credit-note>
                                    Lateness and absences are deducted from these credits first, so a balance can go negative.
                                    @if ($setAside > 0)
                                        <span class="num">{{ PortalLabels::credits($setAside) }}</span> {{ $setAside === 1.0 ? 'credit is' : 'credits are' }} set aside for cash conversion requests and cannot be filed.
                                    @endif
                                </p>
                            @endif
                            {{-- MY leave types with set days: what is left of a yearly one, what each filing of the other may take. --}}
                            @if ($allowances !== [])
                                <div class="border-t border-border px-5 py-3" data-leave-allowances>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Leave with Set Days</p>
                                    <ul class="mt-2 space-y-1 text-sm">
                                        @foreach ($allowances as $a)
                                            <li wire:key="allowance-{{ $loop->index }}" class="flex flex-wrap justify-between gap-2">
                                                <span>{{ $a['name'] }}</span>
                                                @if ($a['filing'] === \App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits::FILING_YEARLY)
                                                    <span class="num text-muted-foreground">{{ PortalLabels::dayCount($a['left']) }} of {{ PortalLabels::dayCount($a['allowance']) }} days left in {{ $creditYear }}</span>
                                                @else
                                                    <span class="num text-muted-foreground">up to {{ PortalLabels::dayCount($a['allowance']) }} days each time</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                    <p class="mt-2 text-xs text-muted-foreground">Days beyond these are unpaid leave days.</p>
                                </div>
                            @endif
                            {{-- MY cash-out requests (LeaveCashOutRecords::own): what was asked, decided and paid. --}}
                            @if ($cashOuts->isNotEmpty())
                                <div class="border-t border-border px-5 py-3" data-cash-outs>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Cash Conversion Requests</p>
                                    <ul class="mt-2 space-y-1 text-sm">
                                        @foreach ($cashOuts as $c)
                                            <li class="flex flex-wrap items-center justify-between gap-2" wire:key="cash-out-{{ $c->id }}" data-cash-out="{{ $c->status }}">
                                                <span>{{ $c->type_name }} · <span class="num">{{ PortalLabels::credits($c->days) }}</span> {{ $c->days === 1.0 ? 'day' : 'days' }}@if (isset(\App\Modules\Timekeeping\Application\LeaveCashOuts::PURPOSES[$c->purpose ?? ''])) · <span data-cash-out-purpose>{{ \App\Modules\Timekeeping\Application\LeaveCashOuts::PURPOSES[$c->purpose] }}</span>@endif · {{ $c->requested_at->setTimezone(\App\Support\LocalClock::zone())->format('M d, Y') }}@if ($c->amount !== null) · <span class="num">${{ number_format($c->amount, 2) }}</span>@endif</span>
                                                <span class="flex items-center gap-2">
                                                    <span class="pill {{ match ($c->status) { 'PENDING' => 'pill-caution', 'APPROVED' => 'pill-primary', 'PAID' => 'pill-success-strong', default => 'pill-muted' } }}">{{ ucfirst(strtolower($c->status)) }}</span>
                                                    {{-- Terminal leave is HR's filing: a leave manager rejects it, the employee does not withdraw it. --}}
                                                    @if ($c->status === 'PENDING' && $c->purpose !== \App\Modules\Timekeeping\Application\LeaveCashOuts::TERMINAL)
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
                                    <flux:heading size="lg" id="cash-out-heading">Convert Leave Credits to Cash</flux:heading>
                                    <flux:text class="text-sm text-muted-foreground">Convert part of your credits into cash, in whole or half days. A leave manager approves it, and it is paid on your next regular payroll.</flux:text>
                                    {{-- Live, so the picked type's limits show before the request is sent. --}}
                                    <x-kit.select label="Leave Type" wire:model.live="cashOutType" placeholder="Choose a leave type">
                                        @foreach ($cashOutTypes as $option)<flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>@endforeach
                                    </x-kit.select>
                                    @if ($cashOutTerms !== null && $cashOutTerms['lines'] !== [])
                                        <ul class="list-disc space-y-0.5 pl-5 text-xs text-muted-foreground" data-cash-out-terms>
                                            @foreach ($cashOutTerms['lines'] as $line)<li>{{ $line }}</li>@endforeach
                                        </ul>
                                    @endif
                                    <flux:input label="Days" type="number" min="0.5" step="0.5" wire:model="cashOutDays" />
                                    <div class="space-y-1">
                                        <flux:textarea label="Note (optional)" wire:model="cashOutNote" rows="2" maxlength="500" />
                                        {{-- A large request's note is its reason. --}}
                                        @if (($cashOutTerms['reason_from'] ?? null) !== null)
                                            <p class="text-xs text-muted-foreground" data-cash-out-reason-hint>Converting <span class="num">{{ \App\Modules\Timekeeping\Domain\Credits\LeaveQuantity::format($cashOutTerms['reason_from']) }}</span> days or more needs a reason: say why in the note.</p>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <flux:button variant="ghost" wire:click="$set('cashOutOpen', false)">Cancel</flux:button>
                                        <flux:button variant="primary" icon="check" wire:click="requestCashOut">Send Request</flux:button>
                                    </div>
                                </div>
                            </flux:modal>
                        </div>
                    @endif

                    {{-- The Application for Leave (leaves/partials/application-form).
                         STICKY FOOTER, as the eDTR dialogs: the <dialog> is the scroll
                         container and Flux pads it (p-6), and its fill is `bg-inherit`
                         down an unbroken chain from the <dialog>. Closing it keeps the
                         draft; a successful filing clears it. --}}
                    @if ($hasEmployee)
                        <flux:modal wire:model.self="applyOpen" class="hris-always-light w-full max-w-5xl scroll-pb-32" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'apply-leave-heading')">
                            <form wire:submit="file" class="space-y-4 bg-inherit">
                                @include('livewire.leaves.partials.application-form', ['filedForm' => null, 'headingId' => 'apply-leave-heading'])

                                <div class="sticky -bottom-6 z-10 -mx-6 -mb-6 mt-2 flex flex-wrap justify-end gap-2 border-t border-border bg-inherit px-6 py-3">
                                    <flux:button type="button" variant="ghost" wire:click="$set('applyOpen', false)">Cancel</flux:button>
                                    <flux:button
                                        type="submit"
                                        variant="primary"
                                        :disabled="$submitDisabled"
                                        wire:loading.attr="disabled"
                                        wire:target="file"
                                    >
                                        <span wire:loading wire:target="file" class="mr-2 inline-flex"><flux:icon.loading class="size-4" /></span>
                                        <span wire:loading.remove wire:target="file" class="mr-2 inline-flex"><flux:icon.paper-airplane class="size-4" /></span>
                                        Submit Application
                                    </flux:button>
                                </div>
                            </form>
                        </flux:modal>

                        {{-- A filed application, read-only: the same form with what was filed and what the office recorded. Drawn only while open. --}}
                        <flux:modal wire:model.self="filedOpen" class="hris-always-light w-full max-w-5xl scroll-pb-32" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'filed-leave-heading')">
                            @if ($filedForm !== null)
                                <div class="space-y-4 bg-inherit" data-filed-application>
                                    @include('livewire.leaves.partials.application-form', [
                                        'filedForm' => $filedForm,
                                        'headingId' => 'filed-leave-heading',
                                        'applyTypes' => $filedForm['types'],
                                        'certification' => $filedForm['certification'],
                                        'applicant' => $filedApplicant,
                                        'officers' => $filedOfficers,
                                    ])

                                    <div class="sticky -bottom-6 z-10 -mx-6 -mb-6 mt-2 flex flex-wrap justify-end gap-2 border-t border-border bg-inherit px-6 py-3">
                                        <flux:button type="button" variant="ghost" wire:click="$set('filedOpen', false)">Close</flux:button>
                                    </div>
                                </div>
                            @endif
                        </flux:modal>
                    @endif
                </div>

                <div class="hris-card overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3">
                        <h2 class="text-base font-bold">My Requests</h2>
                    </div>
                    <div class="hris-table-wrap hris-table-scroll">
                        <table class="hris-table">
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th>Day Type</th>
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
                                        <td>
                                            {{ PortalLabels::describe($r->request_type, $leaveTypeNames) }}
                                            {{-- What the application said: the details of leave, and a commutation asked for. --}}
                                            @php
                                                $rDetail = \App\Modules\Timekeeping\Domain\Absence\LeaveApplication::describe($r->leave_detail ?? null, $r->leave_detail_note ?? null);
                                                $rCommuted = ($r->commutation ?? null) === \App\Modules\Timekeeping\Domain\Absence\LeaveApplication::REQUESTED;
                                            @endphp
                                            @if ($rDetail !== null || $rCommuted)
                                                <p class="mt-1 max-w-xs break-words text-xs text-muted-foreground" data-request-details>{{ implode(' · ', array_filter([$rDetail, $rCommuted ? 'Commutation requested' : null])) }}</p>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap">{{ PortalLabels::dayTypeLabel($r->day_type, $r->half ?? null) }}</td>
                                        <td class="num">{{ $r->start_date->format('M d, Y') }}</td>
                                        <td class="num">{{ $r->end_date->format('M d, Y') }}</td>
                                        <td>
                                            <span @class([
                                                'pill pill-success' => $r->status === 'APPROVED',
                                                'pill pill-danger' => $r->status === 'REJECTED',
                                                'pill pill-warning' => $r->status === 'PENDING',
                                                'pill pill-muted' => ! in_array($r->status, ['APPROVED', 'REJECTED', 'PENDING'], true),
                                            ])>{{ \App\Support\HrisFormat::requestStatus($r->status) }}</span>
                                            {{-- Why management disapproved it, as they wrote it. --}}
                                            @if ($r->status === 'REJECTED' && trim((string) ($r->disapproval_reason ?? '')) !== '')
                                                <p class="mt-2 max-w-sm whitespace-pre-wrap break-words text-xs" data-disapproval-reason><span class="text-muted-foreground">Disapproved due to:</span> {{ trim((string) $r->disapproval_reason) }}</p>
                                            @endif
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
                                                    {{ $r->is_paid ? (($r->unpaid_dates ?? []) !== [] ? 'partly paid · '.count($r->unpaid_dates).' unpaid leave '.(count($r->unpaid_dates) === 1 ? 'day' : 'days') : 'paid') : 'unpaid' }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap text-right">
                                            <flux:button type="button" size="sm" variant="ghost" wire:click="viewFiled('{{ $r->id }}')" data-view-filed>View Form</flux:button>
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
                            <h2 class="text-base font-bold">My Payslips</h2>
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
                                        <td class="num text-right">{{ PortalLabels::money($p->gross_pay) }}</td>
                                        <td class="num text-right text-danger">{{ PortalLabels::money($p->total_deductions) }}</td>
                                        <td class="num text-right font-bold">{{ PortalLabels::money($p->net_pay) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            @if ($payslips->isNotEmpty())
                                {{-- Pinned to the foot of the scrolling list, so a long list still shows its total. --}}
                                <tfoot data-payslip-totals>
                                    <tr>
                                        <td class="sticky bottom-0">Total</td>
                                        <td class="num sticky bottom-0 text-right">{{ PortalLabels::money($payslipTotals['gross']) }}</td>
                                        <td class="num sticky bottom-0 text-right text-danger">{{ PortalLabels::money($payslipTotals['deductions']) }}</td>
                                        <td class="num sticky bottom-0 text-right">{{ PortalLabels::money($payslipTotals['net']) }}</td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </section>
            </div>
        @endif
    </div>

    {{-- The payslip dialog shows THE PAYSLIP AND NOTHING ROUND IT: Flux's bare variant
         draws no panel — no background, padding, shadow or close button — so the sheet
         itself is what opens over the page. No heading or period line either: the
         payslip carries both. Only the close button stays, above the sheet where it is
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
            <div class="mb-2 flex justify-end print:hidden">
                <flux:modal.close>
                    <flux:button type="button" variant="outline" size="sm" icon="x-mark" aria-label="Close" />
                </flux:modal.close>
            </div>

            {{-- The employee's copy is the pay statement alone: what the employer
                 pays is NOT shown in My Portal; Payroll and Reports show it. --}}
            @if ($payslipStatement !== null)
                <div class="payslip-print">
                    <x-payroll.statement
                        :statement="$payslipStatement"
                        :employee="$payslipEmployee['name']"
                        :employee-no="$payslipEmployee['number']"
                        :position="$payslipEmployee['position']"
                    />
                </div>
            @endif
        @endif
    </flux:modal>
</div>
