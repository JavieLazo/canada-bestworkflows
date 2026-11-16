{{-- Everything else a report can be narrowed by, one click away so the page opens on its answer.
     Owners scope every choice: a filter or a column never changes which records are permitted. --}}
@if ($type === 'loan-summary')
    <fieldset class="hris-card space-y-3 px-5 py-4">
        <legend class="sr-only">Loan Deduction Types</legend>
        <p class="text-sm font-medium">Loans to Include</p>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @forelse ($loanTypes as $code => $label)
                <flux:checkbox wire:model.live="deductionCodes" value="{{ $code }}" label="{{ $label }} ({{ $code }})" />
            @empty
                <p class="text-sm text-muted-foreground">No posted deduction types are available.</p>
            @endforelse
        </div>
    </fieldset>
@elseif ($type === 'employee-list')
    @php
        $advancedCount = count(array_filter(array_intersect_key($filters, array_flip(['employment', 'worksite', 'position', 'sex', 'civilStatus', 'education', 'missing', 'ageMin', 'ageMax', 'tenureMin', 'tenureMax', 'hiredFrom', 'hiredTo'])), static fn ($value) => $value !== '' && $value !== 'ALL'));
    @endphp
    <details class="hris-card" wire:ignore.self wire:key="employee-advanced-filters">
        <summary class="cursor-pointer px-5 py-3 text-sm font-medium focus-visible:outline-primary">More filters @if ($advancedCount > 0)<span class="pill pill-primary ml-1">{{ $advancedCount }} in use</span>@endif</summary>
        <div class="grid gap-4 border-t border-border p-5 sm:grid-cols-2 xl:grid-cols-4">
            @foreach (['employment' => 'Employment Status', 'worksite' => 'Worksite', 'position' => 'Position', 'sex' => 'Sex', 'civilStatus' => 'Civil Status', 'education' => 'Highest Recorded Education', 'missing' => 'Missing Information'] as $key => $label)
                <x-kit.select :label="$label" wire:model.live="filters.{{ $key }}" :aria-label="$label">
                    @foreach ($employeeOptions[$key] ?? [] as $value => $option)
                        <flux:select.option :value="$value">{{ $option }}</flux:select.option>
                    @endforeach
                </x-kit.select>
            @endforeach
            <div class="grid grid-cols-2 gap-2">
                <flux:input label="Min Age" type="number" min="0" max="150" wire:model.live.debounce.350ms="filters.ageMin" placeholder="Any" />
                <flux:input label="Max Age" type="number" min="0" max="150" wire:model.live.debounce.350ms="filters.ageMax" placeholder="Any" />
            </div>
            <div class="grid grid-cols-2 gap-2">
                <flux:input label="Min Tenure" type="number" min="0" max="150" wire:model.live.debounce.350ms="filters.tenureMin" placeholder="Years" />
                <flux:input label="Max Tenure" type="number" min="0" max="150" wire:model.live.debounce.350ms="filters.tenureMax" placeholder="Years" />
            </div>
            <div class="space-y-1.5"><label for="report-hired-from" class="text-sm font-medium">Hired From</label><x-kit.date-field id="report-hired-from" wire:model.live="filters.hiredFrom" aria-label="Hired from" /></div>
            <div class="space-y-1.5"><label for="report-hired-to" class="text-sm font-medium">Hired Through</label><x-kit.date-field id="report-hired-to" wire:model.live="filters.hiredTo" aria-label="Hired through" /></div>
            @if ($view === 'analysis')
                <x-kit.select label="Group Employees By" wire:model.live="analysisFilters.groupBy" aria-label="Analysis grouping">
                    @foreach ($analysisGroups as $key => $label)<flux:select.option :value="$key">{{ $label }}</flux:select.option>@endforeach
                </x-kit.select>
                <x-kit.select label="Cross-Tabulate With" wire:model.live="analysisFilters.crossTab" aria-label="Analysis cross-tabulation">
                    @foreach ($analysisCrossTabs as $key => $label)<flux:select.option :value="$key">{{ $label }}</flux:select.option>@endforeach
                </x-kit.select>
            @else
                <x-kit.select label="Sort By" wire:model.live="sortKey" aria-label="Sort employees by">
                    @foreach ($sortOptions as $key => $label)<flux:select.option :value="$key">{{ $label }}</flux:select.option>@endforeach
                </x-kit.select>
                <x-kit.select label="Order" wire:model.live="sortDir" aria-label="Employee sort order">
                    <flux:select.option value="asc">Ascending</flux:select.option>
                    <flux:select.option value="desc">Descending</flux:select.option>
                </x-kit.select>
            @endif
            <p class="text-xs text-muted-foreground sm:col-span-2 xl:col-span-4">Age and tenure are completed years as of today. Education is a recorded level, not proof of graduation. A missing value is kept apart from zero.</p>
        </div>
    </details>
