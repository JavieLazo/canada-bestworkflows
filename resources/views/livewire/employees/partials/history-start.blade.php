{{--
    The record one employee's timeline opens with, shown last because the list
    runs newest first.

    A HIRED row was written when the employee was added. A BASELINE row was
    written for everyone already on file when tracking began: it says how the
    record stood THEN, NEVER HOW IT STOOD AT HIRE, and the wording below must
    keep that difference. Neither is a change, so its details carry `data-fact`
    (never a change row's `data-field`) and the entry is keyed apart from
    `employment-history-`, which marks counted, exported updates only.

    The fold follows history-entry: a native <details> with `wire:ignore.self`,
    keyed by whether the server renders it open.
--}}
@php
    use App\Livewire\Employees\Support\EmploymentChangeDisplay;
    use App\Modules\Workforce\Application\EmploymentHistoryRecorder;

    $baseline = $start->event_type === EmploymentHistoryRecorder::BASELINE;
    $latest = (bool) $start->is_latest;
@endphp
<li
    wire:key="employment-start-{{ $start->id }}"
    data-entry="start"
    data-event="{{ $start->event_type }}"
    @if ($latest) data-latest @endif
    class="relative pl-7"
>
    <span aria-hidden="true" @class(['absolute left-0 top-[7px] size-[11px] rounded-full', 'bg-primary' => $latest, 'bg-muted-foreground/50' => ! $latest])></span>

    <details wire:key="employment-start-fold-{{ $start->id }}-{{ $open ? 'open' : 'shut' }}" class="group" wire:ignore.self @if ($open) open @endif>
        <summary class="-mx-2 block cursor-pointer list-none rounded-md px-2 py-1 hover:bg-accent focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring [&::-webkit-details-marker]:hidden">
            <span class="flex items-start gap-3">
                <span class="min-w-0 flex-1">
                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                        <time datetime="{{ $start->created_at->toIso8601String() }}" class="num whitespace-nowrap">
                            {{-- The backfill's hour says nothing about the employee; the day tracking began does. --}}
                            {{ $baseline ? 'Tracking began '.EmploymentChangeDisplay::savedAt($start->created_at, false) : EmploymentChangeDisplay::savedAt($start->created_at) }}
                        </time>
                        @if ($latest)
                            <span class="pill pill-primary">Latest</span>
                        @endif
                        <span class="pill pill-info">{{ $baseline ? 'Starting record' : 'Record created' }}</span>
                    </span>
                    <span class="mt-1 block break-words font-medium">{{ $baseline ? 'Details on file when tracking began' : 'Employee record created' }}</span>
                    <span class="mt-0.5 block break-words text-xs text-muted-foreground">
                        {{ $baseline ? 'Recorded by the system' : 'Added by '.($start->recorded_by_name ?: 'System') }}
                    </span>
                </span>
                <span class="mt-0.5 inline-flex shrink-0 text-muted-foreground motion-safe:transition-transform group-open:rotate-90" aria-hidden="true">
                    <flux:icon.chevron-right variant="micro" class="size-4" />
                </span>
            </span>
        </summary>

        <div class="mt-3 rounded-lg bg-muted/40 p-3 text-sm sm:p-4">
            <p class="text-xs text-muted-foreground">
                @if ($baseline)
                    Changes made before this date were not tracked. These are the details as they stood then, which may differ from the details at hire.
                @else
                    The details entered when this employee was added.
                @endif
            </p>
            <dl class="mt-3 grid max-w-3xl grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-x-3 gap-y-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:gap-x-4">
                @foreach ($start->facts ?? [] as $fact)
                    <div wire:key="employment-fact-{{ $start->id }}-{{ $fact['field'] }}" data-fact="{{ $fact['field'] }}" class="contents">
                        <dt class="min-w-0 break-words text-muted-foreground">{{ $fact['label'] }}</dt>
                        <dd class="min-w-0 break-words">{{ EmploymentChangeDisplay::value($fact['field'], $fact['value'], $fact['known']) }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </details>
</li>
