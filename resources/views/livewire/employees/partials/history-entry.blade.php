{{--
    One saved edit. The summary says WHAT changed and who saved it; the values
    wait in the fold. ONLY THE FIELDS THE SAVE CHANGED are listed — what the
    record held before tracking began belongs to the starting record, never to
    a change as context.

    $single  one employee's timeline (rail, dot, "Latest") or the list across
             everyone (the entry is titled by the employee instead).
    $open    whether the fold starts open.
    $rail    whether the rail runs on below this entry.

    The fold is a native <details>, so it opens before any script has loaded.
    `wire:ignore.self` stops a re-render from putting the server's `open` back,
    which would shut an entry the reader had just opened; for that reason
    NOTHING THAT CHANGES BETWEEN RENDERS MAY BE AN ATTRIBUTE OF THE <details>.
    The one thing that may change is the server's own intent — the same entry
    is folded in the list and open as the first of a timeline — so that intent
    is part of the fold's key: when it flips the fold is replaced and starts as
    the server says, and otherwise the reader's own state stands.
    The rail and the dot are drawn per entry from Blade's own loop, not with
    :first-child / :last-child, which would redraw while the page is arriving.
--}}
@php
    use App\Livewire\Employees\Support\EmploymentChangeDisplay;
    use App\Support\EmployeeIdentity;

    $latest = $single && $history->is_latest;
    $hidden = $history->changed_count - count($history->changes);
    $unrecorded = false;
    foreach ($history->changes as $change) {
        $unrecorded = $unrecorded || ! $change['before_known'] || ! $change['after_known'];
    }
    $changed = EmploymentChangeDisplay::fieldList($history->changes);
    $approvedRequest = $history->request_id !== null;
    $who = ($approvedRequest ? 'Approved by ' : 'Updated by ').($history->recorded_by_name ?: 'System');
    $identity = $single ? '' : EmployeeIdentity::besideName($history);
@endphp
<li
    wire:key="employment-history-{{ $history->id }}"
    data-entry="change"
    @if ($latest) data-latest @endif
    @class(['relative pl-7' => $single, 'pb-6' => $single && $rail, 'px-5 py-4' => ! $single])