@elseif ($type === 'attendance-reports' && $view !== 'details')
    <details class="hris-card" wire:ignore.self wire:key="attendance-more-filters-{{ $view }}">
        <summary class="cursor-pointer px-5 py-3 text-sm font-medium focus-visible:outline-primary">More Filters</summary>
        <div class="grid gap-4 border-t border-border p-5 sm:grid-cols-2 xl:grid-cols-4">
            @if ($view === 'summary')
                <x-kit.select label="Rank By" wire:model.live="sortKey" aria-label="Rank attendance by">
                    @foreach ($sortOptions as $key => $label)<flux:select.option :value="$key">{{ $label }}</flux:select.option>@endforeach
                </x-kit.select>
                <x-kit.select label="Order" wire:model.live="sortDir" aria-label="Attendance sort order">
                    <flux:select.option value="desc">Highest first</flux:select.option>
                    <flux:select.option value="asc">Lowest first</flux:select.option>
                </x-kit.select>
                @if ($sortKey !== 'employee')
                    <flux:input :label="str_ends_with($sortKey, '_minutes') ? 'At Least This Many Minutes' : 'At Least This Many Days'" type="number" min="0" step="1" wire:model.live.debounce.350ms="filters.minimum" />
                @endif
                <x-kit.select label="Show" wire:model.live="filters.limit" aria-label="Attendance result limit">
                    <flux:select.option value="all">All matching employees</flux:select.option>
                    <flux:select.option value="10">First 10</flux:select.option>
                    <flux:select.option value="25">First 25</flux:select.option>
                    <flux:select.option value="50">First 50</flux:select.option>
                </x-kit.select>
                <p class="text-xs text-muted-foreground sm:col-span-2 xl:col-span-4">Figures use the Daily Time Record as it stands. A shift still open can change, and a day without a schedule is marked to check.</p>
            @else
                <flux:input label="Often late means this many days" type="number" min="1" max="31" step="1" wire:model.live.debounce.350ms="analysisFilters.threshold" />
                <p class="self-end text-xs text-muted-foreground sm:col-span-1 xl:col-span-3">Rates leave out approved leave, official business and days without a schedule. Often late marks records to look at; it is not a disciplinary finding.</p>
            @endif
        </div>
    </details>
@endif
@if ($type === 'employee-list' && $view !== 'analysis')
    <details class="hris-card" wire:ignore.self wire:key="employee-report-columns">
        <summary class="cursor-pointer px-5 py-3 text-sm font-medium focus-visible:outline-primary">Columns <span class="ml-1 text-xs font-normal text-muted-foreground">{{ count($columns) }} shown · the same columns print and export</span></summary>
        <div class="grid gap-3 border-t border-border p-5 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($columnOptions as $key => $label)
                <flux:checkbox wire:model.live="columns" :value="$key" :label="$label" />
            @endforeach
        </div>
    </details>
@endif
