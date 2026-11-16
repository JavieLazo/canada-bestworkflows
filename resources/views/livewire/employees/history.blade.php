{{--
    One employee in view ($subject) reads as their timeline: a header naming
    them, one folding entry per saved edit on a rail, and their starting record
    last. Across everyone the same entries are a plain list titled by employee.
    Only changed fields belong in an edit; snapshots remain the starting
    evidence, and the starting record is never counted or exported as a change.
--}}
@php
    $single = $subject !== null;
    $total = $rows->total();
    $entries = $rows->count() + ($start === null ? 0 : 1);
    $withheld = $startWithheld ? 'The starting record is not shown while filters are on.' : '';
    // ONE sentence for every outcome of a filter. The status line below is
    // always on the page and speaks it; the list header and the empty state
    // show the same words.
    $outcome = match (true) {
        ! $validFilters => 'Check the filters above.',
        $entries === 0 && $single && $filtered => 'No updates match these filters.',
        $entries === 0 && $single => 'No employment history is recorded for this employee yet.',
        $entries === 0 => 'No employment changes found.',
        $total === 0 => 'No updates recorded since the starting record.',
        $filtered => trim($total.' '.($total === 1 ? 'update matches' : 'updates match').' the filters. '.$withheld),
        default => $total.' '.($total === 1 ? 'update' : 'updates').' recorded.',
    };
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Employment History</h1>
        <p class="mt-1 text-sm text-muted-foreground">See what changed, when it was saved, and who updated it.</p>
    </div>

    @unless ($own)
        @include('livewire.employees.partials.view-switch', ['activeView' => 'history'])
    @endunless

    <section class="hris-card overflow-hidden" aria-label="Filter employment history">
        <div class="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1fr)]">
            @unless ($own)
            <div class="min-w-0 space-y-2">
                <label for="history-employee-trigger" id="history-employee-label" class="text-sm font-medium">Employee</label>
                <livewire:kit.employee-picker
                    :value="$employeeId"
                    name="history-employee"
                    placeholder="All employees"
                    aria-label="Employee"
                    labelled-by="history-employee-label"
                    trigger-id="history-employee-trigger"
                    wire:key="history-employee-{{ $employeeId }}"
                />
            </div>
            @endunless
            <div class="min-w-0 space-y-2">
                <p class="text-sm font-medium">Changed Field</p>
                <x-kit.filter-select label="Changed Field" all-value="" wire:model.live="field">
                    <flux:select.option value="">All fields</flux:select.option>
                    @foreach ($fields as $key => $label)
                        <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                    @endforeach
                </x-kit.filter-select>
                @error('field') <p class="text-xs text-danger" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="min-w-0 space-y-2">
                <label for="history-from" class="text-sm font-medium">Changed From</label>
                <x-kit.date-field id="history-from" wire:model.live="from" aria-label="Changed from" />
                @error('from') <p class="text-xs text-danger" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="min-w-0 space-y-2">
                <label for="history-to" class="text-sm font-medium">Changed Through</label>
                <x-kit.date-field id="history-to" wire:model.live="to" aria-label="Changed through" />
                @error('to') <p class="text-xs text-danger" role="alert">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3">
            <p class="text-xs text-muted-foreground">Starting records are not counted as updates and are not exported.</p>
            <div class="flex flex-wrap items-center gap-2">
                @if ($employeeId !== '' || $from !== '' || $to !== '' || $field !== '')
                    <flux:button variant="ghost" size="sm" wire:click="clearFilters">Clear Filters</flux:button>
                @endif
                @if ($validFilters && ! $own)
                    <x-kit.export-button :href="route('employees.history.export', array_filter(['employee' => $employeeId, 'from' => $from, 'to' => $to, 'field' => $field]))" />
                @endif
            </div>
        </div>
    </section>

    {{-- Always rendered, so its text changes IN PLACE and every outcome is announced:
         a count, nothing found, a bad date. A status line that only exists beside
         results says nothing when a filter empties the list. --}}
    <p class="sr-only" role="status">{{ $outcome }}</p>

    @if ($single)
        @include('livewire.employees.partials.history-subject')
    @endif

    @if (! $validFilters)
        <section class="hris-card">
            <x-kit.empty-state :title="$outcome" hint="Enter a valid date range to see employment changes." />
        </section>
    @elseif ($entries === 0)
        <section class="hris-card">
            @if ($single && $filtered)
                {{-- Named by what to remove: the Clear filters button would drop the employee too. --}}
                <x-kit.empty-state icon="clock" :title="$outcome" :hint="trim($withheld.' Remove the Changed field and dates to see the whole timeline.')" />
            @elseif ($single)
                <x-kit.empty-state icon="clock" :title="$outcome" hint="Changes to Employee Details will appear here." />
            @else
                <x-kit.empty-state icon="clock" :title="$outcome" hint="Changes to Employee Details will appear here. Try another employee or date range." />
            @endif
        </section>
    @else
        <section class="hris-card overflow-hidden" aria-labelledby="history-list-heading">
            <header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-border px-5 py-4">
                <div class="min-w-0">
                    <h2 id="history-list-heading" class="text-base font-bold">{{ $single ? 'Timeline' : 'Updates' }}</h2>
                    {{-- The status line above already speaks this sentence. --}}
                    <p class="mt-1 text-xs text-muted-foreground" aria-hidden="true">{{ $outcome }}</p>
                </div>
                @if ($entries > 1)
                    {{-- Client-only: the folds keep their own state, so these two set it and nothing is stored. --}}
                    <div class="flex items-center gap-1">
                        <flux:button type="button" variant="ghost" size="sm" x-on:click="$el.closest('section').querySelectorAll('details').forEach((fold) => fold.open = true)">Expand All</flux:button>
                        <flux:button type="button" variant="ghost" size="sm" x-on:click="$el.closest('section').querySelectorAll('details').forEach((fold) => fold.open = false)">Collapse All</flux:button>
                    </div>
                @endif
            </header>

            <ol @class(['px-5 py-5' => $single, 'divide-y divide-border' => ! $single]) aria-label="{{ $single ? 'Employment timeline, newest first' : 'Employment updates, newest first' }}">
                @foreach ($rows as $history)
                    @include('livewire.employees.partials.history-entry', [
                        'open' => $single && $loop->first,
                        'rail' => ! $loop->last || $start !== null || $rows->hasMorePages(),
                    ])
                @endforeach
                @if ($start !== null)
                    @include('livewire.employees.partials.history-start', ['open' => $rows->isEmpty()])
                @endif
            </ol>

            @if ($total > 0)
                <x-kit.pagination :paginator="$rows" noun="updates" />
            @endif
        </section>
    @endif
</div>
