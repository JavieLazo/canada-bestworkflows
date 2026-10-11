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
    leaves.leave-credits child; and a credits line in the review dialog.
    The dialog stays outside the tab branches, as the portal keeps its own.
--}}
@php
    use App\Livewire\Leaves\Index as LeavesIndex;
    use App\Livewire\Leaves\LeaveLabels;
    use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
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
                Leave and official business filed from My Portal. Open a request to see the full detail
                and acknowledge it with or without pay. Leave Credits holds each employee's yearly
                entitlement per leave type. Leave Ledger records manual accruals, carry-forward
                entries, adjustments and leave taken.
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
        </div>

        @if ($tab === 'requests')
            <section class="mt-4 hris-card overflow-hidden">
                <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
                    <flux:input
                        aria-label="Search requests"
                        placeholder="Search ID, employee or description"
                        class="w-full min-w-[12rem] max-w-xs flex-1"
                        wire:model.live="search"
                    />

                    <x-kit.filter-select label="Filter by status" class="w-[11rem]" wire:model.live="status">
                        <flux:select.option value="ALL">All statuses</flux:select.option>
                        @foreach (LeavesIndex::STATUSES as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </x-kit.filter-select>

                    <x-kit.filter-select label="Filter by description" class="w-[14rem]" wire:model.live="description">
                        <flux:select.option value="ALL">All descriptions</flux:select.option>
                        @foreach ($descriptions as $name)
                            <flux:select.option value="{{ $name }}">{{ $name }}</flux:select.option>
                        @endforeach
                    </x-kit.filter-select>

                    <x-kit.filter-select label="Filter by day type" class="w-[11rem]" wire:model.live="dayType">
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
                                <x-kit.sort-header field="date" label="Date" />
                                <x-kit.sort-header field="description" label="Description" />
                                <x-kit.sort-header field="dayType" label="Day type" />
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @if ($rows->total() === 0)
                                <tr wire:key="empty">
                                    <x-kit.empty-state :colspan="6" title="No request matches those filters." />
                                </tr>
                            @endif
                            @foreach ($rows as $r)
                                @php
                                    $employee = $r->employee;
                                    $isOb = $r->request_type === LeaveLabels::OFFICIAL_BUSINESS;
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
                                    <td class="num whitespace-nowrap">
                                        {{ $fmtDate($r->start_date) }}{{ $r->start_date->format('Y-m-d') === $r->end_date->format('Y-m-d') ? '' : ' – '.$fmtDate($r->end_date) }}
                                    </td>
                                    <td class="whitespace-nowrap">{{ $described[$r->id] }}</td>
                                    <td class="whitespace-nowrap">{{ LeaveLabels::dayTypeLabel($r->day_type) }}</td>
                                    <td class="whitespace-nowrap">
                                        <span class="{{ $pill($r->status) }}">{{ $r->status }}</span>
                                        @if ($r->status === 'APPROVED' && $r->cancelled_dates !== [])
                                            <span class="ml-2 text-xs text-muted-foreground">Partially cancelled</span>
                                        @endif
                                        @if ($r->status === 'APPROVED' && ! $isOb)
                                            <span class="ml-2 text-xs text-muted-foreground">
                                                {{ $r->is_paid === false ? 'without pay' : 'with pay' }}
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
        @else
            <div class="mt-4">
                <livewire:leaves.leave-ledger wire:key="leaves-ledger" />
            </div>
        @endif
    </div>

    <flux:modal wire:model.self="detailOpen" class="w-full max-w-2xl">
        @if ($open)
            @php
                $emp = $open->employee;
                $openIsOb = $open->request_type === LeaveLabels::OFFICIAL_BUSINESS;
                // Calendar days the request spans, inclusive (leave-detail-dialog.tsx:23-27).
                $days = max(1, (int) $open->start_date->diffInDays($open->end_date) + 1);
                $pending = $open->status === 'PENDING';
            @endphp
            <div class="space-y-4">
                <div class="space-y-1.5">
                    <h2 class="text-lg font-semibold leading-none tracking-tight">{{ $open->code ?: 'Request' }}</h2>
                    <p class="text-sm text-muted-foreground">
                        {{ $pending
                            ? 'Review the request, then acknowledge it with or without pay.'
                            : 'This request has already been reviewed.' }}
                    </p>
                </div>

                <div class="flex items-center gap-3 rounded-md border border-border p-4">
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
                    <span class="{{ $pill($open->status) }} ml-auto">{{ $open->status }}</span>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="min-w-0 space-y-1">
                        <p class="text-xs uppercase tracking-wide text-muted-foreground">Description</p>
                        <div class="break-words text-sm font-medium">{{ $openDescription }}</div>
                    </div>
                    <div class="min-w-0 space-y-1">
                        <p class="text-xs uppercase tracking-wide text-muted-foreground">Day type</p>
                        <div class="break-words text-sm font-medium">{{ LeaveLabels::dayTypeLabel($open->day_type) }}</div>
                    </div>
                    <div class="min-w-0 space-y-1">
                        <p class="text-xs uppercase tracking-wide text-muted-foreground">Duration</p>
                        <div class="break-words text-sm font-medium">
                            <span class="num">{{ $days }} {{ $days === 1 ? 'day' : 'days' }}{{ $open->day_type === 'HALF_DAY' && $days === 1 ? ' (half)' : '' }}</span>
                        </div>
                    </div>
                    <div class="min-w-0 space-y-1">
                        <p class="text-xs uppercase tracking-wide text-muted-foreground">From</p>
                        <div class="break-words text-sm font-medium"><span class="num">{{ $fmtDate($open->start_date) }}</span></div>
                    </div>
                    <div class="min-w-0 space-y-1">
                        <p class="text-xs uppercase tracking-wide text-muted-foreground">To</p>
                        <div class="break-words text-sm font-medium"><span class="num">{{ $fmtDate($open->end_date) }}</span></div>
                    </div>
                    <div class="min-w-0 space-y-1">
                        <p class="text-xs uppercase tracking-wide text-muted-foreground">Filed</p>
                        <div class="break-words text-sm font-medium"><span class="num">{{ $fmtDateTime($open->created_at) }}</span></div>
                    </div>
                </div>

                <div class="space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Reason</p>
                    <p class="rounded-md border border-border bg-muted/30 p-3 text-sm">
                        {{ trim($open->reason ?? '') !== '' ? trim($open->reason) : 'No reason given.' }}
                    </p>
                </div>

                {{-- Not in the source: the request's standing against its
                     type's credit for the year it starts. Information for the
                     reviewer, never a refusal; see Index::creditLine(). --}}
                @if ($openBalance !== null)
                    @php
                        $needs = LeaveCreditPolicy::format($openNeeds);
                        $exceeds = $pending && $openBalance->entered() && $openNeeds > $openBalance->remaining;
                    @endphp
                    <p class="text-xs text-muted-foreground" data-credits-line>
                        @if ($openBalance->entered())
                            {{ $openBalance->label }} credits {{ $openBalance->year }} ·
                            <span class="num">{{ LeaveCreditPolicy::format($openBalance->credited) }}</span> credited ·
                            <span class="num">{{ LeaveCreditPolicy::format($openBalance->used) }}</span> used ·
                            <span class="num">{{ LeaveCreditPolicy::format($openBalance->remaining) }}</span> left ·
                        @else
                            No {{ $openBalance->year }} {{ $openBalance->label }} credits entered ·
                        @endif
                        @if ($pending)
                            <span @class(['num', 'pill pill-caution' => $exceeds])>this request needs {{ $needs }}</span>
                        @else
                            <span class="num">this request counted {{ $needs }}</span>
                        @endif
                    </p>
                @endif

                @if (! $pending)
                    @php
                        $reviewLine = match ($open->status) {
                            'APPROVED', 'CANCELLED' => ($openIsOb
                                ? 'Approved as official business — credited as time worked.'
                                : ($open->is_paid === false
                                    ? 'Approved without pay.'
                                    : 'Approved with pay.')),
                            'WITHDRAWN' => 'Withdrawn before review.',
                            'REJECTED' => 'Rejected.',
                            default => '',
                        };

                        if ($reviewerName) {
                            $reviewLine .= ' Reviewed by '.$reviewerName;
                        }

                        if ($open->reviewed_at) {
                            $reviewLine .= ' on '.$fmtDateTime($open->reviewed_at);
                        }

                        $reviewLine .= '.';
                    @endphp
                    <p class="text-xs text-muted-foreground">{{ $reviewLine }}</p>
                @endif

                @if ($open->cancellations !== [])
                    <section class="space-y-2 border-t border-border pt-4" aria-label="Cancelled leave dates">
                        <h3 class="text-sm font-semibold">Cancelled dates</h3>
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
                    <form wire:submit="cancelSelectedDates" class="space-y-3 border-t border-border pt-4" aria-label="Cancel future leave dates">
                        <h3 class="text-sm font-semibold">Cancel future dates</h3>
                        <p class="text-xs text-muted-foreground">Select the dates to cancel. Today, past dates and dates already in finalized payroll cannot be cancelled.</p>
                        <fieldset class="max-h-48 overflow-y-auto rounded-md border border-border p-3" wire:key="cancel-options-{{ $open->id }}-{{ count($open->cancelled_dates) }}">
                            <legend class="px-1 text-xs text-muted-foreground">Dates to cancel</legend>
                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach ($open->cancellable_dates as $date)
                                    <div wire:key="cancel-date-{{ $open->id }}-{{ $date }}">
                                        <flux:checkbox wire:model.live="cancelDates" value="{{ $date }}" label="{{ $date }}{{ $open->day_type === 'HALF_DAY' ? ' · half day' : '' }}" aria-label="{{ $date }}{{ $open->day_type === 'HALF_DAY' ? ' · half day' : '' }}" />
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>
                        <flux:textarea label="Cancellation reason" wire:model="cancelReason" rows="2" maxlength="1000" required />
                        @php
                            $selectedDates = array_values(array_intersect($open->cancellable_dates, $cancelDates));
                            $selected = count($selectedDates);
                            $returned = LeaveCreditPolicy::wouldConsume($open->request_type, $open->is_paid)
                                ? LeaveCreditPolicy::selectedDays($open->day_type, $selectedDates) : 0.0;
                        @endphp
                        <p class="text-xs text-muted-foreground" role="status">
                            {{ $selected }} {{ $selected === 1 ? 'date' : 'dates' }} selected · {{ LeaveCreditPolicy::format($returned) }} leave {{ $returned === 1.0 ? 'credit' : 'credits' }} returned to {{ $open->start_date->format('Y') }}.
                            Attendance and draft payroll will recalculate. Any manually entered Leave pay needs payroll review.
                        </p>
                        @if ($cancelError !== '')
                            <p role="alert" class="text-sm text-danger">{{ $cancelError }}</p>
                        @endif
                        <div class="flex justify-end">
                            <flux:button type="submit" variant="primary" :disabled="$selected === 0" wire:loading.attr="disabled" wire:target="cancelSelectedDates" wire:key="cancel-submit-{{ $open->id }}-{{ $selected === 0 ? 'empty' : 'selected' }}">Cancel selected dates</flux:button>
                        </div>
                    </form>
                @elseif ($cancelError !== '')
                    <p role="alert" class="text-sm text-danger">{{ $cancelError }}</p>
                @endif

                @if ($pending)
                    <div class="flex flex-wrap justify-end gap-2 border-t border-border pt-4">
                        <flux:button
                            variant="outline"
                            icon="x-mark"
                            class="text-danger hover:text-danger"
                            wire:click="review('REJECTED')"
                            wire:loading.attr="disabled"
                            wire:target="review"
                        >
                            Reject
                        </flux:button>
                        @if ($openIsOb)
                            <flux:button
                                variant="primary"
                                icon="check"
                                wire:click="review('APPROVED')"
                                wire:loading.attr="disabled"
                                wire:target="review"
                            >
                                Approve official business
                            </flux:button>
                        @else
                            <flux:button
                                variant="outline"
                                icon="check"
                                wire:click="review('APPROVED', false)"
                                wire:loading.attr="disabled"
                                wire:target="review"
                            >
                                Approve without pay
                            </flux:button>
                            <flux:button
                                variant="primary"
                                icon="check"
                                wire:click="review('APPROVED', true)"
                                wire:loading.attr="disabled"
                                wire:target="review"
                            >
                                Approve with pay
                            </flux:button>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>
</div>
