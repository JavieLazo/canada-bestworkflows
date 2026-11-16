{{--
    Leave Management — the port of leaves-page.tsx (table + filters) and
    leave-detail-dialog.tsx (the review dialog), one screen.

    Fidelity notes where shadcn has no Flux twin:
      - Avatar / AvatarFallback are a span with an <img> or the initials,
        styled with the same Tailwind the source's classes resolved to.
      - The Dialog is <flux:modal>; header/description are plain markup
        carrying shadcn's DialogTitle/DialogDescription classes.
      - The source disables all three review buttons and spins the busiest one
        while `busy` is true; here every button disables on wire:target
        "review" and Flux's built-in wire-loading spinner marks the clicked one.

    Not in the source: a tab strip (the My Portal approximation of shadcn
    Tabs) over the table, with a second tab, Leave Credits, that mounts the
    leaves.leave-credits child; and the review dialog's own layout: banded
    sections, the reason beside the decision, and the type's credits for
    the year.
    The dialog stays outside the tab branches, as the portal keeps its own.
--}}
@php
    use App\Livewire\Leaves\Index as LeavesIndex;
    use App\Livewire\Leaves\LeaveLabels;
    use App\Modules\Timekeeping\Application\LeaveRecommendation;
    use App\Modules\Timekeeping\Domain\Absence\LeaveApplication;
    use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
    use App\Support\ManilaClock;
    use App\Platform\Files\PortraitUrl;

    // The status pill (leaves-page.tsx:314-323).
    $pill = static fn (string $status): string => match ($status) {
        'APPROVED' => 'pill pill-success',
        'REJECTED' => 'pill pill-danger',
        'PENDING' => 'pill pill-warning',
        default => 'pill pill-muted',
    };

    $photoUrl = static fn ($employee): ?string => $employee?->photo_path
        ? PortraitUrl::for($employee->photo_path)
        : null;

    $initials = static fn ($employee): string => $employee !== null
        ? \App\Support\EmployeeName::initials($employee)
        : '—';

    $employeeName = static fn ($employee): string => \App\Support\EmployeeName::display($employee);

    // fmtDate / fmtDateTime (hris.ts:199-213); datetimes display in Manila.
    $fmtDate = static fn ($d): string => $d->format('M d, Y');
    $fmtDateTime = static fn ($at): string => $at->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A');
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Leave Management</h1>
            <p class="text-sm text-muted-foreground">
                Manage leave and official business request. Review and approve requests, track paid and
                unpaid leave days, and monitor employee leave credits and transaction history.
            </p>
        </div>
    </div>

    <div>
        {{-- The same tab strip My Portal and eDTR draw: a muted role=tablist of
             role=tab buttons; $set fires updatedTab(), which whitelists the
             value. Only the active tab's content is rendered. --}}
        <div class="inline-flex h-9 items-center justify-center rounded-lg bg-muted p-1 text-muted-foreground" role="tablist">
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === 'requests' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'requests')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'requests' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
            >
                Requests
            </button>
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === 'credits' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'credits')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'credits' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
            >
                Leave Credits
            </button>
            <button type="button" role="tab" aria-selected="{{ $tab === 'ledger' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'ledger')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'ledger' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}">
                Leave Ledger
            </button>
            <button type="button" role="tab" aria-selected="{{ $tab === 'cashouts' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'cashouts')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'cashouts' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}">
                Cash Conversions
            </button>
        </div>

        @if ($tab === 'requests')
            <section class="mt-4 hris-card overflow-hidden">
                <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
                    @if ($canFile)
                        <flux:button variant="primary" icon="plus" wire:click="openFile" data-file-for-employee>File for an Employee</flux:button>
                    @endif
                    <flux:input
                        aria-label="Search requests"
                        placeholder="Search ID, employee or description"
                        class="w-full min-w-[12rem] max-w-xs flex-1"
                        wire:model.live="search"
                    />

                    <x-kit.filter-select label="Filter by Status" class="w-[11rem]" wire:model.live="status">
                        <flux:select.option value="ALL">All statuses</flux:select.option>
                        @foreach (LeavesIndex::STATUSES as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </x-kit.filter-select>

                    <x-kit.filter-select label="Filter by Description" class="w-[14rem]" wire:model.live="description">
                        <flux:select.option value="ALL">All descriptions</flux:select.option>
                        @foreach ($descriptions as $name)
                            <flux:select.option value="{{ $name }}">{{ $name }}</flux:select.option>
                        @endforeach
                    </x-kit.filter-select>

                    <x-kit.filter-select label="Filter by Day Type" class="w-[11rem]" wire:model.live="dayType">
                        <flux:select.option value="ALL">All day types</flux:select.option>
                        @foreach (LeaveLabels::DAY_TYPES as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </x-kit.filter-select>

                    <div class="ml-auto">
                        <x-kit.export-button :href="route('leaves.export', array_filter(['q' => $search, 'status' => $status, 'description' => $description, 'dayType' => $dayType, 'sort' => $sortKey, 'dir' => $sortDir]))" />
                    </div>
                </header>

                <div class="hris-table-wrap hris-table-scroll">
                    <table class="hris-table">
                        <thead>
                            <tr>
                                <x-kit.sort-header field="id" label="ID" />
                                <x-kit.sort-header field="employee" label="Employee" />
                                <x-kit.sort-header field="filed" label="Date Filed" />
                                <x-kit.sort-header field="date" label="Leave Period" />
                                <x-kit.sort-header field="description" label="Description" />
                                <x-kit.sort-header field="dayType" label="Day Type" />
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @if ($rows->total() === 0)
                                <tr wire:key="empty">
                                    <x-kit.empty-state :colspan="7" title="No request matches those filters." />
                                </tr>
                            @endif
                            @foreach ($rows as $r)
                                @php
                                    $employee = $r->employee;
                                    $isOb = $r->request_type === LeaveLabels::OFFICIAL_BUSINESS;
                                    $filedAt = $r->created_at->setTimezone(ManilaClock::TZ);
                                @endphp
                                <tr
                                    wire:key="req-{{ $r->id }}"
                                    tabindex="0"
                                    aria-label="Open {{ $r->code ?: 'request' }}"
                                    class="cursor-pointer focus:outline-none focus-visible:bg-accent"
                                    wire:click="show('{{ $r->id }}')"
                                    x-on:keydown.enter.prevent="$wire.show('{{ $r->id }}')"
                                    x-on:keydown.space.prevent="$wire.show('{{ $r->id }}')"
                                >
                                    {{-- A request with no code prints "—". --}}
                                    <td class="num whitespace-nowrap align-middle">{{ $r->code ?: '—' }}</td>
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                                                @if ($photoUrl($employee))
                                                    <img src="{{ $photoUrl($employee) }}" alt="{{ $employeeName($employee) }}" class="h-full w-full object-cover" />
                                                @else
                                                    <span class="num text-xs font-semibold">{{ $initials($employee) }}</span>
                                                @endif
                                            </span>
                                            <div class="min-w-0">
                                                <p class="truncate font-medium">{{ $employeeName($employee) }}</p>
                                                <p class="truncate text-xs text-muted-foreground">{{ $employee?->position ?? '—' }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    {{-- When the request was recorded, in Manila: the day, the time under it. --}}
                                    <td class="num whitespace-nowrap">
                                        <p>{{ $fmtDate($filedAt) }}</p>
                                        <p class="text-xs text-muted-foreground">{{ $filedAt->format('g:i A') }}</p>
                                    </td>
                                    <td class="num whitespace-nowrap">
                                        {{ $fmtDate($r->start_date) }}{{ $r->start_date->format('Y-m-d') === $r->end_date->format('Y-m-d') ? '' : ' – '.$fmtDate($r->end_date) }}
                                    </td>
                                    <td class="whitespace-nowrap">{{ $described[$r->id] }}</td>
                                    <td class="whitespace-nowrap">{{ LeaveLabels::dayTypeLabel($r->day_type, $r->half ?? null) }}</td>
                                    <td class="whitespace-nowrap">
                                        <span class="{{ $pill($r->status) }}">{{ \App\Support\HrisFormat::requestStatus($r->status) }}</span>
                                        @if ($r->status === 'APPROVED' && $r->cancelled_dates !== [])
                                            <span class="ml-2 text-xs text-muted-foreground">Partially cancelled</span>
                                        @endif
                                        @if ($r->status === 'APPROVED' && ! $isOb)
                                            <span class="ml-2 text-xs text-muted-foreground">
                                                {{ $r->is_paid === false ? 'unpaid' : (($r->unpaid_dates ?? []) !== [] ? 'partly paid' : 'paid') }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-kit.pagination :paginator="$rows" noun="requests" />
            </section>
        @elseif ($tab === 'credits')
            <div class="mt-4">
                <livewire:leaves.leave-credits wire:key="leaves-credits" />
            </div>
        @elseif ($tab === 'cashouts')
            <div class="mt-4">
                <livewire:leaves.leave-cash-outs wire:key="leaves-cash-outs" />
            </div>
        @else
            <div class="mt-4">
                <livewire:leaves.leave-ledger wire:key="leaves-ledger" />
            </div>
        @endif
    </div>

    <flux:modal wire:model.self="detailOpen" class="w-full max-w-3xl">
        @if ($open)
            @php
                $emp = $open->employee;
                $openIsOb = $open->request_type === LeaveLabels::OFFICIAL_BUSINESS;
                // Calendar days the request spans, inclusive (leave-detail-dialog.tsx:23-27).
                $days = max(1, (int) $open->start_date->diffInDays($open->end_date) + 1);
                $pending = $open->status === 'PENDING';
                // "Leave Request #5": the code the table shows, a bare number marked as one.
                $kind = $openIsOb ? 'Official Business' : 'Leave Request';
                $openCode = (string) ($open->code ?? '');
                $heading = $openCode === '' ? $kind : $kind.(ctype_digit($openCode) ? ' #' : ' ').$openCode;
                // The dialog's bands: a rule between each pair, no space above the first or below the last.
                $band = 'py-5 first:pt-0 last:pb-0';
                $fieldLabel = 'text-xs uppercase tracking-wide text-muted-foreground';
                // The decision as the approval panel states it. A cancelled
                // request keeps its original approval; the Cancelled dates
                // band below says which dates were taken back.
                $openUnpaid = count($open->unpaid_dates ?? []);
                [$decision, $decisionDetail, $tone] = match ($open->status) {
                    'APPROVED', 'CANCELLED' => $openIsOb
                        ? ['Approved as official business', 'Credited as time worked.', 'success']
                        : ($open->is_paid === false
                            ? ['Approved as unpaid leave', null, 'success']
                            : ($openUnpaid > 0
                                ? ['Approved as partly paid', $openUnpaid.' unpaid leave '.($openUnpaid === 1 ? 'day' : 'days').' from '.\App\Support\HrisFormat::date(min($open->unpaid_dates)).'.', 'success']
                                : ['Approved as paid leave', null, 'success'])),
                    'REJECTED' => ['Disapproved', null, 'danger'],
                    'WITHDRAWN' => ['Withdrawn before review', null, 'muted'],
                    default => ['Awaiting review', null, 'muted'],
                };
                $decidedBy = implode(' · ', array_filter([
                    $reviewerName,
                    $open->reviewed_at ? $fmtDateTime($open->reviewed_at) : null,
                ]));
            @endphp
            <div class="divide-y divide-border">
                {{-- pe-10 keeps the status clear of the modal's close button. --}}
                <header class="{{ $band }} flex items-start justify-between gap-4 pe-10">
                    <div class="min-w-0 space-y-1.5">
                        <h2 class="text-lg font-semibold leading-none tracking-tight">{{ $heading }}</h2>
                        <p class="text-sm text-muted-foreground">
                            {{ $pending
                                ? 'Review the request, then approve or disapprove it. Approving pays the days the available balance covers; the rest are unpaid leave days.'
                                : 'This request has already been reviewed.' }}
                        </p>
                    </div>
                    <span class="{{ $pill($open->status) }} shrink-0">{{ LeavesIndex::STATUSES[$open->status] ?? $open->status }}</span>
                </header>

                <div class="{{ $band }} flex flex-wrap items-center gap-x-3 gap-y-2">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                        @if ($photoUrl($emp))
                            <img src="{{ $photoUrl($emp) }}" alt="{{ $employeeName($emp) }}" class="h-full w-full object-cover" />
                        @else
                            <span class="num text-sm font-semibold">{{ $initials($emp) }}</span>
                        @endif
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-semibold">{{ $employeeName($emp) }}</p>
                        {{-- The Employee ID when there is one, then the position:
                             joined by EmployeeIdentity so an unnumbered employee
                             shows their position alone rather than "— · Driver". --}}
                        <p class="num truncate text-xs text-muted-foreground">
                            {{ \App\Support\EmployeeIdentity::meta(\App\Support\EmployeeIdentity::besideName($emp), $emp?->position) ?: '—' }}
                        </p>
                    </div>
                    <div class="ml-auto flex flex-wrap items-center gap-3">
                        @if ($reviewerName)
                            <p class="text-sm text-muted-foreground">Reviewed by <span class="text-foreground">{{ $reviewerName }}</span></p>
                        @endif
                        <flux:button type="button" size="sm" variant="outline" icon="document-text" wire:click="viewApplication" data-view-application>View Application Form</flux:button>
                    </div>
                </div>

                <dl class="{{ $band }} grid grid-cols-2 gap-x-6 gap-y-5 sm:grid-cols-3">
                    <div class="min-w-0">
                        <dt class="{{ $fieldLabel }}">{{ $openIsOb ? 'Request Type' : 'Leave Type' }}</dt>
                        <dd class="mt-1 break-words font-semibold">{{ $openDescription }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="{{ $fieldLabel }}">Day Type</dt>
                        <dd class="mt-1 break-words font-semibold">{{ LeaveLabels::dayTypeLabel($open->day_type, $open->half ?? null) }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="{{ $fieldLabel }}">{{ $openIsOb ? 'Period' : 'Leave Period' }}</dt>
                        <dd class="num mt-1 font-semibold">{{ $days }} {{ $days === 1 ? 'day' : 'days' }}{{ $open->day_type === 'HALF_DAY' && $days === 1 ? ' (half)' : '' }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="{{ $fieldLabel }}">Start Date</dt>
                        <dd class="num mt-1 font-semibold">{{ $fmtDate($open->start_date) }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="{{ $fieldLabel }}">End Date</dt>
                        <dd class="num mt-1 font-semibold">{{ $fmtDate($open->end_date) }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="{{ $fieldLabel }}">Filed On</dt>
                        <dd class="num mt-1 font-semibold">{{ $open->created_at->setTimezone(ManilaClock::TZ)->format('M d, Y · g:i A') }}</dd>
                    </div>
                    {{-- The application's details of leave and commutation, as filed; a request filed before they were kept has neither. --}}
                    @php
                        $openDetail = LeaveApplication::describe($open->leave_detail ?? null, $open->leave_detail_note ?? null);
                        $openCommutation = LeaveApplication::COMMUTATION_LABELS[$open->commutation ?? ''] ?? null;
                    @endphp
                    @if ($openDetail !== null)
                        <div class="col-span-2 min-w-0">
                            <dt class="{{ $fieldLabel }}">Details of Leave</dt>
                            <dd class="mt-1 break-words font-semibold" data-leave-detail>{{ $openDetail }}</dd>
                        </div>
                    @endif
                    @if ($openCommutation !== null)
                        <div class="min-w-0">
                            <dt class="{{ $fieldLabel }}">Commutation</dt>
                            <dd class="mt-1 font-semibold" data-commutation-value>{{ $openCommutation }}</dd>
                        </div>
                    @endif
                </dl>

                <div class="{{ $band }} grid gap-6 sm:grid-cols-2">
                    <section class="min-w-0 space-y-3">
                        <h3 class="text-sm font-semibold">{{ $openIsOb ? 'Reason' : 'Reason for Leave' }}</h3>
                        {{-- As the employee typed it: line breaks and spacing kept, so the text sits on one line inside the element. --}}
                        <p class="whitespace-pre-wrap break-words rounded-md bg-muted/40 p-3 text-sm" data-leave-reason>{{ trim($open->reason ?? '') !== '' ? trim($open->reason) : 'No reason given.' }}</p>
                    </section>
                    <section class="min-w-0 space-y-3">
                        <h3 class="text-sm font-semibold">Approval Information</h3>
                        <div class="space-y-2 rounded-md bg-muted/40 p-3">
                            <div class="flex items-start justify-between gap-3">
                                <p class="font-semibold" data-decision>{{ $decision }}</p>
                                @if ($tone === 'success')
                                    <flux:icon.check-circle variant="mini" class="size-5 shrink-0 text-success" />
                                @elseif ($tone === 'danger')
                                    <flux:icon.x-circle variant="mini" class="size-5 shrink-0 text-danger" />
                                @elseif ($pending)
                                    <flux:icon.clock variant="mini" class="size-5 shrink-0 text-muted-foreground" />
                                @else
                                    <flux:icon.minus-circle variant="mini" class="size-5 shrink-0 text-muted-foreground" />
                                @endif
                            </div>
                            @if ($decisionDetail !== null)
                                <p class="text-sm">{{ $decisionDetail }}</p>
                            @endif
                            @if ($decidedBy !== '')
                                <p class="text-xs text-muted-foreground">{{ $decidedBy }}</p>
                            @endif
                            {{-- The recommendation recorded before the decision: what was recommended, by whom and when. Whoever decides may follow it or not. --}}
                            @php $openRecommendation = LeaveRecommendation::describe($open->recommendation ?? null, $open->recommendation_reason ?? null); @endphp
                            @if ($openRecommendation !== null)
                                <div class="space-y-1 border-t border-border pt-2" data-recommendation>
                                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Recommendation</p>
                                    <p class="whitespace-pre-wrap break-words text-sm" data-recommendation-text>{{ $openRecommendation }}</p>
                                    <p class="text-xs text-muted-foreground">{{ implode(' · ', array_filter([$open->recommender?->name, $open->recommended_at ? $fmtDateTime($open->recommended_at) : null])) }}</p>
                                </div>
                            @endif
                            @if (trim((string) ($open->approval_note ?? '')) !== '')
                                <div class="space-y-1 border-t border-border pt-2">
                                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Approved for: others</p>
                                    <p class="whitespace-pre-wrap break-words text-sm" data-approval-note>{{ trim((string) $open->approval_note) }}</p>
                                </div>
                            @endif
                            {{-- Why it was disapproved: what the employee reads in My Portal. A request disapproved before reasons were kept has none. --}}
                            @if ($open->status === 'REJECTED')
                                @php $openReason = trim((string) ($open->disapproval_reason ?? '')); @endphp
                                <div class="space-y-1 border-t border-border pt-2">
                                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Disapproved Due To</p>
                                    @if ($openReason !== '')
                                        <p class="whitespace-pre-wrap break-words text-sm" data-disapproval-reason>{{ $openReason }}</p>
                                    @else
                                        <p class="text-sm text-muted-foreground">No reason was recorded.</p>
                                    @endif
                                </div>
                            @endif
                            {{-- What approving would pay: the days the credits (or the type's set days) do not cover are without pay, never refused. --}}
                            @if ($pending && $openPayNotice !== null)
                                <p class="text-sm text-foreground" role="status" data-pay-plan>
                                    <span class="pill pill-caution">Partly unpaid</span>
                                    {{ $openPayNotice }}
                                </p>
                            @endif
                            {{-- The leave type may go below zero (HR's limit): what approving with pay would take it to. --}}
                            @if ($pending && $openBelowZero !== null)
                                <p class="text-sm text-foreground" role="status" data-pay-below-zero>
                                    <span class="pill pill-caution">Negative balance</span>
                                    {{ $openBelowZero }}
                                </p>
                            @endif
                            {{-- The leave type falls back on another: say before the decision what approving with pay will draw from it. --}}
                            @if ($openFallback !== null)
                                <p class="text-xs text-muted-foreground" data-fallback-line>
                                    Approving as paid leave draws
                                    <span class="num">{{ LeaveQuantity::credits(min($openFallback['short'], $openFallback['available'])) }}</span>
                                    from {{ $openFallback['name'] }}
                                    (<span class="num">{{ LeaveQuantity::credits($openFallback['available']) }}</span> available){{ $openFallback['available'] < $openFallback['short'] ? ', which does not cover all of it' : '' }}.
                                </p>
                            @endif
                        </div>
                    </section>
                </div>

                {{-- Not in the source: the request's standing against its
                     type's credit for the year it draws on. Information for
                     the reviewer, never a refusal; see Index::creditLine(). --}}
                @if ($openBalance !== null)
                    @php
                        $needs = LeaveQuantity::credits($openNeeds);
                        $exceeds = $pending && $openBalance->entered() && $openNeeds > $openBalance->available;
                        $credited = (float) $openBalance->credited;
                        $remaining = (float) $openBalance->remaining;
                        // The share of the year's credit already used; none of nothing.
                        $usedShare = $openBalance->entered() && $credited > 0 ? $openBalance->used / $credited * 100 : null;
                        $usedBar = $usedShare === null ? 0.0 : min(100.0, max(0.0, $usedShare));
                    @endphp
                    <section class="{{ $band }} space-y-4" data-credits-line>
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <h3 class="text-sm font-semibold">{{ $openBalance->label }} Credits · {{ $openBalance->year }}</h3>
                            @if ($pending)
                                <span @class(['num text-sm', 'pill pill-caution' => $exceeds])>This request needs {{ $needs }}</span>
                            @else
                                <span class="num text-sm text-muted-foreground">This request counted {{ $needs }}</span>
                            @endif
                        </div>
                        @if ($openBalance->entered())
                            <dl class="grid grid-cols-3 gap-x-6 gap-y-4">
                                <div class="flex min-w-0 flex-col-reverse gap-1">
                                    <dt class="text-xs text-muted-foreground">Total Credited</dt>
                                    <dd class="num text-xl font-semibold">{{ LeaveQuantity::credits($credited) }}</dd>
                                </div>
                                <div class="flex min-w-0 flex-col-reverse gap-1">
                                    <dt class="text-xs text-muted-foreground">Used</dt>
                                    <dd class="num text-xl font-semibold">{{ LeaveQuantity::credits($openBalance->used) }}</dd>
                                </div>
                                <div class="flex min-w-0 flex-col-reverse gap-1">
                                    <dt class="text-xs text-muted-foreground">Available Balance</dt>
                                    <dd @class(['num text-xl font-semibold', 'text-success' => $remaining > 0, 'text-danger' => $remaining < 0])>{{ LeaveQuantity::credits($remaining) }}</dd>
                                </div>
                            </dl>
                            @if ($openBalance->spoken_for > 0)
                                <p class="text-xs text-muted-foreground">
                                    <span class="num">{{ LeaveQuantity::credits($openBalance->spoken_for) }}</span> of the available balance is set aside for cash conversion.
                                </p>
                            @endif
                            @if ($usedShare !== null)
                                <div class="space-y-2">
                                    <div class="flex justify-between gap-4 text-xs text-muted-foreground">
                                        <span id="credit-use-{{ $open->id }}">Credit usage</span>
                                        <span class="num">{{ number_format($usedShare, 2) }}%</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-muted" role="progressbar" aria-labelledby="credit-use-{{ $open->id }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $usedBar }}">
                                        <div @class(['h-full rounded-full', 'bg-success' => $usedShare <= 100, 'bg-danger' => $usedShare > 100]) style="width: {{ $usedBar }}%"></div>
                                    </div>
                                </div>
                            @endif
                        @else
                            <p class="text-sm text-muted-foreground">No {{ $openBalance->year }} {{ $openBalance->label }} credits entered.</p>
                        @endif
                    </section>
                @endif

                @if ($open->cancellations !== [])
                    <section class="{{ $band }} space-y-2" aria-label="Cancelled leave dates">
                        <h3 class="text-sm font-semibold">Cancelled Dates</h3>
                        <p class="text-xs text-muted-foreground">The original approval above is retained. Only these dates were cancelled.</p>
                        <ul class="space-y-2">
                            @foreach ($open->cancellations as $cancellation)
                                <li wire:key="cancelled-{{ $cancellation->id }}" class="rounded-md border border-border p-3 text-sm">
                                    <p class="font-medium num">{{ $fmtDate($cancellation->date) }}</p>
                                    <p class="break-words text-xs text-muted-foreground">Cancelled by {{ $cancellation->canceller?->name ?? 'Account #'.$cancellation->cancelled_by }} · {{ $fmtDateTime($cancellation->cancelled_at) }}</p>
                                    <p class="mt-1 break-words">{{ $cancellation->reason }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($open->cancellable_dates !== [])
                    <form wire:submit="cancelSelectedDates" class="{{ $band }} space-y-3" aria-label="Cancel future leave dates">
                        <h3 class="text-sm font-semibold">Cancel Future Dates</h3>
                        <p class="text-xs text-muted-foreground">Select the dates to cancel. Today, past dates and dates already in finalized payroll cannot be cancelled.</p>
                        <fieldset class="max-h-48 overflow-y-auto rounded-md border border-border p-3" wire:key="cancel-options-{{ $open->id }}-{{ count($open->cancelled_dates) }}">
                            <legend class="px-1 text-xs text-muted-foreground">Dates to Cancel</legend>
                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach ($open->cancellable_dates as $date)
                                    <div wire:key="cancel-date-{{ $open->id }}-{{ $date }}">
                                        <flux:checkbox wire:model.live="cancelDates" value="{{ $date }}" label="{{ $date }}{{ $open->day_type === 'HALF_DAY' ? ' · half day' : '' }}" aria-label="{{ $date }}{{ $open->day_type === 'HALF_DAY' ? ' · half day' : '' }}" />
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>
                        <flux:textarea label="Cancellation Reason" wire:model="cancelReason" rows="2" maxlength="1000" required />
                        @php
                            $selectedDates = array_values(array_intersect($open->cancellable_dates, $cancelDates));
                            $selected = count($selectedDates);
                            // Only the dates the approval charged give credits back
                            // (a rest day never cost one): the component counts them.
                            $returned = $cancelReturned;
                        @endphp
                        <p class="text-xs text-muted-foreground" role="status">
                            {{ $selected }} {{ $selected === 1 ? 'date' : 'dates' }} selected · {{ LeaveQuantity::credits($returned) }} leave {{ $returned === 1.0 ? 'credit' : 'credits' }} returned to {{ $open->credits_year ?? $open->start_date->format('Y') }}.
                            Attendance and draft payroll will recalculate. Any manually entered Leave pay needs payroll review.
                        </p>
                        @if ($cancelError !== '')
                            <p role="alert" class="text-sm text-danger">{{ $cancelError }}</p>
                        @endif
                        <div class="flex justify-end">
                            <flux:button type="submit" variant="primary" :disabled="$selected === 0" wire:loading.attr="disabled" wire:target="cancelSelectedDates" wire:key="cancel-submit-{{ $open->id }}-{{ $selected === 0 ? 'empty' : 'selected' }}">Cancel Selected Dates</flux:button>
                        </div>
                    </form>
                @elseif ($cancelError !== '')
                    <p role="alert" class="{{ $band }} text-sm text-danger">{{ $cancelError }}</p>
                @endif

                {{-- Days a posted payslip already paid (as absences): approving
                     still lands, but that payslip never changes, so the
                     reviewer is told first and Payroll is notified after. --}}
                @if ($pending && $openPaidDates !== [])
                    <div class="{{ $band }}">
                        <div role="status" data-paid-dates-warning class="flex gap-2 rounded-md border border-warning/20 bg-warning-soft px-3 py-2 text-sm text-foreground">
                            <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-4 shrink-0 text-warning" />
                            <p>
                                Already on a posted payslip:
                                <span class="num">{{ implode(', ', array_map(\App\Support\HrisFormat::date(...), $openPaidDates)) }}</span>.
                                Approving won't change that payslip; Payroll will be notified to settle any difference on the next payroll.
                            </p>
                        </div>
                    </div>
                @endif

                {{-- Dates with no shift: approving waits until the schedule is set
                     (ReviewAbsence refuses it too). Disapproving and recommending
                     do not. --}}
                @if ($pending && $openNoShift !== null)
                    <div class="{{ $band }}">
                        <div role="status" data-no-shift-hold class="flex gap-2 rounded-md border border-warning/20 bg-warning-soft px-3 py-2 text-sm text-foreground">
                            <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-4 shrink-0 text-warning" />
                            <p>{{ $openNoShift }}</p>
                        </div>
                    </div>
                @endif

                {{-- The recommendation before the decision: for approval, or for disapproval with the reason. The request stays pending. --}}
                @if ($pending && $recommending && $recommendationReady)
                    <form wire:submit="recommend" class="{{ $band }} space-y-3" data-recommendation-form>
                        <flux:radio.group wire:model.live="recommendation" label="Recommendation">
                            @foreach (LeaveRecommendation::LABELS as $value => $label)
                                <flux:radio value="{{ $value }}" :label="$label" />
                            @endforeach
                        </flux:radio.group>
                        @if ($recommendation === LeaveRecommendation::FOR_DISAPPROVAL)
                            <div class="space-y-2">
                                <flux:label>
                                    For Disapproval Due To
                                    <span aria-hidden="true" class="ml-0.5 text-danger">*</span>
                                    <span class="sr-only">(required)</span>
                                </flux:label>
                                <flux:textarea
                                    wire:model="recommendationReason"
                                    aria-label="For disapproval due to"
                                    rows="3"
                                    maxlength="{{ LeaveRecommendation::MAX_LENGTH }}"
                                    :invalid="$recommendationError !== ''"
                                    required
                                />
                            </div>
                        @endif
                        <p class="text-xs text-muted-foreground">The request stays pending until someone approves or disapproves it.</p>
                        @if ($recommendationError !== '')
                            <p role="alert" class="text-sm text-danger">{{ $recommendationError }}</p>
                        @endif
                        <div class="flex flex-wrap justify-end gap-2">
                            <flux:button type="button" variant="outline" wire:click="$set('recommending', false)">Back</flux:button>
                            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="recommend">Save Recommendation</flux:button>
                        </div>
                    </form>
                {{-- Disapproving asks why first: the reason is what the employee reads in My Portal, and the command requires it. --}}
                @elseif ($pending && $disapproving)
                    <form wire:submit="review('REJECTED')" class="{{ $band }} space-y-3" data-disapproval-form>
                        <div class="space-y-2">
                            <flux:label>
                                Disapproved Due To
                                <span aria-hidden="true" class="ml-0.5 text-danger" data-required-mark>*</span>
                                <span class="sr-only">(required)</span>
                            </flux:label>
                            <flux:textarea
                                wire:model="disapprovalReason"
                                aria-label="Disapproved due to"
                                rows="3"
                                maxlength="{{ \App\Modules\Timekeeping\Application\LeaveDisapproval::MAX_LENGTH }}"
                                placeholder="e.g. Not enough staff on those dates"
                                :invalid="$disapprovalError !== ''"
                                required
                            />
                        </div>
                        @if ($disapprovalError !== '')
                            <p role="alert" class="text-sm text-danger">{{ $disapprovalError }}</p>
                        @endif
                        <div class="flex flex-wrap justify-end gap-2">
                            <flux:button type="button" variant="outline" wire:click="$set('disapproving', false)">Back</flux:button>
                            <flux:button type="submit" variant="danger" icon="x-mark" wire:loading.attr="disabled" wire:target="review">Disapprove</flux:button>
                        </div>
                    </form>
                @elseif ($pending)
                    {{-- The application's "others (specify)": optional, recorded only. --}}
                    @if (! $openIsOb && \App\Modules\Timekeeping\Application\LeaveApprovalNote::ready())
                        <div class="{{ $band }}" data-approval-note-field>
                            <flux:input wire:model="approvalNote" label="Approved for: others (specify)" description="Optional. Shown on the application; it changes no pay or credits." maxlength="{{ \App\Modules\Timekeeping\Application\LeaveApprovalNote::MAX_LENGTH }}" />
                        </div>
                    @endif
                    <div class="{{ $band }} flex flex-wrap justify-end gap-2">
                        @if ($recommendationReady)
                            <flux:button
                                variant="outline"
                                icon="chat-bubble-left-ellipsis"
                                class="sm:mr-auto"
                                wire:click="$set('recommending', true)"
                                data-recommend
                            >
                                {{ ($open->recommendation ?? null) === null ? 'Recommend…' : 'Change Recommendation…' }}
                            </flux:button>
                        @endif
                        <flux:button
                            variant="outline"
                            icon="x-mark"
                            class="text-danger hover:text-danger"
                            wire:click="$set('disapproving', true)"
                            data-disapprove
                        >
                            Disapprove…
                        </flux:button>
                        @if ($openIsOb)
                            <flux:button
                                variant="primary"
                                icon="check"
                                wire:click="review('APPROVED')"
                                wire:loading.attr="disabled"
                                wire:target="review"
                                :disabled="$openNoShift !== null"
                                :title="$openNoShift"
                            >
                                Approve Official Business
                            </flux:button>
                        @elseif ($openUnpaidType)
                            {{-- A leave type filed without pay is approved without pay; there is nothing else to choose. --}}
                            <flux:button
                                variant="primary"
                                icon="check"
                                wire:click="review('APPROVED', false)"
                                wire:loading.attr="disabled"
                                wire:target="review"
                                :disabled="$openNoShift !== null"
                                :title="$openNoShift"
                            >
                                Approve as Unpaid Leave
                            </flux:button>
                        @else
                            <flux:button
                                variant="outline"
                                icon="check"
                                wire:click="review('APPROVED', false)"
                                wire:loading.attr="disabled"
                                wire:target="review"
                                :disabled="$openNoShift !== null"
                                :title="$openNoShift"
                            >
                                Approve as Unpaid Leave
                            </flux:button>
                            {{-- Pays as far as the credits or set days reach when it is clicked; the rest is without pay. --}}
                            <flux:button
                                variant="primary"
                                icon="check"
                                wire:click="review('APPROVED', true)"
                                wire:loading.attr="disabled"
                                wire:target="review"
                                :disabled="$openNoShift !== null"
                                :title="$openNoShift"
                                data-approve-paid
                            >
                                {{ $openPayNotice === null ? 'Approve as Paid Leave' : 'Approve' }}
                            </flux:button>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>

    {{-- The request's Application for Leave, read-only, over the review dialog. The decision stays in that dialog. Drawn only while open. --}}
    <flux:modal wire:model.self="formOpen" class="hris-always-light w-full max-w-5xl scroll-pb-32" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'review-leave-heading')">
        @if ($applicationForm !== null)
            <div class="space-y-4 bg-inherit" data-filed-application>
                @include('livewire.leaves.partials.application-form', [
                    'filedForm' => $applicationForm['filed'],
                    'headingId' => 'review-leave-heading',
                    'applyTypes' => $applicationForm['filed']['types'],
                    'certification' => $applicationForm['filed']['certification'],
                    'applicant' => $applicationForm['applicant'],
                    'officers' => $applicationForm['officers'],
                ])

                <div class="sticky -bottom-6 z-10 -mx-6 -mb-6 mt-2 flex flex-wrap justify-end gap-2 border-t border-border bg-inherit px-6 py-3">
                    <flux:button type="button" variant="ghost" wire:click="$set('formOpen', false)">Back to the Review</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- A manager files a request on an employee's behalf, past dates included. --}}
    @if ($canFile)
    <flux:modal wire:model.self="fileOpen" class="w-full max-w-lg" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'file-for-heading')">
        @if ($fileOpen)
        <form wire:submit="fileForEmployee" class="space-y-4" data-file-for-dialog>
            <flux:heading size="lg" level="2" id="file-for-heading">File for an Employee</flux:heading>
            <p class="text-sm text-muted-foreground">The request is filed in the employee's name and waits for approval like any other. Dates already past may be filed: a request brought in on paper is recorded as it was.</p>
            <div class="space-y-2">
                <p id="file-employee-label" class="text-sm font-medium">Employee</p>
                <livewire:kit.employee-picker name="file-employee" :value="$fileEmployeeId ?? ''" labelled-by="file-employee-label" wire:key="file-employee-{{ $fileEmployeeId ?? 'none' }}" />
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <x-kit.select label="Request Type" wire:model.live="fileForm.type">
                    <flux:select.option value="">Choose a type</flux:select.option>
                    @foreach ($requestOptions as $option)
                        <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </x-kit.select>
                <x-kit.select label="Day Type" wire:model.live="fileForm.day_type">
                    @foreach (LeaveLabels::DAY_TYPES as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </x-kit.select>
            </div>
            @if (($fileForm['day_type'] ?? '') === 'HALF_DAY' && $halvesReady)
                <x-kit.select label="Which Half" wire:model="fileForm.half">
                    <flux:select.option value="">Either half</flux:select.option>
                    @foreach (\App\Modules\Timekeeping\Application\LeaveHalves::HALVES as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </x-kit.select>
            @endif
            {{-- The application's Details of leave (what the picked type asks) and Commutation, as written on the request; official business carries neither. --}}
            @if ($applicationReady && ($fileForm['type'] ?? '') !== '' && ($fileForm['type'] ?? '') !== LeaveLabels::OFFICIAL_BUSINESS)
                @include('livewire.leaves.partials.application-fields', [
                    'section' => $fileDetailSection,
                    'detail' => (string) ($fileForm['leave_detail'] ?? ''),
                    'detailModel' => 'fileForm.leave_detail',
                    'noteModel' => 'fileForm.leave_detail_note',
                    'commutationModel' => 'fileForm.commutation',
                    'idPrefix' => 'file',
                ])
            @endif
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="space-y-2">
                    <flux:label for="file-start">From</flux:label>
                    <x-kit.date-field id="file-start" wire:model="fileForm.start" aria-label="From" />
                </div>
                <div class="space-y-2">
                    <flux:label for="file-end">To</flux:label>
                    <x-kit.date-field id="file-end" wire:model="fileForm.end" aria-label="To" />
                </div>
            </div>
            <div class="space-y-2">
                <flux:label for="file-reason">Reason (optional)</flux:label>
                <flux:textarea id="file-reason" wire:model="fileForm.reason" rows="3" maxlength="2000" placeholder="As written on the request" />
            </div>
            @if ($fileError !== null)
                <p role="alert" class="text-sm text-danger">{{ $fileError }}</p>
            @endif
            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="outline" wire:click="$set('fileOpen', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary">File Request</flux:button>
            </div>
        </form>
        @endif
    </flux:modal>
    @endif
</div>
