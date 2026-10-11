{{-- Filter choices are scoped by Workforce; selecting a column never changes which employees are permitted. --}}
@php
    $advancedCount = count(array_filter(array_intersect_key($filters, array_flip(['position', 'sex', 'civilStatus', 'education', 'missing', 'ageMin', 'ageMax', 'tenureMin', 'tenureMax', 'hiredFrom', 'hiredTo'])), static fn ($value) => $value !== '' && $value !== 'ALL'));
@endphp
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach (['active' => 'Active status', 'employment' => 'Employment status', 'department' => 'Department or unit', 'worksite' => 'Worksite'] as $key => $label)
        <x-kit.select :label="$label" wire:model.live="filters.{{ $key }}" :aria-label="$label">
            @foreach ($employeeOptions[$key] ?? [] as $value => $option)
                <flux:select.option :value="$value">{{ $option }}</flux:select.option>
            @endforeach
        </x-kit.select>
    @endforeach
</div>
<details class="rounded-lg border border-border" wire:ignore.self wire:key="employee-advanced-filters">
    <summary class="cursor-pointer px-4 py-3 text-sm font-medium focus-visible:outline-primary">More filters @if ($advancedCount > 0)<span class="pill pill-primary ml-1">{{ $advancedCount }} active</span>@endif <span class="ml-1 text-xs font-normal text-muted-foreground">Position, profile, service and missing information</span></summary>
    <div class="grid gap-4 border-t border-border p-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach (['position' => 'Position', 'sex' => 'Sex', 'civilStatus' => 'Civil status', 'education' => 'Highest recorded education', 'missing' => 'Missing information'] as $key => $label)
            <x-kit.select :label="$label" wire:model.live="filters.{{ $key }}" :aria-label="$label">
                @foreach ($employeeOptions[$key] ?? [] as $value => $option)
                    <flux:select.option :value="$value">{{ $option }}</flux:select.option>
                @endforeach
            </x-kit.select>
        @endforeach
        <div class="grid grid-cols-2 gap-2">
            <flux:input label="Min age" type="number" min="0" max="150" wire:model.live.debounce.350ms="filters.ageMin" placeholder="Any" />
            <flux:input label="Max age" type="number" min="0" max="150" wire:model.live.debounce.350ms="filters.ageMax" placeholder="Any" />
        </div>
        <div class="grid grid-cols-2 gap-2">
            <flux:input label="Min tenure" type="number" min="0" max="150" wire:model.live.debounce.350ms="filters.tenureMin" placeholder="Years" />
            <flux:input label="Max tenure" type="number" min="0" max="150" wire:model.live.debounce.350ms="filters.tenureMax" placeholder="Years" />
        </div>
        <div class="space-y-2">
            <div class="space-y-1.5"><label for="report-hired-from" class="text-sm font-medium">Hired from</label><x-kit.date-field id="report-hired-from" wire:model.live="filters.hiredFrom" aria-label="Hired from" /></div>
            <div class="space-y-1.5"><label for="report-hired-to" class="text-sm font-medium">Hired through</label><x-kit.date-field id="report-hired-to" wire:model.live="filters.hiredTo" aria-label="Hired through" /></div>
        </div>
        <p class="text-xs text-muted-foreground sm:col-span-2 xl:col-span-4">Age and tenure are completed years as of today. Education means a recorded level, not proof of graduation. Missing values are kept separate from zero.</p>
    </div>
</details>
@if ($view !== 'analysis')
<div class="grid items-start gap-4 lg:grid-cols-2">
    <details class="rounded-lg border border-border" wire:ignore.self wire:key="employee-report-columns">
        <summary class="cursor-pointer px-4 py-3 text-sm font-medium focus-visible:outline-primary">Columns <span class="ml-1 text-xs font-normal text-muted-foreground">{{ count($columns) }} selected · same columns in print and CSV</span></summary>
        <div class="grid gap-3 border-t border-border p-4 sm:grid-cols-2">
            @foreach ($columnOptions as $key => $label)
                <flux:checkbox wire:model.live="columns" :value="$key" :label="$label" />
            @endforeach
        </div>
    </details>
    <div class="grid grid-cols-2 gap-3">
        <x-kit.select label="Sort by" wire:model.live="sortKey" aria-label="Sort employees by">
            @foreach ($sortOptions as $key => $label)<flux:select.option :value="$key">{{ $label }}</flux:select.option>@endforeach
        </x-kit.select>
        <x-kit.select label="Order" wire:model.live="sortDir" aria-label="Employee sort order">
            <flux:select.option value="asc">Ascending</flux:select.option>
            <flux:select.option value="desc">Descending</flux:select.option>
        </x-kit.select>
    </div>
</div>
@endif
