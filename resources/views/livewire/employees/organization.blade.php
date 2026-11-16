{{--
    Organization — every department as a card in one wrapping grid, in HR
    order. A card opens into a table of its people right under its row:
    first the people placed in the department itself, then a shaded row per
    unit followed by that unit's people. A department without units is one
    plain list. Empty departments and units stay, with a sentence; positions
    nobody holds and people who are not placed never appear.

    Open state lives in Alpine (`open`, keyed by department id): everything
    is rendered once, several departments may be open at once, and opening
    one never waits on the server. A name links to the record only when the
    viewer may open it (`canOpen`); for everyone else it is plain text.
--}}
@php
    // A slash in a title is where a long one may break ("Driver/Messenger").
    $breakable = static fn (string $text): string => str_replace('/', '/<wbr>', e($text));
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Employee Records</h1>
        <p class="text-sm text-muted-foreground">
            Manage employee records: attendance, payroll, and accounts all resolve against these records.
        </p>
    </div>

    @include('livewire.employees.partials.view-switch', ['activeView' => 'organization'])

    <section
        class="hris-card overflow-hidden"
        x-data="{
            open: {},
            isOpen(id) { return this.open[id] === true },
            toggle(id) {
                this.open[id] = ! this.isOpen(id);
                if (this.open[id]) { this.$nextTick(() => this.reveal(id)) }
            },
            shut(id) {
                this.open[id] = false;
                document.getElementById('org-card-' + id)?.focus();
            },
            every(state) {
                this.$root.querySelectorAll('[data-org-dept]').forEach((el) => { this.open[el.dataset.orgDept] = state });
            },
            reveal(id) {
                const panel = document.getElementById('org-panel-' + id);
                if (! panel) { return }
                const box = panel.getBoundingClientRect();
                if (box.bottom <= window.innerHeight) { return }
                const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                window.scrollBy({ top: Math.min(box.bottom - window.innerHeight + 16, box.top - 80), behavior: calm ? 'auto' : 'smooth' });
            },
        }"
    >
        <header class="flex flex-wrap items-center justify-end gap-3 border-b border-border px-5 py-3">
            <div class="flex items-center gap-2">
                <flux:button variant="outline" size="sm" x-on:click="every(true)">
                    @include('livewire.employees.partials.org-icon', ['icon' => 'chevrons-up-down', 'class' => 'size-4'])
                    Expand All
                </flux:button>
                <flux:button variant="outline" size="sm" x-on:click="every(false)">
                    @include('livewire.employees.partials.org-icon', ['icon' => 'chevrons-down-up', 'class' => 'size-4'])
                    Collapse All
                </flux:button>
            </div>
        </header>

        <ul class="hris-org-grid" role="list" aria-label="Departments">
            @forelse ($departments as $department)
                <li
                    wire:key="org-dept-{{ $department->id }}"
                    id="org-dept-{{ $department->id }}"
                    class="hris-org-dept"
                    data-org-dept="{{ $department->id }}"
                    x-bind:data-open="isOpen(@js($department->id))"
                >
                    <button
                        type="button"
                        id="org-card-{{ $department->id }}"
                        class="hris-org-card relative flex w-full cursor-pointer flex-col gap-1.5 rounded-xl border border-border bg-card px-3.5 py-3 text-left text-card-foreground shadow-card transition-[border-color,box-shadow] duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring motion-reduce:transition-none"
                        aria-expanded="false"
                        aria-controls="org-panel-{{ $department->id }}"
                        x-bind:aria-expanded="isOpen(@js($department->id)) ? 'true' : 'false'"
                        x-on:click="toggle(@js($department->id))"
                    >
                        <span class="hris-org-top flex items-center justify-between gap-2 text-[11px] font-medium uppercase tracking-wider text-muted-foreground" aria-hidden="true">
                            <span class="flex items-center gap-1.5">
                                @include('livewire.employees.partials.org-icon', ['icon' => 'building-2', 'class' => 'size-3.5 shrink-0'])
                                Department
                            </span>
                            @include('livewire.employees.partials.org-icon', ['icon' => 'chevron-down', 'class' => 'hris-org-chev size-4 shrink-0 transition-transform duration-200 motion-reduce:transition-none'])
                        </span>
                        <span class="text-[15px] font-bold leading-[1.35] [overflow-wrap:anywhere]">{{ $department->name }}</span>
                        <span class="sr-only">, department</span>
                        <span class="mt-auto flex min-h-11 min-w-0 items-center gap-2.5">
                            @if ($department->people !== [])
                                @include('livewire.employees.partials.org-photo', ['person' => $department->people[0], 'class' => 'size-9 text-xs'])
                                <span class="flex min-w-0 flex-1 flex-col leading-[1.35]">
                                    <b class="truncate text-[13px] font-medium">{{ $department->people[0]->name }}</b>
                                    <span class="line-clamp-1 text-xs text-muted-foreground" title="{{ $department->people[0]->position }}">{!! $breakable($department->people[0]->position) !!}</span>
                                </span>
                                @if (count($department->people) > 1)
                                    <span class="hris-org-more ml-auto flex shrink-0 items-center" title="{{ collect(array_slice($department->people, 1))->pluck('name')->implode(', ') }}">
                                        @foreach (array_slice($department->people, 1, 3) as $next)
                                            @include('livewire.employees.partials.org-photo', ['person' => $next, 'class' => 'size-6 text-[9px] -ml-[7px] first:ml-0'])
                                        @endforeach
                                    </span>
                                @endif
                            @elseif ($department->units !== [])
                                <span class="flex items-start gap-1.5 text-xs leading-[1.4] text-muted-foreground">
                                    @include('livewire.employees.partials.org-icon', ['icon' => 'users', 'class' => 'mt-0.5 size-3.5 shrink-0'])
                                    <span>{{ collect($department->units)->pluck('name')->implode(', ') }}</span>
                                </span>
                            @else
                                <span class="text-xs text-muted-foreground">No one in this department yet</span>
                            @endif
                        </span>
                    </button>
                </li>
                <li
                    wire:key="org-panel-{{ $department->id }}"
                    id="org-panel-{{ $department->id }}"
                    class="hris-org-panel relative overflow-hidden rounded-xl bg-card shadow-card"
                    hidden
                    x-bind:hidden="! isOpen(@js($department->id))"
                >
                    <div role="region" aria-labelledby="org-panel-title-{{ $department->id }}">
                        <div class="flex items-center justify-between gap-3 border-b border-border py-2.5 pr-3 pl-4">
                            <h2 id="org-panel-title-{{ $department->id }}" class="flex items-center gap-2 text-sm font-semibold">
                                @include('livewire.employees.partials.org-icon', ['icon' => 'building-2', 'class' => 'size-3.5 shrink-0 text-muted-foreground'])
                                {{ $department->name }}
                            </h2>
                            <button
                                type="button"
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-md px-2 py-1.5 text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                                aria-label="Close {{ $department->name }}"
                                x-on:click="shut(@js($department->id))"
                            >
                                @include('livewire.employees.partials.org-icon', ['icon' => 'x', 'class' => 'size-4'])
                                Close
                            </button>
                        </div>
                        <div class="hris-table-wrap">
                            <table class="hris-table hris-org-table">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Position</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @if ($department->units !== [])
                                        <tr class="hris-org-group" wire:key="org-own-{{ $department->id }}">
                                            <td colspan="2" class="text-[13px] font-semibold">
                                                <span class="flex items-center gap-2">
                                                    @include('livewire.employees.partials.org-icon', ['icon' => 'building-2', 'class' => 'size-3.5 shrink-0'])
                                                    In the department itself
                                                </span>
                                            </td>
                                        </tr>
                                        @forelse ($department->people as $person)
                                            @include('livewire.employees.partials.org-row', ['person' => $person, 'inside' => true, 'place' => $department->id])
                                        @empty
                                            <tr wire:key="org-none-{{ $department->id }}">
                                                <td colspan="2" class="text-[13px] text-muted-foreground">No one is placed in the department itself</td>
                                            </tr>
                                        @endforelse
                                        @foreach ($department->units as $unit)
                                            <tr class="hris-org-group" wire:key="org-unit-{{ $unit->id }}">
                                                <td colspan="2" class="text-[13px] font-semibold">
                                                    <span class="flex items-center gap-2">
                                                        @include('livewire.employees.partials.org-icon', ['icon' => 'users', 'class' => 'size-3.5 shrink-0'])
                                                        <span class="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">Unit</span>
                                                        {{ $unit->name }}
                                                    </span>
                                                </td>
                                            </tr>
                                            @forelse ($unit->people as $person)
                                                @include('livewire.employees.partials.org-row', ['person' => $person, 'inside' => true, 'place' => $unit->id])
                                            @empty
                                                <tr wire:key="org-none-{{ $unit->id }}">
                                                    <td colspan="2" class="text-[13px] text-muted-foreground">No one in this unit yet</td>
                                                </tr>
                                            @endforelse
                                        @endforeach
                                    @else
                                        @forelse ($department->people as $person)
                                            @include('livewire.employees.partials.org-row', ['person' => $person, 'inside' => false, 'place' => $department->id])
                                        @empty
                                            <tr wire:key="org-none-{{ $department->id }}">
                                                <td colspan="2" class="text-[13px] text-muted-foreground">No one in this department yet</td>
                                            </tr>
                                        @endforelse
                                    @endif
                                </tbody>
                            </table>
                        </div>
                    </div>
                </li>
            @empty
                <li class="col-span-full text-sm text-muted-foreground">No departments have been set up yet.</li>
            @endforelse
        </ul>
    </section>
</div>
