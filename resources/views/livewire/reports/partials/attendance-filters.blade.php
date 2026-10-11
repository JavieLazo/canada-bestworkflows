{{-- Ranking uses numeric facts from the DTR owner, never formatted duration strings. --}}
<div class="grid gap-4 border-t border-border pt-4 sm:grid-cols-2 xl:grid-cols-4">
    <x-kit.select label="Rank by" wire:model.live="sortKey" aria-label="Rank attendance by">
        @foreach ($sortOptions as $key => $label)<flux:select.option :value="$key">{{ $label }}</flux:select.option>@endforeach
    </x-kit.select>
    <x-kit.select label="Order" wire:model.live="sortDir" aria-label="Attendance sort order">
        <flux:select.option value="desc">Highest first</flux:select.option>
        <flux:select.option value="asc">Lowest first</flux:select.option>
    </x-kit.select>
    <x-kit.select label="Show employees with" wire:model.live="filters.issue" aria-label="Attendance condition">
        <flux:select.option value="all">Any attendance</flux:select.option>
        <flux:select.option value="late">Lateness</flux:select.option>
        <flux:select.option value="undertime">Undertime</flux:select.option>
        <flux:select.option value="absent">Absences</flux:select.option>
        <flux:select.option value="incomplete">Incomplete days</flux:select.option>
    </x-kit.select>
    <x-kit.select label="Results" wire:model.live="filters.limit" aria-label="Attendance result limit">
        <flux:select.option value="all">All matching employees</flux:select.option>
        <flux:select.option value="10">First 10</flux:select.option>
        <flux:select.option value="25">First 25</flux:select.option>
        <flux:select.option value="50">First 50</flux:select.option>
    </x-kit.select>
    <div class="sm:col-span-2">
        <flux:input label="Search selected employees" wire:model.live.debounce.350ms="filters.search" placeholder="ID, Employee ID, name or position" />
    </div>
    @if ($sortKey !== 'employee')
        <flux:input :label="str_ends_with($sortKey, '_minutes') ? 'Minimum minutes' : 'Minimum days'" type="number" min="0" step="1" wire:model.live.debounce.350ms="filters.minimum" />
    @endif
    <p class="self-end text-xs leading-relaxed text-muted-foreground">Rankings use the current DTR. Ongoing shifts can still change; days without an assigned schedule are flagged for review.</p>
</div>