>
    @if ($single)
        @if ($rail)
            <span aria-hidden="true" class="absolute -bottom-[3px] left-[5px] top-[22px] w-px bg-border"></span>
        @endif
        <span aria-hidden="true" @class(['absolute left-0 top-[7px] size-[11px] rounded-full', 'bg-primary' => $latest, 'bg-muted-foreground/50' => ! $latest])></span>
    @endif

    <details wire:key="employment-fold-{{ $history->id }}-{{ $open ? 'open' : 'shut' }}" class="group" wire:ignore.self @if ($open) open @endif>
        <summary class="-mx-2 block cursor-pointer list-none rounded-md px-2 py-1 hover:bg-accent focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring [&::-webkit-details-marker]:hidden">
            <span class="flex items-start gap-3">
                <span class="min-w-0 flex-1">
                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                        <time datetime="{{ $history->created_at->toIso8601String() }}" class="num whitespace-nowrap">{{ EmploymentChangeDisplay::savedAt($history->created_at) }}</time>
                        @if ($latest)
                            <span class="pill pill-primary">Latest</span>
                        @endif
                    </span>
                    @if ($single)
                        <span class="mt-1 block break-words font-medium">{{ $changed }}</span>
                        <span class="mt-0.5 block break-words text-xs text-muted-foreground">{{ $who }}</span>
                    @else
                        <span class="mt-1 flex flex-wrap items-center gap-2 font-medium">
                            <span class="break-words">{{ $history->employee_name }}</span>
                            @if ($identity !== '')
                                <span class="num pill pill-muted">{{ $identity }}</span>
                            @endif
                        </span>
                        <span class="mt-0.5 block break-words text-xs text-muted-foreground">{{ EmployeeIdentity::meta($changed, $who) }}</span>
                    @endif
                </span>
                <span class="mt-0.5 inline-flex shrink-0 text-muted-foreground motion-safe:transition-transform group-open:rotate-90" aria-hidden="true">
                    <flux:icon.chevron-right variant="micro" class="size-4" />
                </span>
            </span>
        </summary>

        <div class="mt-3 rounded-lg bg-muted/40 p-3 text-sm sm:p-4">
            @if ($approvedRequest)
                <div class="mb-3 space-y-1 border-b border-border pb-3 text-xs text-muted-foreground">
                    <p>Requested by {{ $history->requested_by_name ?: 'Account' }}@if ($history->requested_at) · {{ EmploymentChangeDisplay::savedAt($history->requested_at) }}@endif</p>
                    @if (trim((string) $history->request_reason) !== '')
                        <p class="break-words">Reason: {{ $history->request_reason }}</p>
                    @endif
                    @if ($history->review_note)
                        <p class="break-words">Review note: {{ $history->review_note }}</p>
                    @endif
                    <p class="break-all">Request {{ $history->request_id }}</p>
                </div>
            @endif
            {{-- The captions are for the eye; each value repeats its own for a screen reader.
                 A phone has no room for three columns (a peso amount broke mid-number at 77px),
                 so there the field name takes its own line above the two values. --}}
            <div class="grid max-w-3xl grid-cols-2 gap-x-3 gap-y-2 sm:grid-cols-[9rem_minmax(0,1fr)_minmax(0,1fr)] sm:gap-x-4">
                <p aria-hidden="true" class="hidden text-xs text-muted-foreground sm:block">Field</p>
                <p aria-hidden="true" class="text-xs text-muted-foreground">Previous value</p>
                <p aria-hidden="true" class="text-xs text-muted-foreground">New value</p>
                <dl class="contents">
                    @foreach ($history->changes as $change)
                        <div wire:key="employment-change-{{ $history->id }}-{{ $change['field'] }}" data-field="{{ $change['field'] }}" class="contents">
                            <dt class="col-span-2 -mb-1.5 min-w-0 break-words border-t border-border pt-2 text-xs text-muted-foreground sm:col-span-1 sm:mb-0 sm:border-t-0 sm:pt-0 sm:text-sm sm:text-foreground">{{ $change['label'] }}</dt>
                            <dd data-before class="min-w-0 break-words"><span class="sr-only">Previous value: </span>{{ EmploymentChangeDisplay::value($change['field'], $change['before'], $change['before_known']) }}</dd>
                            <dd data-after class="min-w-0 break-words font-medium"><span class="sr-only">New value: </span>{{ EmploymentChangeDisplay::value($change['field'], $change['after'], $change['after_known']) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            @if ($hidden > 0)
                <p data-hidden-changes class="mt-3 border-t border-border pt-3 text-xs text-muted-foreground">
                    {{ $hidden }} other {{ $hidden === 1 ? 'detail' : 'details' }} changed in this update {{ $hidden === 1 ? 'is' : 'are' }} hidden by the Changed field filter.
                </p>
            @endif
            @if ($unrecorded)
                <p class="mt-3 border-t border-border pt-3 text-xs text-muted-foreground">
                    “Not recorded” means the value was not captured when this change was saved.
                </p>
            @endif
            @unless ($single)
                {{-- Links live in the fold: a link inside <summary> would toggle it. --}}
                <p class="mt-3 flex flex-wrap gap-x-4 gap-y-1 border-t border-border pt-3 text-xs">
                    <a href="{{ route('employees.history', ['employee' => $history->employee_id]) }}" class="font-medium text-primary hover:underline">View timeline</a>
                    <a href="{{ route('employees.show', $history->employee_id) }}" class="font-medium text-primary hover:underline">Open employee record</a>
                </p>
            @endunless
        </div>
    </details>
</li>
